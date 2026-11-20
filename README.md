# Zunaflux

**Vos sources. Votre rythme.**

Zunaflux est un lecteur RSS et Atom auto-hébergé pour retrouver ses sources dans un espace de lecture personnel, sur ordinateur comme sur mobile.

Interface disponible en : 🇫🇷 Français · 🇬🇧 English · 🇩🇪 Deutsch. La langue se choisit dans les préférences après connexion ; les pages publiques sont affichées en français.

## 🚧 Zunaflux recherche ses premiers testeurs !

Zunaflux est un logiciel libre, open source et en développement actif. L'application est déjà utilisable ; elle a besoin de retours d'expérience pour progresser vers une première version stable.

### Participer aux tests

- Installez Zunaflux sur un serveur Linux que vous contrôlez ; ne l'utilisez pas encore comme unique copie de vos données importantes.
- Importez vos abonnements au format OPML ou ajoutez des flux manuellement.
- Testez la lecture, la recherche, les favoris, les articles à lire plus tard et les filtres, sur ordinateur comme sur mobile.
- Partagez les comportements inattendus, les difficultés d'utilisation et les idées d'amélioration.

🐛 **Un bug ou une suggestion ?** [Ouvrez une issue sur GitHub](https://github.com/CultureLinux/zunaflux/issues/new). Décrivez le navigateur, l'appareil ou le serveur concerné, les étapes pour reproduire le problème et, si possible, son message d'erreur. Ne publiez jamais de mot de passe, de clé ou d'autre donnée sensible.

💬 **Envie de contribuer ?** Les retours d'expérience, les tests sur différents environnements, les propositions d'amélioration et les contributions au code sont bienvenus.

### Limites connues

- Les notifications Web Push ont été validées par le développeur ; les fournisseurs pris en charge restent limités à Chrome et Firefox.
- La lecture hors ligne n'est pas prise en charge : l'application nécessite une connexion réseau pour afficher les articles.
- Avant toute mise à jour, sauvegardez la base de données, `var/media/` et votre configuration locale.

<a href="https://ko-fi.com/E1E31E9X8Y" target="_blank"><img height="36" style="border:0px;height:36px;" src="https://storage.ko-fi.com/cdn/kofi5.png?v=6" border="0" alt="Donate" /></a>

![Illustration de la salle de lecture Zunaflux](public/images/lecture-editoriale.png)

## Votre veille, à votre façon

- **Suivez vos sources** : ajoutez des flux RSS et Atom, organisez-les par catégories et importez ou exportez vos abonnements au format OPML.
- **Découvrez des sources** : explorez le catalogue par langue et catégorie, ou recherchez les flux proposés par un site.
- **Lisez à votre rythme** : retrouvez vos articles lus et non lus, marquez vos favoris et gardez des articles pour plus tard.
- **Retrouvez l’essentiel** : recherchez dans vos articles et vos sources, enregistrez des filtres et appliquez des actions à plusieurs articles.
- **Choisissez votre espace** : thème clair, sombre ou système, préférences de lecture et vue en colonnes sur ordinateur.
- **Partagez une instance, pas votre lecture** : les flux et les articles sont mutualisés, tandis que les abonnements, favoris et états de lecture restent propres à chaque compte.
- **Gardez la main** : exportez vos données et administrez les comptes, les sources et les incidents depuis votre instance.
- **Partagez sur Mastodon** : connectez un compte dans les préférences pour publier un article, avec le texte et la visibilité de votre choix.

Zunaflux propose également une installation sur l’écran d’accueil depuis un navigateur compatible. Les notifications Web Push pour les nouveaux articles et les erreurs de collecte sont validées par le développeur. L’application nécessite une connexion réseau pour lire les articles.

Les préférences permettent notamment de choisir la langue, le thème, la présentation de lecture et une plage de silence pour les notifications. Les flux peuvent être rafraîchis individuellement, par catégorie ou ensemble. L’export de compte fournit une archive de données personnelles ; la réimportation complète des états de lecture ne fait pas partie du format actuel. Voir [la synthèse](docs/SYNTHESE.md#configuration-validation-et-limites).

## Installer Zunaflux

Zunaflux s’adresse aux personnes qui souhaitent héberger leur propre lecteur. Pour Docker, suivez le [guide Docker synthétique](docs/SYNTHESE.md#installation-et-mise-à-jour-docker) : FrankenPHP sert directement l’application et ses fichiers statiques. L’installation manuelle ci-dessous nécessite PHP et un serveur web ; adaptez les chemins et les commandes PHP à votre environnement.

### Prérequis

- PHP 8.5 et ses extensions `ctype`, `iconv`, `mbstring`, `pdo_mysql`, `xml`, `dom`, `curl`, `intl`, `openssl` et `sodium` ;
- Composer 2 et MariaDB 10.11 ;
- un serveur web configuré pour servir le répertoire `public/` ;
- HTTPS pour l’installation PWA et les notifications.

### Configuration et installation

Depuis la racine du projet, créez votre configuration locale privée à partir de l’exemple fourni :

```bash
cp .env.example .env.local
```

Modifiez ensuite `.env.local` : définissez notamment une valeur aléatoire pour `APP_SECRET`, les identifiants MariaDB dans `DATABASE_URL`, et un transport mail adapté si nécessaire. Les exemples de ce fichier ne sont pas des secrets utilisables en production. Le fichier `.env.local` est ignoré par Git et doit rester privé.

Sur une installation existante, conservez votre configuration et vos données : ne recopiez pas l’exemple par-dessus `.env.local`. Avant toute mise à jour, sauvegardez la base, les médias et la configuration comme indiqué ci-dessous.

Par défaut, `.env.example` contient `ZUNAFLUX_PUBLIC_REGISTRATION=1`, ce qui autorise la création de comptes depuis `/register`. Pour une instance exposée sur Internet dont vous souhaitez contrôler les accès, définissez `ZUNAFLUX_PUBLIC_REGISTRATION=0` dans `.env.local` et créez chaque compte nécessaire avec `php85 bin/console app:user:create EMAIL MOT_DE_PASSE --env=prod`.

Installez les dépendances puis construisez les ressources :

```bash
php85 /usr/bin/composer install --no-dev --optimize-autoloader
php85 bin/console tailwind:build --minify --env=prod
php85 bin/console asset-map:compile --env=prod
```

Appliquez ensuite les migrations à la base configurée. **Cette commande modifie la base de données** :

```bash
php85 bin/console doctrine:migrations:migrate --env=prod
```

Les visiteurs peuvent créer un compte depuis `/register` lorsque l’inscription publique est activée. Pour créer le premier administrateur, la commande du projet demande une adresse et un mot de passe :

```bash
php85 bin/console app:user:create EMAIL MOT_DE_PASSE --admin --env=prod
```

L’argument de mot de passe peut apparaître dans l’historique du terminal ou la liste des processus : utilisez un environnement adapté et protégez cet accès.

### Collecte des flux

La collecte est mise en file d’attente avec Symfony Messenger. Lancez régulièrement la commande de collecte, par exemple depuis un planificateur :

```bash
php85 bin/console app:feeds:collect --env=prod
```

Un worker doit être actif pour traiter les messages de collecte et de notification :

```bash
php85 bin/console messenger:consume async --env=prod
```

Configurez ce worker comme un service supervisé sur votre serveur. La durée de conservation des articles est réglable avec `ZUNAFLUX_RETENTION_MONTHS` ; sa valeur par défaut est de 12 mois. La purge s’appuie sur la date de collecte et est exécutée par `app:maintenance:purge-articles` (options `--max-seconds` et `--batch-size`) : intégrée à `docker/collect-feeds.sh` sous Docker, à planifier (cron ou timer systemd) hors Docker.

Une [unité systemd](deploy/systemd/zunaflux-messenger.service) est fournie ; adaptez les chemins et l’utilisateur à votre serveur.

### Partage Mastodon

Pour connecter un compte Mastodon, configurez dans `.env.local` une origine HTTPS publique accessible à l’instance Mastodon et une clé privée stable :

```dotenv
ZUNAFLUX_PUBLIC_BASE_URL=https://rss.example.org
ZUNAFLUX_MASTODON_TOKEN_KEY=cle-privee-base64-de-32-octets
```

La clé doit être une valeur aléatoire de 32 octets encodée en base64. Elle chiffre les jetons d’accès enregistrés : conservez-la avec vos sauvegardes de configuration, ne la publiez pas et ne la remplacez pas lors d’une mise à jour. Une clé perdue ou changée rend les jetons précédents illisibles ; les comptes concernés devront être reconnectés. Chaque utilisateur peut ensuite connecter son instance dans ses préférences et publier depuis un article.

## Changements incompatibles

Zunaflux n'a pas encore de version stable : les valeurs par défaut évoluent vers le plus sûr, sans période de transition. Si vous mettez à jour une installation existante, vérifiez ces points.

| Depuis | Changement | Ce qu'il faut faire |
|---|---|---|
| Lot 10 | Les cookies de session et « se souvenir de moi » sont `Secure` en production : un accès en HTTP simple ne garde plus la session. | Servir Zunaflux en HTTPS (derrière un proxy, avec `SYMFONY_TRUSTED_PROXIES`), ou définir `ZUNAFLUX_COOKIE_SECURE=0` pour un essai local en HTTP. |
| Lot 10 | L'imitation d'un compte par un administrateur passe uniquement par le bouton de l'administration (requête POST protégée). Le lien `?_switch_user=…` ne fonctionne plus. Un administrateur ne peut plus imiter un autre administrateur ni enchaîner deux imitations. | Utiliser le bouton « Imiter » de la page Administration, puis « Revenir à mon compte ». |

### Variables ajoutées

- `ZUNAFLUX_COOKIE_SECURE` (défaut : `1` en production) : `0` autorise les cookies en HTTP.
- `ZUNAFLUX_SETUP_TOKEN` (facultative) : si elle est définie, la page `/setup` exige ce jeton pour créer le premier administrateur. Sans elle, créez l'administrateur par la console avant d'exposer le serveur : `php85 bin/console app:user:create EMAIL MOT_DE_PASSE --admin --env=prod`.

### Politique de sécurité du contenu

Zunaflux envoie une politique CSP en mode « rapport seul » : rien n'est bloqué, les violations sont journalisées (`CSP violation reported.`) via `/csp-report`. Elle servira à durcir la politique plus tard.

## Sauvegarder et mettre à jour

Avant une mise à jour, effectuez une sauvegarde cohérente de la base MariaDB, de vos médias locaux et de votre configuration privée. Conservez ces sauvegardes hors du répertoire servi par le serveur web et testez une restauration dans un environnement distinct avant de vous y fier.

```bash
mariadb-dump --single-transaction --routines --events -u ZUNAFLUX_USER -p ZUNAFLUX_DATABASE > zunaflux-YYYY-MM-DD.sql
tar -czf zunaflux-media-YYYY-MM-DD.tar.gz var/media/
cp .env.local zunaflux-env-local-YYYY-MM-DD
```

Pour mettre l'application à jour, lisez les notes de version, sauvegardez d'abord, puis récupérez la version souhaitée et reconstruisez les dépendances et les ressources. Vérifiez les migrations proposées avant de les appliquer : elles modifient la base de données.

```bash
php85 /usr/bin/composer install --no-dev --optimize-autoloader
php85 bin/console tailwind:build --minify --env=prod
php85 bin/console asset-map:compile --env=prod
php85 bin/console doctrine:migrations:migrate --env=prod
```

Après une mise à jour, redémarrez le worker Messenger selon votre supervision et vérifiez la lecture des flux ainsi que les journaux applicatifs. La restauration consiste à réimporter la sauvegarde SQL dans une base MariaDB dédiée, à remettre `var/media/` et `.env.local`, puis à redémarrer les services ; elle doit être testée hors production avant toute utilisation en situation réelle.

## PWA et notifications

L’installation sur l’écran d’accueil dépend du navigateur. Zunaflux est une PWA installée depuis le navigateur : il n’existe pas d’application native distribuée sur Google Play ou l’App Store. Les notifications demandent HTTPS, une configuration VAPID, un worker Messenger et une activation par utilisateur et par appareil. Elles sont actuellement limitées aux services Web Push de Chrome et Firefox. Leur validation a été confirmée par le développeur le 16 novembre 2026 ; la liste des appareils et versions testés n’est pas consignée.

Consultez [la synthèse de configuration](docs/SYNTHESE.md#configuration-validation-et-limites) pour la configuration des clés et les limites actuelles.

## Docker Compose

L'image de production est publiée sur Docker Hub sous `clinuxfr/zunaflux`. Elle est construite et publiée exclusivement par GitHub Actions. Consultez [le guide Docker Compose](docs/SYNTHESE.md#installation-et-mise-à-jour-docker) pour préparer l'environnement et approuver les migrations avant le démarrage des services.

## Développement

Le projet utilise Symfony 8.1, PHP 8.5, Doctrine, Twig, Tailwind CSS et MariaDB 10.11. Le choix technique est une orientation « bleeding edge » plutôt qu’une cible Symfony LTS ; les montées de version restent explicites et validées par le développeur. Pour installer les dépendances de développement, utilisez `php85 /usr/bin/composer install` sans l’option `--no-dev`.

```bash
php85 bin/console lint:container
php85 bin/console lint:twig templates/
php85 vendor/bin/phpunit
```

Les tests fonctionnels écrivent dans la base de test : configurez `DATABASE_URL` pour une base dédiée avant de les lancer. Les tests JavaScript disponibles dans `tests/JavaScript/` peuvent être exécutés avec Node.js, par exemple :

```bash
node tests/JavaScript/reading-selection.test.cjs
```

## Documentation

- [Synthèse globale : état du projet, production, exploitation et limites](docs/SYNTHESE.md)

## Licence

Zunaflux est distribué sous licence [GNU AGPL-3.0 uniquement](LICENSE).
