# Reprise des collectes et notifications — lot 3

## Chemin durable

La collecte télécharge et analyse le flux avant d’ouvrir sa transaction d’écriture. Sur la connexion Doctrine par défaut, une transaction ORM enregistre les articles, leurs validateurs HTTP, l’état de collecte et une ligne `notification_outbox` portant un identifiant d’événement stable. Si l’écriture échoue, aucun de ces changements n’est conservé. Le gestionnaire ORM fermé après un rollback n’est pas réutilisé pour enregistrer un incident avec des objets périmés ; l’exception est rendue à Messenger.

Cette atomicité utilise le comportement documenté de [`EntityManager::wrapInTransaction`](https://www.doctrine-project.org/projects/doctrine-orm/en/current/reference/transactions-and-concurrency.html). L’outbox et l’ORM doivent conserver la même connexion par défaut ; ce dépôt ne configure qu’une connexion.

Après une collecte réussie, le handler tente le relais des événements de ce flux. Un échec conserve les articles et leur événement dans l’outbox ; le prochain passage périodique peut les reprendre même si la collecte suivante n’a aucun nouvel article. Une interruption juste après le commit de collecte est couverte de la même façon.

`app:notifications:send`, déjà appelé toutes les minutes par le service Docker `notifications` ou le cron documenté, relaie jusqu’à 100 événements avant de traiter les récapitulatifs. Un échec de relais est signalé avec un code de sortie non nul, mais les récapitulatifs déjà constitués sont quand même traités lorsque la base reste disponible. Aucun nouveau cron ni service permanent n’est requis si ce planificateur est actif.

La commande `app:notifications:relay` relaie aussi jusqu’à 100 événements, sans appeler directement le fournisseur Web Push. Elle ne remplace pas le worker : celui-ci doit consommer `async` pour accumuler les récapitulatifs. L’envoi externe reste la responsabilité du planificateur `app:notifications:send`.

## Reprises et concurrence

Chaque relais ouvre une transaction DBAL pour une ligne, utilise `FOR UPDATE SKIP LOCKED`, transmet un `NotifyFeedMessage` avec l’identifiant enregistré, puis supprime la ligne après succès. Un autre relais ignore cette ligne verrouillée. En cas d’exception ou d’arrêt avant commit, la ligne et son identité restent disponibles.

Avec le transport Doctrine `async` sur la connexion par défaut, insertion dans `messenger_messages` et suppression de l’outbox partagent la transaction. L’option `auto_setup: false` évite la création automatique de tables pendant cette transaction : les migrations doivent être appliquées avant de démarrer les producteurs et consommateurs. Le transport d’échec `failed` utilise la même table, avec `queue_name=failed`, distinct de `async`.

Si un transport externe était introduit ultérieurement, acceptation réseau et suppression de l’outbox ne pourraient plus être atomiques : une copie supplémentaire serait possible. Elle garderait le même identifiant et bénéficierait de la déduplication des récapitulatifs. Le paramétrage livré reste celui de Doctrine/MariaDB ; une autre infrastructure demande une validation spécifique.

Messenger applique aux messages `async` cinq nouvelles tentatives après le traitement initial, avec des délais de base de 1, 2, 4, 8 et 16 secondes, une variation de 10 % et un plafond configuré de 60 secondes. Un compte occupé lève désormais une exception ordinaire : il suit aussi cette limite, sans forcer des reprises infinies. Après épuisement, Messenger conserve le message dans `failed`. Le worker existant continue de consommer uniquement `async`, jamais `failed` automatiquement. Voir la [documentation Messenger sur les reprises et le transport d’échec](https://symfony.com/doc/current/messenger.html#retries-failures).

Le relais de l’outbox n’est pas un message Messenger : tant que sa transmission échoue, la ligne reste dans l’outbox et le planificateur réessaie à son prochain passage. Elle n’est ni supprimée après cinq tentatives ni transférée automatiquement dans `failed`. Une ligne illisible ou une erreur persistante peut bloquer un lot de relais ; suivre le nombre et l’âge des lignes, corriger la cause puis relancer.

## Inspection sans effet sur les données

Depuis la racine du projet, une fois les migrations appliquées :

```bash
php85 bin/console messenger:stats async failed --env=prod
php85 bin/console messenger:failed:show --transport=failed --stats --env=prod
php85 bin/console messenger:failed:show --transport=failed --max=20 --env=prod
```

Attendu : compteurs par transport, puis classes et références des messages en échec. Ces inspections ne rejouent ni ne suppriment les messages. Elles peuvent écrire le cache Symfony. Les diagnostics d’exception peuvent contenir des informations sensibles provenant d’un transport : conserver ces sorties dans l’environnement d’exploitation et les expurger avant partage.

Dans votre client SQL MariaDB habituel, en lecture seule :

```sql
SELECT COUNT(*) AS pending_events, MIN(created_at) AS oldest_pending_utc
FROM notification_outbox;

SELECT HEX(event_id) AS event_id_hex, HEX(feed_id) AS feed_id_hex,
       article_count, created_at
FROM notification_outbox
ORDER BY created_at, event_id
LIMIT 20;

SELECT queue_name, COUNT(*) AS message_count
FROM messenger_messages
GROUP BY queue_name;
```

Attendu : l’outbox baisse après relais ; `failed` doit normalement rester vide. Un grand âge d’événement indique un planificateur absent, une panne de transport ou une ligne à diagnostiquer. Les identifiants hexadécimaux ne sont pas des identifiants de message Messenger : pour un rejeu de `failed`, utiliser l’identifiant numérique affiché par `messenger:failed:show`.

## Rejeu — approbation humaine requise

Ces commandes modifient les files et la base. Elles peuvent provoquer ultérieurement un envoi réel si les workers et le planificateur fonctionnent. Corriger d’abord la cause de panne ; ne pas les lancer comme contrôles en lecture seule.

Pour reprendre au maximum 100 lignes de l’outbox :

```bash
php85 bin/console app:notifications:relay --env=prod --no-interaction
```

Attendu : `N notification event(s) queued.` avec code zéro. Un code non nul signifie que des événements restent à reprendre ; consulter `var/log/notification_prod.log` pour `outbox_retry_pending`, puis comparer les compteurs. Répéter humainement la commande si le volume dépasse un lot et si le transport est sain. Le planificateur continue aussi ce travail à chaque passage.

Pour inspecter puis rejouer un seul message échoué, remplacer `ID_DU_MESSAGE` par l’identifiant vérifié :

```bash
php85 bin/console messenger:failed:show ID_DU_MESSAGE --transport=failed --env=prod
php85 bin/console messenger:failed:retry ID_DU_MESSAGE --transport=failed --env=prod
```

La seconde commande demande une confirmation interactive. Attendu après réussite : le message échoué a été traité ou replacé dans son circuit de reprise, sa copie réussie ne reste plus dans `failed`, et le récapitulatif ne compte pas deux fois son événement. Contrôler le résultat métier et les compteurs ; le seul code de sortie de la commande ne prouve pas que toute la chaîne a réussi.

Ne pas reconstruire manuellement un `NotifyFeedMessage` avec un nouvel UUID pour rejouer une notification : il serait considéré comme un nouvel événement. Ne pas lancer de purge globale de `failed` ou de l’outbox. Aucun script de suppression automatique n’est ajouté.

## Garanties et limites

- Les nouveaux événements ont une identité conservée de la collecte jusqu’aux reprises. La contrainte existante `(subscription_id, event_id)` empêche leur double accumulation par abonnement.
- Les événements consommés de `notification_pending` sont conservés 30 jours par la politique existante. Un rejeu plus ancien après purge peut compter à nouveau ; inspecter la date et décider humainement du rejeu. Aucune garantie de déduplication illimitée n’est annoncée.
- Les messages historiques sérialisés avant l’ajout d’`eventId` ne permettent pas une reprise idempotente garantie. Les vider avec l’ancien code avant activation ; ne pas les rejouer aveuglément après migration. Le comportement de compatibilité préexistant du handler n’est pas modifié.
- La liste des comptes, abonnements actifs et appareils éligibles est évaluée par le handler existant. Un compte désactivé, un abonnement supprimé ou l’absence d’appareil ne crée pas de destinataire supplémentaire.
- La suppression d’un flux supprime volontairement ses articles et son outbox par cascade. Ce n’est pas une reprise de notification à un flux supprimé.
- L’acceptation Web Push et l’enregistrement du curseur d’appareil ne forment pas une transaction. Une interruption entre les deux peut produire un doublon externe ; `sent` signifie acceptation du fournisseur, pas affichage sur l’appareil.
- La reprise dépend d’une base conservée, des workers et du planificateur actifs. Aucune notification déjà perdue avant ce lot ne peut être reconstruite avec certitude à partir de son seul compteur.

## Activation et retour arrière

La migration `Version20261115120000` crée uniquement `notification_outbox`, avec clés et index compatibles MariaDB 10.11/InnoDB. La table Messenger existe depuis `Version20261027110000` ; aucune migration supplémentaire n’est nécessaire pour la file `failed`.

Sauvegarder, laisser l’ancien code vider les messages historiques, puis arrêter proprement les processus applicatifs suivant la procédure du développeur. Inspecter le plan des migrations et appliquer la nouvelle migration avant de démarrer le nouveau code. Vérifier les transports et la nouvelle commande, puis reprendre worker, collecte et planificateur avec le gestionnaire habituel. L’agent n’exécute aucune de ces opérations. Les commandes exactes sont séparées dans le [compte-rendu de validation](lot-3-messenger-validation.md).

Dans Compose, le service `migrations` s’exécute au démarrage et les autres services en dépendent : l’approbation des migrations doit précéder tout `up`. Consulter le [guide Docker](docker.md). Sur l’hôte systemd, suivre la [procédure du worker](messenger-systemd.md) et le cron des [notifications](notification-schedule.md).

La nouvelle table est gérée par migration/DBAL, sans entité ORM. Ne pas utiliser `schema:update --force`, qui pourrait la considérer comme étrangère au mapping. Un retour au code précédent doit être préparé par le développeur avec les files sauvegardées et les événements traités. Ne pas exécuter le `down` destructeur de la migration sur une outbox contenant des événements à récupérer.
