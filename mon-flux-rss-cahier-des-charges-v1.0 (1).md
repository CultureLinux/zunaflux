# Mon Flux RSS — Cahier des charges technique V1.0

**Statut :** spécification de référence pour implémentation par lots avec Codex  
**Date :** 19 octobre 2026  
**Licence :** AGPL-3.0-only (fichier `LICENSE` et notices à ajouter au dépôt)  
**Déploiement de référence :** Linux natif ; Docker Compose également supporté.

## 1. Objectifs et périmètre

Mon Flux RSS remplace intégralement FreshRSS par une application web Symfony auto-hébergée, multi-utilisateur et autonome pour la collecte RSS/Atom. L'expérience privilégie une liste large, une navigation latérale rétractable et un aperçu du résumé en fenêtre modale. Le contenu intégral n'est pas extrait : un lien ouvre le site original.

### Décisions figées

- Symfony **LTS** compatible avec l'environnement cible, version exacte verrouillée au démarrage du projet ; PHP compatible et supporté.
- MariaDB 10.11 uniquement ; Doctrine ORM et migrations.
- Twig + Tailwind CSS + Stimulus ; Turbo seulement si son bénéfice est démontré.
- API JSON **interne** seulement en V1, pas d'API publique ni de client mobile natif.
- Linux natif (Nginx, PHP-FPM, systemd) comme installation de référence ; Docker Compose en second mode officiellement testé.
- Code ouvert sous **AGPL-3.0-only** ; dépendances et licences vérifiées avant publication.
- Comptes indépendants ; inscription publique activable/désactivable par administrateur.
- Collecte native RSS 2.0 / Atom, découverte des flux, import/export OPML.
- Catégories plates, un abonnement classé dans au plus une catégorie ; un flux partagé peut être collecté une seule fois pour plusieurs utilisateurs.
- Liste compacte ou confortable ; menu latéral rétractable ; défilement infini ; résumé dans une modale avec précédent/suivant et raccourcis clavier.
- Lecture automatique **à l'ouverture de la modale** ou manuelle, au choix de l'utilisateur. L'ouverture du site source ne déclenche pas à elle seule un marquage automatique.
- Favoris et « À lire plus tard » séparés ; actions groupées ; marquer comme lus les articles antérieurs à une date.
- Recherche simple et filtres enregistrables ; préférences de thème (clair/sombre/OS), densité, taille du texte et couleur d'accent.
- Collecte à intervalle global configurable avec adaptation automatique ; actualisation manuelle d'un flux, d'une catégorie ou de tous ses flux.
- Images de miniatures et images de résumés mises en cache localement.
- Centre de notifications d'incidents de collecte avec regroupement et acquittement.
- Rétention par défaut de 12 mois, configurable ; les favoris et articles sauvegardés encore rattachés à un abonnement actif sont protégés.
- Limite d'abonnements par compte configurable globalement, avec dérogation individuelle administrateur.
- Administration des comptes : créer, désactiver, réinitialiser les accès, ajuster les limites et supprimer définitivement après confirmation.
- Export individuel des données ; sauvegarde/restauration MariaDB 10.11 opérée par l'administrateur système.

### Hors périmètre V1

Extraction du contenu intégral, API publique Google Reader/Fever, PWA hors ligne, notifications email, IA, règles automatiques complexes, catégories imbriquées, WebSub, application mobile native, partage social et sauvegarde MariaDB 10.11 depuis le navigateur.

## 2. Expérience utilisateur

### 2.1 Structure

