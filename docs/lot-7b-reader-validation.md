# Lot 7B — Vues et sources du lecteur extraites

16 novembre 2026. Vérifications Linux obtenues ; acceptation humaine et exécution GitHub Actions en attente. Les limites d’acceptation temporelle du lot 6 ne changent pas.

## Fichiers et comportement

| Fichier | Changement |
| --- | --- |
| `assets/reading/sources.js` | Module ES : paramètres de vue, visibilité des sources/catégories, URL des liens, contrôles de marquage. |
| `assets/controllers/article-list.js` | Importe le module ; synchronisation déléguée avec document, vue et catégorie explicites. |
| `importmap.php`, `templates/article/index.html.twig` | Entrée locale `reader`, import map Symfony pour résoudre les URL des modules vers leurs assets compilés avec empreinte. |
| `tests/JavaScript/reading-views.test.cjs`, `reading-sources.test.cjs` | Import du vrai module, suppression de la découpe de ses fonctions depuis les sources ; deux cas supplémentaires de priorité/default des vues et de préservation des paramètres. |
| `tests/Support/evaluate-article-list.cjs`, `reading-layout.test.cjs`, `reading-metadata.test.cjs` | Passerelle des anciens tests VM : injecter les vrais exports et retirer la ligne d’import avant évaluation. Aucun code métier recopié. |
| `tests/Support/BrowserDatabase.php`, son test unitaire | Préfixe `zunaflux_performance_test_*` accepté en plus du préfixe browser, conformément à la demande explicite du développeur. |
| `tests/Browser/router.php` | Environnement browser et DSN explicitement propagés avant Runtime, dotenv désactivé dans HTTP de test, front controller fixé. |
| Guide navigateur, plan 7B, ce document et suivi des lots | Protocole et preuves actualisés. |

Aucun changement de filtre, CSS, API, droits ou schéma. Le recours existant au compteur non lu pour la visibilité des sources favorites/à-lire est conservé. Aucun index, migration, variable supplémentaire ou dépendance nouvelle dans les manifestes/verrous. Aucun CDN ajouté. Le reste du lecteur demeure dans le contrôleur.

## Préparation et diagnostics

Node 24.22.3 était déjà installé : aucune installation Node/PHP ni modification permanente du PATH. Les trois paquets npm verrouillés ont été installés avec scripts désactivés. Chromium headless et FFmpeg ont été téléchargés dans le cache utilisateur sans installer de paquet système. Playwright avertit que cet OS n’est pas officiellement supporté et utilise le binaire de repli Ubuntu 24.04 ; les résultats concernent cet hôte et ces profils.

La première tentative npm sandbox a échoué sur DNS ; la reprise autorisée hors sandbox a réussi. Les manifestes npm n’ont pas changé. À la demande du développeur, sa base de performance a été réutilisée sans exécuter les bancs simultanément. Le garde conserve l’environnement browser, MariaDB 10.11 et un DSN explicite, avec seulement deux préfixes de test admis. Aucune migration lancée : le contrôle des versions a confirmé le schéma courant.

Premier essai navigateur : 11 échecs de connexion, 1 interrompu et 2 non exécutés avant arrêt gracieux. Les journaux dev montraient des utilisateurs introuvables et aucune session browser n’existait. Le serveur HTTP ne propageait pas les variables dans `$_SERVER`/`$_ENV` avant Runtime, qui chargeait dotenv/dev. Le routeur force maintenant browser et sa base ; la référence corrigée a passé les 14 parcours. Le vrai firewall et le CSRF restent actifs.

Après extraction, un premier essai a révélé les imports relatifs sans import map et un cache de compilation ancien ; une requête manquante pouvait aussi provoquer une réentrée Runtime dans le routeur. Correction : import map Symfony standard, reconstruction du cache browser avant compilation et `SCRIPT_FILENAME` fixé à `public/index.php`. Les parcours suivants ont chargé le vrai module compilé et réussi.

## Vérifications réellement exécutées

- Nouveau cas du garde : échec avant adaptation, puis **3 tests / 14 assertions réussis**.
- JavaScript avant extraction : **55 tests réussis**, séquentiellement comme en CI.
- Test de vue important le futur module : échec `MODULE_NOT_FOUND` avant création ; passage après extraction.
- JavaScript final après restauration : **57 tests réussis**.
- Chromium avant extraction : **14 parcours réussis** (15,0 s).
- Chromium après extraction/import map : **14 parcours réussis** (13,5 s).
- Contrôle négatif : retrait temporaire du seul listener d’ouverture, recompilation, test bureau d’ouverture en échec sur la visibilité du dialogue, code 1.
- Source restaurée octet pour octet dans un `finally`, sans commande Git de restauration ; recompilation puis **14 parcours réussis** (13,9 s), aucun ignoré.
- Contrôle SQL après les suites navigateur : **0 compte et 0 flux browser restant**.
- Suite PHP après le navigateur : **187 tests, 843 assertions, aucun échec**, cinq notices de mocks déjà connues dans `NotificationDigestTest`, `MastodonOAuthServiceTest` et `PushDeliveryTest`.
- Lints PHP des fichiers concernés/import map et Node `--check` du contrôleur/module : valides. **27 templates Twig valides**. PHPStan niveau 6 du périmètre 7A : aucune erreur. Garde CI : 25 scénarios réussis. `git diff --check` sans défaut.

Rapports finaux ignorés par Git : `var/browser-report/`, `var/browser-results/`. Logs locaux : `/tmp/zunaflux-browser-{before,negative,restored}.log`. Les traces d’échec peuvent contenir les identifiants synthétiques : ne pas les publier comme sources. `config/reference.php`, régénéré par les consoles, a été remis à son contenu initial après vérification qu’il était intact au début du lot. Aucune vérification Windows exécutée.

