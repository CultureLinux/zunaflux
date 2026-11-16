# Lot 4 — Tests temporels et MariaDB : livraison à valider

Date : 15 novembre 2026. Sources et tests préparés ; aucune validation PHP/MariaDB revendiquée. Les validations précédentes ne sont pas considérées acquises sans retour explicite du développeur.

## Changements livrés

| Fichiers | Changement |
| --- | --- |
| `src/Application/Time/Clock.php`, `SystemClock.php`, `config/services.yaml` | Horloge interne injectable, fuseau PHP existant préservé et alias Symfony. |
| `ArticleRetention.php`, `NotificationCounter.php`, `NotificationOutbox.php`, `NotifyFeedHandler.php`, `SendNotificationsCommand.php` | Heure obtenue depuis l’horloge ; anciens appels de constructeurs toujours acceptés. |
| `DigestQueue.php`, `SendNotificationDigests.php` | Purge à partir de l’instant de référence UTC explicite, sans dépendance à `UTC_TIMESTAMP()`. |
| `tests/Support/FrozenClock.php` | Horloge déplaçable uniquement dans les tests. |
| `NotificationCounterTest.php`, `NotificationTraceTest.php`, `NotificationDigestTest.php`, `NotificationOutboxTest.php` | Dates de notification fixes ; minuit local ; suppression du `+2 hours` et du réajustement SQL des dates des événements de récapitulatif. |
| `UserPreferenceNotificationScheduleTest.php` | Cinq tests supplémentaires sur bornes de silence, changement de date, fuseaux et deux transitions DST. |
| `tests/Functional/DigestQueueTest.php` | Trois tests de curseurs, purge et verrous entre deux comptes/connexions. |
| `tests/Functional/ArticleRetentionTest.php` | Test de borne calendaire, limites de lot, fallback de date et protection des favoris/enregistrements entre comptes. |
| `tests/Support/MigrationDatabase.php`, `tests/Unit/Infrastructure/MigrationDatabaseTest.php` | Préconditions de cible dédiées et deux tests unitaires de refus/acceptation. |
| `tests/Migration/check-migrations.php` | Parcours réels sur base vide et depuis le schéma précédent, conservation des données et seconde application sans effet. |
| `.github/workflows/ci.yml`, `tests/Container/ci-quality-gate.sh` | Matrice de deux jobs MariaDB jetables, refus de réutilisation d’une base remplie, contrôle obligatoire dans quality ; 22 scénarios shell. |
| `docs/tests-mariadb.md`, `docs/notification-schedule.md`, `docs/lots-amelioration.md` | Procédure isolée, limites et suivi du lot. |

Onze nouvelles méthodes de test PHP au total ; les tests existants cités sont aussi renforcés. Aucun choix de cadence, créneau, fuseau de compte, durée de rétention ou autorisation multi-utilisateur n’est modifié.

Aucune migration de production ni dépendance Composer/npm ajoutée. La purge des événements prend désormais son instant de référence côté PHP, en UTC ; le seuil reste strictement antérieur à 30 jours et les événements non consommés restent protégés. L’horloge de production reste celle de PHP ; la soustraction des mois d’articles reste celle de `DateTimeImmutable::modify`.

Une variable **de test uniquement**, `MIGRATION_TEST_DATABASE_URL`, est requise par le script de migrations et définie dans la CI. Elle ne remplace pas `DATABASE_URL` pour l’application, n’est pas à ajouter en production et n’est jamais prise par défaut depuis cette dernière. Les deux variables d’URL de la procédure manuelle servent seulement à fournir les cibles préparées par le développeur ; aucune valeur secrète n’est versionnée.

## Vérifications réellement effectuées

L’environnement fourni est Bash Linux ; aucun contrôle Windows n’a été exécuté. Aucun PHP, Composer, PHPUnit, Node, Docker, build, migration ou service n’a été lancé. Aucun outil n’a été installé. Les tests PHP ont été préparés avant les adaptations métier ; aucun cycle rouge/vert PHP n’est revendiqué sans leur exécution.

