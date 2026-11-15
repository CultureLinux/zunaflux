# Lot 2 — Versions : compte-rendu du 15 novembre 2026

Implémentation de la politique approuvée ; lot en attente des validations Linux et GitHub du développeur. Le lot 1 reste lui aussi en attente de son retour explicite.

## Livraison et compatibilité

| Fichier | Modification |
| --- | --- |
| `.github/scripts/release-tags.awk` | Validation du format publiable et génération des tags de version/alias à partir d’un inventaire explicite. |
| `.github/workflows/publish-image.yml` | Validation avant CI et registre, sérialisation de publication, relevé distant et contrôle du commit, alias explicites sans latest automatique. |
| `.github/workflows/ci.yml` | Exécution des deux nouvelles suites shell dans les contrôles de workflow. |
| `tests/Container/release-tags.sh` | 39 scénarios sur formats, préversions, maintenance, grands nombres, historique et limites Docker. |
| `tests/Container/release-workflow.sh` | 10 scénarios exécutant la vraie étape de préparation avec des réponses Git contrôlées, sans réseau ni écriture Git. |
| `tests/Container/publish-workflow-config.sh` | Contrats complétés pour validation préalable, file de publication et absence d’alias implicites. |
| `docs/docker.md` | Renvoi à la politique précise de publication. |
| `docs/publication-versions.md` | Formats, exemples, maintenance, nouvelle tentative, procédure humaine et limites. |
| `docs/lots-amelioration.md` | Travaux du lot 2 cochés, critères d’acceptation et validation maintenus ouverts. |
| `docs/lot-2-versions-validation.md` | Présent compte-rendu. |

Aucune migration, dépendance Composer/npm ou variable d’environnement applicative ajoutée. Les outils `sh`, `awk`, `sed` et Git sont déjà présents sur les runners Ubuntu ; aucun nouvel outil n’est installé. Les nouveaux noms `RELEASE_TAG`, `RELEASE_SHA`, `RELEASE_VALIDATE_ONLY` et `LC_ALL` sont limités aux étapes CI et aux tests. Aucun secret supplémentaire.

La validation est limitée au format `vMAJEUR.MINEUR.CORRECTIF[-préversion]` compatible Docker ; les nouveaux suffixes `+build` sont refusés, même s’ils sont valides en SemVer. Les anciennes références avec métadonnées restent prises en compte pour empêcher un recul de priorité. Le tag de version sans `v` et le tag SHA court préexistants sont conservés. Le label OCI de version contient la référence Git complète.

Le mécanisme de file `queue: max` requiert le support GitHub Actions documenté dans la [politique de publication](publication-versions.md). Les tags d’anciennes branches ne bénéficient des protections que si leur commit contient les nouveaux workflows/scripts.

## Vérifications réellement effectuées

Le terminal fourni est Bash Linux ; aucune vérification Windows n’est revendiquée. Aucun PHP, Composer, Node, Docker, PHPUnit, build d’assets, migration ou run GitHub Actions n’a été exécuté par l’agent.

- Échec initial des nouveaux tests constaté en l’absence du générateur.
- 39 scénarios du générateur exécutés avec succès.
- 10 scénarios de préparation de publication exécutés avec succès. Le faux exécutable Git vérifie l’appel exact `git ls-remote --tags origin` et renvoie des références contrôlées ; aucun dépôt ni registre distant n’est contacté.
- Les contrats existants des workflows et les 19 scénarios de la porte `quality` du lot 1 passent.
- Un générateur volontairement altéré dans un répertoire temporaire pour rendre `latest` à une ancienne version fait échouer le scénario « older patch has no aliases » ; les sources du dépôt ne sont pas altérées par ce contrôle.
- Syntaxe shell et `git diff --check` vérifiés. `actionlint` et `shellcheck` indisponibles ; aucun outil installé. Validation YAML/GitHub réelle non exécutée.

Les tests locaux prouvent les décisions et la préparation des entrées metadata ; ils n’exécutent pas les actions Docker et ne prouvent pas une publication réelle.

## Vérifications locales PowerShell possibles

