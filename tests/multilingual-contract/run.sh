#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
SUITE="legacy-baseline"
SCALE="3"
PHP_VERSION="8.4"
WP_VERSION="6.2"
PROVIDER="bundled"
ADDON_PATH="${ERANKLY_ML_CONTRACT_ADDON_PATH:-}"
DRIVER_FILE="${ERANKLY_ML_CONTRACT_DRIVER_FILE:-}"
EXPECTED_CONFORMANCE_IDS="ML-CONF-001,ML-CONF-002,ML-CONF-003,ML-CONF-004,ML-CONF-005,ML-CONF-006,ML-CONF-007,ML-CONF-008,ML-CONF-009"
M2_EXPECTED_CONFORMANCE_IDS="ML-CONF-002,ML-CONF-003,ML-CONF-004,ML-CONF-005,ML-CONF-006"
CORE_VERSION=""

for argument in "$@"; do
	case "${argument}" in
		--suite=*) SUITE="${argument#*=}" ;;
		--scale=*) SCALE="${argument#*=}" ;;
		--php=*) PHP_VERSION="${argument#*=}" ;;
		--wordpress=*) WP_VERSION="${argument#*=}" ;;
		--provider=*) PROVIDER="${argument#*=}" ;;
		*) echo "Unknown argument: ${argument}" >&2; exit 2 ;;
	esac
done

case "${SUITE}" in
	legacy-baseline|multisite-conformance|m2-bridge|all) ;;
	*) echo "Unknown M1 suite: ${SUITE}" >&2; exit 2 ;;
esac

case "${SCALE}" in
	3|250|501) ;;
	*) echo "M1 scale must be 3, 250, or 501." >&2; exit 2 ;;
esac

case "${PROVIDER}" in
	bundled|addon) ;;
	*) echo "M1 provider must be bundled or addon." >&2; exit 2 ;;
esac

if ! command -v docker >/dev/null 2>&1; then
	echo "Docker is required for the clean M1 WordPress fixture." >&2
	exit 2
fi

if [[ "${PROVIDER}" == "addon" && ! -d "${ADDON_PATH}" ]]; then
	echo "Set ERANKLY_ML_CONTRACT_ADDON_PATH to the add-on checkout." >&2
	exit 2
fi

if [[ "${PROVIDER}" == "addon" && -z "${DRIVER_FILE}" ]]; then
	echo "Set ERANKLY_ML_CONTRACT_DRIVER_FILE to the add-on test adapter path inside the container." >&2
	exit 2
fi

RUN_ID="erankly-m1-$PPID-$$"
WORK_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/erankly-m1.XXXXXX")"
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

mkdir -p "${SITE_DIR}"
docker network create "${NETWORK_NAME}" >/dev/null
docker run -d \
	--name "${DB_CONTAINER}" \
	--network "${NETWORK_NAME}" \
	-e MARIADB_ROOT_PASSWORD=m1-root \
	-e MARIADB_DATABASE=wordpress \
	-e MARIADB_USER=wordpress \
	-e MARIADB_PASSWORD=m1-password \
	mariadb:10.11 >/dev/null

ready=0
for _ in $(seq 1 60); do
	if docker exec "${DB_CONTAINER}" mariadb-admin ping -uroot -pm1-root --silent >/dev/null 2>&1; then
		ready=1
		break
	fi
	sleep 1
done
if [[ "${ready}" != "1" ]]; then
	echo "MariaDB did not become ready for M1." >&2
	exit 1
fi

uid="$(id -u)"
gid="$(id -g)"

wp_core() {
	docker run --rm \
		--network "${NETWORK_NAME}" \
		--user "${uid}:${gid}" \
		-e WP_CLI_ALLOW_ROOT=1 \
		-e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache \
		-v "${SITE_DIR}:/var/www/html" \
		-w /var/www/html \
		"${CLI_IMAGE}" wp "$@"
}

