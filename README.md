# Zunaflux

Zunaflux est un lecteur RSS/Atom multi-utilisateur auto-hébergé, distribué sous licence AGPL-3.0-only.

Le projet cible PHP 8.5, Symfony 8.1 et MariaDB 10.11. La documentation de référence est le cahier des charges livré à la racine du dépôt.

## Démarrage sur Linux

Prérequis : PHP 8.5 avec les extensions `ctype`, `iconv`, `mbstring`, `pdo_mysql`, `xml`, `dom`, `curl`, `intl` et `openssl`; Composer 2; MariaDB 10.11.

```bash
composer install
cp .env .env.local
# Renseigner APP_SECRET, DATABASE_URL et MAILER_DSN dans .env.local.
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console tailwind:build --minify
php bin/console asset-map:compile
```

L'application est servie par Nginx et PHP-FPM ; le binaire Symfony CLI n'est pas requis.

Les visiteurs peuvent créer un compte depuis `/register`. Pour créer le premier administrateur, utilisez la commande suivante dans un terminal non journalisé ou en fournissant le mot de passe de manière sûre :

```bash
php bin/console app:user:create admin@zunaflux.clinux.fr 'change-this-password' --admin
```

## Validation

Les commandes de validation et les opérations modifiant la base sont documentées dans les comptes-rendus de lots. Ne versionnez jamais `.env.local` ni aucun secret SMTP ou MariaDB.

`ZUNAFLUX_RETENTION_MONTHS` règle la conservation des articles ; sa valeur par défaut est `12`.
