# Lot 1 — CI : livraison du 15 novembre 2026

Implémentation proposée ; lot non clôturé avant retour explicite du développeur sur Linux et GitHub Actions.

## Changements

- `.github/workflows/ci.yml` : déclenchement direct pour PR/main seulement ; jobs PHP, JavaScript et assets indépendants ; `quality` agrège leurs résultats et ceux de Docker.
- `.github/workflows/publish-image.yml` : délais maximaux de détection et publication ; dépendance obligatoire à la CI réutilisable conservée.
- `tests/Container/ci-config.sh` et `workflow-config.sh` : commandes PHP adaptées et absence de déclenchement direct par tag contrôlée.
- `tests/Container/ci-quality-gate.sh` : exécute le vrai script de décision avec 19 scénarios (succès, échec, annulation, job ignoré et sortie de détection absente). Ces quatre scripts sont exécutés par le job JavaScript.
- `docs/lots-amelioration.md` : suivi mis à jour ; ce compte-rendu décrit la validation restante.

Les PR obsolètes sont annulées par numéro de PR ; les autres exécutions utilisent un groupe unique par run et ne sont pas annulées automatiquement. Pas de sérialisation ni de politique d’alias Docker ajoutée : cela relève du lot 2.

Rapport JUnit conservé sept jours, y compris après échec PHPUnit. Si l’échec précède PHPUnit, l’absence de rapport produit un avertissement sans remplacer l’erreur initiale. Les sorties des étapes restent dans les journaux GitHub Actions ; aucun fichier `.env`, journal applicatif, dump SQL ou secret n’est exporté comme artefact. Ne pas introduire de secrets réels dans les données de test ou messages PHPUnit.

Délais : PHP 20 min, JS 5 min, assets 15 min, détection 5 min, Docker 30 min, agrégation 5 min, publication 45 min. À ajuster sur mesures si les runners sont plus lents.

Aucune dépendance applicative, migration ni variable d’environnement nouvelle. L’action `actions/upload-artifact@v4` est ajoutée pour conserver JUnit. Le runner configure `php85` et `/usr/bin/composer` à partir des outils PHP 8.5 installés par setup-php. Le build assets utilise uniquement une configuration CI factice. Le nom `quality` est conservé ; contrôler les noms exacts des checks dans les règles de protection GitHub après cette restructuration.

## Vérifications réellement effectuées

L’environnement fourni est un terminal Bash Linux, malgré la description Windows dans AGENTS.md. Aucun contrôle Windows n’a été exécuté.

- Échec initial constaté du contrôle interdisant les tags directs, avant correction.
- Contrôles shell des workflows et 19 scénarios du script d’agrégation exécutés avec succès.
- Syntaxe shell des scripts concernés et `git diff --check` contrôlés.
- PHP, Composer, PHPUnit, JavaScript, compilation, Docker et GitHub Actions non exécutés.
- `actionlint` et PyYAML indisponibles ; aucune installation effectuée, aucune validation YAML locale revendiquée.

## Vérifications locales PowerShell

```powershell
# Sans PHP, Composer ni Node ; aucune écriture applicative.
git diff --check
if ($LASTEXITCODE -ne 0) { throw 'Erreur de diff' }
git diff -- .github/workflows/ci.yml .github/workflows/publish-image.yml tests/Container/ci-config.sh tests/Container/workflow-config.sh
Get-Content tests/Container/ci-quality-gate.sh
Get-Content docs/lot-1-ci-validation.md
git status --short
```

Attendu : diff sans erreur d’espacement, périmètre limité aux fichiers annoncés. La lecture des fichiers ne prouve pas leur exécution.

## Validation Linux sans modification de la base

Depuis la racine d’une copie de validation isolée, dépendances déjà installées et configuration de test dédiée déjà disponible. Ne pas utiliser la configuration ni la base de production.

```bash
set -eu
php85 -v
php85 /usr/bin/composer --version
php85 /usr/bin/composer validate --strict
sh -n tests/Container/ci-config.sh
sh -n tests/Container/workflow-config.sh
sh -n tests/Container/publish-workflow-config.sh
sh -n tests/Container/ci-quality-gate.sh
sh tests/Container/ci-config.sh
sh tests/Container/workflow-config.sh
sh tests/Container/publish-workflow-config.sh
sh tests/Container/ci-quality-gate.sh
git diff --check
find src tests migrations public bin -type f -name '*.php' -print0 | xargs -0 -n1 php85 -l
APP_ENV=test php85 bin/console lint:yaml .github/workflows/
APP_ENV=test php85 bin/console lint:container
APP_ENV=test php85 bin/console lint:twig templates/
result=0
for test_file in tests/JavaScript/*.test.cjs; do
    node "$test_file" || result=1
done
test "$result" = 0
git status --short
```

