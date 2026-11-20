# Lot 8 : robustesse front, PWA et poids des assets

**Priorité :** Moyenne · **Effort :** M · **Migration :** non

**Objectif :** fiabiliser les appels `fetch`, supprimer les courses de requêtes, alléger les images et rendre la PWA cohérente.

Remarque : les fichiers `assets/controllers/*.js` ne sont pas des contrôleurs Stimulus. Ce sont des scripts globaux (IIFE ou modules) exécutés au chargement, sans `connect()` ni `disconnect()`. Ce n'est pas un problème aujourd'hui, puisque Turbo n'est pas utilisé, mais ces scripts n'ont pas de cycle de vie. La migration vers de vrais contrôleurs Stimulus est une décision d'architecture à faire valider.

## Constats et actions

| ID | Priorité | Constat | Preuve | Action | Effort |
|---|---|---|---|---|---|
| 8.1 | Haute | Une session expirée provoque une redirection vers `/login`, qui répond 200 en HTML. `response.json()` lève alors une exception et « Unexpected token < » est affiché à l'utilisateur. | `assets/controllers/article-list.js:328,576` ; `zen.js:67,75` (seul `mastodon-share.js:14` gère ce cas) | Créer un helper `fetchJson()` partagé qui vérifie `redirected` et `content-type`, envoie l'en-tête CSRF et renvoie des messages traduits. Sur une session expirée, rediriger vers la page de connexion. | M |
| 8.2 | Moyenne | Course de requêtes : des clics rapides sur « suivant » peuvent afficher un article périmé. Le polling continue après la fermeture du dialogue et n'a pas de `catch`. | `article-list.js:288` ; `zen.js` (`show()`) ; `subscription-refresh.js:37` ; `feed-sync.js` | Un `AbortController` par ouverture. Arrêter le polling à l'événement `close`. `try/catch` dans la boucle de polling. | S |
| 8.3 | Moyenne | Images surdimensionnées : le logo d'en-tête pèse 512 Ko en 1672×941 pour un affichage en 54×40, sur toutes les pages. L'icône de notification pèse 1,2 Mo, comme `livre-rss.png` sur l'accueil. Les vignettes n'ont pas de dimensions, d'où des décalages de mise en page (CLS). | `base.html.twig:39` ; `public/sw.js:7` ; manifest ; `article-list.js:554` | Versions WebP ou AVIF redimensionnées, `srcset`, `width`/`height` explicites. Icônes PWA 192/512 avec une variante `maskable`. | S |
| 8.4 | Moyenne | PWA incomplète :<br>• le service worker n'est enregistré que sur la page des préférences ;<br>• pas de gestionnaire `fetch` ni de page hors ligne ;<br>• `notificationclick` ouvre toujours une nouvelle fenêtre et ignore `data.url` ;<br>• le manifest n'a ni `lang` ni icône `maskable` ;<br>• `dismissDuration` n'est pas utilisé, donc un refus d'installation est définitif. | `assets/push.js:32` ; `public/sw.js:15` ; `install-pwa.js:9` | Enregistrer le service worker globalement. Cache « network-first » pour les pages, avec une page hors ligne simple ; « cache-first » pour `/assets/*`, qui sont versionnés. Utiliser `clients.matchAll` pour focaliser un onglet existant. Compléter le manifest. Faire expirer le refus d'installation. | M |
| 8.5 | Moyenne | Logique dupliquée :<br>• `shareArticle` et `shareZenArticle` ;<br>• compteur récupéré deux fois ;<br>• en-tête CSRF recopié trois fois ;<br>• gestionnaires de filtres quasi identiques ;<br>• `/api/articles` codé en dur dans `zen.js`. | `article-list.js:11,136,166,199,434,516,600,612` ; `zen.js:13,49` ; `zen-nav-count.js:4` | Module partagé `assets/reading/api.js`. | M |
| 8.6 | Basse | `tailwind.config.js` est au format v3 alors que `app.css` utilise `@import "tailwindcss"` (v4) : la configuration est ignorée. | `tailwind.config.js` ; `app.css:1` | Supprimer le fichier, ou le relier explicitement avec `@config`. | S |
| 8.7 | Basse | Les formulaires « marquer comme lu » sont rendus deux fois. | `article/index.html.twig:63,66` (`_source_mark_read.html.twig`) | Une seule inclusion. | S |
| 8.8 | Basse | Deux bannières d'imitation d'utilisateur peuvent s'afficher en même temps. | `base.html.twig:35-36` | Fusionner les conditions. | S |
| 8.9 | Basse | `theme-color` est fixe. | `base.html.twig:7` | Deux balises avec `media="(prefers-color-scheme: …)"`. | S |
| 8.10 | Basse | Le compteur Mastodon compte en points de code, alors que `maxlength` compte en unités UTF-16. | `mastodon-share.js:35` | Supprimer `maxlength` et valider en JS (et côté serveur) avec la même règle. | S |
| 8.11 | Basse | Le relais d'images répond `Cache-Control: private, no-store` et ne cache rien : chaque affichage refait un appel distant. | Route image de `ArticleController` ; `FeedImageFetcher` | `private, max-age=86400` et/ou cache disque borné dans `var/media` avec purge. À arbitrer selon la politique de confidentialité. | M |

