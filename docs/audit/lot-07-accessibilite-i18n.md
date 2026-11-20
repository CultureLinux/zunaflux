# Lot 7 : accessibilité (WCAG 2.2 AA) et internationalisation

**Priorité :** Moyenne · **Effort :** M · **Migration :** non

**Objectif :** atteindre le niveau AA sur les parcours de lecture et rendre l'interface réellement utilisable en anglais et en allemand.

**Acquis :**
- cibles tactiles de 44 px ;
- `:focus-visible` global ;
- `<dialog>` natifs ;
- `prefers-reduced-motion` respecté ;
- `lang` dynamique ;
- alternative clavier au glisser-déposer ;
- navigation clavier dans les onglets de préférences.

## Constats et actions

| ID | Priorité | Critère WCAG | Constat | Preuve | Action | Effort |
|---|---|---|---|---|---|---|
| 7.1 | Haute | 1.4.3 | Articles lus en thème clair : `--muted` #64748B sur #ECEBE6 donne **3,99:1**. | `assets/styles/app.css:187` ; `article-list.js:148,272,487` | Assombrir `--muted` (≈ #56657A) ou garder le fond `--background` pour les lignes lues. | S |
| 7.2 | Haute | 2.4.3 | Focus perdu sur `<body>` à la fermeture d'un article, parce que la liste est re-rendue. | `article-list.js:356,398` ; `app.css:257` | Mémoriser l'article ouvert et redonner le focus à sa ligne, ou à la suivante, après le rendu. | M |
| 7.3 | Haute | i18n / 3.1.2 | Nombreuses chaînes françaises écrites en dur, qui cassent l'anglais et l'allemand. Les sélecteurs CSS `[aria-label="À propos"]` ne s'appliquent plus hors français. | Templates : `article/_actions.html.twig:1-9`, `article/index.html.twig:3,17-22,48-63`, `base.html.twig:37`, `_navigation_links.html.twig:4`, `_reading_source.html.twig:7-8`. JS : `article-list.js` (une vingtaine d'occurrences), `zen.js:145`, `mastodon-share.js:15-69`, `toast.js:28`, `public/sw.js:4`. CSS : `app.css:42-51`. | Passer les libellés JS par `data-messages`, comme `zen.js`. Traduire les templates. Cibler le lien « À propos » par une classe. | M |
| 7.4 | Haute | i18n | 17 clés utilisées dans Twig sont absentes des catalogues (par exemple « Connecter Mastodon », « Diagnostic du flux », « Partager l'article »). Des messages flash et du domaine sont écrits en dur. | `translations/messages.{en,de}.json` ; `SecurityController.php:57` ; `MastodonConnectionController.php:29` ; `UserChecker.php` ; `FeedParser.php:41,54` | Compléter les catalogues. Lister les clés orphelines avec `debug:translation --only-missing`, puis `--only-unused` (117 clés suspectes). | S |
| 7.5 | Moyenne | 4.1.3 | `aria-live="polite"` est posé sur toute la liste : chaque chargement fait annoncer des dizaines de titres. | `templates/article/index.html.twig:65` | Retirer l'attribut et ajouter une région `sr-only` dédiée (« 20 articles chargés »). | S |
| 7.6 | Moyenne | 2.2.1 / 4.1.3 | Les toasts d'erreur disparaissent en 6 s sans possibilité de pause, avec une double annonce (`role` à l'intérieur d'un `aria-live`). | `assets/toast.js:15` ; `base.html.twig:59-61` | Garder les erreurs jusqu'à leur fermeture, mettre en pause au survol et au focus, et n'utiliser qu'un seul mécanisme d'annonce. | S |
| 7.7 | Moyenne | 1.4.3 / 1.4.11 | L'accent clair donne 4,42:1 (4,03:1 sur `--accent-soft`). Les bordures de champs font 1,27:1 en clair et 1,79:1 en sombre. | `app.css:63,85,172` | Accent ≈ #4A6A99. Nouveau token `--input-border` d'au moins 3:1. | S |
| 7.8 | Moyenne | 2.4.1 | Pas de lien d'évitement. | `base.html.twig` | Lien `sr-only focus:not-sr-only` vers `#main`, et `id` sur chaque `<main>`. | S |
| 7.9 | Moyenne | 2.1.1 / 3.3.2 | Raccourcis clavier limités à ← et → (actifs même dans un `pre` défilant). Aucun raccourci en mode zen, et rien ne les documente. | `article-list.js:365-369` ; `index.html.twig:106` | Ignorer l'événement si l'élément focalisé défile. Ajouter `j`/`k`/`s` en mode zen. Documenter les raccourcis dans l'aide. | M |
| 7.10 | Moyenne | 2.4.3 | L'aide du mode zen n'est pas modale : le focus n'y entre pas et le fond reste focalisable. | `index.html.twig:106` ; `zen.js:100,138` | `<dialog>`, ou fond `inert` avec focus sur « J'ai compris ». | S |
| 7.11 | Basse | 1.3.1 / 2.4.6 | Pas de `<h1>` sur mobile. | `index.html.twig:44` (`hidden md:block`) | `h1` en `sr-only` sur mobile. | S |
| 7.12 | Basse | 4.1.2 | `aria-live` imbriqué dans le bouton zen. Le bouton « enregistrer » du mode zen porte `aria-pressed` sans être une bascule, et son glyphe est incohérent. | `base.html.twig:42` ; `zen.js:114` | Retirer `aria-live`. En faire une vraie bascule, ou retirer `aria-pressed`. | S |

## Tests à ajouter

- Playwright : ajouter `@axe-core/playwright` sur les pages de lecture, de préférences et de connexion, en thème clair et sombre. Nouvelle dépendance de développement npm, à valider.
- Playwright : le focus revient à la ligne de l'article après fermeture ; navigation clavier en mode zen ; l'aide zen piège le focus.
- Test PHP : chaque clé utilisée dans `templates/` existe dans `messages.en.json` et `messages.de.json`.
- Test JS : aucune chaîne française en dur dans `assets/` (liste blanche si nécessaire).

## Validation Linux

Socle commun, puis :

```bash
php85 bin/console debug:translation en --only-missing
php85 bin/console debug:translation de --only-missing
php85 bin/console lint:twig templates/
npm ci && npx playwright install chromium && npm run test:browser
```

Les tests Playwright nécessitent Node 24 et une base de test dédiée (voir `tests/Browser/start-server.sh`).

## Critères d'acceptation

- Aucun contraste inférieur à 4,5:1 (texte) ou 3:1 (composants).
- Le focus est conservé après la fermeture d'un article.
- Aucune violation axe « serious » ou « critical » sur les parcours principaux.
- `--only-missing` est vide en anglais et en allemand.

## Statut d'avancement

*Mis à jour le 20 novembre 2026. Tests PHP et JavaScript exécutés en local ; **Playwright, axe et le rendu visuel (CSS reconstruit par Tailwind) non exécutés**. Aucune vérification avec un lecteur d'écran. En attente du retour de validation du développeur.*

| ID | État | Note |
|---|---|---|
| 7.1 | Appliqué | `--muted` clair `#64748B` → `#56657A` : 3,99:1 → 4,97:1 sur `#ECEBE6` (calculé, pas mesuré dans un navigateur). |
| 7.2 | **Non traité** | Focus perdu à la fermeture d'un article (JS, à tester dans un navigateur). |
| 7.3 | Appliqué (à valider en navigateur) | Templates : tous les textes et attributs visibles passent par `|trans`. JavaScript : mécanisme commun `window.zunaflux.t()` (`assets/i18n.js` + `templates/_js_messages.html.twig`) utilisé par `article-list.js`, `mastodon-share.js`, `zen.js` et `toast.js` ; `push.js` avait déjà `data-messages`. Réponses JSON d'erreur et messages flash traduits côté PHP. Tests `SourceTranslationKeysTest` et `EnglishInterfaceTest`. **Reste en français :** messages CLI/exploitation, `public/sw.js` (texte de repli de notification), le sélecteur CSS sur `aria-label="À propos"` (app.css:42), le texte de l'archive d'export. |
| 7.4 | Appliqué | Clés manquantes ajoutées en anglais et en allemand (17 au départ, puis ~170 après la chasse aux chaînes en dur) ; `debug:translation --only-missing` est vide pour `en` et `de`. Nouveau test `TemplateTranslationKeysTest` (507 usages littéraux analysés). Les 117 clés « inutilisées » n'ont pas été revues. |
| 7.5 | Appliqué | `aria-live` retiré de la liste ; région `sr-only` « N articles chargés » alimentée par `article-list.js`. |
| 7.6 | Partiel | Les erreurs restent jusqu'à fermeture, pause au survol et au focus, plus de `role` en double (le conteneur `aria-live` reste l'unique annonce). Tests `toast.test.cjs`. Les erreurs sont désormais annoncées en mode poli. |
| 7.7 | Partiel | Accent par défaut `#4A6A99` (4,61:1 sur `--surface-muted`) ; nouveau jeton `--input-border` (`#76879B` clair, 3,68:1 ; `#6B7C93` sombre, 3,85:1) appliqué aux champs par une règle hors couche. Les 10 accents au choix n'ont pas été recalculés. |
| 7.8 | Appliqué | Lien d'évitement `.skip-link` et `id="main"` (avec `tabindex="-1"`) sur les 14 `<main>`. |
| 7.9 | **Non traité** | Raccourcis clavier (`j`/`k`/`s`, documentation, éléments défilants). |
| 7.10 | **Non traité** | Aide du mode zen non modale. |
| 7.11 | Appliqué | `<h1>` `sr-only` sur mobile. |
| 7.12 | Partiel | `aria-live` imbriqué retiré des compteurs zen du bouton. **Non fait :** `aria-pressed` du bouton « enregistrer » du mode zen. |

