# Lot 9 : architecture, lisibilité et dette de code

**Priorité :** Basse (en partie préparatoire) · **Effort :** L · **Migration :** non

**Objectif :** rendre le code relisible et testable, alléger les contrôleurs et combler les trous de tests sur les règles métier.

Les refactorings de ce lot touchent beaucoup de fichiers : **chaque sous-lot doit être validé par le développeur avant d'être engagé** (cf. AGENTS.md, « ne refactorise pas hors périmètre sans accord »).

## 9.A Reformatage PSR-12 (préalable, diff purement mécanique)

| Constat | Preuve | Action | Effort |
|---|---|---|---|
| Classes entières écrites sur une seule ligne : 35 fichiers ont des lignes de plus de 200 caractères (113 lignes au total). `wc -l` sous-estime donc la taille réelle (`AdminController` : 86 lignes pour 15,5 Ko). | `src/Entity/FeedIncident.php:6` (1 886 caractères) ; `src/Controller/PreferenceController.php:8` ; `AdminFeedSyncController.php:7` ; `AdminController.php:80-85` ; `PreferenceQuietHoursController.php:5` ; `SavedFilterController.php:7-9` ; `Security/UserChecker.php:5` ; `CollectFeedHandler.php:62` | Lancer PHP-CS-Fixer (`@Symfony`, `@PSR12`) **seul**, sans aucun changement de comportement, puis activer le contrôle en CI (lot 6, point 6.5). | M |

## 9.B Contrôleurs et couches

| Constat | Preuve | Action | Effort |
|---|---|---|---|
| Les contrôleurs les plus gros contiennent du SQL brut et de la logique métier. | `SubscriptionController` (23 Ko), `ArticleController` (20 Ko), `AdminController` (15,5 Ko) | Extraire la logique vers `src/Application/**`. | L |
| La création d'un « sync run » est copiée 4 fois, l'endpoint de statut 4 fois, le `DELETE article_state` 2 fois. | `SubscriptionController.php:123-129,173-176,205-210,270,313` ; `AdminFeedSyncController.php:7` | `FeedSyncRunService` et `SubscriptionRemoval`. | M |
| Le contrôle de propriété est écrit à la main dans chaque action. | `ownedSubscription`, `ownedCategory`, `accessibleArticle` | `SubscriptionVoter`, `CategoryVoter` et `ArticleVoter`, avec tests. | M |
| `SubscriptionController::update` réimplémente `SubscribeToFeed`, et son `DELETE notification_pending` est exécuté hors transaction. | `SubscriptionController.php:238-247` | Réutiliser le service applicatif dans une transaction. | S |
| `AdminController::retry` appelle `CollectFeedHandler` de façon synchrone dans la requête HTTP, ce qui peut durer jusqu'à environ 60 s. | `AdminController.php:55` | Mettre la collecte en file (`dispatch`) et suivre son avancement via un sync run. | S |
| Des appels réseau sont placés dans le domaine. | `Domain/Feed/FeedFetcher`, `DiscoveryFetcher`, `UrlSafetyValidator` | Les déplacer vers `Infrastructure/` derrière des interfaces du domaine. | M |
| Un seul repository dédié : tout le reste passe par `getRepository()` dans les contrôleurs. | `src/Repository/UserRepository.php` seul | `ArticleRepository`, `SubscriptionRepository` et `FeedRepository` pour les requêtes du lot 4. | M |

## 9.C Clients HTTP et erreurs

| Constat | Preuve | Action | Effort |
|---|---|---|---|
| Quatre clients cURL écrits à la main, avec des timeouts, plafonds et gestion d'erreur incohérents. | `FeedFetcher`, `DiscoveryFetcher`, `FeedImageFetcher`, `MastodonHttpClient` | Un `SafeHttpClient` unique : validation SSRF, `CURLOPT_RESOLVE`, plafond en streaming, liste blanche de protocoles, gzip. Alternative : `symfony/http-client` (déjà requis) avec `NoPrivateNetworkHttpClient`, à évaluer pour l'épinglage DNS. | M |
| Aucune exception métier : `\InvalidArgumentException` et `\RuntimeException` partout, avec un message qui sert de clé i18n. | Ensemble de `src/Application` | Exceptions typées (`FeedFetchFailed`, `SubscriptionLimitReached`…) portant une clé de traduction. `catch (\Throwable)` réservé à la journalisation. | M |
| `new \DateTimeImmutable()` est appelé directement dans les entités et les handlers, alors qu'un `Clock` existe. | `Feed.php:99`, `FeedIncident.php:6`, `Article.php:186`, `CollectFeedHandler.php:53` | Passer l'heure en paramètre, ou injecter `ClockInterface` dans les services. | S |

## 9.D Trous de couverture de tests

Ces éléments n'ont aucun test direct (à compléter au fil des lots 1 à 4) :