Attendu, dans l’ordre : PHP 8.5, Composer disponible, manifeste valide, scripts sans erreur de syntaxe, contrats valides et « 19 scenarios passed », diff propre, syntaxe PHP valide, YAML et configuration Symfony valides, tous les tests JS réussis. Les lints Symfony peuvent écrire du cache mais ne doivent pas modifier les données. Aucun PHPStan n’est configuré dans le dépôt ; aucune commande supposée n’est ajoutée. Le lint YAML ne valide pas la sémantique GitHub Actions.

## Opérations sur l’environnement ou les données — approbation humaine requise

À exécuter uniquement par le développeur dans une copie isolée. La configuration `.env`/`.env.test.local` doit préalablement désigner une base MariaDB 10.11 de test dédiée, jetable et distincte de la production. Les tests PHPUnit peuvent modifier cette base. Aucune migration nouvelle n’est livrée ; la commande prépare le schéma existant de test.

```bash
set -eu
# Installation et cache de la copie de validation.
APP_ENV=test php85 /usr/bin/composer install --no-interaction --prefer-dist --no-progress
# Après vérification humaine de DATABASE_URL et du nom de la base dédiée.
APP_ENV=test php85 bin/console doctrine:migrations:status
APP_ENV=test php85 bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=test php85 vendor/bin/phpunit --log-junit var/reports/phpunit.xml
# Compilation : écrit les fichiers générés et peut télécharger Tailwind.
APP_ENV=prod php85 bin/console tailwind:build --minify --env=prod
APP_ENV=prod php85 bin/console asset-map:compile --env=prod
# Docker : construit une image locale et crée des services de test isolés.
docker build --tag clinuxfr/zunaflux:ci-lot1 .
sh tests/Container/docker-image-smoke.sh clinuxfr/zunaflux:ci-lot1
sh tests/Container/compose-config.sh
sh tests/Container/compose-smoke.sh ci-lot1
```

Attendu : dépendances verrouillées installées, migrations de test appliquées, PHPUnit sans échec et XML présent, assets compilés, image et topologie valides, services `app`, `worker`, `collector` opérationnels. Le script Compose contrôle HTTP et santé puis supprime ses propres conteneurs et volumes de test par son nettoyage existant. Aucun service de production à redémarrer, aucune publication ni commande de déploiement nécessaire pour ce lot. Pas de `npm ci` : aucun manifeste npm dans ce dépôt.

## Acceptation GitHub Actions et diagnostic

Sur une PR de validation, contrôler que PHP, JS et assets démarrent indépendamment et que `quality` attend aussi Docker. Sur une branche de test, provoquer temporairement un échec PHP : JS/assets doivent donner leurs résultats et `quality` échouer. Si PHPUnit a démarré, télécharger le rapport JUnit. Un échec préalable doit laisser son diagnostic dans l’étape concernée.

Vérifier aussi un échec JS, assets ou Docker : `quality` doit échouer et la publication doit rester bloquée. Le script local vérifie la porte shell ; seul un run GitHub démontre les dépendances et conditions du workflow.

Deux mises à jour rapprochées d’une PR doivent annuler l’ancienne validation. Une publication en cours ne doit pas être annulée par une PR. Lors d’une prochaine version autorisée par le développeur, vérifier qu’un tag lance seulement « Publish Docker image », dont le job `quality` appelle la CI une seule fois. Ne pas créer de tag de diagnostic : les tags `v*` peuvent publier et leur encadrement appartient au lot 2.

En cas d’échec : arrêter la validation, relever le premier job en erreur et son journal ; consulter JUnit si disponible. Pour Composer vérifier PHP/extensions et le lock ; pour Symfony vérifier la configuration factice et les variables requises sans afficher les secrets ; pour MariaDB vérifier la disponibilité de la base isolée ; pour assets vérifier le téléchargement Tailwind ; pour Docker consulter les logs du smoke test. Ne pas contourner les contrôles avec `continue-on-error`. Fournir les erreurs expurgées au développeur et conserver le lot en attente.

État Git détaillé à relever avec `git status --short`. Aucun commit ni push effectué par l’agent.
