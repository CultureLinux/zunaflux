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
php bin/console app:user:create admin@zunaflux.clinux.fr 'change-this-password' --admin
php bin/console tailwind:build --minify
```

L'application est servie par Nginx et PHP-FPM ; le binaire Symfony CLI n'est pas requis.

La commande de création de compte doit être exécutée dans un terminal non journalisé ou avec un mot de passe fourni de manière sûre : elle est une solution de bootstrap temporaire et sera remplacée par le parcours d’administration du lot 6.

## Validation

Les commandes de validation et les opérations modifiant la base sont documentées dans les comptes-rendus de lots. Ne versionnez jamais `.env.local` ni aucun secret SMTP ou MariaDB.
