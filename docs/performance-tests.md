# Mesures de performance — lot 6

État : trois mesures Linux exécutées après autorisation explicite du développeur ; compteurs SQL stables, budgets temporels des petites collectes encore instables. Le plan est dans [lot-6-performance-plan.md](lot-6-performance-plan.md). La projection des traces de collecte réduit désormais le coût mesuré à 100 abonnés : 208 → 8 instructions SQL, temps médian réduit de 94 à 95 %. Le budget temporel du cas à un abonné reste non validé.

## Hypothèses issues de la lecture des sources

`CollectFeedHandler::logNotQueued()` charge les abonnements puis les appareils de chaque utilisateur. Les utilisateurs peuvent aussi être chargés à la demande. Hypothèse : croissance du nombre de requêtes avec les abonnés. Le banc appelle le vrai handler, le vrai service de collecte et sa transaction, avec une réponse HTTP 304 synthétique ; le réseau n’est jamais sollicité. Le logger ne conserve que le nombre de traces et leur statut et vérifie une trace par appareil. Ce premier cas mesure une collecte sans nouvel article, pas le téléchargement, le parsing, l’insertion ni l’envoi de notifications.

`ArticleController::list()` utilise des jointures, un curseur et des recherches `LOWER(...) LIKE '%...%'` sur plusieurs champs. Le banc appelle directement cette méthode avec un utilisateur synthétique authentifié par un token en mémoire et le vrai sanitizer. Il ne mesure pas HTTP, le firewall, Twig ni le navigateur et ne constitue pas un test des autorisations HTTP. Les règles de recherche validées ne changent pas : passer à FULLTEXT modifierait les résultats et demande une décision distincte fondée sur les mesures.

`ArticleRetention::purgeBatch()` utilise `COALESCE`, une sous-requête de protection des favoris/à-lire et un tri. Le banc exécute le vrai lot de suppression puis annule la transaction de l’échantillon. Aucun index n’est ajouté sur la seule lecture de ces expressions.

## Jeu de données et protocole

Base **exclusive**, migrée mais sans données applicatives, nommée `zunaflux_performance_test_<suffixe>` ; compte limité à cette base. `PERFORMANCE_TEST_DATABASE_URL` est obligatoire, sans repli sur `DATABASE_URL`. Le garde exige `APP_ENV=test`, PDO MySQL et MariaDB 10.11, et refuse les paramètres DSN autres que `charset` et `serverVersion`. Le kernel est ensuite forcé sur cette même URL. Ne lancer aucun worker, collecteur ou autre test sur cette base pendant la mesure.

| Parcours | Données |
| --- | --- |
| Collecte 304 | 1, puis 10, puis 100 abonnés actifs, un appareil par abonné, aucun article dans le flux collecté |
| Lecture première page / non lus | 10 autres sources, 1 000 articles par source ; 2 000 états lus dont 1 000 favoris pour le lecteur |
| Recherche présente / absente | `benchmark` dans les résumés de tous les articles ; `absent-term` absent |
| Purge | 10 000 articles expirés, 1 000 protégés, lot de 100 ; horloge au 16 novembre 2026 à 12 h UTC, rétention de 12 mois |

La base finale comporte 100 utilisateurs, 11 flux, 110 abonnements et 100 appareils. Les dates de publication et collecte des articles sont fixes. Les UUID sont générés : leur distribution physique et les caches serveur peuvent faire varier les temps. Les contenus courts ne représentent pas des articles longs ni les images.

Chaque parcours effectue un échauffement puis cinq échantillons séquentiels, avec `EntityManager::clear()` avant chacun. Les caches de métadonnées et buffers MariaDB sont chauds : ce n’est pas une mesure à froid. Le middleware DBAL compte les instructions SQL, y compris les savepoints de la transaction métier, mais pas les appels driver directs begin/commit. La préparation, le savepoint extérieur de l’échantillon, les EXPLAIN et son rollback sont hors du compteur et du chronomètre. Le chargement du lecteur et les assertions en mémoire sont inclus. Le temps englobe hydratation, logique PHP, instrumentation et sérialisation ; ce n’est pas un temps SQL seul.

`EXPLAIN` au format tabulaire porte sur les vrais SELECT capturés après conversion de leurs paramètres par DBAL. Les plans exportés conservent les identifiants/types de SELECT, tables, types d’accès, index possibles/utilisés, longueur de clé et estimations `rows`/`filtered` lorsqu’elles sont disponibles : ni SQL brut, ni paramètres, ni champs `Extra` ou `ref`. Le JSON EXPLAIN a été abandonné après des erreurs de contrôle et de syntaxe avec les UUID binaires. Le protocole contient désormais `plan_format: tabular` : refaire les trois références avec ce format, les précédentes sont incompatibles. Les empreintes SQL permettent de rapprocher les plans. Aucun `ANALYZE` exécutant implicitement des écritures n’est utilisé. Les estimations ne remplacent pas une mesure des lignes réellement lues.

