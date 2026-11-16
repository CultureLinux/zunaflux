# Lot 6 — Banc de performance préparé, validation en attente

Livraison de sources du 16 novembre 2026. Le lot reste ouvert : mesures, interprétation et optimisation justifiée attendent les résultats Linux. Les validations précédentes, notamment le lot 5, ne sont pas déclarées acquises.

## Contenu et compatibilité

- `tests/Performance/measure.php` : huit parcours des vrais services/contrôleur, comptage DBAL et plans MariaDB, cinq échantillons après échauffement, fixtures transactionnelles.
- `tests/Performance/compare.php` : budgets relatifs de requêtes et de médiane, contrôle des rapports compatibles, sorties 0/1/2 documentées.
- `tests/Support/PerformanceDatabase.php` : DSN obligatoire, environnement test, préfixe dédié et serveur MariaDB 10.11.
- `tests/Unit/Infrastructure/PerformanceDatabaseTest.php` : deux tests de garde hors connexion.
- `tests/Unit/Infrastructure/PerformanceComparisonTest.php` : six tests du comparateur CLI, acceptation et limite de 20 %, régressions SQL/temps, incompatibilité et échantillons incomplets.
- `docs/performance-tests.md`, `docs/lot-6-performance-plan.md`, `docs/lots-amelioration.md` et ce compte-rendu : protocole, hypothèses, plan et suivi.

Aucun fichier applicatif, index, contrôleur, dépendance, asset ou workflow CI modifié. Aucune nouvelle migration. Nouvelle variable d’outillage uniquement : `PERFORMANCE_TEST_DATABASE_URL`. PHP 8.5 et MariaDB 10.11 existants ; aucune installation d’outil par l’agent. Aucun seuil absolu de performance sans mesure ; les budgets relatifs sont une proposition à ratifier sur trois références stables.

## Vérifications effectuées et limites

Le terminal fourni est Bash Linux, malgré les consignes d’environnement Windows du dépôt. Aucune vérification Windows exécutée. Aucune exécution PHP, Composer, PHPUnit, Node, migration, mesure ou build par l’agent. Le cycle rouge/vert PHP n’a donc pas été observé ; les huit tests sont des sources à valider, pas des résultats acquis.

Effectués : lecture statique des services, contrôleur, entités, migrations InnoDB et des API DBAL/ORM/Symfony présentes dans `vendor` ; `git diff --check` et contrôle du périmètre Git. Un contrôle Python de neuf fichiers confirme l’absence d’espaces de fin de ligne, la présence de sauts de ligne finaux et les cibles des liens Markdown locaux. Cette revue ne prouve pas la syntaxe PHP ni l’exécution du banc. Les versions `vendor` doivent correspondre au verrou lors de la validation.

## Vérifications locales PowerShell

```powershell
git diff --check
if ($LASTEXITCODE -ne 0) { throw 'Erreur de diff' }
Get-Content tests/Support/PerformanceDatabase.php
Get-Content tests/Performance/measure.php
Get-Content tests/Performance/compare.php
Get-Content docs/performance-tests.md
git status --short
```

Attendu : diff suivi sans défaut d’espace et périmètre limité au tableau ci-dessus. Les nouveaux fichiers non suivis sont à relire séparément ; `git diff --check` seul ne les valide pas. Aucune installation PHP/npm sur Windows.

## Validation Linux sans modification des données

Depuis une copie isolée, configuration de test et dépendances de développement déjà présentes :

```bash
# En terminal interactif, ne pas activer set -e : contrôler chaque code de sortie.
php85 -v
php85 /usr/bin/composer --version
php85 /usr/bin/composer validate --strict
find tests/Performance -type f -name '*.php' -print0 | xargs -0 -n1 php85 -l
php85 -l tests/Support/PerformanceDatabase.php
php85 -l tests/Unit/Infrastructure/PerformanceDatabaseTest.php
php85 -l tests/Unit/Infrastructure/PerformanceComparisonTest.php
APP_ENV=test php85 bin/console lint:container
APP_ENV=test php85 vendor/bin/phpunit tests/Unit/Infrastructure/PerformanceDatabaseTest.php
APP_ENV=test php85 vendor/bin/phpunit tests/Unit/Infrastructure/PerformanceComparisonTest.php
git diff --check
```

