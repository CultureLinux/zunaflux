# Lot 3 — Messenger : livraison à valider du 15 novembre 2026

Sources et tests préparés. Le lot n’est pas clôturé : validation PHP, MariaDB 10.11 et retour explicite du développeur indispensables. Les lots 1 et 2 restent dans leur état de validation précédent.

## Fonctionnalités et fichiers

| Fichier | Rôle |
| --- | --- |
| `config/packages/messenger.yaml` | File d’échec `failed`, cinq nouvelles tentatives bornées et tables gérées exclusivement par migrations. |
| `config/services.yaml` | Alias du contrat HTTP et journal de notification du relais. |
| `src/Application/Feed/CollectFeed.php` | Téléchargement/analyse hors transaction, enregistrement atomique articles/validateurs/événement. |
| `src/Application/Notification/NotificationOutbox.php` | Enregistrement durable de l’événement et de son compte. |
| `src/Application/Notification/RelayNotificationOutbox.php` | Relais transactionnel, lots bornés et sélection concurrente sans double prise. |
| `src/Domain/Feed/FeedFetcherInterface.php` et `FeedFetcher.php` | Contrat du téléchargement : les tests peuvent fournir du RSS contrôlé sans accès réseau ni exception aux règles SSRF de production. |
| `src/Message/NotifyFeedMessage.php` | Identifiant d’événement optionnel en entrée, conservé pendant les reprises ; les appels à trois arguments restent compatibles. |
| `src/MessageHandler/CollectFeedHandler.php` | Relais de l’événement durable ; protection contre une nouvelle écriture avec un EntityManager fermé après rollback. |
| `src/MessageHandler/NotifyFeedHandler.php` | Un compte occupé suit la limite de tentatives plutôt que des reprises forcées infinies. |
| `src/Command/RelayNotificationsCommand.php` | `app:notifications:relay`, reprise bornée de l’outbox sans appel Web Push direct. |
| `src/Command/SendNotificationsCommand.php` | Reprise périodique intégrée au planificateur existant ; poursuite des récapitulatifs déjà disponibles si seul le relais échoue. |
| `migrations/Version20261115120000.php` | Nouvelle table InnoDB `notification_outbox`, clés, index et compte strictement positif. |
| `tests/Functional/NotificationOutboxTest.php` | Huit tests de collecte atomique, rollback réel, absence de nouveautés/304, interruption après acceptation, concurrence MariaDB, identité sérialisée, compte verrouillé, inspection/rejeu réel de failed. |
| `tests/Unit/Message/MessengerRetryTest.php` | Test de la politique réellement configurée : cinq reprises autorisées, suivantes refusées. |
| `docs/messenger-recovery.md` | Exploitation, garanties, inspection et rejeu. |
| `docs/messenger-systemd.md`, `docs/notification-schedule.md`, `docs/lots-amelioration.md` | Liens opérationnels et état du lot. |
| `docs/lot-3-messenger-validation.md` | Présent compte-rendu. |

La file d’échec utilise `messenger_messages`, table déjà créée par `Version20261027110000`, avec un `queue_name` distinct. Aucune nouvelle variable d’environnement applicative, aucun secret et aucune dépendance Composer/npm. Le DSN livré reste `doctrine://default?queue_name=async`. L’atomicité de collecte suppose, comme dans la configuration actuelle, la même connexion Doctrine par défaut pour l’ORM et l’outbox.

Les nouveaux journaux ne contiennent ni exception de transport ni URI : seulement un statut de reprise et un compte. Les diagnostics détaillés existants de Messenger doivent rester dans l’environnement d’exploitation et être expurgés avant partage.

## Vérifications effectivement effectuées

L’environnement fourni est Bash Linux, contrairement à la description Windows des instructions. Aucune commande Windows n’a été exécutée. Aucun PHP, Composer, PHPUnit, analyseur PHP, Node, Docker, build ou migration n’a été lancé, ni aucun outil installé.

Les tests PHP ont été écrits avant l’implémentation, mais aucun cycle rouge/vert PHP n’est revendiqué : il ne peut pas être démontré sans exécution sur l’environnement du développeur.

Effectués : revue statique du chemin collecte → commit → relais → Messenger → récapitulatif, des injections et des appels existants ; recherche de constructeurs affectés ; lecture de la configuration et des migrations existantes ; `git diff --check` ; contrôles shell des workflows des lots 1 et 2. Ces derniers passent, avec 19 scénarios de la porte qualité, 39 de versions et 10 de préparation de publication. Ils ne valident pas le nouveau comportement PHP.

