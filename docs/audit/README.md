# Audit Zunaflux — 19 novembre 2026

Audit statique du dépôt (branche `develop`, commit `84f7c8e`). Aucune modification de code, aucun test exécuté : les constats viennent de la lecture des sources, des migrations, de la configuration et de la CI. Chaque lot doit être validé sur Linux par le développeur avant d'être considéré comme terminé (voir [AGENTS.md](../../AGENTS.md)).

## Méthode

Cinq analyses parallèles, chacune pilotée par un skill, puis consolidation et contre-vérification des constats majeurs.

| Axe | Skills utilisés |
|---|---|
| Sécurité applicative | `security-reviewer` |
| Architecture et qualité PHP | `php-pro`, `code-reviewer` |
| Base de données et performances | `sql-pro` |
| Front, accessibilité, PWA | `wcag-audit-patterns`, `web-design-guidelines`, `javascript-pro` |
| DevOps, CI, exploitation | `devops-engineer`, `github-actions-templates`, `bash-defensive-patterns` |

Constats revérifiés dans le code pendant la consolidation :

- `phpstan.neon.dist` n'analyse que 2 fichiers.
- `CollectFeedHandler` relance l'exception après `recordFailure()`, alors que Messenger a `max_retries: 5`.
- `ArticleRetention` purge sur `COALESCE(published_at, collected_at)`, et `app:maintenance:purge-articles` n'est planifiée nulle part.
- `markBefore` fait `setMaxResults(500)`, sans tri et sans exclure les articles déjà lus.
- La recherche utilise `LOWER(...) LIKE`.
- Aucun `trusted_proxies` dans la configuration.
- `User` n'implémente pas `EquatableInterface` et `UserChecker::checkPostAuth()` est vide.

**Correction entre rapports :** l'index `FULLTEXT idx_article_search (title, summary)` **existe déjà** (`migrations/Version20261019050000.php:15`). Il n'est simplement pas utilisé. Le lot 4 n'a donc pas besoin de migration pour activer la recherche FULLTEXT.

## Points forts

- **Typage :** `declare(strict_types=1)` dans 100 % de `src/`. Classes `final`/`readonly` et DTO immuables.
- **Protection SSRF solide :**
  - schémas et ports en liste blanche ;
  - IP privées refusées ;
  - IP résolues épinglées via `CURLOPT_RESOLVE` (protection contre le DNS rebinding) ;
  - chaque redirection revalidée.
- **Sanitizer HTML et proxy d'images :**
  - le sanitizer reconstruit le HTML à partir d'une liste blanche ;
  - les images passent par un relais authentifié avec CSP `sandbox` et `nosniff`.
- **CSRF et autorisations :** CSRF vérifié partout. Le contrôle de propriété multi-utilisateur est fait côté serveur et aucune IDOR n'a été trouvée.
- **Messenger :**
  - outbox transactionnel ;
  - `FOR UPDATE SKIP LOCKED` sur le relais ;
  - appels réseau sortis des transactions de collecte.
- **Base de données :**
  - utf8mb4 partout ;
  - clés étrangères avec un `ON DELETE` cohérent ;
  - pagination keyset sur des UUID v7 ;
  - tous les `down()` présents.
- **Tests et CI :**
  - suite large : unitaires, fonctionnels, migrations, performance, restauration, Playwright, JS, conteneur ;
  - CI avec `permissions: contents: read`, timeouts et gate qualité ;
  - publication par tags SemVer stricts.
- **Hygiène :** aucun secret versionné. L'image tourne en `www-data` et le Caddyfile a `admin off`.

## Vue d'ensemble des lots

L'ordre recommandé suit le rapport gain/risque. Les lots 1 à 3 sont courts et corrigent des défauts qui ont un effet en production.