## Tests à ajouter

- Tests JS (`tests/JavaScript/*.test.cjs`) : `fetchJson` sur une réponse HTML, une redirection, un statut 4xx ou 5xx ; annulation des requêtes précédentes.
- Playwright : session expirée, clics rapides sur « suivant », service worker enregistré sur `/articles`.
- Budget de poids : aucune image de `public/images` affichée au-dessus de la ligne de flottaison ne dépasse 100 Ko.

## Validation Linux

Socle commun, puis :

```bash
for f in tests/JavaScript/*.test.cjs; do node "$f" || exit 1; done
php85 bin/console tailwind:build --minify --env=prod
php85 bin/console asset-map:compile --env=prod
npm ci && npm run test:browser
du -h public/images/* | sort -h | tail
```

`asset-map:compile` écrit dans `public/assets`. Nettoyer ce répertoire après le test en local si nécessaire.

## Critères d'acceptation

- Aucun message technique n'est affiché à l'utilisateur.
- Plus de course de requêtes en navigation rapide.
- Le logo et les icônes pèsent moins de 50 Ko.
- La PWA s'installe et un clic sur une notification focalise un onglet existant.
- Les tests JS et Playwright sont verts.

## Statut d'avancement

*Mis à jour le 20 novembre 2026. Tests PHP et JavaScript exécutés en local ; **Playwright, `tailwind:build`, `asset-map:compile` et le rendu navigateur non exécutés**. En attente du retour de validation du développeur.*

| ID | État | Note |
|---|---|---|
| 8.1 | Appliqué | `assets/reading/api.js` : `fetchJson()` (session expirée → redirection vers `/login`, réponse non JSON, erreurs HTTP, réseau, `AbortError` conservé), utilisé par `article-list.js` (liste, article, état) et `zen.js` (liste, article, état, compteur). **Non converti :** les appels `bulk`, `feed-sync.js`, `subscription-refresh.js`, `subscription-page.js`, `feed-retry.js`, `mastodon-share.js` et `zen-nav-count.js` (script non-module). Tests `fetch-json.test.cjs`. |
| 8.2 | Appliqué | Un `AbortController` par ouverture d'article (liste et mode zen) ; le polling de synchronisation s'arrête à la fermeture du dialogue et ses erreurs sont rattrapées. |
| 8.3 | Partiel | Logo d'en-tête, page « À propos » et mode zen : `livre-rss-transparent-320.webp` (6,8 Ko, transparence conservée) au lieu du PNG de 521 Ko. Accueil : `livre-rss-960.webp` (8,4 Ko) au lieu de 1,1 Mo. Icône de notification : `icone-pwa-192.png` (25 Ko) au lieu de 1,2 Mo ; entrée de 1254 px retirée du manifest. **Les anciens PNG sont conservés** (non supprimés). **Non fait :** icône `maskable`, dimensions explicites des vignettes d'articles, `srcset`. |
| 8.4 | Partiel | `notificationclick` focalise un onglet existant (ou le fait naviguer vers la cible), n'accepte qu'une URL de même origine ; `lang` ajouté au manifest ; tests `service-worker.test.cjs`. **Décision (20 novembre 2026) :** un refus d'installation ne doit plus jamais être reproposé : comportement déjà en place, la constante inutilisée `dismissDuration` a été retirée. **Non fait :** enregistrement global du service worker, page hors ligne et caches (à valider avec un vrai navigateur), icône `maskable`. |
| 8.5 | Appliqué | Modules partagés : `assets/reading/api.js` (`fetchJson`, `sendJson` avec l'en-tête CSRF, `fetchCounters`, `articlesEndpoint`) et `assets/reading/share.js` (`shareArticle`, qui remplace `shareArticle` et `shareZenArticle`). Les deux gestionnaires de filtres quasi identiques d'`article-list.js` sont fusionnés en `applyFilter()`. Les appels `bulk` passent par `sendJson`. `zen-nav-count.js` devient un module qui utilise `fetchCounters`. Les tests VM utilisent `tests/Support/reading-api.cjs`. **Non converti :** `feed-sync.js`, `subscription-refresh.js`, `subscription-page.js`, `feed-retry.js`, `mastodon-share.js`. |
| 8.6 | Appliqué | `tailwind.config.js` (format v3, ignoré par Tailwind v4) supprimé. |
| 8.7 | Appliqué | **Décision (20 novembre 2026) :** une seule occurrence. L'inclusion de la barre de filtre est conservée, celle du bas de liste est supprimée ; `ArticleControllerTest` attend désormais 1 formulaire. |
| 8.8 | Appliqué | Une seule bannière d'imitation (conditions fusionnées). |
| 8.9 | Appliqué | Deux balises `theme-color` (`#F8F8F6` clair, `#0F1419` sombre). |
| 8.10 | Appliqué | `maxlength` retiré du champ Mastodon ; validation JS en points de code (1 à 500), identique à la règle serveur. |
| 8.11 | **En attente de décision** | Implications détaillées dans le compte rendu du 20 novembre 2026 : cache navigateur (`private, max-age`, avec hash de l'URL source), cache disque serveur, ou les deux. |