Effectués : lecture des règles existantes, revue des dates et bornes, des appels de constructeurs et de la configuration ; revue des protections de cible et du script de migrations ; recherche des usages temporels non contrôlés dans le périmètre ; `git diff --check` ; contrôles shell des workflows. Le test de porte qualité a d’abord échoué sur `MIGRATIONS_RESULT=failure`, puis réussi après ajout de la dépendance : **22 scénarios**. Les contrôles de versions/publication passent aussi (**39 et 10 scénarios**).

Ces contrôles ne prouvent ni la syntaxe PHP, ni les injections Symfony, ni les requêtes SQL, ni l’exécution des jobs GitHub. Le correctif de l’ancien alias SQL `cursor` et ses nouvelles assertions restent **à confirmer sous MariaDB 10.11**. Les migrations testées en CI sont des parcours préparés, pas des migrations déjà exécutées par l’agent.

## Contrôles locaux PowerShell

```powershell
# Sans PHP, Composer, Node ni connexion à une base.
git diff --check
if ($LASTEXITCODE -ne 0) { throw 'Erreur de diff' }
git diff --stat
Get-Content src/Application/Time/Clock.php
Get-Content src/Application/Time/SystemClock.php
Get-Content tests/Functional/DigestQueueTest.php
Get-Content tests/Functional/ArticleRetentionTest.php
Get-Content tests/Support/MigrationDatabase.php
Get-Content tests/Migration/check-migrations.php
git status --short
```

Attendu : aucun défaut d’espacement, lecture des nouveaux fichiers et périmètre annoncé. Ces lectures ne remplacent pas les vérifications PHP.

## Validation Linux sans modification des données

Depuis une copie isolée du dépôt, dépendances déjà installées et configuration de test dédiée disponible. Les lints Symfony peuvent écrire le cache. Si les dépendances manquent, utiliser d’abord le bloc d’installation soumis à approbation ci-dessous.

```bash
set -eu
php85 -v
php85 /usr/bin/composer --version
php85 /usr/bin/composer validate --strict
find src tests migrations public bin -type f -name '*.php' -print0 | xargs -0 -n1 php85 -l
APP_ENV=test php85 bin/console lint:yaml config/ .github/workflows/
APP_ENV=test php85 bin/console lint:container
APP_ENV=test php85 bin/console lint:twig templates/
sh -n tests/Container/ci-quality-gate.sh
sh tests/Container/test-framework-config.sh
sh tests/Container/ci-config.sh
sh tests/Container/workflow-config.sh
sh tests/Container/publish-workflow-config.sh
sh tests/Container/ci-quality-gate.sh
sh tests/Container/release-tags.sh
sh tests/Container/release-workflow.sh
git diff --check
```

Attendu, dans l’ordre : PHP 8.5, Composer disponible, manifeste strict valide ; syntaxe de tous les PHP valide ; YAML, conteneur et Twig sans erreur ; contrats shell réussis avec 22/39/10 scénarios ; diff propre. Le lint YAML ne prouve pas la sémantique GitHub Actions. Aucun PHPStan n’est déclaré dans le dépôt ; aucune commande d’analyse custom n’est supposée.

## Installation et tests applicatifs — approbation humaine requise

Les commandes suivantes écrivent dans la copie de validation et sa base de test. Vérifier humainement que `DATABASE_URL` et la configuration test ne ciblent aucune donnée réelle. La base des tests applicatifs est différente des deux bases vides destinées aux parcours de migration. Aucune migration nouvelle n’appartient au lot 4 ; les migrations des lots précédents doivent néanmoins être présentes sur cette base.

```bash
set -eu
# Si nécessaire : dépendances de développement et cache de la copie.
APP_ENV=test php85 /usr/bin/composer install --no-interaction --prefer-dist --no-progress
# Uniquement après vérification humaine de la base des tests applicatifs.
APP_ENV=test php85 bin/console doctrine:migrations:status
APP_ENV=test php85 bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=test php85 vendor/bin/phpunit tests/Unit/Entity/UserPreferenceNotificationScheduleTest.php
APP_ENV=test php85 vendor/bin/phpunit tests/Unit/Infrastructure/MigrationDatabaseTest.php
APP_ENV=test php85 vendor/bin/phpunit tests/Functional/NotificationCounterTest.php
APP_ENV=test php85 vendor/bin/phpunit tests/Functional/NotificationTraceTest.php
APP_ENV=test php85 vendor/bin/phpunit tests/Functional/NotificationDigestTest.php
APP_ENV=test php85 vendor/bin/phpunit tests/Functional/NotificationOutboxTest.php
APP_ENV=test php85 vendor/bin/phpunit tests/Functional/DigestQueueTest.php
APP_ENV=test php85 vendor/bin/phpunit tests/Functional/ArticleRetentionTest.php
APP_ENV=test php85 vendor/bin/phpunit --display-phpunit-notices
```

