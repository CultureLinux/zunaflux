# Lot 5 — Parcours navigateur : livraison à valider

**Actualisation :** les parcours ont été exécutés sur Linux pendant le lot 7B, avec 14 succès avant/après extraction et contrôle négatif d’ouverture suivi d’une restauration vérifiée. Le [compte-rendu 7B](lot-7b-reader-validation.md) complète et remplace les constats de non-exécution historiques ci-dessous. Acceptation finale du développeur encore attendue.

Date de livraison : 16 novembre 2026. Sources préparées ; aucune exécution PHP, npm, JavaScript ou Chromium revendiquée. Les lots précédents gardent leur état de validation en attente du développeur.

## Fonctionnalités et fichiers

| Fichier | Changement |
| --- | --- |
| `package.json`, `package-lock.json` | Playwright Test `1.65.1`, scripts `test:browser` et `test:browser:list` ; trois paquets de développement exacts, aucune dépendance Composer. |
| `config/packages/browser/browser.yaml` | Environnement HTTP de test avec sessions natives isolées et coût de hachage adapté aux fixtures ; firewall, CSRF et contrôleurs de production conservés. |
| `tests/Support/BrowserDatabase.php` | Validation d’environnement, URL dédiée, driver, serveur MariaDB 10.11 et nom de base avant les écritures. |
| `tests/Unit/Infrastructure/BrowserDatabaseTest.php` | Deux tests PHP de refus des cibles dangereuses et d’acceptation d’une cible dédiée. |
| `tests/Browser/fixtures.php` | CLI de garde/schéma/seed/nettoyage : deux comptes, trois sources, cinq articles par token ; dates de collecte fixes et nettoyage borné aux fixtures du scénario. |
| `tests/Browser/router.php`, `start-server.sh` | Garde préalable puis serveur PHP de test sur loopback ; vraies routes Symfony et fichiers statiques compilés. |
| `tests/Browser/playwright.config.cjs` | Deux profils Chromium, un worker, zéro retry, délais et rapports bornés ; refus d’un serveur préexistant. |
| `tests/Browser/support.cjs`, `reader.spec.cjs` | Sept parcours réels, login natif avec CSRF, vérification API de persistance et de propriété, navigation clavier ; 14 parcours avec les deux profils. |
| `tests/JavaScript/reading-layout.test.cjs` | Trois anciennes assertions de structure retirées ; les autres tests restent présents. |
| `.github/workflows/ci.yml`, `tests/Container/ci-quality-gate.sh` | Job navigateur indépendant et obligatoire, artefacts bornés ; garde globale à 25 scénarios. |
| `docs/browser-tests.md`, `docs/lots-amelioration.md`, `docs/lot-5-browser-validation.md` | Choix, coût, procédure, diagnostic et suivi du lot. |

Les scénarios couvrent ouverture/fermeture, marquage lu, vues filtrées, changement de source, recherche/effacement, préférence persistée et lecture automatique, clavier et isolation des comptes. La présentation n’est pas vérifiée par un inventaire de classes Tailwind. Les anciens tests de source non remplacés restent présents ; aucune couverture navigateur exhaustive n’est annoncée.

Aucune migration applicative nouvelle, aucun changement de contrôleur ou d’authentification de production. La nouvelle variable **d’outillage uniquement** est `BROWSER_TEST_DATABASE_URL`. L’environnement `browser` et sa configuration ne doivent pas être activés en production. Le manifeste npm sert aux tests ; les assets restent construits par PHP/Tailwind/AssetMapper et le Dockerfile n’installe pas Playwright.

Dépendance et coût expliqués dans le [guide navigateur](browser-tests.md) : trois paquets npm verrouillés, un navigateur Chromium à télécharger avec ses bibliothèques Linux, deux profils, un worker, limite de suite de cinq minutes et job de vingt minutes. La durée et le volume réels restent à mesurer. Le profil mobile est une émulation Chromium, pas Safari ni un appareil réel.