wp_contract() {
	local worker="${1:-}"
	shift
	local mounts=( -v "${SITE_DIR}:/var/www/html" -v "${ROOT}:/var/www/html/wp-content/plugins/easyrankly:ro" )
	if [[ "${PROVIDER}" == "addon" ]]; then
		mounts+=( -v "${ADDON_PATH}:/var/www/html/wp-content/plugins/easyrankly-multilingual:ro" )
	fi
	docker run --rm \
		--network "${NETWORK_NAME}" \
		--user "${uid}:${gid}" \
		-e WP_CLI_ALLOW_ROOT=1 \
		-e WP_CLI_CACHE_DIR=/tmp/wp-cli-cache \
		-e ERANKLY_ML_CONTRACT_EPHEMERAL=1 \
		-e ERANKLY_ML_CONTRACT_SCALE="${SCALE}" \
		-e ERANKLY_ML_CONTRACT_PROVIDER="${PROVIDER}" \
		-e ERANKLY_ML_CONTRACT_DRIVER_FILE="${DRIVER_FILE}" \
		-e ERANKLY_ML_CONTRACT_WORKER="${worker}" \
		"${mounts[@]}" \
		-w /var/www/html \
		"${CLI_IMAGE}" wp "$@"
}

wp_core core download --version="${WP_VERSION}" --force
mkdir -p "${SITE_DIR}/wp-content/plugins"
wp_contract "" config create --dbname=wordpress --dbuser=wordpress --dbpass=m1-password --dbhost="${DB_CONTAINER}:3306" --skip-check
wp_contract "" core multisite-install --url=http://m1.example.test --title='EasyRankly M1' --admin_user=admin --admin_password='m1-admin-password' --admin_email=m1@example.test --skip-email --subdomains=false
wp_contract "" plugin activate easyrankly --network
CORE_VERSION="$(wp_contract "" plugin get easyrankly --field=version)"
wp_contract "" eval-file wp-content/plugins/easyrankly/tests/multilingual-contract/prepare.php

if [[ "${PROVIDER}" == "addon" ]]; then
	wp_contract "" plugin activate easyrankly-multilingual --network
fi

run_legacy() {
	wp_contract "" eval-file wp-content/plugins/easyrankly/tests/multilingual-contract/legacy-baseline.php

	set +e
	wp_contract a eval-file wp-content/plugins/easyrankly/tests/multilingual-contract/concurrency-worker.php &
	worker_a=$!
	wp_contract b eval-file wp-content/plugins/easyrankly/tests/multilingual-contract/concurrency-worker.php &
	worker_b=$!
	wait "${worker_a}"
	status_a=$?
	wait "${worker_b}"
	status_b=$?
	set -e

	if [[ "${status_a}" != "0" || "${status_b}" != "0" ]]; then
		echo "One or both M1 concurrency workers failed." >&2
		return 1
	fi

	wp_contract "" eval-file wp-content/plugins/easyrankly/tests/multilingual-contract/concurrency-verify.php
}

