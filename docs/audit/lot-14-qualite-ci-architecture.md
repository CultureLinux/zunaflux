# Lot 14 : qualité, CI et architecture résiduelle

**Priorité :** Basse · **Effort :** M à L · **Migration :** non

**Objectif :** durcir l'analyse statique et la CI, combler les derniers trous de tests et finir l'extraction de logique des contrôleurs.

| ID d'origine | Point | Décision à arbitrer | Recommandation |
|---|---|---|---|
| 6.1 | `phpstan-symfony` et `phpstan-doctrine` (puis suppression de l'exemption des entités), montée de niveau | Nouvelles dépendances de développement | Accepter, niveau 7 puis 8 par paliers |
| 6.8 | `shellcheck` en CI | Alertes à traiter sur les scripts existants | Corriger d'abord, puis bloquer |
| — | Actions Docker de `publish-image.yml` encore en Node 20 | Montées de version majeures (qemu v4, buildx v4, metadata v6, login v4, build-push v7) | Les passer et vérifier par une publication de test |
| 9 | `AdminController`, `PreferenceController`, `SecurityController` : extraire la logique métier dans `src/Application` | Périmètre | Comptes (création, désactivation, imitation) d'abord |
| 9 | `getRepository()` dans `Application/**` | Aucune | Repositories injectés |
| 9.D | Tests manquants : succès de `DiscoverFeedHandler`, échecs des commandes de relais et d'envoi, quatre routes d'administration annexes | Rendre les classes `RelayNotificationOutbox` doublables | Interface étroite |
| 3.1 | Test du cookie `Secure` | (voir lot 10) | — |

## Validation
Suite complète, PHPStan, PHP-CS-Fixer, CI GitHub.

## Critères d'acceptation
- PHPStan au niveau 8 sans exemption.
- Les contrôleurs n'appellent plus `getRepository()`.
- Les tests de 9.D sont écrits.
