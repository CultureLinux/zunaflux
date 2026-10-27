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

- Les notifications Web Push sont encore en validation sur appareils réels et navigateurs ciblés.
- La lecture hors ligne n'est pas prise en charge : l'application nécessite une connexion réseau pour afficher les articles.
- Avant toute mise à jour, sauvegardez la base de données, `var/media/` et votre configuration locale.

<a href="https://ko-fi.com/E1E31E9X8Y" target="_blank"><img height="36" style="border:0px;height:36px;" src="https://storage.ko-fi.com/cdn/kofi5.png?v=6" border="0" alt="Donate" /></a>

![Illustration de la salle de lecture Zunaflux](public/images/lecture-editoriale.png)

## Votre veille, à votre façon

- **Suivez vos sources** : ajoutez des flux RSS et Atom, organisez-les par catégories et importez ou exportez vos abonnements au format OPML.
- **Lisez à votre rythme** : retrouvez vos articles lus et non lus, marquez vos favoris et gardez des articles pour plus tard.
- **Retrouvez l’essentiel** : recherchez dans vos articles et vos sources, enregistrez des filtres et appliquez des actions à plusieurs articles.
- **Choisissez votre espace** : thème clair, sombre ou système, préférences de lecture et vue en colonnes sur ordinateur.
- **Partagez une instance, pas votre lecture** : les flux et les articles sont mutualisés, tandis que les abonnements, favoris et états de lecture restent propres à chaque compte.
- **Gardez la main** : exportez vos données et administrez les comptes, les sources et les incidents depuis votre instance.

Zunaflux propose également une installation sur l’écran d’accueil depuis un navigateur compatible. Les notifications Web Push pour les nouveaux articles et les erreurs de collecte sont en cours de validation sur appareil. L’application nécessite une connexion réseau pour lire les articles.

## Installer Zunaflux

Zunaflux s’adresse aux personnes qui souhaitent héberger leur propre lecteur. L’installation ci-dessous vise un serveur Linux avec Nginx et PHP-FPM ; adaptez les chemins et les commandes PHP à votre environnement.

### Prérequis

- PHP 8.5 et ses extensions `ctype`, `iconv`, `mbstring`, `pdo_mysql`, `xml`, `dom`, `curl`, `intl` et `openssl` ;
- Composer 2 et MariaDB 10.11 ;
- un serveur web configuré pour servir le répertoire `public/` ;
- HTTPS pour l’installation PWA et les notifications.

### Configuration et installation

Depuis la racine du projet, créez votre configuration locale privée à partir de l’exemple fourni :

```bash
cp .env.example .env.local
```

Modifiez ensuite `.env.local` : définissez notamment une valeur aléatoire pour `APP_SECRET`, les identifiants MariaDB dans `DATABASE_URL`, et un transport mail adapté si nécessaire. Les exemples de ce fichier ne sont pas des secrets utilisables en production. Le fichier `.env.local` est ignoré par Git et doit rester privé.

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

Configurez ce worker comme un service supervisé sur votre serveur. La durée de conservation des articles est réglable avec `ZUNAFLUX_RETENTION_MONTHS` ; sa valeur par défaut est de 12 mois.

Un exemple prêt pour systemd est fourni dans [`docs/messenger-systemd.md`](docs/messenger-systemd.md).

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

L’installation sur l’écran d’accueil dépend du navigateur. Zunaflux est une PWA installée depuis le navigateur : il n’existe pas d’application native distribuée sur Google Play ou l’App Store. Les notifications demandent HTTPS, une configuration VAPID, un worker Messenger et une activation par utilisateur et par appareil. Elles sont actuellement limitées aux services Web Push de Chrome et Firefox ; leur fonctionnement réel reste à vérifier sur les navigateurs et appareils ciblés.

Consultez [la documentation PWA et Web Push](docs/pwa-web-push.md) pour la configuration des clés, les limites actuelles et les points à valider.

## Docker Compose

L'image de production est publiée sur Docker Hub sous `clinuxfr/zunaflux`. Elle est construite et publiée exclusivement par GitHub Actions. Consultez [le guide Docker Compose](docs/docker.md) pour préparer l'environnement, démarrer les services et appliquer explicitement les migrations.

## Développement

Le projet utilise Symfony 8.1, PHP 8.5, Doctrine, Twig, Tailwind CSS et MariaDB 10.11. Pour installer les dépendances de développement, utilisez `php85 /usr/bin/composer install` sans l’option `--no-dev`.

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

- [PWA et notifications Web Push](docs/pwa-web-push.md)
- [Format d’export des données](docs/export-format-v1.md)
- [Direction artistique](docs/direction-artistique.md)

## Licence

Zunaflux est distribué sous licence [GNU AGPL-3.0 uniquement](LICENSE).
