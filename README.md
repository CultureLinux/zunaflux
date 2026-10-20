# Zunaflux

![Une salle de lecture et un flux d’articles](public/images/lecture-editoriale.png)

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

## PWA 

# Depuis la racine du projet
```
# Aller dans le projet symfony

# Le répertoire sera au même niveau que le projet.
VAPID_DIR="$(dirname "$PWD")/zunaflux-secrets"
printf 'Répertoire des clés : %s\n' "$VAPID_DIR"

# Empêche la lecture des nouveaux fichiers par d'autres utilisateurs.
umask 077
mkdir -p "$VAPID_DIR"
chmod 700 "$VAPID_DIR"

# Ne pas remplacer une clé existante : les appareils déjà abonnés
# pourraient devoir être réinscrits.
if [ -e "$VAPID_DIR/vapid-private.pem" ]; then
    printf 'Clé existante : génération annulée.\n' >&2
else
    openssl ecparam -genkey -name prime256v1 \
        -out "$VAPID_DIR/vapid-private.pem"
    chmod 600 "$VAPID_DIR/vapid-private.pem"
fi

# Vérifie la clé privée sans afficher son contenu.
openssl ec -in "$VAPID_DIR/vapid-private.pem" -check -noout

# Extrait la clé publique au format attendu par le navigateur.
openssl ec -in "$VAPID_DIR/vapid-private.pem" -pubout -outform DER \
    | tail -c 65 \
    | base64 \
    | tr -d '=\n' \
    | tr '/+' '_-' > "$VAPID_DIR/vapid-public.txt"

chmod 600 "$VAPID_DIR/vapid-public.txt"
```