Attendu : syntaxe valide, conteneur valide, huit tests réussis. Le comparateur lance un sous-processus via le binaire PHP courant ; `proc_open` doit être autorisé sur l’hôte de test. Ces tests écrivent des fichiers temporaires supprimés dans leur `finally` et ne se connectent pas à une base. Les lints peuvent écrire du cache. Aucun PHPStan configuré : aucune commande supposée.

## Préparation, migrations existantes et mesures — approbation humaine requise

Suivre la création de base et la configuration du [protocole](performance-tests.md). Puis, exclusivement dans cette copie avec une base dédiée vide :

```bash
# En terminal interactif, ne pas activer set -e : contrôler chaque code de sortie.
: "${PERFORMANCE_TEST_DATABASE_URL:?Configurer la base de performance dédiée}"
export APP_ENV=test APP_DEBUG=0
export DATABASE_URL="$PERFORMANCE_TEST_DATABASE_URL"
# Installation de dépendances existantes ; modifie vendor et les caches.
php85 /usr/bin/composer install --no-interaction --prefer-dist --no-progress
php85 tests/Performance/measure.php guard
php85 bin/console doctrine:migrations:status --env=test
php85 bin/console doctrine:migrations:migrate --env=test --no-interaction
mkdir -p var/performance
php85 tests/Performance/measure.php run > var/performance/baseline-1.json
php85 tests/Performance/measure.php run > var/performance/baseline-2.json
php85 tests/Performance/measure.php run > var/performance/baseline-3.json
# Première estimation de la stabilité, avant toute optimisation.
php85 tests/Performance/compare.php var/performance/baseline-1.json var/performance/baseline-2.json
php85 tests/Performance/compare.php var/performance/baseline-1.json var/performance/baseline-3.json
# Suite applicative : écrit des fixtures dans la base de test.
php85 vendor/bin/phpunit --display-phpunit-notices
```

Attendu : garde accepté avant migration, huit parcours / cinq échantillons, rollback contrôlé, comparaisons stables et tests existants réussis. La suite PHPUnit est placée après le banc car certains tests peuvent conserver des fixtures ; un nouveau banc refuse alors cette base non vide. Utiliser une nouvelle base dédiée pour recommencer, ne pas vider globalement une base existante pour contourner le garde. La création de cette base, les migrations et le banc sont des écritures nécessitant l’accord humain. Aucun effet de production autorisé.

## Assets et services

Aucun changement frontend : aucun npm ni rebuild requis pour les mesures directes du contrôleur. Pour compléter un contrôle d’intégration dans la copie isolée après accord humain, les commandes existantes sont :

```bash
APP_ENV=test php85 bin/console tailwind:build --minify --env=test
APP_ENV=test php85 bin/console asset-map:compile --env=test
```

Attendu : assets compilés ; ces commandes écrivent caches/fichiers générés et ne participent pas au chronométrage. Aucun service à démarrer, redémarrer ou déployer pour ce lot. Sur une installation Docker existante, un contrôle facultatif en lecture seule est :

```bash
docker compose --env-file docker/.env -f compose.yaml ps
docker compose --env-file docker/.env -f compose.yaml logs --tail=100 notifications worker collector
```

Attendu : état habituel, aucun service applicatif connecté à la base de performance. Ne pas utiliser ce contrôle comme autorisation de déploiement.

## Diagnostic, risques et acceptation

Arrêter au premier échec ; ne pas exploiter un JSON vide/tronqué. Pour un refus de garde, contrôler le préfixe, le DSN sans révéler son mot de passe et MariaDB 10.11. Pour un refus de banc, vérifier migrations, tables InnoDB et absence de données. Pour une assertion de résultat, vérifier les API et sémantiques réelles avant de lire les temps. Pour une variation temporelle, relever charge, buffers et caractéristiques de l’hôte. Conserver le type d’erreur et les rapports ; aucune exception DBAL brute ne doit être partagée avec des identifiants.

