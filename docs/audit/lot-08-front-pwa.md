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