Les tests ajoutés utilisent de vraies transactions et contraintes MariaDB ; seules la réponse HTTP et l’acceptation externe par le bus sont contrôlées. Le test du transport `failed` exécute les commandes Symfony d’inspection/rejeu sur une copie persistée à la main pour représenter un échec après traitement partiel. Il ne remplace pas l’essai d’épuisement réel des tentatives dans un worker.

## Contrôles locaux PowerShell

```powershell
# Lecture et diff uniquement ; pas de PHP/Composer/Node requis.
git diff --check
if ($LASTEXITCODE -ne 0) { throw 'Erreur de diff' }
git diff --stat
git diff -- config/packages/messenger.yaml config/services.yaml src/Application/Feed/CollectFeed.php src/MessageHandler/CollectFeedHandler.php src/MessageHandler/NotifyFeedHandler.php
Get-Content migrations/Version20261115120000.php
Get-Content src/Application/Notification/NotificationOutbox.php
Get-Content src/Application/Notification/RelayNotificationOutbox.php
Get-Content src/Command/RelayNotificationsCommand.php
Get-Content tests/Functional/NotificationOutboxTest.php
Get-Content tests/Unit/Message/MessengerRetryTest.php
git status --short
```

Attendu : aucun défaut d’espacement, périmètre conforme au tableau et lecture des nouveaux fichiers non suivis. Aucun test applicatif Windows n’est supposé disponible.

## Validation Linux — prérequis et contrôles sans modification des données

Utiliser une copie isolée et une base MariaDB 10.11 dédiée aux tests. La configuration de test doit désigner cette base, jamais celle de production. Les dépendances doivent être installées avant les commandes Symfony ; si nécessaire, utiliser le bloc d’installation soumis à approbation ci-dessous. Les inspections de files demandent que les migrations soient déjà appliquées.

```bash
set -eu
php85 -v
php85 /usr/bin/composer --version
php85 /usr/bin/composer validate --strict
find src tests migrations public bin -type f -name '*.php' -print0 | xargs -0 -n1 php85 -l
APP_ENV=test php85 bin/console lint:yaml config/
APP_ENV=test php85 bin/console lint:container
APP_ENV=test php85 bin/console lint:twig templates/
APP_ENV=test php85 bin/console debug:messenger
APP_ENV=test php85 bin/console help app:notifications:relay
sh tests/Container/ci-config.sh
sh tests/Container/workflow-config.sh
sh tests/Container/publish-workflow-config.sh
sh tests/Container/ci-quality-gate.sh
sh tests/Container/release-tags.sh
sh tests/Container/release-workflow.sh
git diff --check
```

Attendu, dans l’ordre : PHP 8.5, Composer disponible, manifeste valide ; tous les fichiers PHP syntaxiquement valides ; YAML, injection et Twig sans erreur ; handlers de collecte/découverte/notification visibles dans Messenger ; aide de la nouvelle commande ; contrats de workflow valides et 19/39/10 scénarios réussis ; diff propre. Les commandes Symfony peuvent écrire le cache mais ne doivent pas modifier les données. Aucune configuration PHPStan n’est présente ; aucune commande d’analyse custom n’est supposée.

## Installation, migrations et tests — approbation humaine requise

Ces commandes modifient l’environnement de la copie et sa base de test. Vérifier humainement `DATABASE_URL` avant de commencer ; ne pas afficher les secrets dans les sorties partagées. Préparer deux bases de test séparées si l’on vérifie à la fois une installation vide et une mise à jour depuis la version précédente. Aucun test ne doit viser une base partagée avec la production.

```bash
set -eu
# Si les dépendances ne sont pas déjà installées ; écrit vendor et les caches.
APP_ENV=test php85 /usr/bin/composer install --no-interaction --prefer-dist --no-progress
# Contrôler le plan, puis appliquer exclusivement à la base de test vérifiée.
APP_ENV=test php85 bin/console doctrine:migrations:status
APP_ENV=test php85 bin/console doctrine:migrations:migrate --dry-run --no-interaction
APP_ENV=test php85 bin/console doctrine:migrations:migrate --no-interaction
# Politique de reprise, chemin durable, puis régressions proches.
APP_ENV=test php85 vendor/bin/phpunit tests/Unit/Message/MessengerRetryTest.php
APP_ENV=test php85 vendor/bin/phpunit tests/Functional/NotificationOutboxTest.php
APP_ENV=test php85 vendor/bin/phpunit tests/Functional/NotificationDigestTest.php
APP_ENV=test php85 vendor/bin/phpunit tests/Functional/NotificationTraceTest.php
# Suite du dépôt : ne pas réduire la validation aux seuls nouveaux tests.
APP_ENV=test php85 vendor/bin/phpunit --display-phpunit-notices
result=0
for test_file in tests/JavaScript/*.test.cjs; do
    node "$test_file" || result=1
done
test "$result" = 0
# Écrit les assets de la copie et peut télécharger Tailwind.
APP_ENV=prod php85 bin/console tailwind:build --minify --env=prod
APP_ENV=prod php85 bin/console asset-map:compile --env=prod
# Construit une image et crée/nettoie uniquement des services de test isolés.
docker build --tag clinuxfr/zunaflux:ci-lot3 .
sh tests/Container/docker-image-smoke.sh clinuxfr/zunaflux:ci-lot3
sh tests/Container/compose-config.sh
sh tests/Container/compose-smoke.sh ci-lot3
```

