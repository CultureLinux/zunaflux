# Rythme personnel des notifications

Dans Préférences → Notifications, chaque compte choisit un intervalle de 1, 2, 3, 4, 5, 6 ou 12 heures, ou une sélection parmi 08:00, 12:00, 18:00 et 22:00. Une heure sélectionnée signifie une fois par jour. Les heures fixes suivent le fuseau du compte, y compris les changements d’heure. Le choix initial est une heure.

Les nouveautés de toutes les sources actives sont regroupées. Le compteur comprend les nouveaux articles collectés, même si certains ont déjà été lus. Aucun récapitulatif vide. Le premier intervalle commence à la première nouveauté mise en attente ; les suivants depuis le dernier envoi accepté sur l’appareil. Les horaires de silence reportent les nouveautés sans les effacer. Après une interruption du planificateur, un seul récapitulatif regroupe les nouveautés en attente.

La collecte RSS reste partagée et indépendante. Les nouveautés collectées après un créneau d’envoi attendent le suivant : un cron de collecte à 08:00 ne garantit pas que sa collecte asynchrone sera terminée pour l’envoi de 08:00. Un nouvel appareil reçoit uniquement les événements postérieurs à son activation. Les tests manuels d’appareil restent immédiats.

## Reprise des événements de collecte

Depuis le lot 3, `app:notifications:send` relaie d’abord les événements conservés dans `notification_outbox`. Les articles et leur événement sont enregistrés ensemble ; une panne de mise en file n’efface plus l’intention de notifier. La migration supplémentaire, la file d’échec Messenger et les limites de déduplication sont décrites dans la [procédure de reprise](messenger-recovery.md). Les commandes d’activation ci-dessous décrivent le lot de cadence initial ; consulter aussi le [compte-rendu du lot 3](lot-3-messenger-validation.md) pour sa migration et ses tests.

## Activation Linux — approbation humaine requise

Aucune dépendance nouvelle, aucune variable d’environnement nouvelle. Sauvegarder la base et laisser le worker vider les anciens NotifyFeedMessage avant la mise à jour : ils ne portent pas encore d’identifiant d’événement. Arrêter les processus applicatifs suivant votre procédure habituelle, puis appliquer :

```bash
php85 bin/console doctrine:migrations:migrate --env=prod --no-interaction
php85 bin/console tailwind:build --minify --env=prod
php85 bin/console asset-map:compile --env=prod
php85 bin/console cache:clear --env=prod
```

Attendu : migration Version20261114120000 appliquée, table notification_pending, champs de cadence et curseurs créés, assets générés. Redémarrer les workers avec votre gestionnaire habituel pour charger le nouveau code. Aucun nom de service systemd n’est imposé par ce dépôt.

Conserver le cron de collecte et ajouter dans `/etc/cron.d/…` (chemin php85 absolu à adapter si le PATH de cron ne le contient pas) :

```cron
* * * * * zunaflux php85 /home/zunaflux/zunaflux/bin/console app:notifications:send --env=prod --no-interaction
```

La commande effectue de vrais envois et modifie les curseurs. Ne pas l’utiliser comme vérification sans effet. Un verrou MariaDB par compte empêche deux passages simultanés d’envoyer le même récapitulatif ; le passage qui ne peut pas prendre le verrou attend le prochain cron. Le verrou est libéré même en cas d’échec. Les notifications asynchrones en cours d’accumulation sont réessayées par Messenger si le compte est verrouillé.

Dans Docker, le service `notifications` exécute cette commande puis attend 60 secondes. Monter aussi les clés VAPID dans ce service, avec les mêmes chemins et droits que dans le worker. L’image utilise son propre exécutable `php`, comme les autres services existants. Recréer l’image et les services relève du déploiement humain.

## Validation sur une base de test MariaDB 10.11

Si les dépendances manquent, `php85 /usr/bin/composer install --no-interaction --prefer-dist` modifie vendor et les caches : accord humain requis. Aucun npm nécessaire.

Sans modification des données applicatives :

```bash
git diff --check
php85 /usr/bin/composer validate --strict
find src tests migrations -type f -name '*.php' -print0 | xargs -0 -n1 php85 -l
APP_ENV=test php85 bin/console lint:container
APP_ENV=test php85 bin/console lint:twig templates/
node --check public/preference-tabs.js
for test_file in tests/JavaScript/*.test.cjs; do node "$test_file" || exit 1; done
sh -n docker/send-notifications.sh
```

Attendu : sorties sans erreur, tests JavaScript réussis. Aucun PHPStan n’est configuré dans composer.json. Les lint Symfony peuvent écrire leur cache local.

Après vérification que DATABASE_URL cible exclusivement une base de test, avec accord humain (ces commandes modifient cette base) :

```bash
APP_ENV=test php85 bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=test php85 vendor/bin/phpunit tests/Unit/Entity/UserPreferenceNotificationScheduleTest.php
APP_ENV=test php85 vendor/bin/phpunit tests/Functional/Controller/NotificationScheduleTest.php
APP_ENV=test php85 vendor/bin/phpunit tests/Functional/NotificationDigestTest.php
APP_ENV=test php85 vendor/bin/phpunit tests/Functional/NotificationTraceTest.php
APP_ENV=test php85 vendor/bin/phpunit --display-phpunit-notices
```

Attendu : aucun échec, analyser séparément les notices existantes. En cas d’erreur de table/colonne, vérifier les migrations sur la bonne base ; en cas d’injection, reprendre lint:container ; en cas d’échec fonctionnel, fournir la sortie complète du test concerné.

Contrôle visuel après construction des assets : 320, 375, 768 et 1280 px, clair/sombre, FR/EN/DE, clavier, changement de mode, sauvegarde et retour à l’onglet Notifications. Vérifier qu’une sélection vide en mode heures fixes produit un message d’erreur et conserve les réglages enregistrés.

Contrôle des services : vérifier le cron installé avec `cat /etc/crontab` et les fichiers concernés de `/etc/cron.d/`, puis consulter `var/log/notification_prod.log`. Dans Docker : `docker compose --env-file docker/.env -f compose.yaml ps` et `docker compose --env-file docker/.env -f compose.yaml logs --tail=100 notifications worker collector` (lecture seule).

## Limites et diagnostic

`digest_pending` indique une nouveauté enregistrée ; `sent` indique l’acceptation par le service push, pas l’affichage sur l’appareil. Une panne de livraison conserve les nouveautés et réessaie au prochain passage autorisé. Chaque appareil progresse séparément pour éviter de renvoyer aux appareils déjà servis. Les événements consommés sont conservés 30 jours pour dédupliquer les reprises Messenger ; un rejeu plus ancien peut être renotifié. Les événements non consommés restent en attente.

Une interruption entre acceptation réseau et sauvegarde du curseur peut produire un doublon : l’envoi Web Push et MariaDB ne constituent pas une transaction unique. La table notification_pending est gérée explicitement par DBAL et la migration, pas par une entité Doctrine ; ne pas appliquer automatiquement un schema:update qui la supprimerait.

Aucun test applicatif ni déploiement n’a été effectué par l’agent. Le lot attend le retour explicite des validations Linux.
