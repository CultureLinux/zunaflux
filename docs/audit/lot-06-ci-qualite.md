# Lot 6 : CI, analyse statique et supply chain

**Priorité :** Moyenne · **Effort :** M · **Migration :** non

**Objectif :** donner à la CI une couverture réelle (PHPStan sur tout `src/`, style, schéma) et détecter les vulnérabilités de dépendances et d'image avant publication.

## Constats et actions

| ID | Priorité | Constat | Preuve | Action | Effort |
|---|---|---|---|---|---|
| 6.1 | Haute | PHPStan n'analyse que 2 fichiers sur 101. | `phpstan.neon.dist` (`paths:` limité à `ArticleStateService.php` et `MarkSubscriptionRead.php`) ; `ci.yml:70` | Passer à `paths: [src]`, générer une baseline (`--generate-baseline`), puis monter progressivement du niveau 6 vers 8. Évaluer `phpstan/phpstan-symfony` et `phpstan/phpstan-doctrine` : ce sont de nouvelles dépendances de développement, à valider. | M |
| 6.2 | Haute | Aucun contrôle de vulnérabilités : pas de `composer audit`, pas de scan d'image, pas de SBOM ni de provenance. | `ci.yml`, `publish-image.yml:482-490` | Ajouter `php85 /usr/bin/composer audit --locked` dans le job PHP, et Trivy (ou Grype) sur l'image `ci-$SHA` avec échec sur HIGH/CRITICAL corrigeables. Activer `sbom: true` et `provenance: mode=max`. | M |
| 6.3 | Moyenne | Les actions sont épinglées par tag et non par SHA, alors que le job de publication manipule `DOCKERHUB_TOKEN`. | `ci.yml`, `publish-image.yml` | Épingler par SHA avec la version en commentaire. Ajouter `environment: production` (relecteurs requis) sur `build-and-push`. | S |
| 6.4 | Moyenne | Aucune mise à jour automatisée des dépendances. | `.github/` ne contient ni `dependabot.yml` ni `renovate.json` | Configurer Dependabot hebdomadaire pour `composer`, `npm`, `docker` et `github-actions`, avec regroupement des mises à jour mineures. Les montées de version restent validées par le développeur (cf. AGENTS.md). | S |
| 6.5 | Moyenne | Pas de contrôle de style. 113 lignes de `src/` dépassent 200 caractères. | `awk 'length>200'` sur `src/` | Ajouter PHP-CS-Fixer (`@Symfony`, `@PSR12`) en mode `--dry-run` en CI, après le reformatage du lot 9. Nouvelle dépendance de développement, à valider. | S |
| 6.6 | Moyenne | `doctrine:schema:validate` n'est pas exécuté en CI, ce qui laisse passer la dérive des index (lot 4, point 4.6). | `ci.yml` | Ajouter `php85 bin/console doctrine:schema:validate` après les migrations de test. | S |
| 6.7 | Basse | Pas de cache Composer. La CI n'est pas déclenchée sur `develop` en push. | `ci.yml:5-7` | `actions/cache` sur le cache Composer (clé : `composer.lock`). Ajouter `develop` aux branches `push`. | S |
| 6.8 | Basse | Pas de lint des scripts shell. | `docker/*.sh`, `tests/Container/*.sh` | Ajouter `shellcheck` en CI. | S |

## Tests et gates à ajouter

- Job `php` : `composer audit`, PHPStan sur `src/`, `schema:validate`, PHP-CS-Fixer en `--dry-run`.
- Job `container` : Trivy sur l'image construite.
- Job `config` : shellcheck.
- Les nouveaux jobs doivent être intégrés au job agrégé `quality`.

## Validation Linux

Commandes **sans effet sur les données** :

```bash
php85 /usr/bin/composer audit --locked
php85 vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=1G
APP_ENV=test php85 bin/console doctrine:schema:validate --skip-sync   # mapping seul, sans base
sh tests/Container/ci-config.sh
sh tests/Container/workflow-config.sh
sh tests/Container/publish-workflow-config.sh
```

Les tests `tests/Container/*workflow*.sh` vérifient la structure des workflows. Les mettre à jour si les jobs ou les actions changent.

## Risques et critères d'acceptation

**Risques :**
- La baseline PHPStan masque la dette existante. Interdire de l'agrandir : vérifier en revue que le nombre d'erreurs ne fait que diminuer.
- Trivy peut bloquer une publication à cause d'une CVE de l'image de base sans correctif. Prévoir `--ignore-unfixed`.

**Critères d'acceptation :**
- PHPStan couvre `src/`.
- `composer audit` tourne en CI.
- L'image est scannée avant publication.
- Les actions sont épinglées par SHA.
- Dependabot est actif.

## Statut d'avancement

*Mis à jour le 20 novembre 2026. Tests PHP et `tests/Container/*workflow*.sh` exécutés en local ; **le workflow GitHub Actions n'a pas été exécuté** (première vraie exécution : au prochain push ou à la prochaine pull request). En attente du retour de validation du développeur.*

| ID | État | Note |
|---|---|---|
| 6.1 | Partiel | PHPStan analyse `src/` (niveau 6) **sans baseline** : les 41 erreurs initiales sont corrigées (voir ci-dessous), en CI avec 1 Go de mémoire. **Non fait :** montée de niveau, `phpstan-symfony` et `phpstan-doctrine` (nouvelles dépendances à valider). |
| 6.2 | Partiel | `composer audit --locked` ajouté au job `php` (aucune vulnérabilité trouvée en local). **Non fait :** scan d'image Trivy/Grype, SBOM, provenance. |
| 6.3 | **Non traité** | Épinglage des actions par SHA et `environment: production` : demande de résoudre les SHA (réseau) et de créer l'environnement GitHub. |
| 6.4 | Appliqué | `.github/dependabot.yml` : composer, npm, docker, github-actions, hebdomadaire, mises à jour mineures groupées. À activer côté dépôt GitHub. |
| 6.5 | Appliqué | PHP-CS-Fixer ajouté (accepté le 20 novembre 2026) avec une étape de contrôle en CI, après le reformatage du lot 9.A. |
| 6.6 | **Non traité** | `doctrine:schema:validate` échoue encore (dérive, voir 4.6) : l'ajouter à la CI la casserait. |
| 6.7 | Appliqué | Cache Composer (clé `composer.lock`) ; `develop` ajouté aux branches de `push`. |
| 6.8 | **Non traité** | `shellcheck` en CI : outil absent ici, non testable ; les scripts pourraient déclencher des alertes à traiter d'abord. |

**Traitement des 41 erreurs initiales du niveau 6 :**
- 7 types manquants (`array<…>`) corrigés par des annotations.
- `AdminController` : comparaison toujours vraie supprimée ; `SubscriptionController` : `??` inutile retiré ; `UserExport` : `getRepository()` appelé directement (classe littérale) au lieu d'une fonction anonyme ; `createUser` recevait un `$logger` absent (bug que j'avais introduit au lot 3, corrigé, test `AdminUserCreationTest`).
- 30 erreurs sur les entités Doctrine (`property.onlyWritten`, `property.unused`, `property.unusedType`) : exemption **limitée à `src/Entity/*`** dans `phpstan.neon.dist`, car Doctrine lit et écrit ces propriétés par réflexion. À supprimer si `phpstan-doctrine` est adopté. Ce n'est pas une correction mais un choix de configuration : à confirmer.