## Vérifications réellement effectuées

Le terminal fourni est Bash Linux ; aucune vérification Windows exécutée. Aucun PHP, Composer, Node/npm, PHPUnit, serveur HTTP, navigateur, build ou migration n’a été exécuté ni installé par l’agent.

Les métadonnées publiques npm ont été lues après autorisation réseau pour préparer le lockfile, sans installer de paquet ni télécharger les archives. Le verrou a été construit depuis leurs versions, URL et intégrités ; il n’est pas présenté comme généré ou validé par npm.

Effectués : revue statique des routes, formulaires, sessions, handlers de lecture, filtres, libellés et préférences ; vérification des locators et des données de collecte déterministes ; contrôle JSON du manifeste/verrou, cohérence des trois versions et de leurs références, format des empreintes SHA-512 ; syntaxe shell du serveur ; `git diff --check` ; contrôles shell des workflows.

Le garde qualité a d’abord échoué sur `BROWSER_RESULT=failure`, puis passe après intégration du job : **25 scénarios**. Les contrats de publication et les suites shell antérieures passent aussi, avec **39 et 10 scénarios**. Ces résultats ne prouvent ni la syntaxe JS/PHP, ni `npm ci`, ni les 14 parcours, ni la validité sémantique du workflow GitHub.

## Vérifications locales PowerShell

```powershell
# Pas de PHP, Node, npm ni navigateur requis pour ces lectures.
Get-Content package.json -Raw | ConvertFrom-Json | Out-Null
Get-Content package-lock.json -Raw | ConvertFrom-Json | Out-Null
git diff --check
if ($LASTEXITCODE -ne 0) { throw 'Erreur de diff' }
git diff --stat
Get-Content config/packages/browser/browser.yaml
Get-Content tests/Browser/reader.spec.cjs
Get-Content tests/Support/BrowserDatabase.php
git status --short
```

Attendu : JSON lisible et aucun défaut de diff ; périmètre conforme au tableau. Ne pas installer les outils absents sur Windows pour exécuter ce bloc.

## Validation Linux sans modification des données

Depuis une copie de validation isolée, avec PHP 8.5, Composer et Node 24 déjà disponibles, dépendances de développement installées et configuration de test habituelle présente. Les lints Symfony peuvent écrire leur cache. Si les dépendances manquent, utiliser d’abord le bloc d’installation approuvé ci-dessous.

```bash
set -eu
php85 -v
php85 /usr/bin/composer --version
node --version
npm --version
php85 /usr/bin/composer validate --strict
find src tests migrations public bin -type f -name '*.php' -print0 | xargs -0 -n1 php85 -l
for browser_file in tests/Browser/*.cjs; do
    node --check "$browser_file"
done
node --check tests/JavaScript/reading-layout.test.cjs
APP_ENV=test php85 bin/console lint:yaml config/ .github/workflows/
APP_ENV=test php85 bin/console lint:container
APP_ENV=browser php85 bin/console lint:container
APP_ENV=browser php85 bin/console lint:twig templates/
sh -n tests/Browser/start-server.sh
sh tests/Container/test-framework-config.sh
sh tests/Container/ci-config.sh
sh tests/Container/workflow-config.sh
sh tests/Container/publish-workflow-config.sh
sh tests/Container/ci-quality-gate.sh
sh tests/Container/release-tags.sh
sh tests/Container/release-workflow.sh
git diff --check
```

Attendu : versions adaptées ; manifeste, PHP et JavaScript syntaxiquement valides ; configuration du conteneur dans les deux environnements et Twig valides ; garde serveur valide et suites shell avec 25/39/10 scénarios. Le lint YAML ne vérifie pas les expressions GitHub Actions. Aucun analyseur PHPStan n’est configuré ; aucune commande custom n’est supposée.

Après `npm ci`, on peut lister les tests sans serveur ni connexion à une base :

