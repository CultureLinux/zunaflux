# Lot 8 — État de la validation du 16 novembre 2026

Le lot reste ouvert jusqu’au retour explicite du développeur. Le [guide](restoration.md) donne le protocole, les résultats attendus, les diagnostics et les commandes d’exploitation.

## Périmètre livré

Les scripts d’exercice, les fixtures synthétiques, la transmission des variables privées dans Compose et l’intégration du parcours à la CI existaient au début de cette reprise. Les compléments portent sur :

- `tests/Restoration/run.sh` : résolution de l’endpoint Docker en respectant la priorité du contexte explicite, refus du distant, endpoint figé pour tout le parcours, arrêt immédiat si une inspection d’image échoue, absence de chargement implicite de `.env`, contrôle des réseaux après nettoyage.
- `tests/Container/restoration-preflight.sh` : le vrai lanceur est exécuté avec un substitut du CLI Docker ; un `DOCKER_HOST` distant, un contexte distant prioritaire et une image absente doivent être refusés avant toute opération Compose.
- `.github/workflows/ci.yml` : ajout du contrôle de refus avant la construction Docker. Le parcours complet et l’archivage du rapport étaient déjà présents.
- `docs/restoration.md`, `docs/docker.md`, `docs/lot-8-restoration-plan.md`, `docs/lots-amelioration.md` : procédure cohérente de sauvegarde/restauration, ordre de mise à jour corrigé et approbation des migrations avant `up`.
- `README.md`, `docs/pwa-web-push.md` : confirmation de la validation Web Push donnée par le développeur. Aucun résultat sur un appareil précis n’a été inventé.

Aucune migration, nouvelle dépendance, nouvelle variable de production ou montée de version. Les variables `RESTORE_TEST_*` et `ZUNAFLUX_RESTORE_EXERCISE` sont réservées à l’outillage synthétique existant. Le comportement de migration automatique du Compose de production est conservé. Aucun déploiement ni redémarrage de service de production.

## Contrôles réellement exécutés

L’environnement réel de cette session est Linux/Bash, avec PHP 8.5.12, Docker et Composer déjà disponibles, contrairement à la description Windows historique d’AGENTS.md. Aucun test n’a été exécuté sur Windows et aucun outil n’y a été installé.

- Test de refus : échec avant correction, succès après correction.
- Syntaxe PHP de la fixture et syntaxe shell des lanceurs : contrôlées.
- Rendu du Compose et transmission des deux variables privées : contrôlés avec valeurs synthétiques.
- YAML de l’exercice et de la CI analysé avec Symfony Yaml ; contrats CI et 25 scénarios de la porte de qualité sans échec.
- PHPStan sur le périmètre configuré : aucune erreur. La première tentative en sandbox a été bloquée par son socket local ; exécution hors sandbox autorisée ensuite.
- PHPUnit, répertoire `tests/Unit` : **102 tests, 298 assertions, aucun échec, 4 notices**. Elles concernent les deux tests de publication de `MastodonOAuthServiceTest` et les deux tests de `PushDeliveryTest`, pour des mocks sans attentes. Pas de modification hors périmètre de ces tests.

La suite PHP fonctionnelle complète, les parcours navigateur et les services de production n’ont pas été relancés pendant cette reprise. Ne pas confondre le succès unitaire et l’absence de régression de toutes les fonctionnalités.

## Exercice réel : première tentative

Après autorisation Docker, commande `sh tests/Restoration/run.sh clinuxfr/zunaflux:test`.

- Image applicative `sha256:68245a738a2446a683864680c83515ef02ad76c74ac4ec23a4299d7f7be84b5a`, datée du 2 novembre 2026.
- Image MariaDB `sha256:d769e37960821dfdcf096954f6e7ac89f747e116c1bba98e48edd265dfc7deae`.
- **Échec**, code 1 à `source_fixture` : l’image applique seulement 22 migrations jusqu’à `Version20261027110000`, alors que le dépôt en contient 25, jusqu’à `Version20261115120000`. Le script de fixture signale une `Error` sans exposer ses détails sensibles. L’image n’est pas adaptée à la qualification du code actuel ; le détail exact de l’exception n’a pas été journalisé.
- Rapport local ignoré : `var/restoration/zunaflux-restore-26a239ed7391775d.json`, `passed: false`, `cleanup_ok: true`.
- Contrôle Docker indépendant : aucun conteneur, volume ou réseau ne reste pour le projet de cette tentative.

