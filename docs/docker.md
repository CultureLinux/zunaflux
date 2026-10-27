# Déployer Zunaflux avec Docker Compose

L'image de production est publiée sur Docker Hub sous `clinuxfr/zunaflux`. Les images sont construites et publiées uniquement par GitHub Actions ; ne publiez pas depuis votre poste ou votre serveur.

## Prérequis

- Docker Engine avec Docker Compose v2 ;
- un nom de domaine et un proxy HTTPS devant le port HTTP de Zunaflux pour la PWA et les notifications ;
- une image Zunaflux déjà publiée sur Docker Hub ;
- un serveur disposant d'un stockage persistant pour MariaDB et les médias.

## Préparer la configuration

L'image embarque FrankenPHP, la configuration HTTP et les fichiers statiques compilés. Le service `app` sert directement l'application sur le port 8080 ; aucun fichier de configuration du serveur web n'est à monter depuis l'hôte.

Copiez l'exemple et remplacez tous les mots de passe. `DATABASE_URL` doit contenir le même mot de passe que `MARIADB_PASSWORD`, encodé pour une URL si nécessaire.

```bash
cp docker/env.example docker/.env
chmod 600 docker/.env
docker compose --env-file docker/.env -f compose.yaml config --quiet
```

La dernière commande est une vérification sans effet sur les données. Le fichier `docker/.env` reste local et ne doit jamais être versionné.

## Premier démarrage

Après validation humaine, téléchargez l'image et démarrez les services :

```bash
docker compose --env-file docker/.env -f compose.yaml pull
docker compose --env-file docker/.env -f compose.yaml up -d
```

Ces commandes créent ou mettent à jour les conteneurs, les réseaux et les volumes Docker. Au premier démarrage, le service `migrations` crée le schéma Zunaflux.

### Première installation

Au premier `up -d`, le service ponctuel `migrations` applique automatiquement le schéma après que MariaDB est devenue saine. Les services `app`, `worker` et `collector` ne démarrent qu'après son succès.

Ouvrez ensuite `http://localhost:8080/setup` pour créer le premier administrateur. Cette page n'est disponible que tant qu'aucun compte n'existe ; elle reste accessible même lorsque `ZUNAFLUX_PUBLIC_REGISTRATION=0`.

Le formulaire crée un compte administrateur et modifie la base. Vérifiez l'adresse e-mail et le mot de passe saisis avant validation.

### Mise à jour — modifie la base

Chaque `up -d` exécute aussi les migrations non appliquées. Avant une mise à jour de production, sauvegardez la base et les médias, puis inspectez les migrations proposées :

```bash
docker compose --env-file docker/.env -f compose.yaml run --rm migrations php bin/console doctrine:migrations:migrate --env=prod --no-interaction --dry-run
```

Ne lancez `up -d` qu'après validation humaine des migrations et de la sauvegarde.

## Exploitation

Le worker Messenger et le collecteur sont déjà démarrés comme services distincts. Consultez leurs journaux avec :

```bash
docker compose --env-file docker/.env -f compose.yaml logs --tail=100 worker collector
docker compose --env-file docker/.env -f compose.yaml ps
```

Les notifications Web Push exigent que les fichiers VAPID soient montés dans `app`, `worker` et `collector` au même chemin interne que celui défini dans `docker/.env`. Ajoutez un fichier Compose local non versionné pour ces montages ; ne placez jamais une clé privée dans l'image ou dans Git.

## Passage de Nginx à FrankenPHP

Attendez qu'une image contenant FrankenPHP soit publiée par GitHub Actions, puis utilisez cette image et le nouveau `compose.yaml` ensemble. L'ancienne image PHP-FPM ne sert pas HTTP sur le port 8080.

Après sauvegarde de la base et des médias, téléchargez l'image et recréez les services en supprimant les anciens conteneurs `web` et `assets` :

```bash
docker compose --env-file docker/.env -f compose.yaml pull
docker compose --env-file docker/.env -f compose.yaml up -d --remove-orphans
```

Conservez le même nom de projet Compose pour retrouver les volumes `database-data` et `media-data`. L'ancien volume `public-assets` n'est plus utilisé et peut rester en place ; ne lancez pas `down --volumes` sur votre installation.

Si vous utilisez Traefik, rattachez désormais le service `app` au réseau du proxy et placez ses labels de routage sur ce service avec le port cible `8080`. Traefik termine HTTPS ; FrankenPHP sert HTTP à l'intérieur du réseau Docker. Le port hôte reste configurable avec `ZUNAFLUX_HTTP_PORT`.

## Mise à jour et retour arrière

Avant toute mise à jour, sauvegardez la base MariaDB et le volume `media-data`. Préférez un tag versionné, par exemple `1.2.3`, à `latest` en production.

```bash
docker compose --env-file docker/.env -f compose.yaml pull
docker compose --env-file docker/.env -f compose.yaml up -d
```

Après approbation humaine, vérifiez les migrations proposées ; `up -d` les appliquera via le service `migrations` :

```bash
docker compose --env-file docker/.env -f compose.yaml run --rm migrations php bin/console doctrine:migrations:migrate --env=prod --no-interaction --dry-run
```

Pour revenir en arrière, restaurez d'abord la base et les médias si une migration a été appliquée, puis restaurez aussi le Compose de cette version et fixez `ZUNAFLUX_IMAGE_TAG` sur le tag précédent dans `docker/.env` et relancez `pull` puis `up -d`. Ne supprimez pas les volumes sans sauvegarde validée.