```bash
BROWSER_TEST_DATABASE_URL='mysql://unused:unused@127.0.0.1/zunaflux_browser_test_list' \
  npm run test:browser:list
```

Attendu : 14 tests déclarés. La liste seule ne valide pas leurs interactions.

## Installation et régressions — approbation humaine requise

Sur la copie de validation, avec la configuration `test` pointant exclusivement vers la base PHPUnit dédiée habituelle :

```bash
set -eu
# Écrit les dépendances, caches et node_modules ; aucun outil PHP/Node installé ici.
APP_ENV=test php85 /usr/bin/composer install --no-interaction --prefer-dist --no-progress
npm ci --ignore-scripts
# Prépare uniquement la base des tests applicatifs, distincte de la base navigateur.
APP_ENV=test php85 bin/console doctrine:migrations:status
APP_ENV=test php85 bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=test php85 vendor/bin/phpunit tests/Unit/Infrastructure/BrowserDatabaseTest.php
APP_ENV=test php85 vendor/bin/phpunit --display-phpunit-notices
result=0
for test_file in tests/JavaScript/*.test.cjs; do
    node "$test_file" || result=1
done
test "$result" = 0
```

Attendu : verrou npm accepté sans modification, deux tests de garde et suite PHP réussis, tests JavaScript restants réussis. Fournir toute erreur, y compris issue des lots précédents. Ne pas déclarer le lot validé parce que seuls les nouveaux tests de garde passent.

Installation de Chromium et de ses bibliothèques Linux, **sur l’hôte de validation uniquement après accord humain** :

```bash
npx --no-install playwright install --with-deps chromium
```

Cette commande télécharge le navigateur verrouillé et peut installer des paquets système/demander sudo. Ce n’est pas une opération en lecture seule et elle n’a pas été exécutée par l’agent.

## Base navigateur, migrations et parcours — approbation humaine requise

Préparer une base dédiée `zunaflux_browser_test_<suffixe>`, un compte limité à celle-ci et `BROWSER_TEST_DATABASE_URL` selon le [guide](browser-tests.md). Aucun compte, mot de passe ou URL réel ne doit être ajouté aux fixtures. Aucun worker, collecteur ou planificateur ne doit cibler cette base.

```bash
set -eu
: "${BROWSER_TEST_DATABASE_URL:?Configurer la base navigateur dédiée}"
export APP_ENV=browser APP_DEBUG=0
export DATABASE_URL="$BROWSER_TEST_DATABASE_URL"
php85 tests/Browser/fixtures.php guard
php85 bin/console doctrine:migrations:status --env=browser
php85 bin/console doctrine:migrations:migrate --env=browser --no-interaction
php85 tests/Browser/fixtures.php check
php85 bin/console tailwind:build --minify --env=browser
php85 bin/console asset-map:compile --env=browser
npm run test:browser
```

Attendu : garde avant migration, schéma courant, assets compilés et **14 parcours réussis, aucun ignoré**. Chaque scénario utilise ses propres comptes/articles et nettoie uniquement ses données après fermeture des pages. Les fixtures, préférences, états de lecture et sessions sont des écritures de test. Le serveur loopback doit être lancé et arrêté par Playwright ; un port occupé doit produire un échec, pas une réutilisation d’un serveur arbitraire.

Confirmer le refus d’une cible non dédiée au moyen des deux tests unitaires et vérifier que les garde-fous ne sont pas désactivés pour obtenir un passage vert. Les sorties de `guard` et `check` contiennent uniquement un statut de préparation, pas de données d’authentification.

## Preuve de régression et acceptation

Une fois la suite réellement verte, suivre la procédure du guide dans une copie de validation : retirer temporairement le listener d’ouverture, recompiler les assets et exécuter le seul scénario d’ouverture bureau. Il doit échouer sur le comportement, puis réussir après restauration/recompilation. Ce contrôle et sa restauration n’ont pas été effectués par l’agent.