run_conformance() {
	local output status actual_ids
	set +e
	output="$(wp_contract "" eval-file wp-content/plugins/easyrankly/tests/multilingual-contract/multisite-conformance.php 2>&1)"
	status=$?
	set -e
	printf '%s\n' "${output}"

	if [[ "${PROVIDER}" == "bundled" && "${CORE_VERSION}" == "2.0.0" ]]; then
		actual_ids="$(printf '%s\n' "${output}" | sed -n 's/^ERANKLY_ML_CONTRACT_FAILURE_IDS=//p' | tail -n 1)"
		if [[ "${status}" == "0" ]]; then
			echo "The bundled 2.0 conformance suite unexpectedly passed." >&2
			return 1
		fi
		if [[ "${actual_ids}" != "${EXPECTED_CONFORMANCE_IDS}" ]]; then
			echo "Bundled conformance failure IDs differ from the exact M1 contract." >&2
			echo "Expected: ${EXPECTED_CONFORMANCE_IDS}" >&2
			echo "Actual:   ${actual_ids:-<none>}" >&2
			return 1
		fi
		echo "Bundled EasyRankly ${CORE_VERSION} conformance produced exactly ${EXPECTED_CONFORMANCE_IDS}."
		return 0
	fi

	if [[ "${PROVIDER}" == "bundled" && "${CORE_VERSION}" == "2.1.0" ]]; then
		actual_ids="$(printf '%s\n' "${output}" | sed -n 's/^ERANKLY_ML_CONTRACT_FAILURE_IDS=//p' | tail -n 1)"
		if [[ "${status}" == "0" ]]; then
			echo "The M4 defects unexpectedly passed during the M2 release bridge." >&2
			return 1
		fi
		if [[ "${actual_ids}" != "${M2_EXPECTED_CONFORMANCE_IDS}" ]]; then
			echo "EasyRankly 2.1 conformance differs from the M2/M4 milestone boundary." >&2
			echo "Expected: ${M2_EXPECTED_CONFORMANCE_IDS}" >&2
			echo "Actual:   ${actual_ids:-<none>}" >&2
			return 1
		fi
		echo "Bundled EasyRankly ${CORE_VERSION}: M2 conformance is green; M4 remains expected-red for ${M2_EXPECTED_CONFORMANCE_IDS}."
		return 0
	fi

	return "${status}"
}

run_m2() {
	docker run --rm \
		--user "${uid}:${gid}" \
		-v "${ROOT}:/workspace:ro" \
		-w /workspace \
		--entrypoint php \
		"${CLI_IMAGE}" tests/multilingual-contract/m2-provider-registry.php

	wp_contract "" eval-file wp-content/plugins/easyrankly/tests/multilingual-contract/m2-bridge.php

	set +e
	wp_contract a eval-file wp-content/plugins/easyrankly/tests/multilingual-contract/m2-settings-concurrency-worker.php &
	worker_a=$!
	wp_contract b eval-file wp-content/plugins/easyrankly/tests/multilingual-contract/m2-settings-concurrency-worker.php &
	worker_b=$!
	wait "${worker_a}"
	status_a=$?
	wait "${worker_b}"
	status_b=$?
	set -e

	if [[ "${status_a}" != "0" || "${status_b}" != "0" ]]; then
		echo "One or both M2 settings concurrency workers failed." >&2
		return 1
	fi

	wp_contract "" eval-file wp-content/plugins/easyrankly/tests/multilingual-contract/m2-settings-concurrency-verify.php
	wp_contract "" eval-file wp-content/plugins/easyrankly/tests/multilingual-contract/m2-uninstall-retained-prepare.php
	wp_contract "" plugin deactivate easyrankly --network
	wp_contract "" plugin uninstall easyrankly --skip-delete
	wp_contract "" eval-file wp-content/plugins/easyrankly/tests/multilingual-contract/m2-uninstall-retained-verify.php

	wp_contract "" plugin activate easyrankly --network
	wp_contract "" eval-file wp-content/plugins/easyrankly/tests/multilingual-contract/m2-uninstall-normal-prepare.php
	wp_contract "" plugin deactivate easyrankly --network
	wp_contract "" plugin uninstall easyrankly --skip-delete
	wp_contract "" eval-file wp-content/plugins/easyrankly/tests/multilingual-contract/m2-uninstall-normal-verify.php

	mkdir -p "${SITE_DIR}/wp-content/mu-plugins"
	cp "${ROOT}/tests/multilingual-contract/m2-fake-provider-mu.php" "${SITE_DIR}/wp-content/mu-plugins/m2-fake-provider.php"
	wp_contract "" plugin activate easyrankly --network
	wp_contract "" eval-file wp-content/plugins/easyrankly/tests/multilingual-contract/m2-fake-provider-verify.php
}

case "${SUITE}" in
	legacy-baseline) run_legacy ;;
	multisite-conformance) run_conformance ;;
	m2-bridge) run_m2 ;;
	all)
		run_legacy
		run_conformance
		run_m2
		;;
esac