## PowerShell local

Sans installer d’outil sur Windows :

```powershell
git diff --check
if ($LASTEXITCODE -ne 0) { throw 'Défaut dans le diff' }
Get-Content assets/reading/sources.js
Get-Content importmap.php
Get-Content tests/Browser/router.php
git diff -- assets/controllers/article-list.js templates/article/index.html.twig
git status --short
```

Attendu : extraction ciblée, import map présent et aucun défaut d’espace. Les fichiers non suivis restent à relire séparément.

## Linux sans modification des données

PHP 8.5 et Node 24 déjà disponibles. Sur cet hôte, le binaire utilisé est `/home/ckd/.nvm/versions/node/v24.22.3/bin/node` ; `node` ci-dessous doit pointer sur cette version majeure.

```bash
node --version
php85 -l importmap.php
php85 -l tests/Browser/router.php
php85 -l tests/Support/BrowserDatabase.php
php85 -l tests/Unit/Infrastructure/BrowserDatabaseTest.php
node --check assets/reading/sources.js
node --check assets/controllers/article-list.js
APP_ENV=test php85 vendor/bin/phpunit tests/Unit/Infrastructure/BrowserDatabaseTest.php
result=0
for test_file in tests/JavaScript/*.test.cjs; do
    node "$test_file" || result=1
done
test "$result" = 0
APP_ENV=test php85 bin/console lint:twig templates/
php85 vendor/bin/phpstan analyse --configuration=phpstan.neon.dist --no-progress --debug --memory-limit=512M
sh tests/Container/ci-quality-gate.sh
git diff --check
```

Attendu : trois tests PHP de garde, 57 tests JS, Twig et analyse valides. Les outils peuvent écrire leurs caches. Contrôler chaque code de sortie ; ne pas activer `set -e` dans un terminal interactif.

## Installation, assets et parcours — écritures autorisées

Ces opérations ont été autorisées pour cette copie ; accord humain requis pour une autre copie. Aucun outil système à installer implicitement. Si `vendor` manque : `php85 /usr/bin/composer install --no-interaction --prefer-dist --no-progress` après vérification de la copie de validation. Le verrou Composer n’est pas modifié par 7B.

Avec Node 24 actif et `BROWSER_TEST_DATABASE_URL` configurée localement sur une base de test migrée, exporter les valeurs de test du [guide navigateur](browser-tests.md), puis :

```bash
npm ci --ignore-scripts
node node_modules/playwright/cli.js install --only-shell chromium
php85 tests/Browser/fixtures.php guard
php85 tests/Browser/fixtures.php check
APP_ENV=browser php85 bin/console tailwind:build --minify --env=browser
APP_ENV=browser php85 bin/console cache:clear --env=browser
APP_ENV=browser php85 bin/console asset-map:compile --env=browser
node node_modules/@playwright/test/cli.js test --config=tests/Browser/playwright.config.cjs --max-failures=1
# Après arrêt du serveur Playwright, sur DATABASE_URL de test uniquement :
APP_ENV=test php85 vendor/bin/phpunit --display-phpunit-notices
```

Attendu : garde/schéma acceptés, 15 assets compilés dont le module de sources, 14 parcours et suite PHP sans échec. Ne pas lancer en parallèle le banc de performance sur la base partagée. Ne jamais inscrire de DSN réel dans Git. Aucune migration nouvelle, aucun déploiement ou redémarrage exécuté. Les assets devront être reconstruits lors d’un éventuel déploiement décidé séparément. Contrôle facultatif de services Docker existants, en lecture seule :

```bash
docker compose --env-file docker/.env -f compose.yaml ps
```

## Acceptation et limites

Le périmètre extrait conserve les vues/sources et devient testable par import direct. Les anciens tests du reste du contrôleur restent partiellement fondés sur VM/source ; leur passerelle ne remplace pas une importation native du contrôleur complet. Chromium valide ici le chargement ES réel, l’authentification, les préférences et l’isolation. Safari, Firefox, appareils réels, lecteur d’écran, PWA/Web Push et gros volumes restent hors périmètre. Aucun audit visuel ni gain de performance revendiqué.

En cas d’échec, arrêter au premier scénario : vérifier environnement/DSN explicites, cache browser reconstruit, import map rendu et résolution des assets compilés. Pour un refus de garde, vérifier le préfixe et MariaDB 10.11 ; ne pas autoriser une base réelle pour obtenir un passage vert. Consulter la trace sans remplacer une assertion comportementale par une chaîne source. La preuve négative est déjà exécutée, à rejouer uniquement si une modification le justifie.

Preuves locales de 7B et des parcours du lot 5 obtenues ; acceptation humaine et CI réelle encore attendues. Aucun indexage, commit, push, tag, changement de branche ou publication. L’état Git final est relevé lors de la livraison ; tous les fichiers du tableau restent dans l’arbre de travail.

## État Git relevé

```text
 M assets/controllers/article-list.js
 M docs/browser-tests.md
 M docs/lot-5-browser-validation.md
 M docs/lot-7-maintenance-validation.md
 M docs/lots-amelioration.md
 M templates/article/index.html.twig
 M tests/Browser/router.php
 M tests/JavaScript/reading-layout.test.cjs
 M tests/JavaScript/reading-metadata.test.cjs
 M tests/JavaScript/reading-sources.test.cjs
 M tests/JavaScript/reading-views.test.cjs
 M tests/Support/BrowserDatabase.php
 M tests/Unit/Infrastructure/BrowserDatabaseTest.php
?? assets/reading/
?? docs/lot-7b-reader-plan.md
?? docs/lot-7b-reader-validation.md
?? importmap.php
?? tests/Support/evaluate-article-list.cjs
```

Aucun commit ni push effectué.