- Barre supérieure : nom de l'application, recherche, actualisation, notifications, menu utilisateur.
- Sidebar rétractable : Tous, Non lus, Favoris, À lire plus tard, catégories plates et flux, compteurs non lus, accès aux abonnements et paramètres.
- Zone centrale large : titre de vue, filtres, tri, actions groupées, liste des articles.
- Deux densités : **compacte** (titre, source, date, états et actions) et **confortable** (idem + résumé court et miniature). Préférence persistante par utilisateur.
- Mobile : sidebar en tiroir, liste pleine largeur, modale plein écran. Cibles tactiles accessibles.
- Modale : titre, source, date, résumé HTML purifié, images locales, bouton « Ouvrir l'original », états lu/favori/à lire plus tard, précédent/suivant, fermeture Échap. Les flèches ne s'activent pas lorsqu'un champ éditable a le focus.
- Navigation modale dans **l'ordre de la liste filtrée** ; la fermeture restaure position de scroll, filtres et sélection.
- Défilement infini par curseur opaque et chargement progressif, avec état de chargement, fin de liste et erreur récupérable. Les changements de filtre réinitialisent le curseur.

### 2.2 États de lecture

- `unread` par défaut en l'absence de `ArticleState` ; `read_at` lors de la transition non-lu → lu.
- Préférence `read_mode` : `on_preview` (défaut proposé) ou `manual`.
- Marquage manuel toujours disponible, y compris pour revenir à non-lu.
- Favori et sauvegarde sont deux booléens indépendants du statut lu.
- Rouvrir un article déjà lu ne crée pas une nouvelle « première lecture » statistique.
- Actions de masse : lu, non lu, favori/non favori, sauvegarder/retirer ; portée soit sélection explicite, soit **tous les résultats du filtre** après confirmation.
- « Marquer lus avant une date » applique une borne de date explicite dans le fuseau utilisateur, avec aperçu du nombre concerné et confirmation.

### 2.3 Recherche

- Recherche texte dans titre, résumé texte, auteur et titre de source ; insensible à la casse et, si configuré, aux accents.
- Filtres combinables : abonnement, catégorie, lu/non lu, favori, sauvegardé, période, tri date ascendante/descendante.
- Filtres enregistrés par compte (`SavedFilter`) avec nom unique, édition, suppression et rappel.
- Recherche sur **les articles visibles au compte**, jamais sur les flux d'autres utilisateurs.
- Requête et filtres reflétés dans l'URL lorsque pertinent ; restauration après navigation.

## 3. Architecture logique

```text
Browser (Twig / Tailwind / Stimulus)
             |
       Nginx + PHP-FPM
             |
      Symfony Application
       /       |       \
  Controllers  Use Cases  Security/Voters
       |         |           |
       +------ Domain -------+
                |
      Doctrine repositories
                |
            MariaDB 10.11
                |
     Messenger transport Doctrine
          /             \
  Feed workers       Media workers
          \             /
           Scheduler / maintenance
                |
        RSS/Atom sites (HTTP)
```

**Modules métier** : Identity, Subscription, Feed, Article, Reading, Search, Notification, Media, Statistics, Administration. Contrôleurs minces, services applicatifs orchestrant les transactions, infrastructure isolant HTTP, XML, stockage et SQL. Aucune dépendance directe du domaine envers Twig ou les contrôleurs.

### Arborescence indicative

```text
src/
  Controller/{ArticleController,SubscriptionController,CategoryController,SearchController,Admin/}
  Domain/{Feed,Article,Subscription,Reading,Search,Notification,User,Media,Statistics}/
  Application/{Feed,Article,Reading,Administration,Export}/
  Infrastructure/{Feed,Http,Media,Search,Export}/
  Entity/ Repository/ Security/ Message/ MessageHandler/ Scheduler/ Command/
templates/{layout,article,subscription,category,search,settings,admin,components}/
assets/{controllers,styles,images}/
tests/{Unit,Integration,Functional,E2E,Fixtures}/
config/ migrations/ deploy/{systemd,nginx,docker}/ docs/
```

### Processus