Accepter après confirmation des 14 parcours, de cette preuve négative, du succès des tests existants et du blocage de publication si `browser` échoue, est annulé ou est ignoré. Dans GitHub, les jobs PHP, JavaScript, assets, migrations et navigateur restent indépendants ; `quality` attend leurs résultats et ceux de Docker. Examiner aussi les noms de checks requis dans les règles de protection du dépôt.

Les rapports HTML/JUnit et captures/traces d’échec sont conservés sept jours dans l’artefact navigateur. Aucun fichier de session, `.env`, cache complet ou stockage d’authentification persistant n’est exporté. Les traces peuvent contenir les identifiants synthétiques ; les conserver dans l’environnement de validation et les expurger avant partage.

## Image, services et déploiement

Aucune migration de production ni commande de déploiement n’appartient à ce lot. Pour compléter la validation Docker dans la copie isolée après approbation (construction et création/nettoyage de services de test) :

```bash
set -eu
docker build --tag clinuxfr/zunaflux:ci-lot5 .
sh tests/Container/docker-image-smoke.sh clinuxfr/zunaflux:ci-lot5
sh tests/Container/compose-config.sh
sh tests/Container/compose-smoke.sh ci-lot5
```

Attendu : image produite sans installation Playwright, topologie et HTTP de test valides. Les services de production ne sont pas à modifier pour tester ce lot. Après une publication décidée séparément par le développeur, les contrôles habituels en lecture seule restent :

```bash
docker compose --env-file docker/.env -f compose.yaml ps
docker compose --env-file docker/.env -f compose.yaml logs --tail=100 notifications worker collector
```

Ou, sur l’hôte utilisant l’unité fournie :

```bash
sudo systemctl status zunaflux-messenger.service --no-pager
sudo journalctl -u zunaflux-messenger.service -n 50 --no-pager
```

Attendu : fonctionnement inchangé ; ne pas activer l’environnement `browser` dans l’installation réelle.

## Diagnostic et limites

Arrêter la validation au premier échec et conserver le lot ouvert. Pour un refus de cible, vérifier environnement, préfixe et DSN sans afficher le mot de passe. Pour un échec de fixtures, vérifier Composer avec dépendances de développement, MariaDB 10.11, migrations complètes et injection dans `browser`. Pour une connexion qui ne tient pas, vérifier sessions natives, droits sur `var/browser-sessions` et cookies loopback. Pour une liste vide ou un clic inactif, vérifier réponse API, chargement des assets et la trace ; ne pas réintroduire une regex de source à la place du test navigateur. Pour npm, vérifier le lock et Node 24 sans passer à une version non verrouillée pour contourner l’erreur.

Le test ne couvre pas Safari, Firefox, matériel mobile, lecteurs d’écran, comparaison visuelle, PWA, Web Push ou tous les parcours du mode zen. Une interruption peut laisser des fixtures synthétiques : le guide propose un nettoyage à token exact, jamais un vidage global. L’acceptation reste conditionnée aux résultats Linux/GitHub du développeur.

Aucun commit, push, tag, migration, installation de logiciel, exécution navigateur, déploiement ou indexation Git par l’agent. L’état Git final est consigné ci-dessous.

## État Git relevé

```text
 M .github/workflows/ci.yml
 M docs/lots-amelioration.md
 M tests/Container/ci-quality-gate.sh
 M tests/JavaScript/reading-layout.test.cjs
?? config/packages/browser/browser.yaml
?? docs/browser-tests.md
?? docs/lot-5-browser-validation.md
?? package-lock.json
?? package.json
?? tests/Browser/fixtures.php
?? tests/Browser/playwright.config.cjs
?? tests/Browser/reader.spec.cjs
?? tests/Browser/router.php
?? tests/Browser/start-server.sh
?? tests/Browser/support.cjs
?? tests/Support/BrowserDatabase.php
?? tests/Unit/Infrastructure/BrowserDatabaseTest.php
```

Aucun commit ni push effectué.