| Lot | Thème | Priorité | Effort | Migration | Statut | Fichier |
|---|---|---|---|---|---|---|
| 1 | Stabilité de la collecte des flux | Haute | M | Non | Partiel, non validé | [lot-01-collecte.md](lot-01-collecte.md) |
| 2 | Rétention et purge des données | Haute | S–M | Oui (index) | Codé 2.1–2.4, non validé | [lot-02-retention.md](lot-02-retention.md) |
| 3 | Exposition HTTP et sessions | Haute | S–M | Non | Partiel, non validé | [lot-03-securite-http.md](lot-03-securite-http.md) |
| 4 | Performances SQL de la lecture | Haute | M–L | Oui (index) | Partiel (4.1, 4.3, 4.9…), non validé | [lot-04-performances-sql.md](lot-04-performances-sql.md) |
| 5 | Exploitation Docker et observabilité | Haute | M | Non | Partiel (5.1, 5.3, 5.4…), non validé | [lot-05-exploitation.md](lot-05-exploitation.md) |
| 6 | CI, analyse statique et supply chain | Moyenne | M | Non | Partiel (6.1, 6.2, 6.4, 6.7), non validé | [lot-06-ci-qualite.md](lot-06-ci-qualite.md) |
| 7 | Accessibilité et i18n | Moyenne | M | Non | À faire | [lot-07-accessibilite-i18n.md](lot-07-accessibilite-i18n.md) |
| 8 | Robustesse front, PWA et poids des assets | Moyenne | M | Non | À faire | [lot-08-front-pwa.md](lot-08-front-pwa.md) |
| 9 | Architecture et dette de code | Basse | L | Non | À faire | [lot-09-architecture.md](lot-09-architecture.md) |

Le détail de ce qui est appliqué, partiel ou laissé de côté figure dans la section « Statut d'avancement » de chaque fichier de lot.

Effort : **S** ≤ ½ jour, **M** 1–3 jours, **L** > 3 jours.

## Les 10 constats les plus importants

1. **Le backoff de collecte s'emballe** (lot 1). Une erreur réseau passagère déclenche 6 tentatives Messenger. Chacune appelle `recordFailure()`, donc le flux passe directement à 24 h de backoff, avec 6 incidents enregistrés et un message dans `failed`.
2. **La purge recrée les articles** (lot 2). Un article encore présent dans le flux mais avec une `pubDate` ancienne est supprimé, puis réinséré comme nouveau à la collecte suivante : il redevient non lu et déclenche une nouvelle notification.
3. **La purge n'est jamais exécutée** (lot 2). La rétention annoncée (`ZUNAFLUX_RETENTION_MONTHS`) n'est pas appliquée et la base croît sans limite.
4. **Pas de `trusted_proxies`** derrière le proxy HTTPS (lot 3). Les cookies partent sans `Secure`, et `login_throttling` voit une seule IP : quelques échecs de connexion bloquent tout le monde.
5. **Un compte désactivé reste connecté** (lot 3) jusqu'à l'expiration de sa session.
6. **« Marquer lu avant… » ne progresse pas** (lot 4). Le traitement retraite toujours les mêmes 500 articles, ligne par ligne, avec un flush par article.
7. **La liste principale n'a pas d'index sur son tri** (lot 4). Le tri porte sur `collected_at, id` sans index. Les compteurs sont recalculés sur tout l'historique.
8. **Les logs applicatifs prod sont perdus en conteneur** (lot 5), parce qu'ils sont écrits dans `var/log`, qui n'est pas un volume.
9. **Les migrations s'appliquent automatiquement** à chaque `docker compose up`, avec `latest` comme tag par défaut (lot 5).
10. **PHPStan ne couvre que 2 fichiers sur 101** (lot 6). Il n'y a pas non plus de `composer audit`, de scan d'image, ni de Dependabot.

## Dépendances entre lots

- Le lot 3 (proxy de confiance) **doit précéder** le limiteur d'inscription : sans IP réelle, tout limiteur par IP est global.
- Le lot 2 ajoute `idx_article_collected (collected_at, id)`, que le lot 4 réutilise. Les migrations d'index des deux lots peuvent être regroupées.
- Le lot 6 (PHPStan sur `src/` avec baseline) facilite les lots 1, 4 et 9. Le reformatage PSR-12 (lot 9) doit être isolé dans un diff purement mécanique.
- Les lots 7 et 8 touchent les mêmes fichiers JS (`article-list.js`, `zen.js`). Les traiter à la suite limite les conflits.

## Socle de validation Linux commun à tous les lots

Commandes **sans effet sur les données**, reprises de `.github/workflows/ci.yml`. Chaque lot précise ses compléments.

```bash
php85 -v
php85 /usr/bin/composer validate --strict
find src tests migrations public bin -type f -name '*.php' -print0 | xargs -0 -n1 php85 -l
php85 vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=512M
php85 bin/console lint:container
php85 bin/console lint:twig templates/
APP_ENV=test php85 vendor/bin/phpunit
for f in tests/JavaScript/*.test.cjs; do node "$f" || exit 1; done
```

Les commandes qui **modifient une base ou l'environnement** (migrations, `docker compose up`) sont isolées dans chaque lot et exigent une approbation humaine. Les tests fonctionnels et de migration ne doivent tourner que sur une base dédiée.