- HTTP PHP-FPM : pages Twig et endpoints JSON internes.
- Scheduler : envoie des tâches de découverte des flux échus, purge, nettoyage des médias et agrégation statistique.
- Messenger : files logiques `feeds`, `media`, `maintenance` ; transport Doctrine DBAL sur MariaDB 10.11 en V1, workers distincts.
- Une collecte par `Feed` partagé, protégée par verrou distribué/DB et unicité SQL ; opérations idempotentes.
- Cache média persistant hors répertoire public ou dans espace contrôlé ; accès uniquement à des formats réencodés sûrs.

## 4. Modèle Doctrine et invariants

| Entité | Champs essentiels | Relations et contraintes |
|---|---|---|
| `User` | id UUID/ULID, email_normalized, password_hash, roles, status, created_at, disabled_at | email unique |
| `UserPreference` | theme, density, font_scale, accent, read_mode, sidebar_collapsed, timezone | 1:1 User |
| `Feed` | id, canonical_url, site_url, title, description, status, etag, last_modified, last_success_at, next_fetch_at, failure_count, interval_seconds | URL canonique unique, 1:N Article |
| `Subscription` | id, user_id, feed_id, category_id nullable, custom_title, enabled, created_at | unique(user_id, feed_id), FK catégorie appartenant au même utilisateur |
| `Category` | id, user_id, name, position | unique(user_id, name), aucune hiérarchie |
| `Article` | id, feed_id, external_id nullable, canonical_link nullable, identity_key, title, summary_html, summary_text, author, published_at nullable, discovered_at, updated_at | unique(feed_id, identity_key) |
| `ArticleState` | user_id, article_id, is_read, read_at, is_favorite, is_saved, updated_at | unique(user_id, article_id) ; autorisé seulement avec abonnement |
| `SavedFilter` | id, user_id, name, criteria_json, created_at | unique(user_id, name), schéma JSON validé |
| `FeedIncident` | id, feed_id, incident_type, opened_at, resolved_at, last_error, occurrence_count | un incident ouvert par flux/type |
| `Notification` | id, user_id, incident_id, created_at, acknowledged_at | unique(user_id, incident_id) |
| `FeedFetchLog` | id, feed_id, started_at, duration_ms, http_status, outcome, created_count, updated_count, error_code | rétention courte, index feed/date |
| `MediaAsset` | id, source_url_hash, storage_key, mime_type, bytes, width, height, status, last_access_at | chemin local contrôlé, déduplication |
| `ReadingDailyStat` | user_id, stat_date, category_snapshot, first_reads | unicité de la dimension statistique |
| `InstanceSetting` | setting_key, value_json, updated_at | clés validées, valeurs typées |

**Invariants obligatoires :**

1. Un utilisateur ne possède jamais de `ArticleState` pour un article dont le flux n'est plus abonné. Vérifier cette règle dans le service et au nettoyage ; les FK seules ne suffisent pas.
2. Une catégorie d'abonnement appartient au même utilisateur que l'abonnement ; contrôle dans le service applicatif.
3. Les articles partagés ne sont pas dupliqués par utilisateur.
4. Les index sur `Article(published_at DESC, id DESC)`, `Article(feed_id, identity_key)`, `Subscription(user_id, feed_id)`, `ArticleState(user_id, article_id)` et un index FULLTEXT MariaDB sur les colonnes textuelles indexables doivent être définis par migration.
5. L'absence d'état personnel signifie non-lu/non-favori/non-sauvegardé ; requêtes de compteurs tenant compte des lignes absentes.
6. Les modifications de flux ne réinitialisent pas les états utilisateurs.
7. `ReadingDailyStat` doit conserver les statistiques agrégées sans exiger la conservation perpétuelle des articles. Le premier marquage lu d'un article ne doit être compté qu'une fois pour l'historique : prévoir un marqueur durable ou un registre de déduplication, avec stratégie compatible avec la purge.
8. Une suppression de compte se fait après désactivation et confirmation ; les références de collecte mutualisées sont conservées si d'autres abonnés en dépendent.

### Spécificités MariaDB 10.11

