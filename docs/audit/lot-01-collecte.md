# Lot 1 : stabilité de la collecte des flux

**Priorité :** Haute · **Effort :** M · **Migration :** non

**Objectif :**
- qu'une erreur passagère ne pénalise plus un flux pendant 24 h ;
- qu'aucune collecte concurrente ne provoque d'erreur d'unicité ;
- que les requêtes conditionnelles HTTP restent efficaces.

## Constats et actions

| ID | Constat | Preuve | Action | Effort |
|---|---|---|---|---|
| 1.1 | Les retries Messenger s'ajoutent au backoff métier : 6 tentatives, `failureCount` passe à 6, le délai saute à 24 h, 6 occurrences d'incident sont créées, puis le message finit dans `failed`. | `src/MessageHandler/CollectFeedHandler.php:44-50` (`throw $error` après `recordFailure()`) ; `config/packages/messenger.yaml` `max_retries: 5` ; `src/Entity/Feed.php` (`min(2 ** (failureCount - 1), 24)`) | Une fois l'échec enregistré, ne plus relancer l'exception, ou lever `UnrecoverableMessageHandlingException`. Ne relancer que si l'EntityManager est fermé. | S |
| 1.2 | Sur une réponse 304, les validateurs ETag/Last-Modified sont écrasés par `null` quand le serveur ne les renvoie pas. | `src/Application/Feed/CollectFeed.php:32` | Sur 304, conserver les valeurs existantes (`$fetched->etag ?? $feed->getEtag()`). | S |
| 1.3 | Un même flux peut être collecté en parallèle (cron, rafraîchissement utilisateur, administration) sans verrou. Résultat : violation de `uniq_article_feed_identity_hash`, EntityManager fermé, puis retries. | `CollectFeedsCommand.php:24-27`, `SubscriptionController.php:117-131,173-210`, `AdminFeedSyncController.php` | Verrou par flux (`GET_LOCK('feed:<id>', 0)`, comme dans `DigestQueue`, ou `SELECT … FOR UPDATE` sur `feed`). Si le verrou est indisponible, sortir proprement en « déjà en cours ». | M |
| 1.4 | Deux items avec le même GUID dans un flux sont insérés deux fois : `findOneBy` ne voit pas les entités persistées mais pas encore flushées. On a aussi N+1 requêtes de déduplication. | `CollectFeed.php:37-41`, `FeedParser.php` | Charger en une requête `IN (...)` les `identity_hash` existants, puis dédupliquer en mémoire. | S |
| 1.5 | Deux utilisateurs qui ajoutent simultanément le même nouveau flux déclenchent une `UniqueConstraintViolationException`, donc une erreur 500. | `src/Application/Subscription/SubscribeToFeed.php:20-28` | Intercepter l'exception, relire le flux existant et continuer. | S |
| 1.6 | `FeedFetcher` charge toute la réponse en mémoire avant de vérifier sa taille. Un flux hostile peut provoquer un OOM du worker. | `src/Domain/Feed/FeedFetcher.php:29-33` | Lire via `CURLOPT_WRITEFUNCTION` avec un plafond, comme `FeedImageFetcher`/`DiscoveryFetcher`. Ajouter `CURLOPT_ENCODING => ''` (gzip) et `CURLOPT_PROXY => ''`. Corriger aussi `MastodonHttpClient`. | S |
| 1.7 | Redirections : une `Location` relative est rejetée alors que RFC 9110 l'autorise. Une 301/308 ne met pas à jour `canonicalUrl`. Un 410 n'arrête pas la collecte. | `FeedFetcher.php:34` | Résoudre l'URL relative puis la revalider SSRF. Persister la redirection permanente si aucun conflit d'unicité. Sur 410, mettre le flux en pause et créer un incident explicite. | M |
| 1.8 | `recordSuccess()` remet `nextCollectionAt` à `null`, donc chaque flux est recollecté à chaque passage du cron. Les flux sans abonné restent collectés. Le cron fait un `findAll()`. | `Feed.php:99`, `CollectFeedsCommand.php:24` | Planifier `nextCollectionAt = now + intervalle`. Sélectionner en SQL les flux échus ayant au moins un abonnement actif. Ajouter un index `idx_feed_next_collection` (migration à regrouper avec le lot 2). | S |
| 1.9 | Parseur : le titre des flux RDF/RSS 1.0 vaut `null`, `atom:link` peut masquer `<link>`, tout DOCTYPE est rejeté, `'(sans titre)'` est écrit en dur dans le domaine, et le charset HTTP est ignoré. | `src/Domain/Feed/FeedParser.php:11,41,52,54,58` | Gérer `rdf:RDF/channel`. Cibler `<link>` sans namespace. Accepter un DOCTYPE sans entités en le rejetant après `loadXML` si `doctype` contient des entités (voir aussi lot 3). Remplacer le libellé par une clé de traduction. | S |
| 1.10 | `catch (\Throwable) {}` silencieux et `new IncidentMessage()` instancié en dur. | `CollectFeedHandler.php:49,62` | Journaliser au niveau warning et injecter le service. | S |

