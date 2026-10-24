# Administration — synchronisation diagnostique Implementation Plan

**Goal:** Permettre à un administrateur de synchroniser un flux, les flux d’une catégorie donnée ou tous les flux, et de suivre chaque résultat dans une modale.

**Architecture:** Les déclenchements créent une opération persistée et une entrée par flux, puis envoient les messages Messenger existants. Le handler enrichit l’entrée avec le résultat de `CollectFeed` (nouveaux articles, aucun nouvel article, erreur) ; une API d’administration protégée est interrogée par la modale jusqu’à la fin.

**Constraints:** ROLE_ADMIN et CSRF côté serveur ; aucune exposition des logs Monolog, des URL privées ou des erreurs brutes ; MariaDB 10.11 ; pas de dépendance supplémentaire ; les collectes déjà temporisées restent respectées uniquement pour le planificateur, les déclenchements d’administration sont explicitement manuels.

### Task 1: Persistance des opérations

- Ajouter une migration avec `feed_sync_run` et `feed_sync_run_entry`, ainsi que les entités et tests de transition `pending → running → collected/no_new_articles/error`.

### Task 2: Messagerie et résultats de collecte

- Étendre `CollectFeedMessage` d’un identifiant d’opération facultatif ; le handler inscrit le résultat réel et clôture l’opération quand chaque entrée est terminale.

### Task 3: API et interface admin

- Ajouter les déclenchements flux/catégorie/tous, l’endpoint JSON de statut et une modale accessible avec polling borné ; ajouter tests fonctionnels des autorisations, CSRF, périmètres et données retournées.
