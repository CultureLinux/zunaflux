# Grill: regroupement des points non faits des lots 1 à 9
Date: 2026-11-20

## Intent
Livrer ce qui reste de l'audit en lots thématiques homogènes, pour préparer une première version publique auto-hébergée (AGPL, image Docker publiée, tags SemVer).

## Constraints
- Aucun retour d'installation à ce jour : pas de base d'utilisateurs à protéger.
- Les changements incompatibles sont acceptés ; ils sont signalés uniquement dans le `README.md` (section « Changements incompatibles »).
- Je ne peux vérifier ni dans un navigateur réel (focus, raccourcis, rendu), ni dans l'interface GitHub, ni sur une installation propre : ces points se terminent par une validation du développeur.

## Key decisions
- Decision: regrouper les points restants en cinq lots par type (10 sécurité applicative, 11 exploitation Docker, 12 données et performances, 13 front et accessibilité, 14 qualité, CI et architecture résiduelle). Reason: un seul lot de ~40 points ne se termine pas, et chaque type a sa propre validation. Alternative considered: un lot 10 unique.
- Decision: 3.3 reste dans le périmètre, limité à une CSP en `Report-Only` et au remplacement du widget Ko-fi par un lien. Reason: ne casse rien et fournit les violations réelles avant tout durcissement.

## Surfaced assumptions
- « Pas de retour » signifie que personne n'a encore installé le produit, pas que l'installation fonctionne : une vérification sur machine propre reste utile avant publication.

## Open questions
- Décisions de fond encore à arbitrer lot par lot : 3.7 (imitation d'utilisateur), 3.8 (jeton de `/setup`), 4.5 (recherche FULLTEXT), 8.11 (cache des images), 2.6 (sort de `first_read_event`), 5.2 et 5.6 (valeurs par défaut Docker).

## Out of scope
- 4.4 (table de compteurs de non-lus) : optimisation prématurée.
- 5.9 (sauvegardes automatisées) : dépend de l'hébergeur ; une commande documentée suffit.
- 5.10 (durcissement systemd) : installation hors Docker, sans retour d'utilisateurs.
- 6.3 (SHA des actions, environnement `production`) : Dependabot couvre les mises à jour, l'environnement se crée à la main.
- Lot 9 : `new \DateTimeImmutable()` dans les entités et exceptions techniques génériques.
