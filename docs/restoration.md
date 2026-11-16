# Sauvegarde, restauration et approbation des mises à jour

Le lot 8 fournit un exercice synthétique autonome, exécuté avec succès sur Linux le 16 novembre 2026 : voir le [compte-rendu](lot-8-restoration-validation.md). Il ne restaure aucune donnée de production et ne démontre pas la restaurabilité de vos sauvegardes réelles. Sa clôture exige le retour explicite du développeur.

## Préparer une sauvegarde exploitable

Une sauvegarde comprend un ensemble cohérent : dump MariaDB 10.11, médias, configuration privée, fichiers VAPID, clé `ZUNAFLUX_MASTODON_TOKEN_KEY`, référence immuable de l’image ou des sources, fichiers Compose et éventuels fichiers de surcharge. Conserver aussi les versions des migrations appliquées. La clé Mastodon permet de déchiffrer les jetons existants ; une autre clé ne les récupère pas. La paire VAPID doit rester stable pour les abonnements Push existants.

Arrêter les écritures pendant la capture de cet ensemble, selon la supervision choisie par l’exploitant : application web, worker, collecteur et ordonnanceur de notifications. Un dump `--single-transaction` protège la cohérence des tables transactionnelles mais ne synchronise pas à lui seul la base, les médias et les opérations DDL. Ne pas lancer de migration pendant la sauvegarde. Les commandes d’arrêt et de reprise doivent être approuvées par l’exploitant ; l’agent ne les exécute pas en production.

Utiliser `mariadb-dump --single-transaction --hex-blob --routines --events --triggers`, avec les identifiants dans un fichier d’options privé ou saisis de façon interactive. Ne pas placer le mot de passe dans les arguments. Archiver les médias et les fichiers privés dans un emplacement hors du répertoire public, avec des permissions restrictives, puis chiffrer et conserver une copie hors du serveur. Vérifier le code de sortie de chaque commande et les sommes de contrôle ; la présence d’un fichier SQL non vide ne suffit pas.

## Vérifications sans modification de base

Depuis la racine du dépôt sur Linux. Les commandes suivantes ne créent aucun conteneur et n’accèdent pas aux données applicatives ; Docker Compose lit uniquement `docker/env.test` et des valeurs synthétiques. Les tests de refus utilisent un substitut du CLI Docker pour exercer le vrai script, sans daemon.

```bash
php85 -v
php85 -l tests/Restoration/fixture.php
sh -n tests/Restoration/run.sh
sh -n tests/Restoration/php85
sh -n tests/Container/restoration-preflight.sh
sh tests/Container/restoration-preflight.sh
sh tests/Container/compose-config.sh
sh tests/Container/compose-private-config.sh
git diff --check
```

Attendu : PHP 8.5, aucune erreur de syntaxe, refus des deux endpoints distants et de l’image absente, variables privées transmises aux services et aucun défaut d’espace. En cas d’échec, ne pas lancer l’exercice ; vérifier la version de Compose et le message du contrôle concerné. Ne jamais transmettre le rendu de `docker/.env` ni une configuration contenant de vrais secrets.

Sur Windows sans PHP ni Docker, les seuls contrôles proposés sont ceux des sources et de Git :

```powershell
git diff --check
git status --short
Get-Content tests/Restoration/run.sh
Get-Content tests/Restoration/compose.yaml
Get-Content tests/Restoration/fixture.php
```

Attendu : diff sans erreur et périmètre conforme. Cela ne valide ni PHP, ni MariaDB, ni la restauration.

## Exercice Docker — approbation humaine obligatoire

Cette étape crée des conteneurs, un réseau interne et deux volumes jetables, applique les migrations à une base synthétique, importe deux dumps et supprime les ressources de l’exercice. Elle modifie donc l’environnement Docker et les données de test. Elle nécessite l’approbation du développeur avant exécution.

