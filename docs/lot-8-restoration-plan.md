# Lot 8 — Exercice isolé de restauration

Objectif : démontrer une restauration SQL + médias + configuration de chiffrement et un retour à une sauvegarde antérieure, sans accès à une base réelle ni modification de la topologie de production.

1. Ajouter un test du rendu Compose : la clé Mastodon et l’origine publique doivent être transmises aux services applicatifs, comme prévu dans l’exemple d’environnement. Observer le refus avant correction puis le passage après ajout des deux variables.
2. Créer un Compose d’exercice autonome : MariaDB 10.11, image locale explicitement choisie et figée par ID, réseau interne, aucun port publié, volume de travail privé, nom de projet aléatoire non configurable.
3. Ajouter un script de fixtures limité à trois noms de bases d’exercice et une variable explicite de garde. Source synthétique : utilisateur, article favori/à-lire, compte Mastodon chiffré, événement outbox, média PNG et configuration privée de test incluant VAPID.
4. Migrer uniquement la base source jetable, sauvegarder avec mariadb-dump (binaire en hexadécimal), restaurer dans une base neuve et recopier les fichiers depuis leur archive. Vérifier contenus, chiffrement et refus d’une clé absente/fausse.
5. Exécuter les vraies commandes de collecte et notifications, consommer le message de notification restauré avec le worker et vérifier heartbeat/file/digest. Ne pas envoyer de Web Push ni collecter un site externe ; le réseau de l’exercice reste interne.
6. Simuler une modification de données, médias et schéma sur la cible jetable, puis restaurer la même sauvegarde dans une troisième base neuve et vérifier l’état antérieur. Ne pas tester une migration descendante de production.
7. Nettoyer seulement le projet/volumes créés par l’exercice et garder un rapport sans clés ni jetons. Exécuter l’exercice avec les images locales déjà présentes ; aucun outil système à installer.
8. Documenter sauvegardes cohérentes, secrets indispensables, ordre de vérification/approbation/migration/mise à jour et retour arrière. Ne pas changer l’application automatique des migrations au démarrage sans décision du développeur.

Vérifications : cycle rouge/vert de transmission Compose, syntaxe PHP/shell/YAML, test réel d’import/restauration/rollback et suite applicative existante. Les contrôles de processus en production restent en lecture seule et ne sont pas exécutés par l’agent.

Contraintes : PHP 8.5 via php85 sur l’hôte, MariaDB 10.11 exclusivement, aucun identifiant réel dans les fichiers, aucun git add/commit/push/tag, aucun déploiement/redémarrage de production. Le futur rapport distinguera explicitement les opérations modifiant les données de test, les vérifications sans effet et la validation humaine encore requise.