```powershell
# Lecture uniquement ; aucune dépendance PHP/Node requise.
git diff --check
if ($LASTEXITCODE -ne 0) { throw 'Erreur de diff' }
git diff -- .github/workflows/ci.yml .github/workflows/publish-image.yml tests/Container/publish-workflow-config.sh docs/docker.md docs/lots-amelioration.md
Get-Content .github/scripts/release-tags.awk
Get-Content tests/Container/release-tags.sh
Get-Content tests/Container/release-workflow.sh
Get-Content docs/publication-versions.md
Get-Content docs/lot-2-versions-validation.md
git status --short
```

Attendu : aucune erreur de diff et uniquement les changements annoncés. Les lectures ne remplacent pas l’exécution des scripts shell.

## Validation Linux sans modification des données

Depuis la racine d’une copie de validation. Les commandes shell suivantes ne contactent ni GitHub ni Docker Hub et ne modifient pas la base. Le test de workflow crée puis nettoie uniquement son répertoire temporaire.

```bash
set -eu
sh -n tests/Container/release-tags.sh
sh -n tests/Container/release-workflow.sh
sh -n tests/Container/publish-workflow-config.sh
sh tests/Container/release-tags.sh
sh tests/Container/release-workflow.sh
sh tests/Container/ci-config.sh
sh tests/Container/workflow-config.sh
sh tests/Container/publish-workflow-config.sh
sh tests/Container/ci-quality-gate.sh
printf '%s\n' v1.2.3 v1.3.0 v2.0.0 v1.2.4 |
  RELEASE_TAG=v1.2.4 LC_ALL=C awk -f .github/scripts/release-tags.awk
git diff --check
git status --short
```

Résultats attendus : syntaxe shell sans erreur ; « Release tags: 39 scenarios passed. », « Release workflow: 10 scenarios passed. », « Quality gate: 19 scenarios passed. » ; autres contrats silencieux avec code zéro ; prévisualisation `1.2.4` puis `1.2` seulement ; diff sans erreur. Chaque commande doit terminer avec le code zéro.

Avec les dépendances existantes déjà installées et la configuration dédiée de test disponible :

```bash
set -eu
php85 -v
php85 /usr/bin/composer --version
php85 /usr/bin/composer validate --strict
APP_ENV=test php85 bin/console lint:yaml .github/workflows/
APP_ENV=test php85 bin/console lint:container
APP_ENV=test php85 bin/console lint:twig templates/
```

Attendu : PHP 8.5, Composer disponible, manifeste verrouillé valide, YAML et configuration valides. Les lints Symfony peuvent écrire le cache, sans modifier la base. Le lint YAML ne remplace pas la validation de la syntaxe et des expressions GitHub Actions. Aucun PHP n’a changé dans ce lot ; aucun PHPStan n’est déclaré dans le dépôt et aucun appel à un script d’analyse supposé n’est proposé.

## Opérations sur l’environnement et les données — approbation humaine requise

Aucune migration nouvelle et aucun déploiement requis par le lot 2. Pour rejouer la validation applicative complète exigée par la CI, utiliser exclusivement une copie et une base MariaDB 10.11 de test isolées. Ne jamais utiliser la configuration ou les identifiants de production. Vérifier humainement la cible `DATABASE_URL` avant les migrations et PHPUnit.

```bash
set -eu
# Installe les dépendances du lock et écrit le cache de cette copie isolée.
APP_ENV=test php85 /usr/bin/composer install --no-interaction --prefer-dist --no-progress
# Contrôle de syntaxe PHP existant, après installation/configuration de test.
find src tests migrations public bin -type f -name '*.php' -print0 | xargs -0 -n1 php85 -l
# Prépare uniquement la base de test dédiée ; pas de migration propre au lot 2.
APP_ENV=test php85 bin/console doctrine:migrations:status
APP_ENV=test php85 bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=test php85 vendor/bin/phpunit --log-junit var/reports/phpunit.xml
# Node doit être disponible sur le serveur de validation ; aucun npm requis.
result=0
for test_file in tests/JavaScript/*.test.cjs; do
    node "$test_file" || result=1
done
test "$result" = 0
# Compilation dans la copie isolée ; peut télécharger Tailwind et écrit les assets.
APP_ENV=prod php85 bin/console tailwind:build --minify --env=prod
APP_ENV=prod php85 bin/console asset-map:compile --env=prod
# Construction locale, sans connexion au registre ni push.
docker build --tag clinuxfr/zunaflux:ci-lot2 .
sh tests/Container/docker-image-smoke.sh clinuxfr/zunaflux:ci-lot2
sh tests/Container/compose-config.sh
sh tests/Container/compose-smoke.sh ci-lot2
```

