#!/usr/bin/env sh
# Synthetic end-to-end drill. Never accepts an existing project, DSN or dump.
set -eu
umask 077

task_image=${1:?Usage: run.sh LOCAL_APPLICATION_IMAGE}
task_root=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd)
# DOCKER_CONTEXT has precedence over DOCKER_HOST in the Docker CLI. Resolve
# that same endpoint, then pin it for every command including cleanup.
if [ -n "${DOCKER_CONTEXT:-}" ]; then
    task_endpoint=$(docker context inspect --format '{{ .Endpoints.docker.Host }}' "$DOCKER_CONTEXT")
elif [ -n "${DOCKER_HOST:-}" ]; then
    task_endpoint=$DOCKER_HOST
else
    task_endpoint=$(docker context inspect --format '{{ .Endpoints.docker.Host }}')
fi
case "$task_endpoint" in
    unix://*) ;;
    *) printf '%s\n' 'Only a local Unix Docker context is accepted.' >&2; exit 64 ;;
esac
export DOCKER_HOST="$task_endpoint"
unset DOCKER_CONTEXT

# Assign before exporting so a failed inspect/key generation preserves its exit
# status under set -e, before any resources or cleanup trap are created.
RESTORE_TEST_IMAGE=$(docker image inspect --format '{{.Id}}' "$task_image")
# The database image is the digest-pinned reference of the production Compose file.
task_db_reference=$(docker compose --env-file "$task_root/docker/env.test" -f "$task_root/compose.yaml" config --images | grep '^mariadb:' | head -n 1)
docker image inspect "$task_db_reference" >/dev/null 2>&1 || docker pull --quiet "$task_db_reference" >/dev/null
RESTORE_TEST_DB_IMAGE=$(docker image inspect --format '{{.Id}}' "$task_db_reference")
RESTORE_TEST_DB_PASSWORD=$(php85 -r 'echo bin2hex(random_bytes(24));')
RESTORE_TEST_APP_SECRET=$(php85 -r 'echo bin2hex(random_bytes(32));')
RESTORE_TEST_TOKEN_KEY=$(php85 -r 'echo base64_encode(random_bytes(32));')
export RESTORE_TEST_IMAGE RESTORE_TEST_DB_IMAGE RESTORE_TEST_DB_PASSWORD
export RESTORE_TEST_APP_SECRET RESTORE_TEST_TOKEN_KEY
task_project="zunaflux-restore-$(php85 -r 'echo bin2hex(random_bytes(8));')"
task_private=$(mktemp -d /tmp/zunaflux-restore.XXXXXXXX)
task_phase=initialization
mkdir -p "$task_root/var/restoration"
task_report="$task_root/var/restoration/$task_project.json"

compose() {
    docker compose --env-file /dev/null --project-name "$task_project" -f "$task_root/tests/Restoration/compose.yaml" "$@"
}

cleanup() {
    task_result=$?
    trap - EXIT INT TERM
    set +e
    task_clean=1
    compose down --volumes --remove-orphans >/dev/null 2>&1 || task_clean=0
    if [ "$task_clean" = 1 ]; then
        task_left=$(docker ps -aq --filter "label=com.docker.compose.project=$task_project") || task_clean=0
        task_volumes=$(docker volume ls -q --filter "label=com.docker.compose.project=$task_project") || task_clean=0
        task_networks=$(docker network ls -q --filter "label=com.docker.compose.project=$task_project") || task_clean=0
        [ -z "$task_left$task_volumes$task_networks" ] || task_clean=0
    fi
    rm -f "$task_private/database.sql"
    rmdir "$task_private" || task_clean=0
    [ "$task_clean" = 1 ] || task_result=1
    php85 -r 'echo json_encode([
        "project" => $argv[1], "application_image_id" => $argv[2],
        "database_image_id" => $argv[3], "passed" => "0" === $argv[4],
        "last_phase" => $argv[5], "cleanup_ok" => "1" === $argv[6],
        "scope" => "synthetic SQL/media/keys restore and rollback; no external collection or Web Push",
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;' \
        "$task_project" "$RESTORE_TEST_IMAGE" "$RESTORE_TEST_DB_IMAGE" "$task_result" "$task_phase" "$task_clean" > "$task_report" || task_result=1
    printf 'Restore exercise report: %s\n' "$task_report"
    exit "$task_result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

sql() {
    compose exec -T database mariadb --defaults-extra-file=/tmp/zunaflux-restore.cnf "$@"
}
run_on() {
    task_database=$1
    shift
    compose run --rm --no-deps \
        -e "DATABASE_URL=mysql://root:$RESTORE_TEST_DB_PASSWORD@database:3306/$task_database?serverVersion=mariadb-10.11.0&charset=utf8mb4" \
        runner "$@"
}
notifications() {
    compose run --rm --no-deps \
        -e "DATABASE_URL=mysql://root:$RESTORE_TEST_DB_PASSWORD@database:3306/zunaflux_restore_target?serverVersion=mariadb-10.11.0&charset=utf8mb4" \
        -e 'MESSENGER_TRANSPORT_DSN=doctrine://default?queue_name=restore_notifications' \
        runner "$@"
}

task_phase=isolated_database
compose up -d --wait --wait-timeout 90 database
compose exec -T database sh -c 'umask 077; printf "[client]\nuser=root\npassword=%s\n" "$MARIADB_ROOT_PASSWORD" > /tmp/zunaflux-restore.cnf'
sql --batch --skip-column-names -e 'SELECT VERSION()' | grep -Eq '^10\.11\.[0-9]+.*MariaDB'
sql -e 'CREATE DATABASE zunaflux_restore_source CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE zunaflux_restore_target CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE zunaflux_restore_rollback CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;'

task_phase=source_fixture
run_on zunaflux_restore_source bin/console doctrine:migrations:migrate --env=prod --no-interaction
run_on zunaflux_restore_source /exercise/fixture.php seed
run_on zunaflux_restore_source /exercise/fixture.php verify

task_phase=backup
compose exec -T database mariadb-dump --defaults-extra-file=/tmp/zunaflux-restore.cnf \
    --single-transaction --hex-blob --routines --events --triggers \
    zunaflux_restore_source > "$task_private/database.sql"
test -s "$task_private/database.sql"

task_phase=restore
sql zunaflux_restore_target < "$task_private/database.sql"
run_on zunaflux_restore_target /exercise/fixture.php restore-files
run_on zunaflux_restore_target /exercise/fixture.php verify

task_phase=restored_processing
# Collector dispatches a real job, but its network fetch is deliberately not run.
run_on zunaflux_restore_target bin/console app:feeds:collect --env=prod --no-interaction
# Separate the notification worker from the queued collection job.
notifications bin/console app:notifications:send --env=prod --no-interaction
notifications bin/console messenger:consume async --env=prod --limit=1 --time-limit=15 --memory-limit=128M --no-interaction
notifications bin/console app:notifications:send --env=prod --no-interaction
run_on zunaflux_restore_target /exercise/fixture.php verify-processing

task_phase=rollback
run_on zunaflux_restore_target /exercise/fixture.php mutate
sql zunaflux_restore_rollback < "$task_private/database.sql"
run_on zunaflux_restore_rollback /exercise/fixture.php restore-files
run_on zunaflux_restore_rollback /exercise/fixture.php verify
task_phase=complete
printf '%s\n' 'SQL, media, encryption, processing and rollback checks passed.'
