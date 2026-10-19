# ADR-0001 — Plateforme initiale

**Statut : accepté — 19 octobre 2026**

Zunaflux utilise PHP 8.5 et Symfony 8.1. La persistance cible exclusivement MariaDB 10.11 avec InnoDB, `utf8mb4` et Doctrine ORM/migrations.

Les choix de démarrage sont : domaine `zunaflux.clinux.fr`, fuseau initial `Europe/Paris`, 15 articles par chargement sur desktop et mobile, défilement infini par curseur opaque, SMTP pour les e-mails transactionnels et export individuel sous forme de fichier temporaire chiffré à durée de vie courte.

Les statistiques d’un compte supprimé seront conservées sous une forme anonymisée. La recherche utilisera FULLTEXT MariaDB avec une validation spécifique de la recherche française et des accents sur un jeu de données réaliste.
