#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
ARTIFACT="${ERANKLY_CERT_ARTIFACT:-${ROOT}/tests/artifacts/migration-certification.json}"
GO_LIVE_ARTIFACT="${ERANKLY_GO_LIVE_ARTIFACT:-${ROOT}/tests/artifacts/migration-go-live.json}"
SCALE="${ERANKLY_CERT_SCALE:-500}"
MAX_SECONDS="${ERANKLY_CERT_MAX_SECONDS:-180}"
MAX_MEMORY_MB="${ERANKLY_CERT_MAX_MEMORY_MB:-256}"
PRO_EVIDENCE="${ERANKLY_CERT_PRO_EVIDENCE:-}"
RUN_ID="erankly-cert-$PPID-$$"
WORK_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/erankly-cert.XXXXXX")"
RESULTS_FILE="${WORK_ROOT}/results.tsv"
PHP_INI_FILE="${WORK_ROOT}/phase7-php.ini"
CURRENT_CONTAINER=""
CURRENT_NETWORK=""

cleanup_cell() {
	if [[ -n "${CURRENT_CONTAINER}" ]]; then
		docker rm -f "${CURRENT_CONTAINER}" >/dev/null 2>&1 || true
		CURRENT_CONTAINER=""
	fi
	if [[ -n "${CURRENT_NETWORK}" ]]; then
		docker network rm "${CURRENT_NETWORK}" >/dev/null 2>&1 || true
		CURRENT_NETWORK=""
	fi
}

cleanup() {
	cleanup_cell
	rm -rf "${WORK_ROOT}"
}
trap cleanup EXIT INT TERM

record_pass() {
	printf '%s\t%s\t%s\t%s\t%s\tpass\n' "$1" "$2" "$3" "$4" "$5" >> "${RESULTS_FILE}"
}

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		echo "Missing required command: $1" >&2
		exit 1
	fi
}

run_standalone() {
	local php_version="$1"
	docker run --rm \
		-v "${ROOT}:/plugin:ro" \
		-w /plugin \
		"php:${php_version}-cli" \
		php tests/phase8-go-live-gate.php
	docker run --rm \
		-v "${ROOT}:/plugin:ro" \
		-w /plugin \
		"php:${php_version}-cli" \
		php tests/concurrent-standalone-certification.php
	record_pass standalone "${php_version}" "" "" contract
}

run_quality() {
	docker run --rm -v "${ROOT}:/plugin:ro" -w /plugin php:8.4-cli php vendor/bin/phpcs
	docker run --rm -v "${ROOT}:/plugin:ro" -w /plugin php:8.4-cli php vendor/bin/phpcs --standard=PHPCompatibilityWP --runtime-set testVersion 8.0- '--ignore=vendor/*,node_modules/*' .
	docker run --rm -v "${ROOT}:/plugin:ro" -w /plugin php:8.4-cli php tests/security-broken-links-ssrf.php
	docker run --rm -v "${ROOT}:/plugin:ro" -w /plugin php:8.4-cli php tests/security-ai-rate-limit.php
	docker run --rm -v "${ROOT}:/plugin:ro" -w /plugin php:8.4-cli php tests/security-health-privacy.php
	docker run --rm -v "${ROOT}:/plugin:ro" -w /plugin php:8.4-cli php tests/security-import-memory.php
	docker run --rm -v "${ROOT}:/plugin:ro" -w /plugin php:8.4-cli php tests/security-workflow-pinning.php
	docker run --rm -v "${ROOT}:/plugin:ro" -w /plugin php:8.4-cli php tests/performance-contract.php
	docker run --rm -v "${ROOT}:/plugin:ro" -w /plugin php:8.0-cli sh -c 'find . -path ./vendor -prune -o -name "*.php" -type f -print0 | xargs -0 -n1 php -l >/dev/null'
	record_pass quality 8.4 "" "" static
}