- Moteur **InnoDB**, encodage `utf8mb4`, collation Unicode adaptée aux recherches en français ; version du serveur fixée à la branche **10.11** et contrôlée à l’installation.
- Recherche simple avec index `FULLTEXT` sur `Article(title, summary_text)` et, si nécessaire, index séparés sur les sources. La recherche par nom de source passe par jointure et filtrage, pas par un index FULLTEXT inter-table.
- Tester explicitement la tokenisation, les mots courts, les mots vides, les accents et la pertinence en français. Prévoir une recherche de secours maîtrisée pour les termes non indexables, avec garde-fous sur les coûts SQL. Ne pas utiliser `tsvector`, `tsquery`, `GIN`, `ILIKE` ou les fonctions spécifiques PostgreSQL.
- Doctrine DBAL et migrations ciblent MariaDB 10.11 (configuration `serverVersion` adaptée) ; tester les index FULLTEXT et contraintes réellement générés par les migrations.
- Pour les actions concurrentes et la collecte, utiliser transactions InnoDB, contraintes uniques et verrous applicatifs/SQL compatibles MariaDB ; les traitements Messenger doivent être idempotents.
- Sauvegarde/restauration via `mariadb-dump` et client `mariadb`, avec tests de restauration, contrôle des permissions et conservation des médias dans la stratégie de sauvegarde.

### Déduplication

Identité stable par flux : identifiant RSS/Atom exploitable, sinon URL canonique normalisée, sinon empreinte déterministe des champs pertinents. Ne pas considérer la date de récupération comme identité. Les changements de titre/résumé mettent à jour l'article existant. En cas de GUID réutilisé ou d'identité contradictoire, journaliser et appliquer une règle conservatrice pour éviter de fusionner deux articles distincts.

## 5. Contrats HTTP internes

Les routes ci-dessous sont des contrats **à implémenter**, non une API publique. Authentification par session, CSRF pour mutations, autorisation par ressource et erreurs JSON homogènes.

| Méthode | Route | Fonction |
|---|---|---|
| GET | `/articles` | Page Twig de lecture |
| GET | `/api/articles` | Liste paginée et filtrée |
| GET | `/api/articles/{id}` | Détails modale (si article accessible) |
| PATCH | `/api/articles/{id}/state` | Modifier lu/favori/sauvegardé |
| POST | `/api/articles/bulk` | Actions massives explicites ou filtrées |
| POST | `/api/articles/mark-before` | Marquer lu avant une date |
| GET | `/api/search` | Recherche filtrée |
| GET/POST | `/api/saved-filters` | Liste/création |
| PATCH/DELETE | `/api/saved-filters/{id}` | Édition/suppression |
| GET/POST | `/subscriptions` | Gestion abonnements |
| PATCH/DELETE | `/subscriptions/{id}` | Modification/suppression confirmée |
| POST | `/subscriptions/{id}/refresh` | Rafraîchir un flux |
| POST | `/categories/{id}/refresh` | Rafraîchir une catégorie |
| POST | `/subscriptions/refresh` | Rafraîchir les flux de l'utilisateur |
| GET | `/api/notifications` | Notifications individuelles |
| PATCH | `/api/notifications/{id}` | Acquittement |
| PATCH | `/api/preferences` | Préférences |
| GET | `/account/export` | Export individuel |
| GET/POST | `/subscriptions/opml` | Export/import OPML selon méthode/route dédiée |
| GET/POST | `/admin/...` | Administration réservée |

**Pagination :** curseur opaque signé ou validé, contenant clés de tri et empreinte des filtres. Tri stable sur `(published_at_effective, id)` ; date effective = publication si valide, sinon découverte. Utiliser une borne/snapshot logique pour réduire les réordonnancements pendant la session. Taille de page plafonnée. Changement de filtre = nouveau curseur.

**Réponse type :**

```json
{
  "items": [{"id":"01...","title":"Exemple","source":"Linux","publishedAt":"2026-10-19T08:30:00Z","isRead":false,"isFavorite":false,"isSaved":false}],
  "nextCursor":"opaque",
  "hasMore":true
}
```

