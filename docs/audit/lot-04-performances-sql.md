# Lot 4 : performances SQL de la lecture et opérations de masse

**Priorité :** Haute · **Effort :** M–L · **Migration :** oui (index)

**Objectif :** borner le coût des pages de lecture, des compteurs et des opérations « tout marquer lu » quand le volume d'articles grandit, et corriger le bug fonctionnel de `markBefore`.

## Requêtes chaudes

| Requête | Emplacement | Situation actuelle |
|---|---|---|
| Liste keyset `collected_at DESC, id DESC` | `ArticleController.php:103-121` | Pas d'index sur `collected_at`, d'où un tri complet. `a.content` (LONGTEXT) est chargé uniquement pour détecter une image. |
| Compteurs non lus | `ArticleController.php:43`, `StatisticsController.php:29-31`, `SubscriptionController.php:91-97` | Agrégation sur tout l'historique, répétée trois fois. |
| Recherche | `ArticleController.php:123` | `LOWER(...) LIKE '%…%'` : balayage complet, alors que le FULLTEXT existe. |
| Marquer lu (flux, avant date, groupé) | `MarkSubscriptionRead.php:25-47`, `ArticleController.php:260-269` | Traitement ligne par ligne, avec un flush par article. |

## Constats et actions

| ID | Constat | Preuve | Action | Effort |
|---|---|---|---|---|
| 4.1 | **Bug : `markBefore` ne progresse jamais.** `setMaxResults(500)` sans `ORDER BY` et sans exclure les articles déjà lus : il retraite les mêmes 500 articles. | `ArticleController.php:269` | Réécriture ensembliste (4.3), ou au minimum exclure les lus, trier par `id` et boucler. | S |
| 4.2 | La liste principale trie sans index. | `ArticleController.php:110` ; seul `idx_article_feed_published` existe | Index `(feed_id, collected_at, id)` (et `(collected_at, id)`, lot 2). Ajouter une colonne `has_image` calculée à la collecte pour ne plus charger `content`. | M |
| 4.3 | « Tout marquer lu » fait 2 `findOneBy`, une transaction et un flush par article, sans `clear()`. Coût en O(n²) dans l'UnitOfWork. `bulk` fait environ 4 requêtes par id. | `MarkSubscriptionRead.php:43` ; `ArticleStateService.php:22-59` | `INSERT … SELECT … ON DUPLICATE KEY UPDATE` par lots de 1 000 (ids générés côté PHP en UUID v7 pour rester cohérent), plus l'équivalent pour `first_read_event` et `reading_daily_stat`. Factoriser pour flux, catégorie, avant-date et groupé. | M |
| 4.4 | Les compteurs sont recalculés sur tout l'historique, trois fois dans la même requête HTTP. | `StatisticsController.php:29-31` | Court terme : une seule passe avec `WITH ROLLUP`, appuyée sur l'index 4.2. Moyen terme : table `subscription_unread_count` mise à jour par delta, à décider. | M–L |
| 4.5 | La recherche n'utilise pas le FULLTEXT existant. `LOWER()` est inutile (collation `_ci`), et `%` et `_` saisis ne sont pas échappés. | `ArticleController.php:123` ; index présent dans `Version20261019050000.php:15` | `MATCH(a.title, a.summary) AGAINST(:q IN BOOLEAN MODE)` via une fonction DQL personnalisée ou DBAL, complété par un `LIKE` échappé sur le titre du flux et le titre personnalisé. | M |
| 4.6 | Dérive entre mapping ORM et schéma : aucun `#[ORM\Index]` déclaré. `doctrine:schema:validate` échoue, et `migrations:diff` proposerait des `DROP INDEX`, y compris sur le FULLTEXT. | `src/Entity/Article.php:13`, `ArticleState.php:12`… | Déclarer tous les index existants (`flags: ['fulltext']` pour la recherche). | S |
| 4.7 | Requêtes N+1 : barre latérale, liste des sources, quotas en administration, `accessibleArticle` appelé pour chaque id. | `ArticleController.php:40` + `_reading_source.html.twig:7` ; `subscription/_source_list.html.twig:20-35` ; `AdminController.php:30` | `addSelect('f', 'c')` en fetch-join, `GROUP BY user_id`, contrôle d'accès en une requête `IN`. | S |
| 4.8 | Désabonnement ou suppression de catégorie : `DELETE` multi-table sans borne, avec des verrous longs sur `article_state`. | `SubscriptionController.php:276,315` | Supprimer par lots de 1 000, une transaction par lot. | S |
| 4.9 | Les index `(user_id, is_read/is_favorite/is_saved)` sont peu sélectifs. « Non lu » correspond à une ligne absente, et `COALESCE(s.isRead, false)` n'est pas indexable. | `Version20261019040000.php:19` ; `ArticleController.php:128` | Remplacer par `(user_id, is_favorite, article_id)` et `(user_id, is_saved, article_id)`. Supprimer `user_read` après `EXPLAIN`. | S |

## Réglages MariaDB à documenter (FULLTEXT)

- `innodb_ft_min_token_size = 3` par défaut : les mots de 1 à 2 lettres sont ignorés.
- La liste de mots vides par défaut est anglaise. Utiliser `innodb_ft_server_stopword_table` (liste FR/EN/DE) ou `innodb_ft_enable_stopword = 0`.
- Pas de stemming ni de parseur ngram.
- Tout changement de ces réglages impose de reconstruire l'index : fenêtre de maintenance, car un FULLTEXT ne se crée pas en `LOCK=NONE`.

## Exemple de migration

```sql
ALTER TABLE article
  ADD INDEX idx_article_feed_collected (feed_id, collected_at, id),
  ADD COLUMN has_image TINYINT(1) NOT NULL DEFAULT 0,
  ALGORITHM=INPLACE, LOCK=NONE;
-- Puis, par lots, remplir has_image pour l'historique. À défaut, la valeur reste fausse pour l'historique.
```

## Tests à ajouter

- Fonctionnel `markBefore` : 1 200 articles anciens donnent 1 200 articles lus, en plusieurs appels si besoin.
- `MarkSubscriptionRead` ensembliste : `first_read_event` est idempotent et `reading_daily_stat` est cohérent.
- Recherche : mots accentués, plusieurs mots, caractères `%` et `_`, titre de flux.
- `tests/Performance` : budget de requêtes et `EXPLAIN` utilisant les nouveaux index sur la liste, les compteurs et la purge.
- `php85 bin/console doctrine:schema:validate` sans erreur, à ajouter en CI (lot 6).

## Validation Linux

Socle commun, puis :

```bash
APP_ENV=test php85 bin/console doctrine:schema:validate
APP_ENV=test php85 vendor/bin/phpunit --filter 'Article|MarkSubscriptionRead|Statistics|Search'
```

**Avec approbation humaine :**

```bash
# Base dédiée : migrations, check de migration et banc de performance
APP_ENV=test php85 bin/console doctrine:migrations:migrate --no-interaction
php85 tests/Migration/check-migrations.php upgrade
php85 tests/Performance/measure.php
```

## Risques et critères d'acceptation

**Risques :**
- Le passage en SQL ensembliste contourne l'UnitOfWork : vérifier qu'aucune entité `ArticleState` n'est ensuite utilisée en mémoire pendant la même requête.
- L'ajout d'une colonne sur une table `article` volumineuse est en `INPLACE`, mais reste à mesurer en préproduction.

**Critères d'acceptation :**
- `markBefore` traite tous les articles visés.
- La liste et les compteurs passent par les index (`EXPLAIN`).
- `schema:validate` est vert.
- La recherche utilise `MATCH … AGAINST`.