run_wp() {
	local php_version="$1"
	local wp_version="$2"
	local topology="$3"
	local label="php${php_version}-wp${wp_version}-${topology}"
	local site_dir="${WORK_ROOT}/${label}"
	local cli_image="wordpress:cli-php${php_version}"
	local db_name="${RUN_ID}-${label}-db"
	local network_name="${RUN_ID}-${label}-net"

	CURRENT_CONTAINER="${db_name}"
	CURRENT_NETWORK="${network_name}"
	mkdir -p "${site_dir}"
	docker network create "${network_name}" >/dev/null
	docker run -d \
		--name "${db_name}" \
		--network "${network_name}" \
		-e MARIADB_ROOT_PASSWORD=phase7-root \
		-e MARIADB_DATABASE=wordpress \
		-e MARIADB_USER=wordpress \
		-e MARIADB_PASSWORD=phase7 \
		mariadb:10.11 >/dev/null

	local ready=0
	for _ in $(seq 1 60); do
		if docker exec "${db_name}" mariadb-admin ping -uroot -pphase7-root --silent >/dev/null 2>&1; then
			ready=1
			break
		fi
		sleep 1
	done
	if [[ "${ready}" != "1" ]]; then
		echo "MariaDB did not become ready for ${label}." >&2
		exit 1
	fi

	local uid gid
	uid="$(id -u)"
	gid="$(id -g)"
	local core=(docker run --rm --network "${network_name}" --user "${uid}:${gid}" -e WP_CLI_ALLOW_ROOT=1 -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache -v "${PHP_INI_FILE}:/usr/local/etc/php/conf.d/zz-erankly-cert.ini:ro" -v "${site_dir}:/var/www/html" -w /var/www/html "${cli_image}" wp)
	"${core[@]}" core download --version="${wp_version}" --force
	mkdir -p "${site_dir}/wp-content/plugins"
	local wp=(docker run --rm --network "${network_name}" --user "${uid}:${gid}" -e WP_CLI_ALLOW_ROOT=1 -e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache -e ERANKLY_CERT_SCALE="${SCALE}" -e ERANKLY_CERT_MAX_SECONDS="${MAX_SECONDS}" -e ERANKLY_CERT_MAX_MEMORY_MB="${MAX_MEMORY_MB}" -v "${PHP_INI_FILE}:/usr/local/etc/php/conf.d/zz-erankly-cert.ini:ro" -v "${site_dir}:/var/www/html" -v "${ROOT}:/var/www/html/wp-content/plugins/easyrankly:ro" -w /var/www/html "${cli_image}" wp)
	"${wp[@]}" config create --dbname=wordpress --dbuser=wordpress --dbpass=phase7 --dbhost="${db_name}:3306" --skip-check

	if [[ "${topology}" == "multisite" ]]; then
		"${wp[@]}" core multisite-install --url=http://phase7.example.test --title='EasyRankly Phase 7' --admin_user=admin --admin_password='phase7-admin-password' --admin_email=phase7@example.test --skip-email --subdomains=false
		"${wp[@]}" plugin activate easyrankly --network
		"${wp[@]}" eval-file wp-content/plugins/easyrankly/tests/contextual-modules-enable.php
		"${wp[@]}" eval-file wp-content/plugins/easyrankly/tests/contextual-modules-wordpress-integration.php
		"${wp[@]}" eval-file wp-content/plugins/easyrankly/tests/phase7-multisite-certification.php
	else
		"${wp[@]}" core install --url=http://phase7.example.test --title='EasyRankly Phase 7' --admin_user=admin --admin_password='phase7-admin-password' --admin_email=phase7@example.test --skip-email
		"${wp[@]}" plugin activate easyrankly
		"${wp[@]}" eval-file wp-content/plugins/easyrankly/tests/contextual-modules-enable.php
		"${wp[@]}" eval-file wp-content/plugins/easyrankly/tests/contextual-modules-wordpress-integration.php
		local test_file
		for test_file in \
			tests/performance-wordpress-integration.php \
			tests/sitemap-wordpress-integration.php \
			tests/phase3-wordpress-integration.php \
			tests/phase4-wordpress-integration.php \
			tests/phase5-wordpress-integration.php \
			tests/phase6-wordpress-integration.php \
			tests/phase7-wordpress-certification.php \
			tests/phase8-wordpress-go-live.php; do
			"${wp[@]}" eval-file "wp-content/plugins/easyrankly/${test_file}"
		done
	fi

	record_pass wordpress "${php_version}" "${wp_version}" 'MariaDB 10.11' "${topology}"
	cleanup_cell
}

require_command docker
require_command git
: > "${RESULTS_FILE}"
printf 'memory_limit=512M\n' > "${PHP_INI_FILE}"

run_standalone 8.0
run_standalone 8.4
run_quality
run_wp 8.0 6.2 single-site
run_wp 8.0 7.0.1 single-site
run_wp 8.4 7.0.1 single-site
run_wp 8.0 6.2 multisite
run_wp 8.4 7.0.1 multisite

artifact_directory="$(dirname -- "${ARTIFACT}")"
artifact_filename="$(basename -- "${ARTIFACT}")"
mkdir -p "${artifact_directory}"
git_commit="$(git -C "${ROOT}" rev-parse HEAD)"
git_dirty=0
if [[ -n "$(git -C "${ROOT}" status --porcelain)" ]]; then
	git_dirty=1
fi
writer=(docker run --rm -e ERANKLY_CERT_GIT_COMMIT="${git_commit}" -e ERANKLY_CERT_GIT_DIRTY="${git_dirty}" -v "${ROOT}:/plugin:ro" -v "${WORK_ROOT}:/cert-results:ro" -v "${artifact_directory}:/cert-output")
if [[ -n "${PRO_EVIDENCE}" ]]; then
	if [[ ! -f "${PRO_EVIDENCE}" ]]; then
		echo "Licensed PRO evidence file does not exist: ${PRO_EVIDENCE}" >&2
		exit 1
	fi
	pro_directory="$(dirname -- "${PRO_EVIDENCE}")"
	pro_filename="$(basename -- "${PRO_EVIDENCE}")"
	writer+=(-v "${pro_directory}:/cert-pro:ro")
fi
writer+=(-w /plugin php:8.4-cli php tests/certification/write-record.php --output="/cert-output/${artifact_filename}" --results=/cert-results/results.tsv)
if [[ -n "${PRO_EVIDENCE}" ]]; then
	writer+=(--pro-evidence="/cert-pro/${pro_filename}")
fi
"${writer[@]}"

go_live_directory="$(dirname -- "${GO_LIVE_ARTIFACT}")"
go_live_filename="$(basename -- "${GO_LIVE_ARTIFACT}")"
mkdir -p "${go_live_directory}"
docker run --rm \
	-e ERANKLY_GATE_GIT_COMMIT="${git_commit}" \
	-e ERANKLY_GATE_GIT_DIRTY="${git_dirty}" \
	-v "${ROOT}:/plugin:ro" \
	-v "${artifact_directory}:/cert-input:ro" \
	-v "${go_live_directory}:/gate-output" \
	-w /plugin \
	php:8.4-cli \
	php tests/certification/evaluate-go-live.php \
		--certification="/cert-input/${artifact_filename}" \
		--output="/gate-output/${go_live_filename}" \
		--allow-blocked

echo "Phase 7 migration certification matrix passed."
echo "Certification artifact: ${ARTIFACT}"
echo "Phase 8 release decision: ${GO_LIVE_ARTIFACT}"
