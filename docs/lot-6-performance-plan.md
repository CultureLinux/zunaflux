# Lot 6 — Plan de mesure des performances

**Objectif :** préparer les preuves Linux nécessaires avant toute optimisation.

**Architecture :** outillage CLI réservé aux tests, connexion MariaDB dédiée instrumentée par le middleware DBAL existant, appels aux vrais services et au contrôleur de lecture. Aucun changement de comportement applicatif, aucune dépendance nouvelle. Le périmètre approuvé est celui du lot 6 dans `docs/lots-amelioration.md`.

**Contraintes :** PHP 8.5 ; MariaDB 10.11 ; aucune installation ni exécution PHP par l’agent ; aucun commit, indexage ou déploiement. Mesures et acceptation Linux à la charge du développeur.

- [x] Préparer les tests de refus des environnements et bases non dédiés (exécution Linux en attente).
- [x] Ajouter un garde de base `zunaflux_performance_test_*`, sans repli de DSN.
- [x] Ajouter un banc CLI : collecte 304 sans réseau avec 1/10/100 abonnés, lecture/recherche réelles, purge réelle restaurée par rollback, EXPLAIN des SELECT réellement observés.
- [x] Préparer l’enregistrement des temps, requêtes, cardinalités, plans et configuration de mesure, sans paramètres SQL ni identifiants.
- [x] Documenter les commandes, les budgets à établir depuis les premières mesures, les limites et la comparaison avant/après ; actualiser le suivi.
- [x] Contrôler statiquement les sources et le diff ; fournir les validations Linux sans les revendiquer exécutées.

## Risques à vérifier

Refus avant écriture d’une cible hors périmètre ; base non vide refusée ; aucune collecte réseau ni notification envoyée ; nettoyage par rollback même en erreur ; plans issus des vraies requêtes plutôt que copies susceptibles de diverger. Le coût HTTP, les gros contenus, la contention et les caches serveur froids restent hors du premier banc. Aucun seuil de temps arbitraire ne constitue un gain mesuré.

## Critère de sortie

Sources préparées uniquement tant que les mesures Linux, les tests et la preuve avant/après ne sont pas retournés. L’optimisation et les migrations d’index sont différées jusqu’à cette preuve.