Critères encore ouverts : trois références stables, hypothèses confirmées ou écartées par compteurs/plans, budgets ratifiés, mesures avant/après d’une optimisation utile, absence de régression. Aucune réduction de coût n’est démontrée par la préparation du banc. La collecte 200, le réseau, les gros contenus, les appareils multiples, les pages profondes, les accès HTTP et la concurrence restent hors de cette première matrice. Les traces du lot 5 et les tests fonctionnels existants conservent leur rôle.

## État Git de la livraison

```text
 M docs/lots-amelioration.md
?? docs/lot-6-performance-plan.md
?? docs/lot-6-performance-validation.md
?? docs/performance-tests.md
?? tests/Performance/
?? tests/Support/PerformanceDatabase.php
?? tests/Unit/Infrastructure/PerformanceComparisonTest.php
?? tests/Unit/Infrastructure/PerformanceDatabaseTest.php
```

Aucune indexation, aucun commit ni push effectué. Aucun déploiement ni migration exécuté. Acceptation conditionnée au retour explicite du développeur sur les validations Linux.

## Correctif après le premier essai Linux

Retour développeur : garde MariaDB accepté ; `run` échoue avec `JsonException`, trois rapports vides. L’étape exacte n’était pas exposée. Hypothèse principale : octets UTF-8 invalides dans les conditions des plans EXPLAIN contenant les UUID binaires. Ce mécanisme reste à confirmer sur l’hôte ; aucune mesure n’est validée.

`tests/Support/PerformancePlan.php` isole le décodage et la sélection des estimations : substitution des octets UTF-8 invalides dans les plans uniquement, suppression inchangée des conditions/paramètres, rejet conservé des erreurs de syntaxe JSON. Deux tests ajoutés dans `tests/Unit/Infrastructure/PerformancePlanTest.php` couvrent les conditions binaires et le JSON tronqué. Le banc affiche désormais une phase et, pour une `JsonException`, son code numérique sans contenu SQL. Aucun comportement applicatif, dépendance, migration ou asset modifié.

Vérifications agent : revue statique et contrôle du diff ; PHP, PHPUnit et MariaDB non exécutés. Le total préparé passe à dix tests avec ces deux nouveaux tests. Les validations initiales restent nécessaires.

Contrôle local sans outil PHP :

```powershell
git diff --check
Get-Content tests/Support/PerformancePlan.php
Get-Content tests/Unit/Infrastructure/PerformancePlanTest.php
git status --short
```

Sur Linux, sans connexion à une base :

```bash
php85 -l tests/Performance/measure.php
php85 -l tests/Support/PerformancePlan.php
php85 -l tests/Unit/Infrastructure/PerformancePlanTest.php
APP_ENV=test php85 vendor/bin/phpunit tests/Unit/Infrastructure/PerformancePlanTest.php
```

Attendu : syntaxe valide et deux tests réussis. Ensuite, dans le terminal déjà configuré sur la base dédiée, relancer le banc (écritures transactionnelles de test, à exécuter par le développeur) :

```bash
php85 tests/Performance/measure.php run > var/performance/baseline-1.json
```

Attendu : JSON non vide avec huit parcours et `rollback_verified: true`. Si l’échec persiste, transmettre phase, type et code JSON ; ne pas exposer les conditions SQL ou le DSN. Ne pas effacer la base pour contourner une erreur. Une fois le premier rapport obtenu, refaire les deux autres mesures et les comparaisons du protocole. Aucun déploiement, migration ou rebuild requis pour ce correctif.

Fichiers supplémentaires dans l’état Git : `tests/Support/PerformancePlan.php` et `tests/Unit/Infrastructure/PerformancePlanTest.php` non suivis, au sein du même lot non indexé.

### Second retour Linux : caractères de contrôle dans EXPLAIN