Attendu : installation sans erreur, syntaxe PHP valide, schéma de test prêt, PHPUnit et JavaScript réussis, rapport XML créé, compilation réussie, image et topologie valides. Le smoke test vérifie HTTP et la santé de `app`, `worker`, `collector`, puis nettoie ses conteneurs et volumes de test. Aucun service de production à modifier ou redémarrer. Aucune commande de tag, push, publication d’image ou création de release n’est exécutée par l’agent.

## Acceptation GitHub et diagnostic

1. Faire passer la CI d’une PR contenant le lot 2 ; contrôler les nouveaux tests et la porte globale du lot 1.
2. Sans créer de tag sur le dépôt de production pour tester, utiliser un dépôt et un registre de validation dédiés, avec leurs propres secrets. Adapter explicitement l’image de destination et le groupe de concurrence de cette copie avant tout essai de publication. Cette préparation et ces publications sont des opérations humaines sur un environnement isolé.
3. Contrôler qu’un tag `vtest` échoue dans `validate-version` : CI réutilisable et publication ignorées, aucune connexion Docker. Vérifier `v1.2.3`, une préversion, puis une version de maintenance selon la matrice de la politique. Examiner les sorties metadata et les tags/digests réels ; une préversion ne doit pas changer `latest`, le majeur ni le mineur.
4. Vérifier qu’un échec de CI interdit toute publication. Faire attendre deux versions dans le même groupe, puis vérifier leur sérialisation et le recalcul des alias après entrée dans le job. Ne pas supposer que leur ordre de fin de CI est leur ordre de version.
5. Confirmer les règles humaines empêchant suppression/déplacement des tags de version et la cohérence de l’inventaire Git avec les alias déjà présents dans le registre. Confirmer aussi que les branches de maintenance contiennent ce nouveau workflow.
6. Lors de la prochaine publication autorisée sur le dépôt réel, vérifier le digest et les deux plateformes, sans mise à jour de production automatique.

Les lots 1 et 2 ne sont pas clôturés avant retour explicite du développeur.

En cas d’échec, arrêter le lot et relever la première commande/job en erreur. Pour les tests shell, fournir le scénario, le résultat attendu et celui obtenu ; pour un refus de version, vérifier les zéros initiaux, caractères et longueur. Pour une erreur de référence, comparer le commit de l’événement au tag distant sans déplacer celui-ci. Pour un alias manquant, rechercher une version stable supérieure dans l’inventaire, même si son image n’a pas encore été publiée. Pour la file, vérifier la prise en charge de `queue: max` et l’éventuelle saturation. Pour un push partiel, inspecter les digests avant de relancer le même workflow. Ne pas activer latest automatique ni contourner la CI pour résoudre l’incident.

## Risques et limites

- Les tags Git conservés font autorité ; une version supérieure déclarée et non publiée peut suspendre la mise à jour des alias d’une version inférieure.
- Un alias modifié manuellement sur Docker Hub, des tags Git supprimés ou un ancien workflow de maintenance échappent à la garantie ; les préconditions d’exploitation sont détaillées dans la politique.
- Les tags d’images exacts et SHA restent réinscriptibles lors d’une nouvelle tentative ; conserver un digest pour une installation figée.
- Un push n’est pas transactionnel sur tous les alias ; la file est limitée à 100 jobs en attente et un timeout reste possible.
- La preuve complète des dépendances GitHub, de metadata-action et du registre nécessite les essais humains isolés ci-dessus.

État final relevé avec `git status --short --untracked-files=all` :

```text
 M .github/workflows/ci.yml
 M .github/workflows/publish-image.yml
 M docs/docker.md
 M docs/lots-amelioration.md
 M tests/Container/publish-workflow-config.sh
?? .github/scripts/release-tags.awk
?? docs/lot-2-versions-validation.md
?? docs/publication-versions.md
?? tests/Container/release-tags.sh
?? tests/Container/release-workflow.sh
```

Aucun fichier indexé par l’agent, aucun commit ni push effectué.