- **Collecte** : `FeedFetcher` (304, redirections, taille), `CollectFeed` (déduplication), chemin d'échec de `CollectFeedHandler`.
- **Services applicatifs** : `SubscribeToFeed`, `ImportOpml`, `CreateCategory`, `ArticleStateService` (testé seulement de façon indirecte), `DeleteUserAccount`, `UserExport`, `CreateInitialAdministrator`.
- **Autres** : les 5 commandes console, `DiscoverFeedHandler`, `UserChecker`, `UserLocaleSubscriber`, `MessengerWorkerHeartbeatSubscriber`.
- **Routes** : `/admin/users*` (création, désactivation, imitation, suppression), `/api/saved-filters`, `/preferences/quiet-hours`.

## Validation Linux

Socle commun. Pour 9.A, vérifier en plus que le diff ne change que le formatage :

```bash
vendor/bin/php-cs-fixer fix --dry-run --diff        # si la dépendance est validée
git diff --stat
git diff -w --ignore-blank-lines | head              # doit être quasi vide pour un pur reformatage
APP_ENV=test php85 vendor/bin/phpunit
```

## Risques et critères d'acceptation

**Risques :**
- Le reformatage produit un gros diff qui complique `git blame`. Le passer en un seul lot, puis ajouter son SHA à `.git-blame-ignore-revs`.
- Les refactorings de contrôleurs risquent des régressions d'autorisation. Les Voters doivent être couverts par des tests fonctionnels multi-utilisateurs.

**Critères d'acceptation :**
- Aucune ligne de plus de 200 caractères dans `src/`.
- Aucun SQL brut dans les contrôleurs.
- Les Voters sont testés.
- Les 4 clients HTTP sont unifiés.
- Tests ajoutés pour les éléments de 9.D.

## Statut d'avancement

*Mis à jour le 20 novembre 2026. Décisions du développeur : PHP-CS-Fixer accepté ; client HTTP unifié sur `symfony/http-client` ; retry d'incident mis en file avec visualisation. Ordre retenu : tests d'abord (9.D), puis 9.A, 9.C (horloge, exceptions), 9.B (doublons, repositories, Voters), et en dernier le client HTTP et le déplacement du réseau hors du domaine.*

### 9.D Trous de couverture : traité (en attente de validation)

| Élément | Tests ajoutés |
|---|---|
| `CreateCategory`, `ImportOpml` | `tests/Functional/Application/SubscriptionServicesTest.php` (unicité par compte et nom, compteurs importé/doublon/échec, catégorie affectée, DOCTYPE refusé) |
| `DeleteUserAccount`, `CreateInitialAdministrator` | `AccountServicesTest.php` (compte actif non supprimable, suppression en cascade sans toucher aux données partagées, administrateur créé une seule fois) |
| `ArticleStateService` | `ArticleStateServiceTest.php` (première lecture enregistrée une seule fois, favori indépendant de la lecture, article inaccessible, isolation par compte) |
| `UserExport` | `UserExportTest.php` (archive complète, aucune donnée d'un autre compte, OPML groupé par catégorie et échappé) |
| `UserChecker` | `tests/Unit/Security/UserCheckerTest.php` |
| `MessengerWorkerHeartbeatSubscriber`, `DiscoverFeedHandler` | `WorkerAndDiscoveryTest.php` (battement limité, message sans cache ignoré, adresse interdite enregistrée en erreur) |
| `UserLocaleSubscriber` | `PreferenceRoutesTest.php` (la langue de la page suit la préférence) |
| `CollectFeedsCommand`, `PurgeArticlesCommand`, `CreateUserCommand`, `RelayNotificationsCommand`, `SendNotificationsCommand` | `tests/Functional/Command/ConsoleCommandsTest.php` |
| `/api/saved-filters` | `SavedFilterApiTest.php` (CRUD, CSRF, isolation entre comptes, entrées invalides) |
| `/preferences/quiet-hours` | `PreferenceRoutesTest.php` (enregistrement, effacement, valeurs invalides ignorées, CSRF, authentification) |
| `/admin/users*` | `AdminUserRoutesTest.php` (accès réservé aux administrateurs, désactivation et réactivation, dernier administrateur protégé, imitation, suppression avec confirmation, création) |

Socle ajouté : `tests/Support/RollbackKernelTestCase.php` (transaction annulée après chaque test) et `tests/Support/CsrfTokens.php`.

**Bug trouvé par ces tests et corrigé :** `SavedFilterController` renvoyait une erreur 500 sur un JSON invalide, un nom vide ou des filtres non tableau ; il répond désormais 400 (JSON) ou 422 (validation) avec un message traduit.

**Non couvert :** chemin de succès de `DiscoverFeedHandler` (requiert le réseau), chemins d'échec des commandes de relais (les classes sont `final`, pas de double possible), quatre routes d'administration annexes (incidents, quotas, synchronisation).

### 9.A à 9.C : à faire

