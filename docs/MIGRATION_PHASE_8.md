# Third-party migrations (Phase 8)

Phase 8 adds two fail-closed decisions that answer different questions:

1. the runtime gate decides whether one completed migration may proceed through
   cutover, live proof or rollback;
2. the release gate decides whether the exact EasyRankly bytes under review may
   be advertised and published as migration-ready, including paid editions.

Neither gate accepts a warning as success, infers missing evidence or treats a
preview as permission to deactivate the source SEO plugin.

## Runtime cutover gate

Every terminal report contains `go_live_gate`, with this stable contract:

- `contract_version`, `state`, `verdict` and `proof_scope`;
- mutually meaningful `ready_for_cutover`, `go_live`,
  `rollback_required` and `can_verify_live` booleans;
- ordered `checks`, explicit `blockers` and `next_actions`;
- `decision_sha256`, calculated without the evaluation timestamp;
- `evaluated_at`, which records when rollback expiry was last re-evaluated.

The states are:

| State | Meaning | Operator authority |
|---|---|---|
| `preview_only` | Source discovery only | Run or repeat an import; never cut over |
| `blocked` | One or more mandatory preflight proofs failed | Keep the source plugin active |
| `ready_for_cutover` | Preflight passed, live proof is pending | Controlled deactivation, cache purge and live verification |
| `go_live` | Every mandatory proof for the declared scope passed | Monitor and retain evidence |
| `rollback_required` | Live output differs or cannot be proven | Retry once after cache/reachability checks, then roll back |
| `rolled_back` | Conditional rollback completed safely | Reactivate the source and start again from a fresh preview |
| `rollback_failed` | Rollback expired or contained failed operations | Manual recovery before another attempt |

The server checks `can_verify_live` again after nonce and capability checks.
Hiding the admin button is therefore not the security or correctness boundary:
a crafted request cannot run live verification from a blocked report.

## Mandatory runtime proofs

Database migration preflight requires all of the following:

- terminal report status is `complete`;
- the immutable source fingerprint was verified immediately before apply;
- the accounting invariant classifies every occurrence exactly once;
- zero failed writes, invalid records, source conflicts, unsupported records,
  preserved target values, warnings and unresolved placeholders;
- zero normalized semantic mismatches across title, canonical, robots, social
  metadata and schema;
- every imported redirect matches persistent EasyRankly storage;
- zero redirect loops, chains, collisions and dangerous regular expressions;
- an unexpired conditional rollback entry for every successful write;
- a captured old-plugin frontend baseline.

Passing preflight produces `ready_for_cutover`, not `go_live`. The operator must
deactivate—but not delete—the source plugin, purge WordPress/page/CDN/edge
caches and run the report's live verifier. Only an exact sampled comparison of
HTML semantics, redirect status and Location, robots.txt and sitemap promotes
the report to full `go_live`. Differences produce `rollback_required`.

The report displays every proof as PASS, BLOCK, PENDING or N/A and includes the
decision SHA-256 in both the UI and downloaded JSON.

## Official-export proof boundary

An official export can be imported on a site where its source plugin does not
own frontend output. In that case an old-plugin HTML baseline is impossible.
A clean import may receive `go_live` with `proof_scope = contract_only`, while
`frontend_baseline` and `live_verification` remain explicitly N/A.

This verdict proves the certified file signature, normalized data contract,
stored values, redirect safety and rollback coverage. It does **not** claim an
old-plugin-versus-EasyRankly frontend comparison. The admin report states this
boundary next to the verdict so contract evidence cannot be mistaken for full
cutover evidence.

## Release gate

The Phase 7 matrix now also writes:

```text
tests/artifacts/migration-go-live.json
```

The release gate binds its decision to the Phase 7 certification SHA-256 and
requires:

- a passing certification record for the exact plugin version;
- the exact current workspace SHA-256;
- every declared PHP, WordPress, MariaDB and topology cell, all passing;
- a certification no older than 24 hours and not dated more than five minutes
  in the future;
- the exact current Git commit and a clean worktree both during certification
  and evaluation;
- passing authorized package evidence for Yoast Premium, Rank Math PRO,
  AIOSEO Pro and SEOPress PRO.

Normal pull-request certification emits the honest PASS/BLOCKED decision as an
artifact without pretending that externally licensed packages were supplied.
The strict publication command is:

```sh
ERANKLY_CERT_PRO_EVIDENCE=/secure/path/pro-evidence.json \
composer migration:go-live
```

It reruns the complete matrix and exits non-zero unless the final release state
is `go_live`. The evidence file has this shape:

```json
{
  "status": "pass",
  "packages": [
    {"source":"yoast","edition":"premium","version":"x.y.z","sha256":"64 lowercase hex characters","status":"pass"},
    {"source":"rankmath","edition":"pro","version":"x.y.z","sha256":"64 lowercase hex characters","status":"pass"},
    {"source":"aioseo","edition":"pro","version":"x.y.z","sha256":"64 lowercase hex characters","status":"pass"},
    {"source":"seopress","edition":"pro","version":"x.y.z","sha256":"64 lowercase hex characters","status":"pass"}
  ]
}
```

Package bytes and private paths are never copied into repository artifacts.
Their source, edition, version, SHA-256 and outcome are retained, together with
the SHA-256 of the exact external evidence record. Contract fixtures still
prove storage behavior, but can never satisfy the real-package release check.

## Certification

`tests/phase8-go-live-gate.php` exhaustively mutates the runtime and release
contracts. It proves every mandatory blocker, deterministic decision hashes,
contract-only boundaries, live mismatch escalation, rollback terminal states,
server-side UI enforcement, workspace/commit/freshness checks and the required
four-package PRO evidence set. `tests/phase8-wordpress-go-live.php` then proves
the full `ready_for_cutover → go_live` and `rollback_required → rolled_back`
paths against real WordPress, MariaDB, persistent reports and rollback tables.
The standalone contract runs on PHP 8.0 and PHP 8.4; the runtime path runs in
every single-site WordPress cell documented in Phase 7.
