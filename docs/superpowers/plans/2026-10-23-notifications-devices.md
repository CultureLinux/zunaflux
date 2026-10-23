# Notifications par appareil

1. Ajouter une description indicative, les dates de résultat et une table d'avis d'expiration, sans stocker le user-agent brut. Préserver les abonnements existants.
2. Exposer aux seuls propriétaires la liste, le test et la suppression de chaque abonnement ; identifier l'appareil courant par son endpoint, jamais par son user-agent. Protéger les mutations par CSRF.
3. Préparer le contenu des notifications de collecte, enregistrer indépendamment le résultat de chaque envoi et supprimer les abonnements expirés après création d'un avis sans secret.
4. Afficher la liste, les résultats et les avis dans les préférences ; synchroniser les actions de l'appareil courant et permettre les tests et suppressions à distance.
5. Ajouter les tests de représentation, d'isolation entre comptes, d'envoi et d'expiration ; vérifier sur Linux et sur les deux appareils.
