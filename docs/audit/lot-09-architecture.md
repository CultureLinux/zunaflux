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

### 9.A Reformatage : appliqué et commité (`dbb5baa`, SHA ajouté à `.git-blame-ignore-revs`)

- **Dépendance validée :** `friendsofphp/php-cs-fixer` (v3.95.32, `require-dev`) ; ses dépendances (`react/*`, `composer/*`…) sont toutes de développement. Aucune version existante n'a changé dans `composer.lock`.
- **Configuration :** `.php-cs-fixer.dist.php` (`@Symfony` + `@PSR12`, hors `yoda_style`, `phpdoc_summary`, `phpdoc_to_comment`, `single_line_throw`) sur `src/` et `tests/`. Les migrations ne sont pas reformatées (déjà appliquées).
- **Méthode :**
  1. Les 35 fichiers à lignes de plus de 200 caractères ont été ré-imprimés par `nikic/php-parser`, avec **comparaison de l'arbre syntaxique avant et après** (identique).
  2. Un second passage a coupé les lignes encore trop longues (virgules d'arguments et de tableaux, chaînes `->`, opérateurs `||` et `&&`, longues requêtes SQL découpées en chaînes concaténées), avec la même vérification d'arbre (la concaténation de chaînes littérales est repliée avant comparaison).
  3. PHP-CS-Fixer a ensuite harmonisé le style ; 7 lignes restantes ont été coupées à la main (SQL, tableau de choix des préférences).
- **Résultat :** 0 ligne de plus de 200 caractères dans `src/` (109 avant) ; `php-cs-fixer fix --dry-run` sans écart ; 275 tests et PHPStan inchangés.
- **CI :** étape « Check code style with PHP-CS-Fixer » ajoutée au job `php` (clôt le point 6.5 du lot 6).

### 9.B Contrôleurs et couches : appliqué (en attente de validation)

| Constat | Résultat |
|---|---|
| Sync run copié 4 fois, endpoint de statut copié 4 fois | `Application/Feed/FeedSyncRunService` (`start`, `entries`, `isComplete`) ; les 5 actions de `SubscriptionController` et `AdminFeedSyncController` l'utilisent. |
| `DELETE article_state` copié 2 fois | `Application/Subscription/SubscriptionRemoval` (`remove`, `removeCategory`). |
| `SubscriptionController::update` réimplémente `SubscribeToFeed`, `DELETE notification_pending` hors transaction | `Application/Subscription/SubscriptionEditor` (une transaction) qui réutilise `SubscribeToFeed::resolveFeed()` (création du flux sous verrou). |
| Contrôle de propriété à la main | `SubscriptionVoter`, `CategoryVoter`, `ArticleVoter` + trait `Controller/ResolvesOwnedEntities` partagé par 3 contrôleurs (suppression de la copie de `accessibleArticle` dans `MastodonStatusController`). Réponses inchangées : 403 pour une source ou catégorie inconnue ou étrangère, 404 pour un article illisible. |
| Retry d'incident synchrone (jusqu'à 60 s dans la requête) | **Mis en file avec visualisation** : `AdminController::retry` crée un sync run (`clearIncident`) et répond 202 ; le dialogue (`feed-retry.js`) suit le run via `/admin/feed-sync/{id}` et affiche l'état de chaque étape ; le handler supprime l'incident dès que la collecte réussit. `CollectFeedHandler::collectOrFail` et le drapeau `$rethrow` ont disparu. |
| Réseau dans le domaine | `FeedFetcher`, `DiscoveryFetcher` et `UrlSafetyValidator` déplacés dans `src/Infrastructure/` ; interfaces de domaine `FeedFetcherInterface` et `PageFetcherInterface`. |
| Un seul repository dédié, SQL brut dans les contrôleurs | `ArticleRepository` (liste paginée, avec `ArticleListFilter`), `FeedRepository` (flux échus, inutilisés, cibles de synchronisation), `SubscriptionRepository`, `UserRepository::countOtherActiveAdministrators`, plus les services `ReadingCounters`, `AdminStatistics`, `HealthCheck` et `DigestQueue::latestId()`. **Plus aucun SQL ni QueryBuilder dans `src/Controller/`.** |

Corrections au passage : la jauge « messages en attente » de l'administration comptait la file `default` alors que la file réelle s'appelle `async` en production (elle comptait donc toujours 0) ; elle compte maintenant tout ce qui n'est pas en échec, comme `/healthz`. Les quotas d'abonnements par compte passent d'une requête par compte à une seule.

**Non fait :** les contrôleurs `AdminController` (gestion des comptes), `PreferenceController` et `SecurityController` gardent de la logique métier (appels `getRepository()` et règles dans les actions) ; seule l'extraction des requêtes est faite. Les `Application/**` utilisent encore `getRepository()` en interne.

### 9.C Clients HTTP, erreurs, horloge : appliqué (en attente de validation)

- **Client HTTP unifié sur `symfony/http-client`** : `Infrastructure/Http/SafeHttpClient` (validation de chaque saut, **adresse épinglée** via l'option `resolve`, redirections suivies à la main et revalidées, proxys ignorés, corps plafonné pendant le téléchargement après décompression, schémas et IPv4 restreignables). `FeedFetcher`, `DiscoveryFetcher`, `FeedImageFetcher` et `MastodonHttpClient` ne contiennent plus de cURL (0 `curl_init` dans `src/`). Les garde-fous SSRF du lot 3 sont conservés (`UrlSafetyValidator`, avec résolveur de noms injectable pour les tests).
- **Tests sans réseau** : `MockHttpClient` couvre l'épinglage, les redirections, les limites de taille, les erreurs de transport, les validateurs conditionnels et le 304 (`SafeHttpClientTest`, `FetchersTest`). Vérification réelle faite une fois en local : flux Atom public (200 puis 304 conditionnel), page HTML, refus de `127.0.0.1`, `169.254.169.254` et `localhost`, 404.
- **Exceptions typées** : interface `TranslatableException` (clé et paramètres), classes `SubscriptionLimitReached`, `AlreadySubscribed`, `CategoryAlreadyExists`, `InvalidFeedAddress`, `InvalidOpmlFile`, `AccountStillActive`, `ArticleInaccessible`, `UserInputError`, `BusinessRuleViolation`, `DiscoveryFailed`, et côté réseau `HttpFetchFailed` avec `HttpFailure`. Elles étendent les exceptions SPL d'origine, donc les `catch` existants restent valides. `IncidentMessage::describeError()` décrit une panne d'après son type avant de retomber sur le texte. Un test vérifie que chaque clé est traduite en anglais et en allemand.
- **Horloge** : `Clock` injecté dans `CollectFeed`, `CollectFeedHandler`, `ArticleStateService`, `MarkSubscriptionRead`, `UserExport`, `CollectFeedsCommand`, `FeedSyncRunService`, `AdminStatistics` ; `Feed::recordSuccess()` et `ArticleState::markRead()` acceptent l'heure. **Non fait :** les entités gardent `new \DateTimeImmutable()` dans leurs constructeurs et plusieurs mutateurs.
- **Non fait :** une soixantaine d'exceptions techniques (configuration, invariants, `Unable to ...`) restent des `\RuntimeException` ou `\InvalidArgumentException` génériques ; `catch (\Throwable)` n'a pas été limité à la journalisation partout.