Le développeur confirme les lints de `measure.php` et `PerformancePlan.php`, puis **2 tests / 3 assertions réussis** sous PHP 8.5.12 / PHPUnit 12.5.40. Le banc échoue encore à `collect_304_1_subscribers:explain`, avec **JSON code 3** (`JSON_ERROR_CTRL_CHAR`). Cela localise l’échec au décodage du plan et indique des caractères de contrôle non échappés ; le test UTF-8 précédent ne couvrait pas ce mécanisme.

Correctif complémentaire dans `PerformancePlan.php` : scanner les chaînes JSON en tenant compte des guillemets et antislashs échappés, convertir leurs octets de contrôle bruts en échappements Unicode. L’espacement JSON extérieur reste intact ; un contrôle illégal hors chaîne et les autres erreurs de syntaxe restent refusés. Les conditions restent exclues du rapport. Deux tests ajoutés couvrent les 32 octets de contrôle dans une chaîne, les guillemets/antislashs échappés et le refus d’un contrôle hors chaîne.

Vérification agent : revue statique et `git diff --check` uniquement, aucune exécution PHP. Les résultats de 2 tests ci-dessus concernent la version précédente ; la suite ciblée comporte désormais **4 tests**, à exécuter. Aucun changement de dépendance, migration, asset ou service, aucun commit ni push.

```powershell
git diff --check
Get-Content tests/Support/PerformancePlan.php
Get-Content tests/Unit/Infrastructure/PerformancePlanTest.php
git status --short
```

Validation Linux sans base :

```bash
php85 -l tests/Support/PerformancePlan.php
php85 -l tests/Unit/Infrastructure/PerformancePlanTest.php
APP_ENV=test php85 vendor/bin/phpunit tests/Unit/Infrastructure/PerformancePlanTest.php
```

Attendu : syntaxe valide et 4 tests réussis. Puis, dans le terminal pointant déjà vers la base dédiée, après accord du développeur pour les écritures transactionnelles de test :

```bash
php85 tests/Performance/measure.php run > var/performance/baseline-1.json
```

Attendu : rapport non vide, huit parcours, `rollback_verified: true`. Aucun besoin de migration, installation, rebuild ou redémarrage. En cas d’échec, transmettre phase et code JSON et garder le lot ouvert. Les risques et critères d’acceptation du protocole initial restent inchangés.

## Exécution directe après autorisation du développeur

Le développeur a explicitement demandé à l’agent de prendre en charge les tests et fourni la connexion de la base dédiée. PHP 8.5.12 est présent sur cet hôte Linux. Aucun identifiant n’est enregistré dans les sources ou ce document. Les constats de non-exécution des sections précédentes décrivent les étapes antérieures et sont remplacés par les résultats ci-dessous pour la version actuelle.

Le nouvel échec `JSON code 4` dans le premier EXPLAIN montre que la réparation UTF-8/contrôles ne suffit pas. Le banc utilise désormais **EXPLAIN tabulaire**, sans décodage JSON des plans. `PerformancePlan` filtre ses colonnes ; ses quatre tests couvrent estimations, exclusion des valeurs binaires/sensibles, ordre des jointures/sous-requêtes et plan vide. Les quatre anciens tests du décodeur JSON sont remplacés. `plan_format: tabular` est ajouté au protocole pour interdire une comparaison avec les anciennes références.

Résultats réellement exécutés :

- Syntaxe PHP du banc, du filtre et de son test : valide.
- Tests ciblés garde/comparateur/plans : **12 tests, 38 assertions**, aucun échec.
- Trois bancs : code de sortie **0**, huit parcours de cinq échantillons chacun et `rollback_verified: true` dans les trois rapports `var/performance/tabular-{1,2,3}.json`.
- Suite PHPUnit complète, exécutée ensuite sur cette base dédiée : **182 tests, 802 assertions, aucun échec, 5 notices PHPUnit**. Log local ignoré : `var/performance/phpunit-full.log`. Les notices concernent des mocks sans attentes dans `NotificationDigestTest::testRetriesAreDeduplicatedAndSourcesAreGroupedPerAccountAndDevice`, `MastodonOAuthServiceTest::testItPublishesTheSelectedVisibilityWithTheDecryptedToken`, `MastodonOAuthServiceTest::testItRejectsADirectStatusWithoutAMention`, `PushDeliveryTest::testAcceptedAndFailedResultsAreRecordedSeparately` et `PushDeliveryTest::testTwoDevicesKeepIndependentDeliveryResults`. Ces tests n’ont pas été modifiés.

