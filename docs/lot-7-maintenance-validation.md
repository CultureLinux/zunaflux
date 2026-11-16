# Lot 7A — Maintenance PHP, validation locale Linux obtenue

16 novembre 2026. Première livraison du lot 7, dans la continuité de l’autorisation du développeur de prendre en charge les tests Linux sur la base dédiée. Acceptation finale du développeur et exécution GitHub Actions en attente. Le statut d’acceptation du lot 6 reste inchangé.

## Contenu et fichiers

| Fichier | Résultat |
| --- | --- |
| `src/Application/Reading/MarkSubscriptionRead.php` | Service de marquage d’un abonnement par lots de 100, avec curseur UUID et délégation des états/statistiques au service existant. |
| `src/Controller/ArticleController.php` | Deux routes de marquage délèguent au service ; contrôles d’appartenance, activation, CSRF et redirections conservés ; blocs concernés mis en forme. |
| `tests/Functional/Controller/SubscriptionReadBatchTest.php` | Caractérisation HTTP sur 102 articles : passage de lot, autres comptes/flux, favori conservé, statistiques idempotentes sur deux appels. |
| `tests/Functional/MarkSubscriptionReadTest.php` | Test direct du service sur base réelle : abonnement vide, propriétaire déduit de l’abonnement, état enregistré pour lui seul et statistiques sans double comptage. |
| `composer.json`, `composer.lock` | Dépendance de développement `phpstan/phpstan` sous contrainte `^2.1`, version résolue `2.3.6` ; aucun autre paquet ajouté, changé ou retiré. |
| `phpstan.neon.dist` | Niveau 6, périmètre explicite de deux services : `ArticleStateService` et `MarkSubscriptionRead`, cache dans `var/phpstan`. Aucune baseline ni erreur ignorée. |
| `.github/workflows/ci.yml` | Commande PHPStan dans le job PHP déjà requis par le garde qualité ; aucun job optionnel ni échec autorisé. |
| `docs/lot-7-maintenance-plan.md`, ce document, `docs/lots-amelioration.md` | Périmètre, vérification, progression et limites. |

Il s’agit d’une extraction de responsabilité, sans objectif d’optimisation. Les transactions par article, le tri UUID, le filtre des non lus et les règles de première lecture restent ceux du code d’origine. L’utilisateur n’est plus un argument pouvant diverger de l’abonnement : il est obtenu depuis celui-ci. Les contrôles HTTP restent obligatoires avant l’appel du service. Aucune migration, variable d’environnement, route, dépendance de production ou modification d’asset.

## Analyse statique progressive

PHPStan sert à vérifier les types et contrats des services maintenus ; il est installé uniquement en développement. Coût : un paquet Composer supplémentaire et une analyse de deux fichiers. La durée, RAM maximale et coût CI complet ne sont pas revendiqués mesurés. Le périmètre initial est volontairement limité et ne représente pas une analyse de toute l’application. Aucune extension Doctrine/Symfony n’est ajoutée ; la validité des DQL et de l’injection est vérifiée par les tests et le lint du conteneur.

Progression : lors d’une extraction suivante, ajouter son service dans `paths` et traiter ses erreurs au niveau 6 avant de fusionner ce changement ; élargir ensuite un sous-répertoire cohérent. Ne pas générer une baseline globale ou désactiver des erreurs pour obtenir un résultat vert. Un échec PHPStan fait échouer le job PHP puis le garde qualité existant. L’exécution réelle sur GitHub reste à confirmer.

## Vérifications réellement exécutées

Hôte Linux avec PHP 8.5.12 ; aucune exécution Windows. Outils système non installés ni PATH modifié. PHPStan a été installé via Composer avec `--no-scripts --no-plugins` et cache temporaire, après autorisation réseau. La première tentative sandbox sans réseau a été annulée automatiquement par Composer ; seule la seconde a produit le verrou et l’installation réussis.

- Référence avant extraction : suite complète **184 tests, 819 assertions**, aucun échec et 5 notices déjà présentes.
- Caractérisation HTTP avant extraction : **15 tests, 94 assertions**, réussis.
- Après extraction : mêmes **15 tests, 94 assertions**, réussis.
- Suite complète avec les deux nouveaux tests : **186 tests, 839 assertions**, aucun échec, mêmes **5 notices PHPUnit**.
- PHPStan niveau 6 : **aucune erreur** sur les deux fichiers sélectionnés.
- Composer : manifeste/verrou valides avec `validate --strict`.
- Syntaxe PHP : service, contrôleur et deux nouveaux tests valides.
- `lint:container` : injection compatible.
- Contrats shell CI/publication : réussis ; garde qualité 25 scénarios, tags 39 et release 10.
- `git diff --check` : aucun défaut.

Les cinq notices concernent les mocks sans attentes dans `NotificationDigestTest`, deux cas de `MastodonOAuthServiceTest` et deux cas de `PushDeliveryTest`, déjà décrits dans le compte-rendu du lot 6. Elles ne sont pas corrigées hors périmètre.

Composer a signalé **16 avis de sécurité sur 11 paquets Symfony déjà verrouillés**, aucun sur PHPStan selon l’audit exécuté. Ces versions n’ont pas été modifiées par cette livraison. Les paquets concernés sont `config`, `filesystem`, `framework-bundle`, `http-client`, `http-foundation`, `http-kernel`, `mime`, `routing`, `security-http`, `validator` et `yaml`. Une mise à jour ciblée doit être traitée séparément avec ses tests ; ne pas interpréter l’absence d’erreur PHPStan comme une validation de sécurité. Le résultat détaillé d’audit est local dans `/tmp/zunaflux-lot7-composer-audit.json` et n’est pas versionné.

