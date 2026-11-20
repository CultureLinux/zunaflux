# Zunaflux — Synthèse globale

État accepté par le développeur le 16 novembre 2026. Mise en production sous sa responsabilité ; aucun déploiement effectué par l’agent.

## Projet et bilan

Lecteur RSS/Atom multi-utilisateur : PHP 8.5, Symfony 8.1, Twig, Stimulus, Tailwind, MariaDB 10.11 ; licence AGPL-3.0-only. Versions modifiées uniquement sur décision explicite.

Les huit lots d’amélioration sont acceptés : CI/publication, reprise Messenger, tests métier et navigateur, performances, maintenance et restauration. Dernières preuves consignées : 187 tests PHP / 843 assertions, 57 tests JavaScript, 14 parcours Chromium sans échec ; PHPStan sans erreur. Collecte à 100 abonnés : 208 → 8 instructions SQL. Web Push validé par le développeur.

La restauration SQL/médias/clés, la reprise interne des traitements et le retour arrière ont réussi sur Docker isolé, avec nettoyage confirmé. Lors de cette reprise : 102 tests unitaires / 298 assertions sans échec ; suite fonctionnelle complète non relancée. Les rapports locaux restent dans `var/restoration/`.

## Installation et mise à jour Docker

Image `clinuxfr/zunaflux`, FrankenPHP sur le port 8080, publié par défaut sur `127.0.0.1` (`ZUNAFLUX_HTTP_BIND`), derrière un proxy HTTPS. Préparer `docker/.env` depuis `docker/env.example`, remplacer les identifiants et choisir une version précise : `ZUNAFLUX_IMAGE_TAG` est obligatoire, sans valeur par défaut.

Contrôle sans modification des données :

```bash
docker compose --env-file docker/.env -f compose.yaml config --quiet
```

**Après sauvegarde et approbation humaine des migrations**, ces commandes modifient l’environnement et peuvent modifier la base :

```bash
docker compose --env-file docker/.env -f compose.yaml pull
docker compose --env-file docker/.env -f compose.yaml up -d database
docker compose --env-file docker/.env -f compose.yaml --profile migrate run --rm migrations
docker compose --env-file docker/.env -f compose.yaml up -d
```

Les migrations ne sont **jamais** appliquées par `up` : le service `migrations` est sous le profil `migrate` et se lance à la main, à chaque installation et à chaque mise à jour, après lecture des notes de version et sauvegarde. Un `up` sans migration laisse `/healthz` en 503 tant que le schéma n'est pas à jour. Le premier administrateur se crée via `/setup` (protégé par `ZUNAFLUX_SETUP_TOKEN` s'il est défini). Installation manuelle : [README](../README.md#installer-zunaflux).

## Exploitation et sauvegardes

Compose supervise application, worker, collecte et notifications, avec un contrôle de santé par service : `/healthz` pour l'application, `app:worker:healthcheck` (battement du worker) pour le worker, et un fichier de vie rafraîchi à chaque cycle pour la collecte et les notifications. `php bin/console app:status --env=prod` (option `--json`) résume le worker, la file, les messages en échec et les incidents de flux, avec un code de sortie 1 si quelque chose demande de l'attention. Les conteneurs applicatifs sont en lecture seule (`read_only`), sans capacités Linux, avec des limites de mémoire et de CPU. Hors Docker, superviser `messenger:consume async`, planifier `app:feeds:collect` et exécuter `app:notifications:send` chaque minute, lancer `app:maintenance:purge-articles --max-seconds=60` périodiquement (par exemple chaque heure ; dans Docker, `collect-feeds.sh` s’en charge), via `php85 bin/console … --env=prod`. Adapter l’[unité systemd](../deploy/systemd/zunaflux-messenger.service) fournie.

L’outbox conserve les événements à relayer ; `failed` conserve les échecs définitifs. Inspecter avec `php85 bin/console messenger:failed:show --env=prod`. Le rejeu nécessite une décision humaine et peut renotifier ; un envoi externe et son curseur ne forment pas une transaction unique.

Sauvegarder ensemble base, médias, configuration privée, paire VAPID, clé stable `ZUNAFLUX_MASTODON_TOKEN_KEY`, référence d’image et Compose. Suspendre les écritures pour une capture cohérente ; utiliser `mariadb-dump --single-transaction --hex-blob --routines --events --triggers`. Protéger les sauvegardes hors du répertoire public et tester leur restauration isolée.

Le retour arrière restaure un ensemble compatible base/médias/secrets/image et perd les écritures postérieures à la sauvegarde. Ne jamais supprimer les volumes de production avec `down --volumes`.

Contrôler `docker compose --env-file docker/.env -f compose.yaml ps -a` et les logs de `migrations`, `worker`, `collector`, `notifications` : migrations réussies, services actifs et traitements qui progressent.

## Configuration, validation et limites

Web Push : HTTPS, activation par appareil, fichiers VAPID déposés dans `docker/secrets/` (`vapid-public.txt`, `vapid-private.pem`, répertoire non versionné monté en lecture seule dans `app`, `worker` et `notifications`) et `ZUNAFLUX_VAPID_SUBJECT`. Fournisseurs autorisés : Chrome et Firefox. Mastodon : origine publique et clé base64 stable de 32 octets, à conserver avec les sauvegardes.

Les commandes de validation sont dans [la CI](../.github/workflows/ci.yml). Tests fonctionnels, migrations, navigateur et performance : bases dédiées exclusivement. Playwright nécessite Node 24. Assets : `php85 bin/console tailwind:build`, puis `php85 bin/console asset-map:compile`. Dépendances : `php85 /usr/bin/composer install`, selon le verrou.

Publication exclusivement par GitHub Actions, après CI réussie, sur un tag `vMAJEUR.MINEUR.CORRECTIF[-préversion]` valide. Les préversions ne déplacent pas `latest` ; les versions de maintenance ne font pas reculer les alias stables. Ne pas déplacer les tags Git.

Limites : lecture en ligne uniquement ; export ZIP v1 sans secrets et sans réimportation complète des états de lecture. Maintenance restante : notices PHPUnit connues, avis de sécurité précédemment signalés et stabilité du budget temporel à un abonné. L’acceptation ne remplace pas le contrôle des résultats GitHub Actions.