Prérequis : Docker local par socket Unix, Compose v2 avec `--wait`, PHP 8.5 sur l’hôte, images applicative et `mariadb:10.11` déjà présentes. Aucune installation, construction, récupération d’image ou publication n’est réalisée par le script. L’image applicative doit correspondre à la version à qualifier ; seul son code embarqué est testé, avec les fixtures du dépôt montées en lecture seule.

Lire les images disponibles avant de choisir la référence :

```bash
docker image ls --format '{{.Repository}}:{{.Tag}} {{.ID}}'
```

Après approbation, si `clinuxfr/zunaflux:restoration-lot8` est bien l’image locale à qualifier :

```bash
sh tests/Restoration/run.sh clinuxfr/zunaflux:restoration-lot8
```

Le script fige les deux images par ID, génère un nom de projet aléatoire non configurable et des secrets synthétiques. Il refuse un endpoint Docker distant, même via `DOCKER_HOST` ou `DOCKER_CONTEXT`, et ignore les fichiers `.env` pour son Compose. Il ne publie aucun port et n’utilise ni volume externe ni montage de données de l’hôte. La base n’accepte que les trois noms fixes `zunaflux_restore_source`, `zunaflux_restore_target` et `zunaflux_restore_rollback`, derrière le nom de service `database`, avec une garde explicite.

Le parcours vérifie :

1. Migration d’une source vide, création d’un compte, d’un article favori/à-lire, d’un compte Mastodon chiffré, d’un appareil synthétique et d’un événement outbox.
2. Sauvegarde SQL avec UUID binaires en hexadécimal et archive des médias/configurations privées.
3. Restauration dans une base neuve : identités, mot de passe, états personnels, versions des migrations, empreinte du média, déchiffrement du jeton, rejet des clés absentes/fausses et cohérence VAPID.
4. Exécution des commandes réelles de collecte et de notifications, consommation d’un message par Messenger, contrôle de l’outbox, du digest et du heartbeat.
5. Modification volontaire du schéma, d’un article et du média de la cible, puis restauration de la sauvegarde antérieure dans une troisième base neuve. Les contrôles doivent retrouver le schéma et les fichiers d’origine.

La collecte est mise en file mais sa requête réseau n’est pas consommée. Le worker de notification utilise une file séparée. Les fichiers VAPID ne sont pas activés dans le conteneur : aucun envoi Web Push n’est effectué. Ce parcours démontre la reprise interne des traitements, pas la collecte externe ni la réception sur appareil (Web Push validé séparément par le développeur le 16 novembre 2026).

Attendu : code de sortie 0, message `SQL, media, encryption, processing and rollback checks passed.`, puis rapport `var/restoration/zunaflux-restore-*.json` avec `passed: true`, `last_phase: complete`, `cleanup_ok: true` et les deux IDs d’image. Le rapport ne contient aucune clé ni URL de connexion. Ne pas considérer un résultat partiel comme un succès.

## Diagnostic et nettoyage

Le rapport indique la dernière phase atteinte : `isolated_database`, `source_fixture`, `backup`, `restore`, `restored_processing` ou `rollback`. Une erreur avant la création du projet, telle qu’une image absente, n’écrit pas de rapport. Une image périmée ou une migration absente doit conduire à qualifier une image appropriée, sans adapter les données réelles au test.

Le nettoyage automatique cible exclusivement le projet aléatoire créé par le script et vérifie l’absence de ses conteneurs et volumes. Il s’exécute aussi en cas d’échec ou de signal interceptable. Un arrêt brutal de l’hôte ou un `SIGKILL` peut empêcher ce nettoyage. En cas de `cleanup_ok: false`, relever le nom exact dans le rapport, puis inspecter en lecture seule, en remplaçant la valeur ci-dessous par ce nom exact :

```bash
task_restore_project='zunaflux-restore-REMPLACER_PAR_LE_NOM_DU_RAPPORT'
docker ps -a --filter "label=com.docker.compose.project=$task_restore_project"
docker volume ls --filter "label=com.docker.compose.project=$task_restore_project"
docker network ls --filter "label=com.docker.compose.project=$task_restore_project"
```

