# Third-party migrations (Phase 7)

Phase 7 turns the migration tests into a reproducible certification system.
Passing one fixture or one local WordPress installation is not sufficient: the
certifier fails unless every declared cell, source, edition, format, topology
and static check produces evidence for the exact workspace bytes under test.

## One fail-closed command

From the plugin root, run:

```sh
bash tests/certification/run.sh
```

`composer migration:certify` is an equivalent convenience alias when Composer
and a host PHP binary are available. The runner itself requires Docker, Git and
the installed Composer development dependencies; PHP execution happens inside
the declared containers. It stops on the first failed assertion, removes its
temporary containers, networks and WordPress files, and writes the successful
record to:

```text
tests/artifacts/migration-certification.json
```

The artifact directory is intentionally ignored by Git. CI uploads the record
under the exact commit SHA instead of treating a locally generated file as
permanent release evidence.

## Required matrix

The authoritative matrix is
`tests/certification/manifest.php`. The current required cells are:

| Layer | PHP | WordPress | Database | Topology |
|---|---:|---:|---|---|
| Contract | 8.0 | — | — | standalone |
| Contract | 8.4 | — | — | standalone |
| Static quality | 8.4 | — | — | PHPCS, PHPCompatibility and PHP 8.0 syntax |
| Runtime | 8.0 | 6.2 | MariaDB 10.11 | single-site |
| Runtime | 8.0 | 7.0.1 | MariaDB 10.11 | single-site |
| Runtime | 8.4 | 7.0.1 | MariaDB 10.11 | single-site |
| Runtime | 8.0 | 6.2 | MariaDB 10.11 | Multisite |
| Runtime | 8.4 | 7.0.1 | MariaDB 10.11 | Multisite |

The WordPress boundary cells deliberately pair the declared minimums and the
current certified release. Adding a supported runtime requires adding a
manifest cell; changing the runner alone cannot make the record pass.

## Source and paid-edition coverage

The contract suite covers both editions of every adapter:

| Source | Editions | Paid surfaces proved by the contract |
|---|---|---|
| Yoast SEO | Free, Premium | Additional keyphrases, schema and Premium redirects |
| Rank Math | Free, PRO | Advanced robots, schema and redirections |
| AIOSEO | Lite, Pro | Pro term storage, schema and redirects |
| SEOPress | Free, PRO | Pro schema, redirects and visibility conditions |

Every storage-contract and export fixture has a reviewed SHA-256 in the
manifest. Any byte change fails certification until the manifest is explicitly
updated. The standalone suite proves conversion behavior and deterministic
pagination. The real WordPress suite additionally applies all four database
adapters and every recognized official-export format through the resumable
worker, verifies exact accounting, and rolls the writes back.

## Resilience, security and scale

The required regression chain covers:

- immutable source fingerprints and fail-closed unknown storage;
- idempotent replay, stale-lock takeover, cancellation and resume;
- existing-target preservation and source collision handling;
- private upload permissions, source mismatch rejection and lifecycle cleanup;
- per-process temporary-upload isolation under concurrent standalone suites;
- authorization boundaries in the admin surface;
- exact alignment between the authoritative manifest and Docker WordPress tests;
- exact evidence accounting, zero-follow HTTP probes and conditional rollback;
- queue, report, metadata and journal isolation between Multisite sites;
- a default 500-post scale preview that must use multiple batches, complete in
  at most 180 seconds and consume at most 256 MiB of incremental peak memory.

The scale size and budgets can be tightened without editing the suite:

```sh
ERANKLY_CERT_SCALE=1000 \
ERANKLY_CERT_MAX_SECONDS=120 \
ERANKLY_CERT_MAX_MEMORY_MB=192 \
bash tests/certification/run.sh
```

The size is clamped to 100–2,000 records so an accidental environment value
cannot turn a certification run into an unbounded load test.

## Evidence record

The JSON record contains:

- certification schema and EasyRankly version;
- UTC completion time;
- Git commit and dirty-worktree state;
- SHA-256 of all relevant workspace files;
- certification-manifest and fixture SHA-256 values;
- every required matrix cell and its outcome;
- the exact source, edition, version-range and paid-surface contract;
- the separate state of licensed paid-plugin binary evidence.

The record writer rejects missing, duplicate, extra or non-passing cells. A
partial matrix therefore cannot be presented as a successful full run.

## Honest boundary for licensed PRO packages

Yoast Premium, Rank Math PRO, AIOSEO Pro and SEOPress PRO packages cannot be
bundled in this repository. The built-in fixtures certify the database and
official-export contracts and are never described as vendor-signed binaries.

An authorized operator may attach separately produced evidence by setting:

```sh
ERANKLY_CERT_PRO_EVIDENCE=/secure/path/pro-evidence.json \
bash tests/certification/run.sh
```

The external JSON must have a passing status and identify the licensed package
versions and SHA-256 values used. Its own SHA-256 is retained with the evidence;
package bytes and private filesystem paths are never copied into the
certification artifact.
Without it, the contract/runtime certification still passes but the record says
`licensed_pro_evidence.status = not_supplied`. The Phase 8 release gate treats
that state as a go-live blocker; Phase 7 never hides it.

## CI

`.github/workflows/migration-certification.yml` runs the same command for every
migration-affecting pull request and push to `main`, then uploads the JSON record
under the tested commit SHA. Local and CI certification therefore share one
manifest, one runner and the same PASS/FAIL rules.
