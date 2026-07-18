# Third-party migrations (Phase 6)

Phase 6 turns the terminal migration report into evidence that can support a
real SEO-plugin cutover. The report no longer treats a completed worker as
proof by itself: it accounts for every normalized source occurrence, compares
the values written by EasyRankly, audits redirects, captures a representative
frontend baseline and retains a conditional rollback journal.

## Exact accounting invariant

Before the temporary queue is deleted, the auditor assigns every discovered
object, metadata field and redirect occurrence to one exclusive terminal
outcome. Metadata and redirects use the same stable vocabulary:

- `ready` for a preview proposal;
- `imported` for a successful real write;
- `identical` for source duplicates or an already identical target;
- `preserved` for an existing EasyRankly value;
- `conflict`, `invalid`, `unsupported` or `failed` for exceptions.

`transformed` is a modifier rather than a second terminal outcome. A converted
template can therefore be both transformed and imported without being counted
twice. The report fails its `every_discovered_occurrence_classified_once`
invariant unless each area has `discovered === classified`.

The accounting scope is explicit: `adapter_normalized_occurrences`. Unsupported
source modules and unresolved variables remain visible in the source profile
and warning/placeholder diagnostics instead of disappearing from the switch
decision.

## Semantic before/after proof

For title, canonical, robots, social and JSON-LD fields, the report compares the
normalized adapter value with the stored EasyRankly value. It persists match
counts and SHA-256 before/after hashes, not a second copy of raw source payloads.
Samples include direct links to the affected post, term or author editor.

The JSON download contains the complete report and bounded human-readable
samples. Every exception is also copied into the value-free, paginated
`erankly_migration_exceptions` ledger before the temporary queue is deleted.
The companion CSV streams that complete ledger in fixed-size pages, so a large
site does not need to load every exception into memory. It is protected by the
same capability and nonce boundary as the JSON report. CSV cells that could be
interpreted as formulas are neutralized before streaming. When the bounded
parent report is evicted, its exception ledger is deleted with it.

## Redirect audit

The terminal auditor builds a graph from every valid imported redirect and
reports:

- direct loops;
- chains whose internal target is another imported source;
- collisions on the normalized source path;
- regex patterns with conservative nested-quantifier/backtracking warnings;
- stored status-code and `Location` agreement for representative rules.

The live verifier sends same-origin GET requests with `redirection => 0`, so a
probe records the first status and `Location` without traversing a chain. It
never requests an imported external target.

## Old-plugin HTML baseline and cutover

At terminal finalization, while the source SEO plugin is expected to still own
frontend output, EasyRankly samples public migrated objects and extracts title,
canonical, robots, Open Graph, Twitter and JSON-LD semantics. It also samples
`robots.txt`, the source plugin's conventional sitemap endpoint and imported
redirect sources. Raw HTML is never retained; only normalized semantic hashes,
field presence, response codes and locations are stored.

After the administrator deactivates the source plugin and purges every cache,
**Verify live after cutover** repeats the sample against EasyRankly output. The
action refuses to run while another recognized SEO plugin still owns the head.
Its result is `verified`, `differences_found`, `inconclusive` or `no_baseline`.
Official-file migrations can legitimately have `not_source_owned` when the old
plugin is not installed; their database/semantic/redirect evidence remains
available, but the report does not mislabel EasyRankly's own output as an old
plugin baseline.

All probes are same-origin, have a bounded sample count and timeout, do not
follow redirects and never persist raw response bodies. Operators may tune the
sample count with `erankly_migration_live_sample_limit` and the per-request
timeout with `erankly_migration_probe_timeout`.

## Conditional rollback journal

Real imports require the site-scoped `erankly_migration_changes` table. A
pending journal row is written before each metadata or redirect mutation and is
marked applied after the target write. This makes a crash between write and
checkpoint conservative: rollback can inspect pending as well as applied rows.

The default rollback window is seven days and may be changed with
`erankly_migration_rollback_ttl` (minimum one day). Rollback processes changes
in reverse order and restores a value only when the current target still
semantically equals what that migration wrote:

- migration-created metadata is deleted;
- migration-created redirects are deleted;
- migration-updated redirects are restored to their previous rule;
- later manual edits are classified `preserved` and never overwritten.

The report shows available, rolled-back, preserved and failed journal counts
plus the exact UTC expiry. Reset and uninstall remove the journal,
exception-ledger tables and their schema options. Normal expiry pruning removes
sensitive rollback payloads.

## Certification

The real WordPress/MariaDB suite covers balanced accounting, semantic hashes,
old-plugin baseline capture, zero-follow live probes, chain and dangerous-regex
detection, editor links, admin controls, and a mixed rollback where unchanged
migration writes are restored while a later manual edit survives:

```sh
wp eval-file wp-content/plugins/easyrankly/tests/phase6-wordpress-integration.php
```

Phase 1–5 suites, full PHPCS, PHPCompatibility and whole-plugin PHP syntax lint
remain mandatory regressions.