Faire approuver toute suppression manuelle après avoir vérifié les labels. Ne jamais exécuter `docker system prune`, ni `down --volumes` sur le projet de production. Le dump temporaire et les secrets synthétiques sont supprimés ; seul le rapport est conservé. Une nouvelle exécution produit un nouvel environnement et ne réutilise pas une cible partiellement restaurée. Pour diagnostiquer une erreur PHP, inspecter les sources de la phase indiquée ; ne pas publier de dumps ou de journaux contenant des secrets.

## Ordre d’une mise à jour de production

Les opérations de cette section relèvent du développeur. Elles ne sont pas exécutées par l’agent.

1. Choisir une version immuable et lire ses notes ; récupérer l’image seulement après approbation de cette modification d’environnement. Archiver aussi la configuration Compose correspondante.
2. Tester cette version et la restauration d’une sauvegarde dans un environnement distinct. Identifier les migrations ajoutées et leurs effets, la compatibilité de la configuration et l’espace disque nécessaire.
3. Planifier la fenêtre d’arrêt des écritures, réaliser une sauvegarde cohérente et contrôler son intégrité.
4. Inspecter le SQL proposé sur une copie isolée. Un `--dry-run` n’applique pas le SQL métier mais peut initialiser les métadonnées Doctrine : ne pas le traiter comme une garantie d’absence totale d’écriture sur une base réelle.
5. Obtenir l’approbation humaine explicite des migrations et du retour arrière avant `up -d`. Le Compose actuel applique automatiquement les migrations via son service `migrations` ; cette topologie reste inchangée.
6. Mettre à jour, puis vérifier HTTP, connexion, lecture, médias, migrations, worker, collecte et notifications. Contrôler aussi la progression des files et les logs ; des processus démarrés ne suffisent pas à prouver le traitement.

Inspection sur copie isolée et application, **toutes deux soumises à approbation humaine** ; ne pas copier ces commandes sur la production sans vérifier le projet et `docker/.env` :

```bash
# Copie isolée configurée pour la version cible ; ne pas lancer les dépendances.
docker compose --env-file docker/.env -f compose.yaml run --rm --no-deps --entrypoint /usr/local/bin/php migrations bin/console doctrine:migrations:migrate --env=prod --no-interaction --dry-run
# Production, uniquement après approbation de la sauvegarde et des migrations.
docker compose --env-file docker/.env -f compose.yaml up -d
```

L’image PHP 8.5 de production nomme son interpréteur `/usr/local/bin/php`, sans alias `php85` : la commande Docker utilise donc ce chemin comme entrypoint. Sur l’hôte utiliser exclusivement `php85`. Le lanceur de l’exercice fournit son propre wrapper sans modifier l’image.

Contrôles de services en lecture seule, après l’intervention du développeur :

```bash
docker compose --env-file docker/.env -f compose.yaml ps -a
docker compose --env-file docker/.env -f compose.yaml logs --tail=100 migrations worker collector notifications
```

Attendu : migrations terminées sans erreur, services attendus actifs, aucune boucle de redémarrage et traitements qui progressent. Examiner ces logs localement : ils peuvent contenir des données personnelles.

## Retour arrière réel

Un retour à une image ancienne ne suffit pas après une migration incompatible. Arrêter les écritures après approbation, restaurer la sauvegarde dans une nouvelle base dédiée, remettre les médias et les secrets de la même sauvegarde, puis sélectionner les sources/image et la configuration compatibles avec cet état. Contrôler le schéma avant de reconnecter les services. Conserver la base échouée jusqu’à validation du retour arrière ; ne pas tenter de migrations descendantes improvisées.

La restauration perd les écritures postérieures à la sauvegarde. Les envois externes déjà acceptés ne sont pas annulés par un retour arrière ; le rétablissement d’anciens curseurs peut entraîner une nouvelle notification. L’exercice ne mesure pas encore les objectifs de perte de données et de délai de reprise d’une instance réelle, et ne remplace pas un test périodique de ses propres sauvegardes.