## Tests à ajouter

- `CollectFeedHandler` : une erreur réseau donne **un** `recordFailure`, **une** occurrence d'incident, et aucune exception relancée.
- `CollectFeed` : un 304 conserve ETag/Last-Modified ; un GUID dupliqué dans le même flux ne produit qu'un article.
- `SubscribeToFeed` : une violation d'unicité est rattrapée, en simulant l'exception.
- `FeedFetcher` : dépassement de taille en streaming, redirection relative, 410.
- `FeedParser` : fixtures RDF, RSS 0.91 avec DOCTYPE, `atom:link` placé avant `<link>`.

## Validation Linux

Socle commun ([README](README.md#socle-de-validation-linux-commun-à-tous-les-lots)), puis :

```bash
APP_ENV=test php85 vendor/bin/phpunit --filter 'CollectFeed|FeedFetcher|FeedParser|SubscribeToFeed'
```

**Avec approbation humaine (base dédiée) :** si 1.8 ajoute l'index, appliquer les migrations sur une copie et lancer `tests/Performance` pour comparer le nombre de requêtes de collecte (référence actuelle : 8 instructions pour 100 abonnés).

## Risques et critères d'acceptation

**Risques :**
- Ne plus relancer l'exception retire les retries rapides. Un flux en erreur ne repassera qu'à l'échéance du backoff : c'est le comportement voulu.
- Le verrou par flux doit être libéré même en cas d'exception (`finally`).

**Critères d'acceptation :**
- Une coupure DNS simulée donne `failureCount = 1` et un délai d'1 h.
- Deux workers sur le même flux ne produisent aucune erreur.
- Un 304 conserve les validateurs.

## Statut d'avancement

*Mis à jour le 20 novembre 2026. États déduits de la lecture du code (commit `44b12bd` et suivants) : le lot n'a pas encore reçu de retour de validation Linux.*

| ID | État | Note |
|---|---|---|
| 1.1 | Appliqué | `CollectFeedHandler` n'est plus relancé après `recordFailure()` ; `collectOrFail` sert l'appel synchrone. |
| 1.2 | Appliqué | Un 304 conserve ETag/Last-Modified (fusionné en un seul appel lors du `/simplify`). |
| 1.3 | Appliqué | Verrou `GET_LOCK` par flux dans `CollectFeed` ; `FeedCollectionInProgress` si déjà en cours. |
| 1.4 | Appliqué | Déduplication par requêtes `IN` par paquets de 500. |
| 1.5 | Appliqué | `SubscribeToFeed` : double lecture autour d'un verrou. |
| 1.6 | Partiel | `FeedFetcher` plafonne en streaming, `CURLOPT_ENCODING` et `CURLOPT_PROXY` sont posés. **`MastodonHttpClient` non corrigé.** |
| 1.7 | Partiel | `Location` relative résolue par `FeedDiscovery::resolve()`. **Non fait :** mise à jour de `canonicalUrl` sur 301/308, pause + incident sur 410. |
| 1.8 | Partiel | Le cron sélectionne en SQL les flux échus avec abonné actif. **Non fait :** `recordSuccess()` remet toujours `nextCollectionAt` à `null` (donc recollecte à chaque passage), et l'index `idx_feed_next_collection` n'existe pas. |
| 1.9 | Partiel | DOCTYPE sans entités accepté, `link` non-Atom ciblé (`RSS_LINK`), RDF géré pour les items. **Non fait :** libellé `'(sans titre)'` toujours en dur, charset HTTP ignoré ; titre RDF à revérifier. |
| 1.10 | Partiel | Journalisation ajoutée à plusieurs endroits. **Non fait :** `IncidentMessage` est toujours instancié par défaut dans le constructeur. |

**Écarts connus après `/simplify` :** le verrou `GET_LOCK` est dupliqué (`CollectFeed`, `SubscribeToFeed`, `DigestQueue`) ; un service `NamedLock` partagé a été écarté pour l'instant (change les constructeurs et les tests).