Une construction locale `docker build --tag clinuxfr/zunaflux:restoration-lot8 .` a été autorisée pour reprendre le parcours avec le code courant. Elle utilise le Dockerfile et le verrou Composer existants, sans publication, sans modification des dépendances du dépôt et sans installation sur l’hôte.

## Exercice réel : seconde tentative réussie

Construction terminée sans erreur, avec compilation Tailwind et AssetMapper. Après autorisation, commande `sh tests/Restoration/run.sh clinuxfr/zunaflux:restoration-lot8`, code de sortie **0**.

- Image applicative `sha256:b860855aec3cb693a50e27ccaa4321a8706239be386136c6136ada344943ab90` ; même image MariaDB que la première tentative.
- **25 migrations**, jusqu’à `Version20261115120000`, appliquées sur la source synthétique neuve.
- Source et cible restaurée vérifiées : compte et mot de passe, identités UUID, article favori/à-lire/non lu, migrations, média et configuration privée, déchiffrement Mastodon et cohérence VAPID ; clés absentes et fausses refusées.
- Une collecte mise en file, événement outbox relayé, notification consommée et acquittée, un article conservé dans le digest et heartbeat récent. Aucun envoi Web Push ; aucune requête de collecte externe.
- Mutation de la cible observée, restauration dans une troisième base et retour au schéma/données/média d’origine contrôlés.
- Rapport local ignoré `var/restoration/zunaflux-restore-30794ad17c076c4a.json` : `passed: true`, `last_phase: complete`, `cleanup_ok: true`.
- Contrôle indépendant après sortie : aucun conteneur, volume ou réseau du projet `zunaflux-restore-30794ad17c076c4a` ne reste.

L’image locale de test et le cache de construction sont conservés ; leur suppression n’a pas été demandée. Aucun service de production n’a été arrêté, migré ou redémarré. L’acceptation finale du développeur et l’exécution GitHub Actions restent en attente.

## Commandes de reproduction

Vérifications locales possibles sur Windows sans outils applicatifs :

```powershell
git diff --check
git status --short
Get-Content tests/Restoration/run.sh
Get-Content tests/Restoration/compose.yaml
Get-Content tests/Restoration/fixture.php
```

Validation Linux sans écriture en base, avec les dépendances déjà installées :

```bash
php85 -v
php85 -l tests/Restoration/fixture.php
sh -n tests/Restoration/run.sh
sh -n tests/Restoration/php85
sh -n tests/Container/restoration-preflight.sh
sh tests/Container/restoration-preflight.sh
sh tests/Container/compose-config.sh
sh tests/Container/compose-private-config.sh
php85 vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=512M
php85 vendor/bin/phpunit tests/Unit --display-phpunit-notices
git diff --check
```

Attendu : codes 0, aucune erreur d’analyse ou de syntaxe ; les quatre notices PHPUnit connues restent signalées. Les tests unitaires et PHPStan écrivent leurs caches/rapports locaux, sans accès à une base applicative. Si une commande échoue, conserver le lot ouvert, relever le contrôle concerné et appliquer le diagnostic du [guide](restoration.md).

**Après approbation humaine**, opérations modifiant l’environnement de développement et les données de test :

```bash
# Uniquement si les dépendances de développement manquent ; utilise le verrou.
php85 /usr/bin/composer install --no-interaction --prefer-dist
# Construit également Tailwind et AssetMapper dans l’image, sans npm requis.
docker build --tag clinuxfr/zunaflux:restoration-lot8 .
# Migre/restaure uniquement les trois bases synthétiques de l’exercice.
sh tests/Restoration/run.sh clinuxfr/zunaflux:restoration-lot8
```

Attendu : installation et construction sans erreur, exercice terminé avec `passed: true`, `last_phase: complete`, `cleanup_ok: true`. Aucune migration de production à appliquer pour ce complément ; les commandes de mise à jour réelle sont séparées dans le guide et restent sous responsabilité du développeur.

## Acceptation et limites

Le succès exige la restauration SQL/médias/clés, le rejet d’une clé erronée, la reprise interne de l’outbox/digest/worker et le retour à l’état sauvegardé dans une troisième base. Il ne prouve pas la restauration d’une sauvegarde réelle, la durée de reprise à grande échelle ou l’annulation d’envois externes. Les écritures postérieures au point de sauvegarde sont perdues lors d’un retour arrière. Aucun envoi Web Push ni collecte externe dans ce parcours.

Aucun indexage, commit, push, tag, changement de branche ou publication effectué. L’état final de l’arbre de travail est relevé dans le compte-rendu utilisateur ; seuls les fichiers listés ci-dessus et ce rapport sont concernés.
