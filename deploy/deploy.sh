#!/bin/sh
# Deploys (or rolls back) the stack in this directory. Shipped next to docker-compose.yml by CI.
#
#   sh deploy.sh            run the stack's normal tag (IMAGE_TAG in .env, else "latest")
#   sh deploy.sh <tag>      run a specific image, e.g. a commit SHA, to roll back
#
# Steps: back up the database, pull, start, migrate, then check https://<APP_URL host>/up and
# the login page. If the check fails, the previous image is started again and the script fails.
set -eu

cd "$(dirname "$0")"

if [ -n "${1:-}" ]; then
    export IMAGE_TAG="$1"
fi

env_value() {
    grep -s "^$1=" .env | tail -n 1 | cut -d= -f2- | tr -d '"' || true
}

TAG="${IMAGE_TAG:-$(env_value IMAGE_TAG)}"
IMAGE="ghcr.io/zsoltgombocz/budget-app:${TAG:-latest}"
HOST="$(env_value APP_URL | sed -e 's#^[a-z]*://##' -e 's#/.*$##')"

previous="$(docker compose ps -q app | xargs -r docker inspect --format '{{.Image}}' 2>/dev/null || true)"

(umask 077 && mkdir -p backups)
if [ -n "$(docker compose ps --status running -q mariadb)" ]; then
    backup="backups/db-$(date +%Y%m%d-%H%M%S).sql.gz"
    (umask 077 && docker compose exec -T mariadb sh -c 'exec mariadb-dump --single-transaction -u"$MARIADB_USER" -p"$MARIADB_PASSWORD" "$MARIADB_DATABASE"' | gzip > "$backup")
    echo "Database backup: $backup"
    ls -1t backups/db-*.sql.gz | tail -n +15 | xargs -r rm --
fi

docker compose pull app queue scheduler
docker compose up -d --remove-orphans
docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan db:seed --class=CategoryTemplateSeeder --force
docker compose exec -T scheduler php artisan schedule:clear-cache

healthy() {
    for _ in 1 2 3 4 5 6 7 8 9 10 11 12; do
        if curl -fsk -o /dev/null --resolve "$HOST:443:127.0.0.1" "https://$HOST/up" \
            && curl -fsk -o /dev/null --resolve "$HOST:443:127.0.0.1" "https://$HOST/login"; then
            return 0
        fi
        sleep 5
    done

    return 1
}

if ! healthy; then
    echo "Health check failed for https://$HOST" >&2
    if [ -n "$previous" ]; then
        echo "Rolling back to the previous image $previous" >&2
        docker tag "$previous" "$IMAGE"
        docker compose up -d --remove-orphans
    fi
    exit 1
fi

version="$(curl -fsk --resolve "$HOST:443:127.0.0.1" "https://$HOST/login" | grep -o 'app-version" content="[^"]*"' | cut -d'"' -f3 || true)"
echo "Healthy: https://$HOST runs $IMAGE (version ${version:-unknown})"
docker image prune -f >/dev/null
