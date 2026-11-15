# Lots d’amélioration à traiter

Date de l’analyse : 15 novembre 2026.

Statut : propositions à traiter, sans validation applicative. Cette analyse statique porte sur les workflows, la configuration, plusieurs services métier, les tests et la documentation ; elle ne constitue pas un audit exhaustif.

Ordre proposé : **1 → 2 → 3 → 4 → 5 → 6 → 7 → 8**.

## Règles de traitement

- [ ] Avant chaque lot, vérifier l’état réel du dépôt et les instructions applicables.
- [ ] Définir un périmètre ciblé et préserver les décisions fonctionnelles validées.
- [ ] Fournir les commandes Linux adaptées aux fichiers réellement présents, avec `php85` et `php85 /usr/bin/composer`.
- [ ] Séparer les contrôles sans effet sur les données des opérations modifiant la base ou l’environnement, soumises à validation humaine.
- [ ] Documenter les fichiers modifiés, dépendances, migrations, variables d’environnement, risques et résultats réellement obtenus.
- [ ] Ne clôturer un lot qu’après retour explicite du développeur sur les validations Linux.

Aucun commit, push, tag ou déploiement ne doit être effectué par l’agent.

## Point de cadrage — Référence Symfony

- [ ] Clarifier la référence technique : `AGENTS.md` mentionne Symfony LTS, alors que `composer.json` et le README ciblent Symfony 8.1.
- [ ] Harmoniser les documents après décision du développeur, sans changer les dépendances implicitement.

## Lot 1 — Supprimer les doubles validations et améliorer les diagnostics CI

**Priorité : haute — Statut : implémenté, validation Linux/GitHub en attente**

### Constats

Un tag `v*` déclenche directement `.github/workflows/ci.yml`, puis `.github/workflows/publish-image.yml` appelle à nouveau cette CI. Dans le job `quality`, un échec PHPUnit empêche les tests JavaScript et le build des assets de s’exécuter.

### Travaux

- [x] Conserver la CI directe pour les PR et `main`, et passer par le workflow de publication pour les tags.
- [x] Séparer les validations PHP, JavaScript et assets pour obtenir leurs résultats indépendamment.
- [x] Conserver les rapports PHPUnit et les journaux utiles en cas d’échec, sans secrets.
- [x] Ajouter des limites de durée.
- [x] Annuler les validations obsolètes d’une PR sans interrompre arbitrairement une publication.

### Critères d’acceptation

- [ ] Une seule chaîne de validation est exécutée par tag.
- [ ] Un échec PHP laisse visibles les résultats JavaScript.
- [ ] Aucun contrôle obligatoire en échec ne permet une publication.

## Lot 2 — Encadrer les versions publiées

**Priorité : haute — Statut : implémenté, validation Linux/GitHub en attente — Dépendance : lot 1**

### Constats

Le filtre `v*` accepte aussi un nom comme `vtest`. Le tag Docker SHA étant toujours généré, ce déclencheur ne garantit pas une version SemVer. La publication sur `main` et le déclenchement manuel ont déjà été retirés du workflow examiné.

### Travaux

- [x] Valider le format de version avant toute connexion au registre.
- [x] Définir les règles pour versions stables, préversions et anciennes branches de maintenance.
- [x] Tester la génération des tags Docker, notamment `latest`.
- [x] Documenter la procédure de version et les critères de publication.

### Critères d’acceptation

- [ ] `v1.2.3` est accepté.
- [ ] Un tag invalide est rejeté avant publication.
- [ ] Une préversion ne déplace pas `latest`.
- [ ] La politique de republication d’une ancienne version est explicite, y compris son effet sur les alias Docker.

## Lot 3 — Rendre les échecs Messenger récupérables

**Priorité : haute — Statut : à traiter**

### Constats

`config/packages/messenger.yaml` ne configure pas de transport d’échec. Dans `src/MessageHandler/CollectFeedHandler.php`, un échec de mise en file d’une notification est journalisé puis absorbé.

### Travaux

- [ ] Configurer une file d’échec et une politique de nouvelles tentatives explicite.
- [ ] Ajouter une procédure d’inspection et de rejeu.
- [ ] Garantir la récupération d’une notification dont les articles ont été enregistrés mais dont la mise en file a échoué.
- [ ] Tester les interruptions et le rejeu sans duplication métier.

### Critères d’acceptation

- [ ] Une panne temporaire ne fait pas disparaître silencieusement une notification.
- [ ] Les échecs définitifs restent inspectables et rejouables.
- [ ] Les limites de garantie de livraison sont documentées, notamment pour les envois Web Push externes.

**Impact à déterminer :** une migration peut être nécessaire selon la solution retenue pour assurer la livraison.

## Lot 4 — Consolider les tests métier et temporels

**Priorité : haute — Statut : à traiter**

### Constats

L’incident SQL sur l’alias `cursor` a montré l’utilité des tests MariaDB réels. Les tests de notifications mélangent dates fixes et heure courante ; `NotificationTraceTest` utilise notamment `+2 hours`.

### Travaux

- [ ] Rendre l’heure contrôlable dans les tests de notifications et de rétention.
- [ ] Couvrir changements de jour, fuseaux horaires, heures silencieuses et changements d’heure.
- [ ] Ajouter des scénarios ciblés de concurrence et d’isolation entre comptes.
- [ ] Vérifier les migrations sur base vide et depuis un schéma de version précédente.
- [ ] Confirmer la validation Linux du correctif SQL et de ses assertions de curseur.

### Critères d’acceptation

