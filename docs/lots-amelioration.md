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

**Priorité : haute — Statut : implémenté, validation PHP/MariaDB/Linux en attente**

### Constats

`config/packages/messenger.yaml` ne configure pas de transport d’échec. Dans `src/MessageHandler/CollectFeedHandler.php`, un échec de mise en file d’une notification est journalisé puis absorbé.

### Travaux

- [x] Configurer une file d’échec et une politique de nouvelles tentatives explicite.
- [x] Ajouter une procédure d’inspection et de rejeu.
- [x] Garantir la récupération d’une notification dont les articles ont été enregistrés mais dont la mise en file a échoué.
- [ ] Exécuter sur MariaDB les tests ajoutés pour les interruptions, la concurrence et le rejeu sans duplication métier.

### Critères d’acceptation

- [ ] Une panne temporaire ne fait pas disparaître silencieusement une notification.
- [ ] Les échecs définitifs restent inspectables et rejouables.
- [ ] Les limites de garantie de livraison sont documentées, notamment pour les envois Web Push externes.

**Impact :** migration `Version20261115120000` pour une outbox transactionnelle ; aucune nouvelle variable applicative ni dépendance. Voir la [procédure de reprise](messenger-recovery.md) et le [compte-rendu](lot-3-messenger-validation.md).

## Lot 4 — Consolider les tests métier et temporels

**Priorité : haute — Statut : sources préparées, validation PHP/MariaDB/Linux en attente**

### Constats

L’incident SQL sur l’alias `cursor` a montré l’utilité des tests MariaDB réels. Les tests de notifications mélangent dates fixes et heure courante ; `NotificationTraceTest` utilise notamment `+2 hours`.

### Travaux

- [x] Rendre l’heure contrôlable dans les tests de notifications et de rétention.
- [x] Couvrir changements de jour, fuseaux horaires, heures silencieuses et changements d’heure.
- [x] Ajouter des scénarios ciblés de concurrence et d’isolation entre comptes.
- [ ] Exécuter les deux parcours ajoutés en CI : base vide et mise à niveau depuis `Version20261114120000`, sur bases isolées dédiées.
- [ ] Confirmer la validation Linux du correctif SQL et de ses assertions de curseur.

### Critères d’acceptation

- [ ] Les tests donnent le même résultat quelle que soit l’heure d’exécution.
- [ ] Les erreurs SQL et les régressions de curseur sont détectées sous MariaDB 10.11.
- [ ] Les tests de migration utilisent exclusivement des bases isolées dédiées.

Les cases de sources préparées ne prouvent pas l’exécution des tests. Voir le [guide MariaDB](tests-mariadb.md) et le [compte-rendu du lot 4](lot-4-tests-validation.md). Aucune nouvelle migration de production ni dépendance applicative.

## Lot 5 — Tester les interactions dans un navigateur

**Priorité : moyenne — Statut : 14 parcours Linux et preuve négative exécutés pendant 7B, acceptation finale en attente**

### Constats

Plusieurs tests JavaScript, notamment `tests/JavaScript/reading-layout.test.cjs`, vérifient des chaînes ou expressions régulières dans les sources. Ces assertions ne prouvent pas que les interactions fonctionnent dans un navigateur.

### Travaux

- [x] Ajouter des parcours navigateur : ouvrir un article, marquer lu, filtrer, changer de source et enregistrer une préférence.
- [x] Couvrir mobile, bureau et navigation clavier.
- [x] Remplacer progressivement les assertions de structure par des assertions de comportement.

### Critères d’acceptation

- [x] Une interaction réellement cassée fait échouer un test : listener d’ouverture retiré temporairement, échec observé puis restauration et 14 parcours verts.
- [ ] Un changement de présentation sans changement fonctionnel ne casse pas arbitrairement la suite.
- [x] Les parcours sont reproductibles avec des données de test isolées : 14 succès avant/après extraction et zéro fixture navigateur restante.

**Dépendance retenue :** Playwright Test `1.65.1`, Chromium, sept scénarios sur deux profils, un worker. Exécution Linux désormais réalisée pendant 7B avec Node 24 déjà présent, paquets verrouillés et Chromium headless ; 14 succès avant/après et après restauration de la preuve négative. Base de performance réutilisée sur demande explicite du développeur, sans parallélisme avec le banc. Le [compte-rendu 7B](lot-7b-reader-validation.md) actualise les résultats du [compte-rendu initial](lot-5-browser-validation.md). Voir aussi le [guide navigateur](browser-tests.md).

## Lot 6 — Mesurer les performances avant de refactoriser

**Priorité : moyenne — Statut : optimisation de collecte mesurée, budget temporel à un abonné et acceptation en attente**

### Constats

`CollectFeedHandler::logNotQueued()` parcourt les abonnements et charge les appareils pour chaque utilisateur. Il s’agit d’un point à mesurer lorsque le nombre d’abonnés augmente, pas d’une régression de performance déjà démontrée.

### Travaux

- [x] Mesurer le nombre de requêtes et le temps de collecte sur un jeu contrôlé : 1/10/100 abonnés, puis 10 000 articles pour le lecteur ; représentativité de production à confirmer.
- [x] Examiner les requêtes de lecture, recherche et purge avec EXPLAIN MariaDB sur le jeu contrôlé.
- [x] Réduire les chargements répétitifs et ajuster les index uniquement sur preuve : traces de collecte en une projection, aucun index supplémentaire justifié.
- [ ] Fixer des budgets de performance reproductibles.