Attendu : schéma de test courant, onze nouvelles méthodes et régressions proches sans échec, puis suite entière réussie. Les transactions des tests de purge doivent être annulées. Les tests du lot 3 restent présents ; ne pas ignorer un échec parce qu’il précède ce lot. Relever les notices existantes séparément.

Pour confirmer l’indépendance au fuseau PHP du processus, après la réussite du passage principal, rejouer seulement les tests temporels concernés avec un autre fuseau de processus :

```bash
APP_ENV=test php85 -d date.timezone=Pacific/Honolulu vendor/bin/phpunit tests/Functional/NotificationTraceTest.php
APP_ENV=test php85 -d date.timezone=Asia/Tokyo vendor/bin/phpunit tests/Functional/NotificationDigestTest.php
APP_ENV=test php85 -d date.timezone=UTC vendor/bin/phpunit tests/Unit/Entity/UserPreferenceNotificationScheduleTest.php
```

Attendu : mêmes résultats métier. Ces reprises ciblées vérifient précisément la propriété demandée, sans prétendre tester tous les fuseaux sur toute l’application.

## Parcours de migrations — bases isolées et approbation humaine

Suivre le [guide MariaDB](tests-mariadb.md) pour provisionner deux bases **vides** dédiées et le compte de test. Leur nom doit commencer par `zunaflux_migrations_test_`. Leur création et les droits modifient l’environnement et nécessitent une approbation humaine. Ne pas utiliser la base PHPUnit déjà migrée : elle doit être refusée par le script.

Une fois les deux URL locales renseignées, sans les afficher ni les versionner :

```bash
set -eu
: "${MIGRATION_EMPTY_DATABASE_URL:?Configurer la base vide dédiée}"
: "${MIGRATION_UPGRADE_DATABASE_URL:?Configurer la seconde base vide dédiée}"
APP_ENV=test MIGRATION_TEST_DATABASE_URL="$MIGRATION_EMPTY_DATABASE_URL" \
  php85 tests/Migration/check-migrations.php empty
APP_ENV=test MIGRATION_TEST_DATABASE_URL="$MIGRATION_UPGRADE_DATABASE_URL" \
  php85 tests/Migration/check-migrations.php upgrade
```

Attendu : deux succès explicites, liste des migrations appliquées cohérente, schéma durable et déduplication présents, données synthétiques conservées. Le parcours de mise à jour part de `Version20261114120000` et applique notamment la migration d’outbox du lot 3. Chaque parcours vérifie aussi que l’application suivante ne change plus les données.

Le script ne crée ni ne supprime de base, ne vide aucun schéma et ne lance aucun retour arrière. Il laisse les données de diagnostic dans la base dédiée ; un second lancement sur cette même base doit échouer avec « Migration test database must be empty ». Dans la CI, ce refus précis est contrôlé après le succès des deux parcours, sur les services MariaDB jetables de leurs jobs respectifs.

Ne jamais démarrer de worker ni de planificateur sur les bases de migration : leur ligne Messenger synthétique n’est pas un message de production destiné à être consommé.

## Assets, image et services — approbation humaine pour les écritures

Aucun asset source, dépendance frontend ou définition de service de production ne change. Pour confirmer la CI complète dans la copie isolée :

```bash
set -eu
result=0
for test_file in tests/JavaScript/*.test.cjs; do
    node "$test_file" || result=1
done
test "$result" = 0
APP_ENV=prod php85 bin/console tailwind:build --minify --env=prod
APP_ENV=prod php85 bin/console asset-map:compile --env=prod
docker build --tag clinuxfr/zunaflux:ci-lot4 .
sh tests/Container/docker-image-smoke.sh clinuxfr/zunaflux:ci-lot4
sh tests/Container/compose-config.sh
sh tests/Container/compose-smoke.sh ci-lot4
```