- [ ] Les tests donnent le même résultat quelle que soit l’heure d’exécution.
- [ ] Les erreurs SQL et les régressions de curseur sont détectées sous MariaDB 10.11.
- [ ] Les tests de migration utilisent exclusivement des bases isolées dédiées.

## Lot 5 — Tester les interactions dans un navigateur

**Priorité : moyenne — Statut : à traiter**

### Constats

Plusieurs tests JavaScript, notamment `tests/JavaScript/reading-layout.test.cjs`, vérifient des chaînes ou expressions régulières dans les sources. Ces assertions ne prouvent pas que les interactions fonctionnent dans un navigateur.

### Travaux

- [ ] Ajouter des parcours navigateur : ouvrir un article, marquer lu, filtrer, changer de source et enregistrer une préférence.
- [ ] Couvrir mobile, bureau et navigation clavier.
- [ ] Remplacer progressivement les assertions de structure par des assertions de comportement.

### Critères d’acceptation

- [ ] Une interaction réellement cassée fait échouer un test.
- [ ] Un changement de présentation sans changement fonctionnel ne casse pas arbitrairement la suite.
- [ ] Les parcours sont reproductibles avec des données de test isolées.

**Dépendance à sélectionner :** outil de test navigateur, avec justification de son utilité et de son coût d’exécution.

## Lot 6 — Mesurer les performances avant de refactoriser

**Priorité : moyenne — Statut : à traiter**

### Constats

`CollectFeedHandler::logNotQueued()` parcourt les abonnements et charge les appareils pour chaque utilisateur. Il s’agit d’un point à mesurer lorsque le nombre d’abonnés augmente, pas d’une régression de performance déjà démontrée.

### Travaux

- [ ] Mesurer le nombre de requêtes et le temps de collecte sur un jeu de données représentatif.
- [ ] Examiner les requêtes de lecture, recherche et purge avec les outils MariaDB.
- [ ] Réduire les chargements répétitifs et ajuster les index uniquement sur preuve.
- [ ] Fixer des budgets de performance reproductibles.

### Critères d’acceptation

- [ ] Les mesures avant/après et les caractéristiques du jeu de données sont disponibles.
- [ ] Une réduction du coût est démontrée sur les parcours concernés.
- [ ] Les tests ne montrent aucune régression fonctionnelle.

**Impact à déterminer :** migrations d’index éventuelles, compatibles MariaDB 10.11.

## Lot 7 — Simplifier la maintenance du code

**Priorité : moyenne — Statut : à traiter — Prérequis conseillé : lot 5 pour le lecteur**

### Constats

Lors de l’analyse, `assets/controllers/article-list.js` compte 664 lignes ; les contrôleurs d’articles et d’abonnements concentrent plusieurs centaines de lignes. Le manifeste Composer ne déclare pas PHPStan. La taille seule n’est pas un défaut : les extractions doivent répondre à une difficulté concrète de maintenance ou de test.

### Travaux

- [ ] Introduire progressivement l’analyse statique PHP.
- [ ] Extraire les responsabilités du lecteur JavaScript après couverture des interactions.
- [ ] Déplacer les opérations métier des contrôleurs vers des services ciblés lorsque cela améliore leur testabilité.
- [ ] Harmoniser les blocs de code très condensés dans le périmètre traité.
- [ ] Découper les changements structurels en petites livraisons vérifiables.

### Critères d’acceptation

- [ ] Le comportement est préservé.
- [ ] Les responsabilités extraites sont plus faciles à tester.
- [ ] L’analyse statique est intégrée à la CI avec un périmètre et une progression explicites.

**Dépendance à prévoir :** analyseur statique de développement, à justifier avant ajout.

## Lot 8 — Vérifier la restauration et clarifier l’exploitation

**Priorité : moyenne — Statut : à traiter**

### Constats

Les sauvegardes sont documentées, mais leur restauration reste une procédure manuelle. Compose applique les migrations au démarrage via le service `migrations`, ce que la documentation signale.

### Travaux

- [ ] Préparer un exercice reproductible de restauration sur environnement isolé.
- [ ] Vérifier la base, les médias et la configuration indispensable au chiffrement.
- [ ] Contrôler l’activité des workers, du collecteur et des notifications.
- [ ] Clarifier l’étape d’approbation des migrations avant une mise à jour.

### Critères d’acceptation

- [ ] La restauration est démontrée dans un environnement distinct de la production.
- [ ] Les données restaurées sont lisibles et les traitements opérationnels.
- [ ] La procédure de retour arrière est vérifiée.
- [ ] Les opérations modifiant données et environnement sont clairement identifiées et approuvées par le développeur.

## Suivi des validations

Pour chaque lot, consigner ici ou dans son compte-rendu : date, fichiers modifiés, commandes exactes, résultats, limites, risques restants et retour du développeur. Ne pas considérer les cases de travaux cochées comme une preuve de validation Linux.

| Lot | Implémentation | Validation Linux | Retour développeur |
| --- | --- | --- | --- |
| 1 | Implémenté — [compte-rendu](lot-1-ci-validation.md) | Non exécutée | En attente |
| 2 | Implémenté — [compte-rendu](lot-2-versions-validation.md) | Non exécutée par le développeur | En attente |
| 3 | À traiter | Non exécutée | En attente |
| 4 | À traiter | Non exécutée | En attente |
| 5 | À traiter | Non exécutée | En attente |
| 6 | À traiter | Non exécutée | En attente |
| 7 | À traiter | Non exécutée | En attente |
| 8 | À traiter | Non exécutée | En attente |