### Critères d’acceptation

- [x] Les mesures avant/après et les caractéristiques du jeu de données sont disponibles.
- [x] Une réduction du coût est démontrée sur les parcours concernés : collecte 100 abonnés, 208 → 8 instructions SQL, temps médian réduit de 94 à 95 %.
- [x] Les tests ne montrent aucune régression fonctionnelle : 184 tests, 819 assertions, aucun échec, 5 notices préexistantes.

**Impact à déterminer :** migrations d’index éventuelles, compatibles MariaDB 10.11.

**Preuves :** trois paires avant/après avec EXPLAIN tabulaire et rollback confirmé. Collecte désormais à 8 instructions SQL pour 1/10/100 abonnés, contre 10/28/208. Gain temporel à 100 abonnés de 94 à 95 %. Suite PHPUnit : 184 tests, 819 assertions, 5 notices, aucun échec. Budget de temps à un abonné et acceptation finale encore ouverts. Voir le [protocole](performance-tests.md), le [plan](lot-6-performance-plan.md) et le [compte-rendu](lot-6-performance-validation.md).

## Lot 7 — Simplifier la maintenance du code

**Priorité : moyenne — Statut : 7A et 7B implémentés, vérifications Linux obtenues ; CI et acceptation finale en attente**

### Constats

Lors de l’analyse, `assets/controllers/article-list.js` compte 664 lignes ; les contrôleurs d’articles et d’abonnements concentrent plusieurs centaines de lignes. Le manifeste Composer ne déclare pas PHPStan. La taille seule n’est pas un défaut : les extractions doivent répondre à une difficulté concrète de maintenance ou de test.

### Travaux

- [x] Introduire progressivement l’analyse statique PHP : PHPStan niveau 6 sur deux services, obligatoire dans le job PHP de la CI (exécution GitHub en attente).
- [x] Extraire les responsabilités du lecteur JavaScript après couverture : module des vues/sources, import map Symfony et 14 parcours navigateur avant/après.
- [x] Déplacer les opérations métier des contrôleurs vers des services ciblés : marquage d’un abonnement extrait dans `MarkSubscriptionRead`, avec test direct.
- [x] Harmoniser les blocs de code très condensés dans le périmètre traité : deux routes de marquage et service extrait.
- [x] Découper les changements structurels en petites livraisons vérifiables : 7A PHP, puis 7B JavaScript après validation navigateur.

### Critères d’acceptation

- [x] Le comportement du périmètre extrait est préservé : preuves PHP et navigateur avant/après.
- [x] Les responsabilités extraites sont testées directement : service PHP et module ES des vues/sources.
- [x] L’analyse statique est intégrée au job PHP de la CI, niveau 6 sur deux services sans baseline ; exécution GitHub et élargissement progressif encore attendus.

**Dépendance retenue pour 7A :** `phpstan/phpstan`, développement uniquement, pour vérifier les contrats typés des services extraits ; périmètre initial limité et progression documentée.

**Livraison 7A :** PHPStan `2.3.6` en développement, deux services au niveau 6 sans baseline. Routes de marquage caractérisées avant/après extraction (15 tests / 94 assertions), suite finale 186 tests / 839 assertions sans échec et 5 notices préexistantes. Aucune migration. L’installation a signalé 16 avis sur 11 paquets Symfony déjà verrouillés ; aucune mise à jour hors périmètre. Le [plan](lot-7-maintenance-plan.md) et le [compte-rendu](lot-7-maintenance-validation.md) détaillent les résultats et limites. Extraction JavaScript en attente de validation des interactions du lot 5 ; Node 24 requis, Node 20 disponible sur l’hôte.

**Livraison 7B :** Node 24 trouvé sur l’hôte, référence navigateur obtenue après correction de l’amorçage de test. Vues/sources extraites dans un module ES, import map pour les assets compilés. 57 tests JS, 14 parcours Chromium et 187 tests PHP / 843 assertions sans échec (5 notices connues). Contrôle négatif d’ouverture observé et restauré. Aucun manifeste de dépendances modifié ni migration. Voir le [plan](lot-7b-reader-plan.md) et le [compte-rendu](lot-7b-reader-validation.md) ; cette livraison lève le prérequis local qui bloquait 7B lors de 7A.

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
| 3 | Sources et tests préparés — [compte-rendu](lot-3-messenger-validation.md) | PHP/MariaDB non exécutés | En attente |
| 4 | Sources et tests préparés — [compte-rendu](lot-4-tests-validation.md) | PHP/MariaDB non exécutés | En attente |
| 5 | Parcours exécutés pendant [7B](lot-7b-reader-validation.md) | 14 succès, preuve négative puis restauration, fixtures nettoyées | En attente |
| 6 | Collecte optimisée — [compte-rendu](lot-6-performance-validation.md) | Trois paires avant/après ; collecte 100 abonnés : 208 → 8 SQL ; 184 tests sans échec, 5 notices ; budget temporel à 1 abonné non validé | En attente |
| 7 | 7A PHP et [7B lecteur](lot-7b-reader-validation.md) extraits | 187 tests PHP, 57 JS, 14 Chromium, analyse sans erreur ; 5 notices connues ; CI GitHub à confirmer | En attente |
| 8 | À traiter | Non exécutée | En attente |