Attendu : dépendances verrouillées installées, migrations en attente cohérentes, création de `notification_outbox`, test unitaire et huit tests fonctionnels nouveaux sans échec ; récapitulatifs existants inchangés ; suite complète réussie ; assets construits et image saine. Analyser séparément les notices PHPUnit. Le test `NotificationTraceTest` conserve ses dépendances temporelles préexistantes, sujet du lot 4 ; tout échec doit être rapporté, sans déclarer la suite validée.

Sur la base dédiée à la mise à jour, partir d’un schéma au niveau `Version20261114120000` et vérifier que seule la nouvelle migration est en attente. Sur une autre base vide dédiée, vérifier toute la chaîne de migrations. Ne pas utiliser de commande de suppression de base ou de retour arrière global dans une procédure automatique. La CI existante appliquera aussi les migrations avant la suite PHP.

## Inspections après migration — sans rejeu

```bash
APP_ENV=test php85 bin/console messenger:stats async failed
APP_ENV=test php85 bin/console messenger:failed:show --transport=failed --stats
APP_ENV=test php85 bin/console doctrine:migrations:status
```

Attendu : les deux transports sont disponibles, aucune création implicite de table, et la migration `Version20261115120000` est appliquée. Les compteurs peuvent être nuls. Consulter les requêtes SQL en lecture seule de la [procédure de reprise](messenger-recovery.md) pour surveiller l’outbox et son âge.

## Acceptation opérationnelle sur environnement isolé

Avec des flux, comptes et appareils de test, un transport Doctrine dédié et aucun destinataire réel :

1. Vérifier qu’une collecte réussie crée ensemble articles et événement, puis qu’une nouvelle collecte sans nouveautés ne recrée pas d’événement. Exécuter les tests de rollback : une contrainte violée ne doit laisser ni article, ni outbox, ni nouveau validateur HTTP.
2. Vérifier qu’une interruption de relais conserve l’événement et qu’une reprise utilise le même UUID. Deux copies traitées ne doivent pas augmenter deux fois le récapitulatif. Les deux connexions du test de concurrence ne doivent pas transmettre la même ligne verrouillée.
3. Vérifier que le planificateur périodique est actif. Une ligne d’outbox commitée avant l’arrêt du processus doit être relayée au passage suivant, même sans nouvelle collecte. La commande dédiée effectue une vraie mise en file : son exécution nécessite l’approbation humaine.
4. Faire tenir le verrou de récapitulatif d’un compte de test par une autre connexion et traiter sa notification avec un worker de test. Après le traitement initial et cinq nouvelles tentatives, le message doit être inspectable dans `failed`, non perdu et non repris indéfiniment. Arrêter le worker de test et libérer le verrou suivant la procédure de cet environnement.
5. Inspecter puis rejouer le message choisi dans `failed`, selon la procédure liée ; confirmer l’identité d’événement et le compte métier. Ne pas supprimer la file d’échec pour masquer un problème.
6. Vérifier les limites Web Push avec un fournisseur simulé : une acceptation suivie d’un arrêt avant sauvegarde du curseur peut produire un doublon externe, même si les événements de récapitulatif ne sont pas comptés deux fois.

Le test fonctionnel automatise notamment l’inspection/rejeu d’une copie déjà partiellement traitée. L’essai de worker ci-dessus doit encore prouver la chaîne d’épuisement et transfert réel dans la configuration déployée.

## Activation production — exclusivement par le développeur après approbation

Ce bloc n’est pas un contrôle en lecture seule. Sauvegarder, suspendre les producteurs/planificateurs, vider les messages historiques sans `eventId` avec l’ancien code et arrêter proprement le worker. Ne pas démarrer le nouveau code avant sa migration. Le déploiement du code et la gestion du cron restent la procédure du développeur.

Sur l’hôte utilisant exactement l’unité systemd fournie, après approbation humaine :