| Parcours | Médianes des trois références (ms) | Instructions SQL par échantillon |
| --- | --- | --- |
| Collecte 1 abonné | 1,010 / 1,826 / 1,951 | 10 |
| Collecte 10 abonnés | 4,871 / 6,415 / 5,097 | 28 |
| Collecte 100 abonnés | 37,825 / 44,772 / 35,441 | 208 |
| Lecture | 36,474 / 34,304 / 35,451 | 2 |
| Non lus | 33,486 / 34,274 / 34,475 | 2 |
| Recherche présente | 40,892 / 40,983 / 42,311 | 2 |
| Recherche absente | 28,208 / 26,360 / 25,833 | 2 |
| Purge 100 | 18,841 / 19,169 / 17,877 | 4 |

Les comparaisons 1→2 et 1→3 ont toutes deux **échoué au budget temporel** : collecte 1 abonné dans les deux, collecte 10 abonnés dans la première. Tous les autres parcours passent et les compteurs SQL sont identiques. Ce sont trois références du même code, pas une régression démontrée : la stabilité temporelle des petites collectes est insuffisante pour ratifier +20 %. Ne pas ajuster silencieusement le seuil. Le nombre de requêtes de collecte suit bien `2 × abonnés + 8` sur ce scénario ; la réduction avant/après reste à produire.

La suite applicative a été exécutée après les mesures, car elle peut conserver des fixtures. Ne pas relancer le banc sur cette base sans contrôler sa vacuité ; ne pas la purger globalement pour contourner le garde. Aucune migration, dépendance, installation d’outil, compilation, modification de service ou opération Git interdite exécutée. Les anciennes instructions `set -eu` ont été retirées des guides de ce lot pour éviter la fermeture d’un shell interactif à un échec attendu du comparateur.

Le lot reste ouvert : budgets temporels non stabilisés, optimisation et preuve avant/après non réalisées, acceptation finale du développeur attendue. Les résultats du banc et de PHPUnit ne valident pas les parcours navigateur du lot 5.

## Optimisation mesurée de la journalisation des collectes

Suite à la demande de poursuite du développeur, `CollectFeedHandler::logNotQueued()` utilise une projection scalaire unique : abonnements actifs du flux, utilisateurs et jointure gauche sur les appareils. Cette projection évite l’hydratation des utilisateurs et le chargement individuel des appareils. Une ligne sans appareil produit toujours une trace à identifiants/libellés nuls ; chaque appareil produit sa trace. Les champs, niveaux et messages restent identiques. Le chemin de repli `outbox_retry_pending` utilise la même méthode ; le mécanisme de collecte, l’outbox et les autorisations HTTP ne sont pas modifiés.

Nouveau fichier : `tests/Functional/CollectFeedTraceTest.php`. Deux tests réels MariaDB, réseau remplacé seulement par une réponse 304 synthétique, fixtures annulées par transaction : conservation des utilisateurs sans appareil/de chaque appareil et exclusion des abonnements désactivés/autres flux ; budget de SELECT indépendant du nombre d’abonnés. **Cycle rouge/vert observé** : avant correction, test de budget en échec sur 55 SELECT pour 25 abonnés sans appareil plus un abonné équipé ; après correction, 2 tests / 17 assertions réussis.

Trois candidats `var/performance/optimized-{1,2,3}.json` générés avec le même protocole tabulaire et le même jeu de données : huit parcours, cinq échantillons chacun et rollback confirmé. Aucun ancien rapport remplacé.