Toutes les fixtures sont dans une transaction extérieure annulée en fin de mesure ; chaque échantillon a son savepoint. Le rapport n’est émis qu’après contrôle que les tables de fixtures sont vides. Sur erreur, le nettoyage est tenté dans `finally` ; sur arrêt brutal, la connexion MariaDB doit annuler sa transaction. Aucune table n’est vidée, aucun schéma n’est supprimé. Les rapports restent dans `var/`, déjà ignoré par Git.

## Préparation Linux — approbation humaine requise

Dans une copie de validation isolée, outils déjà installés : PHP 8.5, Composer, MariaDB 10.11. Aucune dépendance nouvelle. Créer une base avec votre client d’administration habituel et lui attribuer un compte dédié :

```sql
CREATE DATABASE zunaflux_performance_test_local
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Configurer localement le DSN `PERFORMANCE_TEST_DATABASE_URL`, sans afficher ni versionner les identifiants. Fournir également la configuration applicative de test habituelle (`APP_SECRET`, etc.), sans secrets de production.

```bash
# En terminal interactif, ne pas activer set -e : contrôler chaque code de sortie.
: "${PERFORMANCE_TEST_DATABASE_URL:?Configurer la base de performance dédiée}"
export APP_ENV=test APP_DEBUG=0
export DATABASE_URL="$PERFORMANCE_TEST_DATABASE_URL"
php85 /usr/bin/composer install --no-interaction --prefer-dist --no-progress
# Le garde se connecte sans exiger de tables : il précède toute migration.
php85 tests/Performance/measure.php guard
php85 bin/console doctrine:migrations:status --env=test
php85 bin/console doctrine:migrations:migrate --env=test --no-interaction
mkdir -p var/performance
# Chaque exécution écrit et annule ses fixtures et ses suppressions.
php85 tests/Performance/measure.php run > var/performance/baseline-1.json
php85 tests/Performance/measure.php run > var/performance/baseline-2.json
php85 tests/Performance/measure.php run > var/performance/baseline-3.json
git rev-parse HEAD
git status --short
```

Attendu : garde accepté, migrations courantes, huit parcours avec cinq échantillons chacun, `rollback_verified: true`, compteurs SQL stables. Consigner à côté des rapports la révision et le diff (pour distinguer deux arbres à même HEAD), CPU/RAM, versions de dépendances, configuration MariaDB/buffer pool, charge concurrente, emplacement disque et date. Les fichiers de rapport tronqués ou vides en cas d’échec sont invalides.

## Budgets et comparaison

Politique initiale proposée : **aucune hausse du maximum de requêtes** et **au plus +20 % sur la médiane de temps**, par parcours, à environnement identique. Ce sont des budgets relatifs reproductibles ; aucun budget absolu en millisecondes n’est inventé sans mesures. Ils restent à ratifier après les trois références. Si les références dépassent déjà cette variation entre elles, stabiliser l’hôte ou augmenter le protocole avant d’accepter une référence ; ne pas ajuster le seuil pour masquer une régression.

Après une optimisation justifiée, conserver les mêmes fixtures et versions, capturer trois rapports candidats et comparer les trois paires correspondantes. Une optimisation de la projection des traces de collecte a été mesurée dans `optimized-{1,2,3}.json` ; voir le compte-rendu du lot pour les gains et les comparaisons partiellement en échec.

```bash
php85 tests/Performance/compare.php var/performance/baseline-1.json var/performance/candidate-1.json
php85 tests/Performance/compare.php var/performance/baseline-2.json var/performance/candidate-2.json
php85 tests/Performance/compare.php var/performance/baseline-3.json var/performance/candidate-3.json
```

Le comparateur recalcule les médianes des cinq échantillons et compare le maximum de requêtes. Il exige des jeux de données, protocoles et versions PHP/MariaDB identiques. Sortie 0 : tous les budgets respectés ; 1 : budget dépassé ; 2 : rapport invalide/incompatible. CPU, RAM et charge restent à contrôler humainement. Un résultat PASS ne prouve pas un gain : démontrer une baisse de requêtes ou de temps sur le parcours visé, puis vérifier les tests fonctionnels existants.

## Diagnostic et suite

Arrêter au premier échec. Vérifier DSN, préfixe, droits dédiés, MariaDB 10.11, tables vides et migrations. Le script n’imprime pas les exceptions DBAL qui pourraient contenir le DSN ; relever la phase et le type d’exception et diagnostiquer localement sans partager de secrets. Un contrôle de résultat inattendu doit être résolu avant exploitation des temps. Examiner les plans par empreinte et les trois points de croissance de la collecte ; ne pas conclure à un N+1 supprimable uniquement sur le temps global.

La matrice reste limitée : un appareil par abonné, abonnements actifs, première page, contenus courts, pas de concurrence ni de collecte 200. Étendre selon les premières observations (0/plusieurs appareils, abonnements désactivés, pagination profonde, nouveaux articles et flux volumineux). Le lot 6 reste ouvert pour les mesures, leur interprétation, les optimisations prouvées et l’acceptation Linux.
