#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
CERTIFICATION="${ERANKLY_CERT_ARTIFACT:-${ROOT}/tests/artifacts/migration-certification.json}"
GO_LIVE="${ERANKLY_GO_LIVE_ARTIFACT:-${ROOT}/tests/artifacts/migration-go-live.json}"

if [[ -z "${ERANKLY_CERT_PRO_EVIDENCE:-}" ]]; then
	echo "ERANKLY_CERT_PRO_EVIDENCE must identify authorized passing evidence for Yoast Premium, Rank Math PRO, AIOSEO Pro and SEOPress PRO." >&2
	exit 2
fi

ERANKLY_CERT_ARTIFACT="${CERTIFICATION}" bash "${ROOT}/tests/certification/run.sh"

git_commit="$(git -C "${ROOT}" rev-parse HEAD)"
git_dirty=0
if [[ -n "$(git -C "${ROOT}" status --porcelain)" ]]; then
	git_dirty=1
fi

docker run --rm \
	-e ERANKLY_GATE_GIT_COMMIT="${git_commit}" \
	-e ERANKLY_GATE_GIT_DIRTY="${git_dirty}" \
	-v "${ROOT}:/plugin:ro" \
	-v "$(dirname -- "${CERTIFICATION}"):/cert-input:ro" \
	-v "$(dirname -- "${GO_LIVE}"):/gate-output" \
	-w /plugin \
	php:8.4-cli \
	php tests/certification/evaluate-go-live.php \
		--certification="/cert-input/$(basename -- "${CERTIFICATION}")" \
		--output="/gate-output/$(basename -- "${GO_LIVE}")"

echo "Phase 8 strict release gate passed."