Attendu : JavaScript réussi, assets générés, image valide, test HTTP et services de test sains. Les builds peuvent télécharger les outils existants et le smoke test crée puis nettoie ses propres conteneurs et volumes. Aucun `npm ci` : pas de manifeste npm. Aucun déploiement, migration de production ou redémarrage de service n’est requis pour valider les sources du lot.

Après une mise à jour de code décidée par le développeur, contrôler les services existants sans les modifier :

```bash
sudo systemctl status zunaflux-messenger.service --no-pager
sudo journalctl -u zunaflux-messenger.service -n 50 --no-pager
```

Ou, pour l’installation Docker :

```bash
docker compose --env-file docker/.env -f compose.yaml ps
docker compose --env-file docker/.env -f compose.yaml logs --tail=100 notifications worker collector
```

Attendu : services opérationnels sans erreur d’injection d’horloge, exécution périodique des notifications conservée. Le renouvellement des workers après changement de code suit la procédure humaine existante ; aucune commande de déploiement automatique n’est ajoutée.

## Acceptation, diagnostic et limites

Accepter uniquement après succès des tests temporels sous MariaDB 10.11, des scénarios de curseur/verrou, de la rétention et des deux chemins de migration. Dans GitHub Actions, les deux jobs de la matrice doivent être visibles ; une migration échouée, annulée ou ignorée doit rendre `quality` rouge et interdire la publication. La preuve shell de cette porte ne remplace pas un run GitHub réel.

En cas d’échec, conserver le lot ouvert et relever la première commande et une sortie expurgée. Pour une injection d’horloge, vérifier l’alias et les signatures avec `lint:container`. Pour une erreur de date, comparer l’instant UTC et le fuseau explicite de la fixture, sans remplacer la date fixe par « maintenant ». Pour un curseur ou SQL, relever le serveur MariaDB effectif, les migrations et le résultat du test ciblé. Pour une migration, conserver la base d’exercice partiellement créée ; ne pas la vider pour masquer l’erreur et ne pas contourner le préfixe de sécurité.

Le périmètre ne fige pas toutes les dates de toutes les entités, ne prouve pas tous les entrelacements concurrents et ne change pas les règles de fin de mois existantes. Les tests d’âge des sources et de session hors périmètre gardent leurs usages temporels. Les limites de livraison Web Push et de déduplication documentées au lot 3 restent inchangées.

Aucun commit, push, tag, migration, déploiement ou indexation par l’agent. L’état Git est relevé en fin de travail ci-dessous. Le lot reste ouvert jusqu’au retour explicite du développeur sur les validations Linux, notamment le correctif SQL de curseur.

## État Git relevé

```text
 M .github/workflows/ci.yml
 M config/services.yaml
 M docs/lots-amelioration.md
 M docs/notification-schedule.md
 M src/Application/Maintenance/ArticleRetention.php
 M src/Application/Notification/DigestQueue.php
 M src/Application/Notification/NotificationCounter.php
 M src/Application/Notification/NotificationOutbox.php
 M src/Application/Notification/SendNotificationDigests.php
 M src/Command/SendNotificationsCommand.php
 M src/MessageHandler/NotifyFeedHandler.php
 M tests/Container/ci-quality-gate.sh
 M tests/Functional/NotificationCounterTest.php
 M tests/Functional/NotificationDigestTest.php
 M tests/Functional/NotificationOutboxTest.php
 M tests/Functional/NotificationTraceTest.php
 M tests/Unit/Entity/UserPreferenceNotificationScheduleTest.php
?? docs/lot-4-tests-validation.md
?? docs/tests-mariadb.md
?? src/Application/Time/Clock.php
?? src/Application/Time/SystemClock.php
?? tests/Functional/ArticleRetentionTest.php
?? tests/Functional/DigestQueueTest.php
?? tests/Migration/check-migrations.php
?? tests/Support/FrozenClock.php
?? tests/Support/MigrationDatabase.php
?? tests/Unit/Infrastructure/MigrationDatabaseTest.php
```

Aucun commit ni push effectué.
