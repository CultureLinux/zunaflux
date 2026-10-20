# Zunaflux

**Vos sources. Votre rythme.**

Zunaflux est un lecteur RSS et Atom auto-hébergé pour retrouver ses sources dans un espace de lecture personnel, sur ordinateur comme sur mobile.

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

Depuis la racine du projet, créez votre configuration locale à partir de l’exemple fourni :

```bash
cp .env.example .env
```

Modifiez ensuite `.env` : définissez notamment une valeur aléatoire pour `APP_SECRET`, les identifiants MariaDB dans `DATABASE_URL`, et un transport mail adapté si nécessaire. Les exemples de ce fichier ne sont pas des secrets utilisables en production. Le fichier `.env` est ignoré par Git et doit rester privé.

Installez les dépendances puis construisez les ressources :

```bash
composer install --no-dev --optimize-autoloader
php bin/console tailwind:build --minify --env=prod
php bin/console asset-map:compile --env=prod
```

Appliquez ensuite les migrations à la base configurée. **Cette commande modifie la base de données** :

```bash
php bin/console doctrine:migrations:migrate --env=prod
```

Les visiteurs peuvent créer un compte depuis `/register` lorsque l’inscription publique est activée. Pour créer le premier administrateur, la commande du projet demande une adresse et un mot de passe :

```bash
php bin/console app:user:create EMAIL MOT_DE_PASSE --admin --env=prod
```

L’argument de mot de passe peut apparaître dans l’historique du terminal ou la liste des processus : utilisez un environnement adapté et protégez cet accès.

### Collecte des flux

La collecte est mise en file d’attente avec Symfony Messenger. Lancez régulièrement la commande de collecte, par exemple depuis un planificateur :

```bash
php bin/console app:feeds:collect --env=prod
```

Un worker doit être actif pour traiter les messages de collecte et de notification :

```bash
php bin/console messenger:consume async --env=prod
```

Configurez ce worker comme un service supervisé sur votre serveur. La durée de conservation des articles est réglable avec `ZUNAFLUX_RETENTION_MONTHS` ; sa valeur par défaut est de 12 mois.

## PWA et notifications

L’installation sur l’écran d’accueil dépend du navigateur. Les notifications demandent HTTPS, une configuration VAPID, un worker Messenger et une activation par utilisateur et par appareil. Leur fonctionnement réel reste à vérifier sur les navigateurs et appareils ciblés.

Consultez [la documentation PWA et Web Push](docs/pwa-web-push.md) pour la configuration des clés, les limites actuelles et les points à valider.

## Développement

Le projet utilise Symfony 8.1, PHP 8.5, Doctrine, Twig, Tailwind CSS et MariaDB 10.11. Pour installer les dépendances de développement, utilisez `composer install` sans l’option `--no-dev`.

```bash
php bin/console lint:container
php bin/console lint:twig templates/
php vendor/bin/phpunit
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
