# Lot 13 : front, accessibilité et PWA

**Priorité :** Moyenne · **Effort :** M · **Migration :** non

**Objectif :** terminer l'accessibilité AA et la robustesse du front. Tout se termine par une validation dans un navigateur réel (Playwright et lecteur d'écran).

| ID d'origine | Point | Décision à arbitrer | Recommandation |
|---|---|---|---|
| 7.2 | Focus conservé à la fermeture d'un article | Aucune | Redonner le focus à la ligne ou à la suivante |
| 7.9 | Raccourcis `j`/`k`/`s`, ignorés dans les éléments défilants, documentés | Quels raccourcis | `j`/`k` suivant/précédent, `s` enregistrer, aide accessible par `?` |
| 7.10 | Aide du mode zen modale | Aucune | `<dialog>` |
| 7.12 | `aria-pressed` du bouton « enregistrer » du mode zen | Vraie bascule ou non | Vraie bascule |
| 7.7 | Contraste des 10 accents au choix | Aucune | Recalcul et validateur de contraste |
| 7.6 | Annonce des erreurs : mode assertif pour les erreurs | Aucune | `role="alert"` pour les erreurs seulement |
| 8.3 | Dimensions explicites des vignettes d'articles, `srcset`, icône `maskable` | Dessin de l'icône `maskable` | Marge de sécurité de 20 % à partir du logo existant |
| 8.4 | Service worker enregistré partout, page hors ligne, caches | Quoi mettre en cache | Pages en « réseau d'abord », `/assets/*` en « cache d'abord », page hors ligne simple |
| 8.5 | Convertir `feed-sync`, `subscription-refresh`, `subscription-page`, `mastodon-share` à `fetchJson` | Aucune | Conversion |
| 8.11 | Cache du relais d'images | Cache navigateur, disque serveur, ou les deux | Cache navigateur `private, max-age` avec hash de l'URL source |
| 3.4 (lot 7) | `@axe-core/playwright` sur les parcours principaux | Nouvelle dépendance de développement npm | Accepter |

## Validation
`npm run test:browser` (Playwright et axe), contrôle au lecteur d'écran, `asset-map:compile`.

## Critères d'acceptation
- Aucune violation axe « serious » ou « critical » sur lecture, préférences et connexion.
- Le focus reste cohérent après fermeture d'un article.
- La PWA s'installe et reste utilisable hors ligne pour la page de repli.