```bash
# Arrêt d’un service réel : vérifier d’abord le nom et l’hôte.
sudo systemctl stop zunaflux-messenger.service
# Dans le code mis à jour, dépendances déjà installées et configuration prod vérifiée.
php85 bin/console doctrine:migrations:status --env=prod
php85 bin/console doctrine:migrations:migrate --env=prod --dry-run --no-interaction
# Seulement après examen du plan et de la sauvegarde.
php85 bin/console doctrine:migrations:migrate --env=prod --no-interaction
php85 bin/console cache:clear --env=prod
php85 bin/console cache:warmup --env=prod
sudo systemctl start zunaflux-messenger.service
```

Attendu : arrêt propre, plan de migration approuvé, table créée, cache construit, worker redémarré sur le nouveau code. Reprendre aussi les producteurs et le planificateur selon la procédure locale. Aucune nouvelle unité systemd n’est ajoutée. Sous Compose, ne pas appliquer ce bloc systemd : un `up` exécute les migrations via le service existant et nécessite l’approbation préalable du développeur.

Contrôles de services en lecture seule après activation humaine :

```bash
sudo systemctl status zunaflux-messenger.service --no-pager
sudo journalctl -u zunaflux-messenger.service -n 50 --no-pager
php85 bin/console messenger:stats async failed --env=prod
php85 bin/console messenger:failed:show --transport=failed --stats --env=prod
```

Attendu : `active (running)`, aucun échec répété de base/transport ; surveiller aussi l’âge de l’outbox avec les requêtes documentées et l’exécution du cron existant de notifications. Sous Docker, l’équivalent de contrôle est :

```bash
docker compose --env-file docker/.env -f compose.yaml ps
docker compose --env-file docker/.env -f compose.yaml logs --tail=100 notifications worker collector
```

Attendu : services opérationnels sans boucle d’échec, reprise périodique effective. Les commandes de rejeu sont séparées dans `docs/messenger-recovery.md` et demandent une décision humaine.

## Diagnostic et limites à accepter

En cas d’échec, arrêter la validation et conserver le lot ouvert. Fournir la première commande, son code de sortie et une sortie expurgée :

- Erreur de service/constructeur : reprendre `lint:container`, vérifier l’alias `FeedFetcherInterface` et les injections du relais ; ne pas ajouter de dépendance pour contourner le problème.
- Table absente : vérifier le nom de la base et l’état de migration ; ne pas activer `auto_setup` dans une transaction ni lancer `schema:update --force`.
- Syntaxe SQL/verrou : confirmer MariaDB 10.11/InnoDB et exécuter le test de concurrence sur cette base isolée.
- Test de reprise : comparer les UUID, vérifier que l’erreur laisse une ligne durable et qu’une copie consommée conserve sa contrainte de déduplication.
- Outbox qui vieillit : contrôler le cron/service notifications, le worker, le transport et une éventuelle ligne illisible ; les lignes non transmises sont conservées sans purge automatique.
- Web Push : distinguer absence de configuration, acceptation réseau et affichage réel ; le lot ne promet pas un envoi externe exactement une fois.

La rétention actuelle de déduplication est de 30 jours après consommation. Les vieux messages sans UUID, les rejeux après purge, la suppression volontaire de flux et la perte de base ne sont pas couverts par une garantie illimitée. Une migration `down` supprimerait l’outbox : ne pas l’exécuter tant que des événements restent à récupérer.

Aucune migration, installation, modification de service ou publication n’a été exécutée par l’agent. Aucun commit, push, tag ou indexation Git effectué. L’état Git final est consigné ci-dessous après vérification.

## État Git relevé

```text
 M config/packages/messenger.yaml
 M config/services.yaml
 M docs/lots-amelioration.md
 M docs/messenger-systemd.md
 M docs/notification-schedule.md
 M src/Application/Feed/CollectFeed.php
 M src/Command/SendNotificationsCommand.php
 M src/Domain/Feed/FeedFetcher.php
 M src/Message/NotifyFeedMessage.php
 M src/MessageHandler/CollectFeedHandler.php
 M src/MessageHandler/NotifyFeedHandler.php
?? docs/lot-3-messenger-validation.md
?? docs/messenger-recovery.md
?? migrations/Version20261115120000.php
?? src/Application/Notification/NotificationOutbox.php
?? src/Application/Notification/RelayNotificationOutbox.php
?? src/Command/RelayNotificationsCommand.php
?? src/Domain/Feed/FeedFetcherInterface.php
?? tests/Functional/NotificationOutboxTest.php
?? tests/Unit/Message/MessengerRetryTest.php
```

Aucun commit ni push effectué.
