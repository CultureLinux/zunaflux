# Lot 2 : rétention et purge des données

**Priorité :** Haute · **Effort :** S–M · **Migration :** oui (index)

**Objectif :** appliquer réellement `ZUNAFLUX_RETENTION_MONTHS` sans recréer les articles purgés, et borner la croissance des tables de diagnostic.

## Constats et actions

| ID | Constat | Preuve | Action | Effort |
|---|---|---|---|---|
| 2.1 | **Boucle purge → réinsertion.** La purge filtre sur `COALESCE(published_at, collected_at)`, mais la déduplication ne s'appuie que sur les lignes `article` encore présentes. Un article encore dans le flux mais à la `pubDate` ancienne est donc supprimé puis réinséré comme nouveau, non lu et notifié. | `src/Application/Maintenance/ArticleRetention.php:33` ; `CollectFeed.php:37` | Purger sur `collected_at` seul. Le flux a alors eu au moins N mois pour retirer l'item. | S |
| 2.2 | Le prédicat `COALESCE` et le tri ne sont pas indexables : balayage complet et tri à chaque lot. | `ArticleRetention.php:33` | Ajouter `idx_article_collected (collected_at, id)`, index également utile au lot 4. | S |
| 2.3 | La commande ne traite qu'un lot de 100 par exécution et n'est appelée nulle part : ni Compose, ni script, ni systemd, ni documentation. | `src/Command/PurgeArticlesCommand.php:24` ; `grep purge-articles` ne trouve rien dans `docker/`, `compose.yaml`, `deploy/`, `README.md` et `docs/` | Boucler par lots jusqu'à obtenir 0 ou atteindre un budget de temps (option `--max-seconds`). Planifier : ajouter la purge à `docker/collect-feeds.sh` ou créer un service Compose dédié, et fournir un timer systemd hors Docker. Documenter dans `docs/SYNTHESE.md`. | S |
| 2.4 | Fenêtre de concurrence entre le `SELECT` et le `DELETE` : un favori ajouté entre les deux est perdu. | `ArticleRetention.php:33-40` | Placer la condition `NOT EXISTS` directement dans le `DELETE … WHERE id IN (…) AND NOT EXISTS (…)`. | S |
| 2.5 | Les fichiers de `var/media` liés aux articles purgés ne sont pas évoqués. | À vérifier dans `ArticleRetention`/les médias | Vérifier si des médias sont rattachés aux articles et prévoir leur nettoyage. | S |
| 2.6 | Les tables de diagnostic ne sont jamais purgées : `feed_sync_run`, `feed_sync_run_entry`, `feed_incident_occurrence`, `first_read_event`, et l'historique de `DigestQueue` au-delà de 30 jours. | Migrations, `AdminFeedSyncController` | Purge à 90 jours dans la même commande, avec un index sur `feed_sync_run.created_at`. | S |

## Exemple de migration (MariaDB 10.11)

```sql
ALTER TABLE article
  ADD INDEX idx_article_collected (collected_at, id),
  ALGORITHM=INPLACE, LOCK=NONE;
```

Déclarer aussi cet index dans `src/Entity/Article.php` (`#[ORM\Index]`), voir lot 4 point 4.6.

## Tests à ajouter

- `ArticleRetention` : un article avec `published_at` ancien mais `collected_at` récent **n'est pas** purgé.
- Les favoris et articles « à lire plus tard » d'un abonné actif ne sont jamais purgés.
- La boucle de la commande s'arrête quand le budget de temps est dépassé.
- Le nettoyage des tables de diagnostic respecte le seuil de 90 jours.

## Validation Linux

Socle commun, puis :

```bash
APP_ENV=test php85 vendor/bin/phpunit --filter 'Retention|Purge'
php85 bin/console app:maintenance:purge-articles --help
```

**Avec approbation humaine :**

```bash
# Sur une base de test dédiée uniquement
APP_ENV=test php85 bin/console doctrine:migrations:migrate --no-interaction
php85 tests/Migration/check-migrations.php upgrade
```

**En production**, après sauvegarde : migrations puis première purge lancée manuellement, en surveillant la durée et les verrous.

## Risques et critères d'acceptation

**Risques :**
- La première purge en production peut supprimer un volume important. La lancer manuellement et par lots.
- Le changement de critère (`collected_at`) conserve les articles plus longtemps que l'ancien critère pour les flux qui republient d'anciens items. C'est voulu.

**Critères d'acceptation :**
- Après purge puis collecte, aucun article purgé ne réapparaît.
- `EXPLAIN` de la sélection de purge utilise `idx_article_collected`.
- La purge est planifiée dans Docker et documentée hors Docker.