## Vérification locale PowerShell

Sans installer PHP/Composer/Node sur Windows :

```powershell
Get-Content composer.json -Raw | ConvertFrom-Json | Out-Null
Get-Content composer.lock -Raw | ConvertFrom-Json | Out-Null
Get-Content phpstan.neon.dist
git diff --check
if ($LASTEXITCODE -ne 0) { throw 'Défaut dans le diff' }
git diff -- src/Controller/ArticleController.php .github/workflows/ci.yml
git status --short
```

Attendu : JSON lisible, deux chemins d’analyse et niveau 6, aucun défaut de diff. Les nouveaux fichiers non suivis restent à relire séparément.

## Reproduction Linux — vérifications sans modification des données

Depuis la racine avec dépendances de développement déjà installées et configuration de test locale :

```bash
php85 -v
php85 /usr/bin/composer validate --strict
php85 -l src/Application/Reading/MarkSubscriptionRead.php
php85 -l src/Controller/ArticleController.php
php85 -l tests/Functional/Controller/SubscriptionReadBatchTest.php
php85 -l tests/Functional/MarkSubscriptionReadTest.php
php85 vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=512M
APP_ENV=test php85 bin/console lint:container
sh tests/Container/ci-config.sh
sh tests/Container/workflow-config.sh
sh tests/Container/publish-workflow-config.sh
sh tests/Container/ci-quality-gate.sh
sh tests/Container/release-tags.sh
sh tests/Container/release-workflow.sh
git diff --check
```

Attendu : validations réussies, aucune erreur d’analyse, garde à 25 scénarios. Le lint et PHPStan écrivent leurs caches locaux ; aucune écriture de données applicatives. PHPStan parallèle peut exiger l’ouverture d’un canal TCP loopback local, qui était bloqué par la sandbox mais fonctionne hors sandbox. Ne pas désactiver l’analyse CI pour contourner ce cas.

## Installation et tests — écritures d’environnement/de base, accord humain requis

Ces opérations ont été autorisées et effectuées pour la copie Linux actuelle. Pour une autre copie, vérifier la cible avant de les reproduire. `DATABASE_URL` doit être celle de la base de test dédiée, jamais une base de production. Ne pas copier de DSN réel dans Git.

```bash
# Dépendances existantes verrouillées ; écrit vendor et caches, aucun outil système installé.
APP_ENV=test php85 /usr/bin/composer install --no-interaction --prefer-dist --no-progress --no-scripts --no-plugins
# Ces tests créent/nettoient des fixtures dans la base dédiée.
APP_ENV=test php85 vendor/bin/phpunit tests/Functional/Controller/SubscriptionReadBatchTest.php tests/Functional/Controller/ArticleControllerTest.php
APP_ENV=test php85 vendor/bin/phpunit tests/Functional/MarkSubscriptionReadTest.php
APP_ENV=test php85 vendor/bin/phpunit --display-phpunit-notices
```

Attendu : tests réussis, état décrit plus haut. L’installation avec scripts désactivés évite un `cache:clear` automatique avant vérification de l’environnement ; le lint explicite permet ensuite de vérifier le conteneur. Aucune migration nouvelle et aucune migration exécutée dans ce lot. La base actuelle était déjà migrée. Aucune commande de déploiement fournie ni exécutée.

## Assets, services et suite du lot

Aucun asset modifié : pas de build nécessaire pour valider 7A. Pour une vérification d’intégration facultative dans une copie isolée, après accord pour les fichiers générés, les commandes existantes sont :

```bash
APP_ENV=test php85 bin/console tailwind:build --minify --env=test
APP_ENV=test php85 bin/console asset-map:compile --env=test
```

Attendu : compilation réussie ; non exécutée pour cette livraison. Aucun service à démarrer ou redémarrer. Contrôle facultatif d’une installation Docker existante, en lecture seule :

```bash
docker compose --env-file docker/.env -f compose.yaml ps
```

Le lecteur JavaScript et les contrôleurs d’abonnements ne sont pas restructurés ici. Prérequis de la future livraison 7B : parcours du lot 5 validés avec Node 24, Chromium et base navigateur dédiée. L’hôte courant dispose de Node 20 et Playwright n’est pas installé localement ; aucun contournement de cette version ni installation d’outil système n’a été tenté. Les tests PHP ne prouvent pas les interactions navigateur.

Critères 7A : comportement des routes préservé sur les mêmes tests avant/après, service testable directement, analyse de ce périmètre sans erreurs et commande intégrée à la CI. Critères encore ouverts du lot 7 : CI GitHub effective, validation humaine, couverture/extraction JavaScript et élargissement progressif. En cas d’échec de reproduction, conserver le lot ouvert, relever commande/code/statut ; vérifier dépendances verrouillées, base dédiée migrée et injection avant tout changement de comportement.

## État Git

Modifiés : `.github/workflows/ci.yml`, `composer.json`, `composer.lock`, `src/Controller/ArticleController.php`, `docs/lots-amelioration.md`.

Nouveaux : `phpstan.neon.dist`, `src/Application/Reading/MarkSubscriptionRead.php`, les deux tests cités, `docs/lot-7-maintenance-plan.md` et ce compte-rendu.

Aucun `git add`, commit, push, tag, changement de branche, migration ou déploiement exécuté. Le développeur conserve la responsabilité de l’acceptation et de la publication.
