# PWA et notifications Web Push

Zunaflux reste dépendant du réseau : aucun contenu personnel n'est mis en cache pour une lecture hors ligne. L'installation et les notifications utilisent un service worker ; les notifications sont activées à l'initiative de chaque utilisateur sur chaque navigateur.

La configuration utilise une paire VAPID stable, dont la clé privée reste hors du dépôt. Les abonnements associent un utilisateur à un point de livraison et aux clés de chiffrement du navigateur. Les requêtes de modification exigent une session authentifiée et une protection CSRF ; le serveur ne doit jamais envoyer de requête vers une destination non fiable.

La collecte produit une notification groupée pour les nouveaux articles d'un flux et signale le début d'une panne, sans répéter l'alerte à chaque tentative. Les envois sont asynchrones et ne doivent pas faire échouer la collecte. Les abonnements expirés sont supprimés. Les notifications évitent d'exposer des contenus sensibles sur l'écran verrouillé.

Le journal `var/log/notification_prod.log` retrace les décisions de collecte et les résultats d'envoi : identifiant du flux, type, nombre de nouveaux articles, adresse de l'utilisateur, identifiant et libellé de l'appareil, et statut. `no_new_articles` signifie qu'aucune notification n'a été mise en file ; `sent` signifie que le service Web Push a accepté l'envoi, pas que l'appareil l'a affiché. Les statuts `no_push_subscription`, `configuration_error`, `queue_error`, `unsupported_endpoint`, `expired_subscription`, `delivery_failed` et `delivery_error` indiquent les causes d'absence d'envoi ou d'échec. `detected` indique seulement que le traitement de notification a commencé. Le journal contient des adresses personnelles : limiter son accès et définir une durée de conservation adaptée. Les points de livraison et les clés ne sont pas journalisés.

La validation Linux comprend les tests métier et fonctionnels, la migration MariaDB, l'installation sur Android et la réception après fermeture de l'application. Les opérations sur la base et l'environnement sont soumises à l'approbation du développeur.

## Configuration

Après validation humaine, configurer dans `.env.local` les chemins absolus des fichiers existants et une adresse de contact :

```dotenv
ZUNAFLUX_VAPID_PUBLIC_FILE=/chemin/vers/zunaflux-secrets/vapid-public.txt
ZUNAFLUX_VAPID_PRIVATE_FILE=/chemin/vers/zunaflux-secrets/vapid-private.pem
ZUNAFLUX_VAPID_SUBJECT=mailto:contact@example.com
```

La clé privée et le répertoire qui la contient doivent être lisibles par le compte du worker Messenger ; le fichier public doit être lisible par PHP-FPM. Ne pas exposer la clé privée au serveur web et ne pas la versionner. Redémarrer le worker pour charger la configuration, après validation humaine. Les abonnements ne fonctionnent qu'une fois la migration appliquée.

## État de validation

Les points de terminaison autorisés sont actuellement limités aux services Web Push de Chrome et Firefox. Les autres navigateurs ou fournisseurs peuvent nécessiter une adaptation. Les livraisons en erreur sont journalisées sans exposer les abonnements ; elles ne sont pas réessayées individuellement. Les icônes de 192 × 192 et 512 × 512 sont fournies au format SVG, avec une variante PNG plus grande ; leur prise en charge et l'installation restent à vérifier sur Android. Les notifications réelles n'ont pas encore été vérifiées sur appareil.