| Collecte | Instructions avant → après | Médianes avant → après, paires 1 / 2 / 3 (ms) |
| --- | --- | --- |
| 1 abonné | 10 → 8 | 1,010 → 1,991 / 1,826 → 1,730 / 1,951 → 2,356 |
| 10 abonnés | 28 → 8 | 4,871 → 0,714 / 6,415 → 1,776 / 5,097 → 1,494 |
| 100 abonnés | 208 → 8 | 37,825 → 1,834 / 44,772 → 2,580 / 35,441 → 2,041 |

À 100 abonnés, réduction de **96,2 % des instructions SQL** et de **94 à 95 % du temps médian** dans les trois paires. Les plans montrent la jointure des utilisateurs par clé primaire et celle des appareils par `idx_push_user` ; à cette petite cardinalité, MariaDB choisit un scan des 100 abonnements. Aucun index supplémentaire justifié par ces résultats, aucune migration ajoutée.

Comparaisons : paire 2 entièrement PASS ; paires 1 et 3 FAIL uniquement sur le temps à 1 abonné. Les sept autres parcours passent dans les trois paires et aucun nombre de requêtes n’augmente. Le budget temporel de la petite collecte n’est pas validé ; ne pas présenter l’ensemble des comparaisons comme vert ni relever le seuil sans accord. Les premières références étaient déjà instables pour ce parcours. Une mesure plus longue/stabilisée pourra distinguer bruit et coût propre au petit cas.

Vérifications effectuées après modification : lints PHP du handler et du test valides, tests ciblés réussis, suite complète **184 tests, 819 assertions, aucun échec, les mêmes 5 notices PHPUnit** dans les tests nommés plus haut, `git diff --check` sans défaut. Aucune migration, dépendance, variable, compilation d’asset ou modification de service requise. Aucun commit ni push effectué. Les changements restent non indexés ; `src/MessageHandler/CollectFeedHandler.php` est modifié et `tests/Functional/CollectFeedTraceTest.php` est non suivi en plus des fichiers du lot déjà listés.

Relecture locale possible :

```powershell
git diff --check
git diff -- src/MessageHandler/CollectFeedHandler.php
Get-Content tests/Functional/CollectFeedTraceTest.php
git status --short
```

Commandes Linux utilisées (les tests fonctionnels écrivent des fixtures transactionnelles dans la base dédiée autorisée) :

```bash
php85 -l src/MessageHandler/CollectFeedHandler.php
php85 -l tests/Functional/CollectFeedTraceTest.php
# APP_ENV=test et DATABASE_URL doivent cibler la base dédiée de validation.
APP_ENV=test php85 vendor/bin/phpunit tests/Functional/CollectFeedTraceTest.php
# Après les mesures, pour ne pas risquer de laisser des fixtures avant le banc :
APP_ENV=test php85 vendor/bin/phpunit --display-phpunit-notices
```

Les trois candidats ont été produits par `php85 tests/Performance/measure.php run`, puis comparés avec :

```bash
php85 tests/Performance/compare.php var/performance/tabular-1.json var/performance/optimized-1.json
php85 tests/Performance/compare.php var/performance/tabular-2.json var/performance/optimized-2.json
php85 tests/Performance/compare.php var/performance/tabular-3.json var/performance/optimized-3.json
```

Codes attendus sur ces rapports : 1 / 0 / 1, pour les raisons documentées ci-dessus. Aucun déploiement ni redémarrage exécuté ou requis pour la validation. Le gain ciblé est démontré ; l’acceptation finale et la stabilisation du budget à un abonné restent ouvertes. Les autres limites du protocole demeurent.

Revue des autres plans : lecture/recherche utilisent `idx_article_feed_published`, `uniq_subscription_user_feed` et `uniq_article_state_user_article`, avec scan des 11 flux. La purge estime un scan d’environ 9 900 articles après son lot, puis des recherches via `fk_article_state_article` et `uniq_subscription_user_feed` dans la sous-requête dépendante. Le scan de purge reste une piste à plus gros volume ; ces estimations et un coût actuel de 18–19 ms ne démontrent pas qu’un index additionnel préserverait la sémantique tout en améliorant le coût. Aucun changement spéculatif appliqué à ces parcours.