**Erreurs :** `400` validation, `401` non authentifié, `403` action interdite, `404` ressource absente/non accessible, `409` conflit, `422` données invalides, `429` limite atteinte. Ne pas divulguer les abonnements d'autrui par différence de message.

**Actions de masse :** mode `ids` (liste plafonnée) ou `filter` (critères validés, nombre d'articles prévisualisé), transaction/traitement par lots pour volumes importants, résultat `{matched, changed, skipped}`. Protection CSRF et anti-double soumission.

## 6. Services et traitements

| Service | Responsabilité |
|---|---|
| `FeedDiscoveryService` | Recherche RSS/Atom via liens HTML et URL directes |
| `FeedFetcher` | HTTP conditionnel, redirections, taille/temps bornés |
| `FeedParser` | Normalisation RSS/Atom, XML sûr |
| `FeedRefreshPlanner` | Échéances adaptatives et backoff |
| `ArticleIdentityResolver` | Identité/déduplication |
| `FeedImportService` | Upsert transactionnel des articles |
| `FeedHealthService` | État de santé et incidents |
| `MediaCacheService` | Téléchargement et réencodage des images |
| `SubscriptionService` | Abonnements et catégories |
| `OpmlService` | Import/export avec rapport et dédoublonnage |
| `ArticleQueryService` | Recherche, permissions, tri, pagination |
| `ArticleStateService` | Lu/favori/sauvegardé |
| `BulkArticleActionService` | Actions de masse |
| `SavedFilterService` | Filtres enregistrés |
| `ReadingStatisticsService` | Statistiques historiques et compteurs |
| `NotificationService` | Regroupement/acquittement des incidents |
| `RetentionService` | Purge et nettoyage des orphelins |
| `UserExportService` | Export individuel versionné |

Messages : `RefreshFeedMessage`, `RefreshUserSubscriptionsMessage`, `CacheMediaMessage`, `PurgeExpiredArticlesMessage`, `AggregateReadingStatisticsMessage`, `CleanupMediaCacheMessage`. Ne jamais lancer un téléchargement HTTP lourd dans une requête utilisateur.

### Politique de collecte initiale (configurable)

- Intervalle de référence 60 min ; borne automatique 15 min à 6 h ; adapter selon fréquence réelle des publications, `304`, erreurs et `Retry-After`.
- Timeout réseau proposé 15 s ; taille flux 10 Mio maximum ; nombre de redirections borné ; nombre de téléchargements concurrents borné globalement et par hôte.
- Respecter `ETag`/`Last-Modified` et `304 Not Modified`.
- Échecs temporaires : backoff exponentiel plafonné avec jitter ; les erreurs persistantes ouvrent un incident, les retours à la normale le résolvent.
- Rafraîchissement manuel : mise en file, dédoublonnage des demandes, respect du cooldown et des limites ; pas de bypass des protections réseau.
- Une collecte ne supprime jamais un article simplement parce qu'il a disparu du XML.
- Planification persistante dans `Feed.next_fetch_at`, revendication atomique des tâches, protection contre workers concurrents.

### Notifications

Un incident ouvert par couple `(feed, type)` ; notification par utilisateur abonné ; incrément des occurrences sans spam ; acquittement propre à l'utilisateur. Une résolution clôt l'incident. Une nouvelle panne ultérieure ouvre un nouvel incident et peut renotifier.

### Cache médias

Mettre en cache miniatures et images réellement présentes dans les résumés. Téléchargement asynchrone, URL distantes filtrées, format/dimensions/poids bornés, décodage puis réencodage sûr (p. ex. JPEG/WebP/PNG). Interdire SVG et contenus actifs. Réécrire les références des résumés vers les URL locales contrôlées ; placeholder neutre en cas d'échec. Dédupliquer et supprimer les médias devenus non référencés selon politique de nettoyage.

## 7. Rétention, suppressions, exports et statistiques

### Rétention

- Durée globale configurable, 12 mois par défaut ; âge calculé depuis la date de publication valide, sinon découverte.
- Un article reste protégé si un **abonné actif** l'a marqué favori ou sauvegardé.
- Purge par lots et transactionnelle, avec métriques et logs ; ne pas supprimer les articles nécessaires à d'autres utilisateurs.
- Après suppression d'un abonnement : supprimer `Subscription` et les `ArticleState` correspondants pour cet utilisateur, y compris favoris et sauvegardes ; confirmation explicite. Les autres abonnés restent inchangés. Les articles non protégés deviennent éligibles à la purge/nettoyage selon politique.
- Après suppression de catégorie : réaffecter les abonnements à « sans catégorie », sans les supprimer.

### Statistiques

- Compteurs courants : total/non lus/lus par vue, catégorie et flux.
- Agrégats journaliers de premières lectures par utilisateur, avec catégorie instantanée ou clé historique ; ne pas augmenter en rouvrant la modale.
- Réinitialiser « non lu » ne retire pas l'événement historique de première lecture ; séparer état courant et événement historique.
- Prévoir une stratégie de comptage durable et idempotente lors des mises à jour concurrentes.

### Export individuel

Archive versionnée contenant manifeste JSON, profil non sensible, préférences, catégories, abonnements, filtres, états de lecture/favoris/sauvegardes et données statistiques exportables. Ne jamais exporter les mots de passe hashés, jetons de session ni secrets système. Contrôle de propriété systématique. OPML exportable séparément. Une réimportation complète des états n'est pas exigée en V1 ; le format doit néanmoins être documenté.

### Gestion des comptes

L'administrateur peut créer, désactiver, réactiver, définir une limite spécifique et déclencher une réinitialisation d'accès par mécanisme sûr. Suppression définitive en deux étapes : désactivation préalable puis confirmation explicite ; purge des données privées et conservation des contenus mutualisés utiles aux autres abonnés. Inscription publique désactivable sans affecter les comptes existants.

## 8. Sécurité

1. **Authentification** : Symfony Security, hash de mot de passe moderne, sessions sécurisées, throttling, réinitialisation par jeton à usage unique et expiration, vérification email si inscription publique activée.
2. **Autorisations** : voters/policies côté serveur pour toute ressource ; requêtes filtrées par abonnements et propriété ; aucune fuite via ID devinable, recherche, export ou compteur.
3. **CSRF** : tokens sur toutes les mutations de session, même JSON ; cookies `Secure`, `HttpOnly`, `SameSite` adaptés.
4. **XSS** : purifier le HTML des résumés avec allowlist, neutraliser liens dangereux et événements, CSP restrictive ; pas de HTML brut provenant d'un flux.
5. **SSRF** : n'autoriser que HTTP/HTTPS, ports approuvés, refuser IP privées/locales/link-local/métadonnées, contrôler chaque redirection et l'adresse réellement connectée, éviter DNS rebinding ; mêmes règles pour favicon, discovery et images. Proxy réseau sortant filtrant recommandé en défense supplémentaire.
6. **XML** : désactiver DTD/entités externes et accès réseau ; tailles, profondeur et nombres d'éléments bornés ; OPML soumis aux mêmes règles.
7. **Images** : vérifier MIME réel, dimensions et taille ; réencoder ; aucun SVG actif ni HTML servi sous URL média ; en-têtes sûrs.
8. **Limites** : quotas abonnements, tailles OPML, pagination, fréquence de rafraîchissement, connexions et concurrence.
9. **Secrets** : `.env.local`/secrets manager, jamais en Git ; permissions fichiers strictes ; pas de secrets dans logs.
10. **Exploitation** : processus non privilégiés, DB avec privilèges minimaux, sauvegardes chiffrées si nécessaire, logs structurés sans données sensibles, dépendances mises à jour.
11. **AGPL** : conserver notices et licence, fournir le code source correspondant aux utilisateurs réseau selon les obligations applicables ; audit des licences des dépendances et assets.

## 9. Administration et observabilité

Écrans admin : utilisateurs et quotas, inscriptions, paramètres de collecte, erreurs/incidents, métriques de collecte, stockage et rétention, santé des workers. Les administrateurs n'accèdent pas par défaut aux listes privées de lecture des autres comptes.

Indicateurs : flux actifs/en erreur, derniers succès, collectes et articles créés sur 24 h, durées de requêtes, backlog Messenger, âge du dernier passage scheduler, volume MariaDB 10.11 estimé et taille cache médias.

Commandes CLI à **développer** :

```text
app:feed:discover
app:feed:refresh
app:feed:refresh-all
app:feed:health
app:articles:purge
app:media:cleanup
app:stats:aggregate
app:user:export
app:doctor
```

`app:doctor` : DB, migrations, permissions de stockage, transports Messenger, santé scheduler, cohérence configuration.

## 10. Déploiement

### 10.1 Linux natif — référence

Cible initiale : distribution Linux maintenue (Rocky Linux 9 ou autre distribution compatible), Nginx, PHP-FPM, extensions PHP nécessaires, MariaDB 10.11 et systemd. La version PHP et Symfony LTS sera fixée et documentée lors du bootstrap ; ne pas supposer une version disponible sur toutes les distributions.

Services séparés : `mon-flux-rss-php` via PHP-FPM, `mon-flux-rss-worker-feeds.service`, `mon-flux-rss-worker-media.service`, `mon-flux-rss-scheduler.service` (noms indicatifs). Exécution sous utilisateur dédié sans privilèges, redémarrage supervisé, journaux dans journald, répertoires persistants séparés du code.

Séquence : installation dépendances → configuration secrets → création DB et utilisateur → migrations → build assets → cache Symfony → démarrage services → `app:doctor` → tests de fumée. Procédure de rollback documentée, avec prudence sur migrations non réversibles.

### 10.2 Docker Compose

Services : `web` (Nginx), `app` (PHP-FPM), `db` (MariaDB 10.11), `worker-feeds`, `worker-media`, `scheduler`. Images versionnées, volumes persistants DB/médias, healthchecks, secrets non intégrés aux images, réseau interne et ports exposés au strict nécessaire. Même configuration métier et commandes que Linux natif.

### 10.3 Sauvegarde / restauration

- Sauvegarde MariaDB 10.11 via `mariadb-dump` ou stratégie d'exploitation adaptée, avec vérification d'intégrité et rotation.
- Sauvegarder **également le cache média** si l'on veut une restauration immédiate des images ; sinon prévoir leur reconstruction et l'impact réseau.
- Sauvegarder la configuration non secrète et les secrets dans un stockage sécurisé séparé.
- Documenter et tester une restauration complète en environnement isolé.
- Aucun formulaire web d'import SQL ou restauration de base en V1.

## 11. Tests et critères d'acceptation

| Couche | Scénarios obligatoires |
|---|---|
| Unitaires | parseur, normalisation, identité article, planificateur, filtres, règles de rétention |
| Intégration | Doctrine, transactions, contraintes, FTS, Messenger, import/export OPML |
| HTTP fonctionnels | auth, CSRF, isolation intercomptes, préférences, recherche, API, erreurs |
| Sécurité | SSRF DNS/redirections, XML XXE, XSS HTML, MIME trompeur, accès croisé, quotas |
| Collecte | 200/304/301/404/429/5xx, timeouts, backoff, GUID répété, concurrent workers |
| UI E2E | sidebar, densité, modale, clavier, retour scroll, infinite scroll, sélection de masse |
| Rétention | protection favoris/sauvegardes, purge par lots, abonnements partagés |
| Statistiques | première lecture unique, concurrence, changement de catégorie, retour non lu |
| Export | contenu attendu, aucun secret, aucune donnée d'un autre utilisateur |
| Déploiement | installation native, Compose, migrations, redémarrage workers, restauration |

Fixtures de flux RSS/Atom locales : les tests CI ne doivent pas dépendre de sites publics. PHPUnit, PHPStan, lint PHP/Twig, analyse de sécurité des dépendances, Playwright E2E. Seuils de performance à mesurer : scénario cible **1 000 flux et 500 000 articles**, navigation usuelle visée <300 ms côté application hors latence réseau, sans promesse avant benchmark.

**Critères de recette V1** : deux comptes partagent un flux sans partager les états ; OPML conserve les catégories ; collecte répétée ne duplique pas ; incidents n'arrêtent pas les autres flux ; pagination stable ; modale accessible au clavier ; filtres enregistrés persistants ; actions de masse ciblent correctement les résultats non chargés ; purge protège favoris/sauvegardes ; export isolé ; Linux natif et Compose opérationnels ; CI verte.

## 12. Plan Codex — 8 lots

| Lot | Livrables | Condition de sortie |
|---|---|---|
| 1. Socle | Symfony LTS, PHP verrouillé, Doctrine, MariaDB 10.11, sécurité, Twig/Tailwind/Stimulus, CI | connexion + tests et migrations OK |
| 2. Abonnements | Feed, Subscription, Category, discovery, OPML | import/export et isolation validés |
| 3. Collecte | Fetcher, parser, identité, Scheduler, Messenger, incidents | tests HTTP/SSRF et idempotence OK |
| 4. Lecture | liste, modale, états, favoris/sauvegardes, infinite scroll, bulk | E2E navigation et état OK |
| 5. Recherche | FTS, filtres combinés/enregistrés, préférences visuelles | recherche isolée et filtres persistants |
| 6. Administration | comptes, quotas, inscriptions, santé, notifications | rôles, notifications et quotas testés |
| 7. Maintenance | médias, rétention, statistiques, export utilisateur | purge et export testés |
| 8. Industrialisation | systemd, Nginx, Compose, documentation, restauration, audit | deux déploiements et recette finale OK |

### Consignes à placer dans `AGENTS.md`

- Ne jamais modifier le périmètre validé sans signaler le changement.
- Respecter conventions Symfony, typage PHP, architecture par domaines et migrations Doctrine.
- Aucun accès réseau non sécurisé ; toute URL externe passe par les protections SSRF.
- Toute requête sur données personnelles doit appliquer l'isolation utilisateur.
- Ajouter tests unitaires/fonctionnels à chaque modification de règle métier.
- Ne pas ajouter Redis, RabbitMQ, SPA ou API publique sans besoin démontré et validation.
- Ne pas committer, pousser, publier de release ni déployer sans demande explicite du propriétaire du dépôt.
- À chaque lot : bilan des fichiers, migrations, tests exécutés, risques et points restant ouverts.

## 13. Points à confirmer au démarrage du lot 1

Ces paramètres ne remettent pas en cause les décisions fonctionnelles :

1. Version précise Symfony LTS/PHP compatible avec les environnements réellement ciblés.
2. Domaine, nom du dépôt, marque et identité visuelle.
3. Valeurs par défaut : nombre maximal d'abonnements, quota images, tailles de page, fuseau horaire initial.
4. Infrastructure SMTP pour confirmation et récupération de compte (ou procédure admin de réinitialisation si SMTP absent).
5. Politique exacte de conservation des statistiques lors de la suppression d'un compte (suppression/anonymisation) et mentions légales applicables.
6. Stratégie d'indexation FULLTEXT des résumés et gestion des accents sous MariaDB 10.11, à mesurer sur jeu de données réaliste.
7. Choix de stockage des fichiers exportés : streaming direct ou fichiers temporaires chiffrés à durée courte.

---

**Fin de la spécification V1.0.** Toute évolution ultérieure doit être enregistrée dans un journal de décisions (ADR) et reliée à des tests de recette.
