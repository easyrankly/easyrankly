#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
WP_VERSION="${ERANKLY_WRITER_WP_VERSION:-6.2}"
PHP_VERSION="${ERANKLY_WRITER_PHP_VERSION:-8.0}"
CORE_ZIP="${ERANKLY_WRITER_CORE_ZIP:-}"
RUN_ID="erankly-writer-$PPID-$$"
WORK_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/erankly-writer.XXXXXX")"
SITE_DIR="${WORK_ROOT}/wordpress"
NETWORK_NAME="${RUN_ID}-net"
DB_CONTAINER="${RUN_ID}-db"
CLI_IMAGE="wordpress:cli-php${PHP_VERSION}"

cleanup() {
	docker rm -f "${DB_CONTAINER}" >/dev/null 2>&1 || true
	docker network rm "${NETWORK_NAME}" >/dev/null 2>&1 || true
	rm -rf -- "${WORK_ROOT}"
}
trap cleanup EXIT INT TERM

if ! command -v docker >/dev/null 2>&1; then
	echo "Docker is required for the localized-source writer certification." >&2
	exit 2
fi
if [[ -n "${CORE_ZIP}" && ! -f "${CORE_ZIP}" ]]; then
	echo "The requested core ZIP does not exist: ${CORE_ZIP}" >&2
	exit 2
fi

mkdir -p "${SITE_DIR}"
docker network create "${NETWORK_NAME}" >/dev/null
docker run -d \
	--name "${DB_CONTAINER}" \
	--network "${NETWORK_NAME}" \
	-e MARIADB_ROOT_PASSWORD=writer-root \
	-e MARIADB_DATABASE=wordpress \
	-e MARIADB_USER=wordpress \
	-e MARIADB_PASSWORD=writer-password \
	mariadb:10.11 >/dev/null

ready=0
for _ in $(seq 1 60); do
	if docker exec "${DB_CONTAINER}" mariadb-admin ping -uroot -pwriter-root --silent >/dev/null 2>&1; then
		ready=1
		break
	fi
	sleep 1
done
if [[ "${ready}" != "1" ]]; then
	echo "MariaDB did not become ready for the writer certification." >&2
	exit 1
fi

uid="$(id -u)"
gid="$(id -g)"
mounts=( -v "${SITE_DIR}:/var/www/html" -v "${ROOT}:/workspace:ro" )
if [[ -z "${CORE_ZIP}" ]]; then
	mounts+=( -v "${ROOT}:/var/www/html/wp-content/plugins/easyrankly:ro" )
else
	mounts+=( -v "${CORE_ZIP}:/tmp/easyrankly.zip:ro" )
fi

wp_run() {
	docker run --rm \
		--network "${NETWORK_NAME}" \
		--user "${uid}:${gid}" \
		-e WP_CLI_ALLOW_ROOT=1 \
		-e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache \
		"${mounts[@]}" \
		-w /var/www/html \
		--entrypoint php \
		"${CLI_IMAGE}" -d memory_limit=512M /usr/local/bin/wp "$@"
}

wp_worker() {
	local worker="$1"
	shift
	docker run --rm \
		--network "${NETWORK_NAME}" \
		--user "${uid}:${gid}" \
		-e WP_CLI_ALLOW_ROOT=1 \
		-e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache \
		-e ERANKLY_WRITER_WORKER="${worker}" \
		"${mounts[@]}" \
		-w /var/www/html \
		--entrypoint php \
		"${CLI_IMAGE}" -d memory_limit=512M /usr/local/bin/wp "$@"
}

wp_run core download --version="${WP_VERSION}" --force
wp_run config create --dbname=wordpress --dbuser=wordpress --dbpass=writer-password --dbhost="${DB_CONTAINER}:3306" --skip-check
wp_run core install --url=http://writer.example.test --title='EasyRankly writer' --admin_user=admin --admin_password=writer-admin --admin_email=admin@example.test --skip-email
if [[ -n "${CORE_ZIP}" ]]; then
	wp_run plugin install /tmp/easyrankly.zip
fi
wp_run plugin activate easyrankly
wp_run eval-file /workspace/tests/localized-value-writer/assertions.php

docker run --rm \
	--network "${NETWORK_NAME}" \
	--user "${uid}:${gid}" \
	"${mounts[@]}" \
	-w /workspace \
	--entrypoint php \
	"${CLI_IMAGE}" tests/localized-value-writer/frontend-context.php /var/www/html/wp-load.php

wp_run eval-file /workspace/tests/localized-value-writer/concurrency-prepare.php
wp_worker a eval-file /workspace/tests/localized-value-writer/concurrency-worker.php &
worker_a=$!
wp_worker b eval-file /workspace/tests/localized-value-writer/concurrency-worker.php &
worker_b=$!
wait "${worker_a}"
wait "${worker_b}"
wp_run eval-file /workspace/tests/localized-value-writer/concurrency-verify.php

echo "Public localized-source writer certification passed (WordPress ${WP_VERSION}, PHP ${PHP_VERSION}, MariaDB 10.11)."
