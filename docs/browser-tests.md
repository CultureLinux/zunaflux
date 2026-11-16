# Parcours navigateur du lecteur

14 parcours exécutés avec succès sur Linux avant et après l’extraction du lot 7B ; contrôle négatif d’ouverture observé, puis restauration et nouvelle suite verte. Acceptation du développeur en attente. Voir le [compte-rendu 7B](lot-7b-reader-validation.md).

## Outil et coût

[Playwright Test](https://playwright.dev/docs/test-configuration) pilote le vrai lecteur avec JavaScript, sessions, CSRF et requêtes Symfony/MariaDB. La version `1.65.1` est fixée dans `package.json` et `package-lock.json` ; son graphe comprend trois paquets npm de développement (`@playwright/test`, `playwright`, `playwright-core`), tous à cette version. Les URL et intégrités viennent des [métadonnées npm officielles](https://registry.npmjs.org/@playwright/test/1.65.1), lues sans télécharger les archives ni exécuter npm.

Le manifeste npm est nouveau et réservé aux tests : le frontend applicatif reste construit par les commandes PHP Tailwind/AssetMapper existantes. Aucune dépendance Composer n’est ajoutée. Les répertoires `node_modules`, `var` et `tests` sont déjà exclus du contexte Docker de production ; aucune installation Node n’est ajoutée au Dockerfile.

Le coût configuré est un téléchargement de Chromium et de ses bibliothèques Linux, deux profils et quatorze parcours séquentiels. Un seul worker, zéro retry, 30 secondes par test, 5 minutes maximum pour la suite et 20 minutes pour le job CI incluant les installations/builds. Ce sont des limites, pas une mesure de durée ou de volume téléchargé.

Le profil bureau mesure 1440 × 900 ; le profil mobile Chromium 390 × 844 avec tactile/émulation mobile. Ce ne sont pas des essais sur appareil réel, Safari ou Firefox. Les service workers sont bloqués dans ces contextes : la PWA et Web Push ne font pas partie du périmètre.

## Comportements contrôlés

Chaque scénario s’exécute sur les deux profils :

1. Ouvrir un article, charger le contenu réel, vérifier le lecteur non modal sur bureau avec panneau actif et modal sur mobile, puis fermer et retrouver la liste.
2. Marquer lu manuellement, vérifier la persistance via l’API authentifiée et le passage entre vues non lues/lues.
3. Filtrer les lus, tous les articles, les favoris et une liste « à lire plus tard » vide.
4. Choisir une source, retirer le filtre et choisir une autre source.
5. Rechercher puis effacer la recherche pour retrouver la liste entière.
6. Enregistrer le mode de lecture et désactiver le panneau, recharger la préférence, constater le lecteur modal et le marquage automatique à l’ouverture.
7. Atteindre les commandes avec Tab, ouvrir avec Entrée, changer d’article avec la flèche et fermer au clavier ; connecter un autre compte dans un contexte indépendant et vérifier les refus d’accès croisés à l’API.

Les locators utilisent rôles, libellés, texte et hooks de données existants, sans assertions sur les noms de classes Tailwind, regex du code source ou captures de référence. Les API applicatives ne sont pas simulées. Les captures servent seulement au diagnostic d’échec, pas à une comparaison visuelle.

Trois anciens tests de `reading-layout.test.cjs` sont retirés : regex du panneau/modal, présence textuelle du bouton d’effacement et classes des colonnes. Le comportement panneau/modal et l’effacement passent dans le navigateur ; la liste exacte des classes CSS n’est plus un critère. Les autres tests de ce fichier et les autres tests JS restent présents : la transition n’est pas une suppression globale des contrôles existants. Les tests de présentation encore fondés sur des chaînes ne sont pas déclarés remplacés.

## Environnement et données

L’environnement Symfony `browser` conserve le vrai firewall et le formulaire de connexion avec CSRF. Il utilise des sessions natives dans `var/browser-sessions` et un hachage de mots de passe à coût de test ; les environnements `prod` et `test` ne sont pas modifiés. Aucun endpoint HTTP de fixtures ou de connexion spéciale n’est ajouté.

`BROWSER_TEST_DATABASE_URL` est une variable d’outillage, sans repli sur `DATABASE_URL`. Les scripts exigent `APP_ENV=browser`, MySQL/PDO, MariaDB 10.11 et une base nommée `zunaflux_browser_test_<suffixe>` ou `zunaflux_performance_test_<suffixe>` (minuscules, chiffres, underscores). Ce second préfixe a été autorisé explicitement par le développeur pour réutiliser sa base de test locale ; ne pas exécuter les deux bancs en même temps sur cette base. Seuls `charset` et `serverVersion` sont permis dans la query string du DSN. Le CLI refuse les migrations incomplètes avant seed/cleanup.

Chaque scénario reçoit un token aléatoire de 32 caractères hexadécimaux, deux comptes ordinaires, trois flux et cinq articles synthétiques. Les dates de collecte sont fixées pour stabiliser l’ordre réel de l’API. Les préférences initiales sont manuelles, en français, avec panneau bureau actif. Un article est déjà lu et un autre favori. Les mots de passe aléatoires sont transmis uniquement au processus de test, pas versionnés ni imprimés dans les journaux normaux.

Après fermeture des pages, le CLI supprime seulement les deux adresses exactes et les trois hash d’URL du token courant. Il ne vide ni ne supprime de base et ne purge pas les autres fixtures. Une interruption brutale peut laisser des données synthétiques ; un nouveau token empêche leur réutilisation involontaire. Aucun worker, collecteur ou planificateur ne doit fonctionner sur cette base de navigateur.

Le serveur de test est lancé automatiquement par Playwright, après contrôle de la base et du schéma, uniquement sur `127.0.0.1:8175`. Le serveur déjà présent n’est jamais réutilisé. Le routeur sert les fichiers publics statiques puis le vrai `public/index.php` Symfony. Ce serveur PHP intégré n’est pas une procédure de production.

## Préparation humaine sur Linux — modifie l’environnement

Utiliser une copie de validation isolée avec PHP 8.5, Composer et Node 24 déjà disponibles. L’agent n’installe aucun de ces outils. Préparer après approbation une base dédiée, avec votre client et vos droits d’administration habituels :

```sql
CREATE DATABASE zunaflux_browser_test_local
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Attribuer au compte de test les droits nécessaires sur cette seule base. Renseigner localement `BROWSER_TEST_DATABASE_URL` avec son DSN `mysql://.../zunaflux_browser_test_local?serverVersion=mariadb-10.11.0&charset=utf8mb4`. Ne pas afficher ni versionner le mot de passe. Préparer aussi les valeurs de configuration applicative de test (secret factice, mail nul, VAPID non configuré) ; la CI montre ces valeurs non sensibles et utilise sa propre base éphémère.

Le routeur force les variables de l’environnement de test avant Symfony Runtime et désactive la lecture de dotenv dans le serveur HTTP de test ; exporter les valeurs ci-dessous explicitement. Il fixe aussi le front controller pour éviter une réentrée dans le routeur. Les routes et fichiers de production restent inchangés.

Dans la copie de validation, après accord humain :

```bash
# En terminal interactif, ne pas activer set -e ; contrôler chaque code de sortie.
: "${BROWSER_TEST_DATABASE_URL:?Configurer la base navigateur dédiée}"
export APP_ENV=browser APP_DEBUG=0 APP_SECRET=browser-local-test-secret
export APP_TIMEZONE=Europe/Paris MAILER_DSN='null://null'
export MESSENGER_TRANSPORT_DSN='doctrine://default?queue_name=async'
export ZUNAFLUX_PAGE_SIZE=30 ZUNAFLUX_MAX_SUBSCRIPTIONS=100
export ZUNAFLUX_PUBLIC_REGISTRATION=1 ZUNAFLUX_MEDIA_STORAGE=var/media
export DATABASE_URL="$BROWSER_TEST_DATABASE_URL"
# Dépendances de développement : écrit vendor, node_modules et les caches.
php85 /usr/bin/composer install --no-interaction --prefer-dist --no-progress
npm ci --ignore-scripts
# Télécharge le Chromium verrouillé et installe ses bibliothèques Linux.
# Cette commande peut modifier les paquets du système et demander sudo.
npx --no-install playwright install --with-deps chromium
# Contrôle de cible sans données ajoutées, puis migration explicite de cette base.
php85 tests/Browser/fixtures.php guard
php85 bin/console doctrine:migrations:status --env=browser
php85 bin/console doctrine:migrations:migrate --env=browser --no-interaction
php85 tests/Browser/fixtures.php check
# Les vrais assets doivent être disponibles avant le serveur HTTP.
php85 bin/console tailwind:build --minify --env=browser
php85 bin/console cache:clear --env=browser
php85 bin/console asset-map:compile --env=browser
npm run test:browser
```

Attendu : installation conforme au lock, garde de cible réussi, schéma courant, assets construits puis **14 parcours réussis**. Les commandes écrivent dans l’environnement et la base de test ; elles ne sont pas une validation en lecture seule. Le planificateur et les envois Web Push ne doivent pas être démarrés. Aucun déploiement ni migration de production n’est nécessaire pour le lot.

## Liste, ciblage et diagnostic

Après installation des dépendances, [la CLI Playwright](https://playwright.dev/docs/test-cli) permet de lister les tests sans lancer les parcours ni le serveur :

```bash
BROWSER_TEST_DATABASE_URL='mysql://unused:unused@127.0.0.1/zunaflux_browser_test_list' \
  npm run test:browser:list
```

Attendu : sept scénarios sur deux projets, 14 tests déclarés. L’URL factice sert uniquement à charger la configuration, pas à exécuter des tests.

Après préparation réelle de la base et des assets :

```bash
npm run test:browser -- --project=desktop-chromium
npm run test:browser -- --project=mobile-chromium
```

Ces commandes exécutent les tests et modifient leurs données ; accord humain requis comme pour la suite entière. Ne rejouer un profil que pour diagnostiquer une propriété spécifique ou un échec identifié, pas pour masquer les autres résultats.

En CI, `quality` exige le succès du job navigateur. Un échec, une annulation ou un job ignoré doit bloquer la publication. Les diagnostics HTML, JUnit, captures et traces d’échec sont conservés sept jours dans les artefacts `browser-<run>-<attempt>`, depuis `var/browser-report` et `var/browser-results`. Les sessions et l’ensemble de `var` ne sont pas exportés. Les traces peuvent contenir les comptes et mots de passe synthétiques de l’exercice ; n’utiliser aucune donnée réelle dans ces fixtures et expurger avant partage hors du contexte de validation.

En cas d’échec : conserver le lot ouvert, noter projet/scénario et première erreur, vérifier d’abord garde/DDL, injection dans `browser`, sessions natives, réponse de connexion, chargement des JS/CSS et contrôles CSRF. Vérifier la disponibilité du port sans réutiliser un serveur inconnu. Pour `npm ci`, fournir la version Node/npm et l’erreur du lock ; ne pas changer automatiquement de version pour contourner l’échec. Une trace ou capture n’est disponible que si le runner a atteint la phase de test.

Si un test a été interrompu après seed, retrouver son token dans l’adresse synthétique affichée dans sa trace ou dans la base de test, puis après accord humain :

```bash
# Remplacer TOKEN par les 32 caractères hexadécimaux du scénario à nettoyer.
APP_ENV=browser DATABASE_URL="$BROWSER_TEST_DATABASE_URL" \
  php85 tests/Browser/fixtures.php cleanup TOKEN
```

Le token littéral `TOKEN` est refusé ; aucune purge globale n’est proposée.

## Preuve de régression à confirmer

Dans une copie de validation uniquement, sauvegarder puis retirer temporairement le listener de clic du bouton d’ouverture (`openButton.addEventListener('click', () => openArticle(item.id));` dans `assets/controllers/article-list.js`). Recompiler les assets de l’environnement `browser`, vider son cache puis lancer :

```bash
APP_ENV=browser php85 bin/console cache:clear --env=browser
php85 bin/console asset-map:compile --env=browser
APP_ENV=browser php85 bin/console cache:clear --env=browser
npm run test:browser -- --project=desktop-chromium --grep="ouvrir et fermer"
```

Attendu : le scénario échoue parce que le lecteur ne s’ouvre plus. Restaurer le fichier sauvegardé, recompiler les assets puis confirmer le succès du même scénario et de la suite complète. Aucune mutation de source, restauration Git ou exécution de ce contrôle n’a été effectuée par l’agent. Cette preuve réelle reste à joindre aux validations Linux.

Le lot ne couvre pas tous les gestes tactiles, lecteurs d’écran, navigateurs, PWA, Web Push, chargements longs ou scénarios du mode zen. Il prépare une base de non-régression du lecteur avant le lot de maintenance, sans déclarer un audit exhaustif d’accessibilité ou de design.
