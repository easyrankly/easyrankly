#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="$(sed -n "s/^ \* Version:[[:space:]]*//p" "${ROOT}/easyrankly.php" | head -n 1)"
OUTPUT="${1:-${ROOT}/build/easyrankly-${VERSION}.zip}"
if [[ "${OUTPUT}" != /* ]]; then
	OUTPUT="${ROOT}/${OUTPUT}"
fi
WORK_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/erankly-build.XXXXXX")"
DIST_ROOT="${WORK_ROOT}/distribution"
PLUGIN_ROOT="${DIST_ROOT}/easyrankly"

cleanup() {
	rm -rf -- "${WORK_ROOT}"
}
trap cleanup EXIT INT TERM

mkdir -p "${PLUGIN_ROOT}" "$(dirname -- "${OUTPUT}")"
rsync -a --exclude-from="${ROOT}/.distignore" "${ROOT}/" "${PLUGIN_ROOT}/"

find "${DIST_ROOT}" -exec touch -t 202001010000 {} +
rm -f -- "${OUTPUT}"

(
	cd "${DIST_ROOT}"
	find easyrankly -type f -print | LC_ALL=C sort | zip -X -q "${OUTPUT}" -@
)

unzip -t "${OUTPUT}" >/dev/null
printf '%s\n' "${OUTPUT}"
