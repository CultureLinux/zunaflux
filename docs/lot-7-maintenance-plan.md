# Lot 7A — Maintenance PHP

Périmètre : première livraison du lot 7, sans changement de comportement. Les extractions du lecteur JavaScript sont différées jusqu’à validation des interactions navigateur du lot 5 (Node 24 requis, Node 20 disponible sur l’hôte). Aucun outil système à installer.

1. Vérifier le marquage HTTP et la suite PHPUnit avant extraction sur la base dédiée autorisée.
2. Ajouter une caractérisation du marquage au-delà de 100 articles : droits/CSRF aux routes, états d’autres comptes, autres flux, favoris et événements de première lecture préservés.
3. Extraire la pagination dans `App\Application\Reading\MarkSubscriptionRead::mark(Subscription $subscription): void`. Le service déduit l’utilisateur de l’abonnement ; les contrôles HTTP d’appartenance et CSRF restent au contrôleur. `ArticleStateService` conserve l’écriture des états et statistiques.
4. Rejouer les mêmes tests. Aucun nouvel endpoint, filtre, format d’URL, index, transaction globale ou stratégie de pagination.
5. Ajouter `phpstan/phpstan` en développement, verrou Composer à jour. Analyse initiale niveau 6 des services de lecture concernés, sans baseline ni erreurs ignorées ; commande explicite dans le job PHP existant. Élargissement ultérieur par ajout de chemins, pas réduction du niveau.
6. Exécuter lints, validation Composer, analyse statique, contrats CI et suite complète ; consigner notices et limites, commandes Linux et PowerShell. Aucun commit, push, migration ou déploiement.

Contraintes globales : PHP 8.5, MariaDB 10.11, modifications non indexées, changements des lots précédents conservés. Les rapports de lot 6 restent disponibles sans changer leur statut d’acceptation finale. Aucun gain de performance n’est recherché dans cette extraction.
