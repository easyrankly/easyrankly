# Third-party migrations (Phase 5)

Phase 5 turns the certified export readers and resumable worker into a complete
administrator workflow. Official Yoast SEO, Rank Math, AIOSEO and SEOPress
exports can be previewed or imported without entering a server path. Terminal
reports make a deterministic switch decision instead of treating “the worker
finished” as proof that the source plugin can be disabled safely.

## Secure upload boundary

The upload action is available only on the EasyRankly settings route and uses
the same `manage_options` or Multisite `manage_network_options` capability gate
as the rest of Import/Export. The dedicated form nonce is checked before the
file is staged. The HTTP entry point accepts only files PHP identifies through
`is_uploaded_file()`; the separate trusted-file method exists for WP-CLI and
test integrations and is never used by the web handler.

Only `.csv` and `.json` files are admitted. The default size ceiling is 100 MB
and can be reduced through `erankly_migration_export_max_bytes`. An accepted
extension is not sufficient: EasyRankly reads the file through all four Phase 4
inspectors and requires exactly one certified source signature. A selected
source/signature mismatch, ambiguous signature, generic CSV or unknown JSON
shape is rejected before a job exists.

The source may be selected explicitly or left on **Detect automatically**:

| Official file | Detected adapter | Certified format |
| --- | --- | --- |
| Yoast Premium redirects CSV | Yoast SEO | `yoast-redirects-csv` |
| Rank Math metadata or redirects CSV | Rank Math | `rankmath-metadata-csv` / `rankmath-redirects-csv` |
| AIOSEO Complete Data JSON or redirects CSV | AIOSEO | `aioseo-redirects-json` / `aioseo-redirects-csv` |
| SEOPress metadata CSV | SEOPress | `seopress-metadata-csv` |

## Private storage and file lifecycle

Accepted exports are copied from PHP's upload temporary file into a per-site
directory derived from WordPress' system temporary directory. EasyRankly
rejects that directory if either its configured path or resolved real path is
inside `ABSPATH` or `WP_CONTENT_DIR`; there is no fallback to Media Library,
uploads or another web-accessible path. If no private writable directory is
available, the upload fails closed.

The directory uses mode `0700`; files use random 128-bit names and mode `0600`.
The original filename is used only to validate the extension and is not placed
in the job or final report. Deletion accepts only a path in the current site's
resolved private directory whose basename matches the managed random pattern.

Lifecycle rules are explicit:

1. a rejected file or failed job admission is deleted immediately;
2. an active or paused resumable job retains its source file;
3. successful preview, successful/partial import and cancellation persist the
   terminal report, remove the queue/checkpoint, then delete the source file;
4. deactivation unschedules work but deliberately preserves the checkpoint and
   file for safe reactivation;
5. abandoned managed files older than 24 hours are pruned, except for the file
   referenced by the active job;
6. reset and uninstall purge every managed file for each affected site.

`erankly_migration_private_directory` may move the private directory to an
operator-controlled path outside WordPress. `erankly_migration_upload_ttl` may
adjust abandoned-file retention, with a five-minute minimum.

The final report records only lifecycle facts (`managed_temporary`, retention,
deletion state and deletion timestamp), never the private server path.

## Preview and approval

Database and official-export sources share the exact same normalization,
fingerprint, conflict and worker paths. Preview performs full discovery and
verification without target writes.

After a valid database preview, the report offers **Approve and run migration**
for the same certified source. An official-export preview is intentionally
one-use: its private copy is deleted at completion, so approval requires the
administrator to upload the same official export again. This prevents a preview
artifact from becoming long-lived sensitive storage.

Existing EasyRankly values continue to win. A real import rechecks target state
immediately before each write, so content edited between preview and import is
preserved and reported rather than overwritten.

## Post-import decision engine

Every terminal report contains a machine-readable `verification` object:

- `state`: `ready`, `safe`, `review` or `blocked`;
- `ready_to_import`: true only for a complete fingerprint-verified preview;
- `ready_to_switch`: true only for a clean complete fingerprint-verified real
  import;
- `checks`: source integrity, write failures, invalid records, conflicts and
  diagnostics, each with `pass`, `warn`, `fail` or `not_applicable` status;
- `next_actions`: ordered, stable action codes rendered as an admin checklist.

Decision semantics are strict:

| State | Meaning | Source plugin action |
| --- | --- | --- |
| `ready` | Clean verified preview | Approve the real import; do not switch yet |
| `safe` | Clean verified real import | Controlled deactivation is permitted |
| `review` | Import/preview completed but has invalid records, preserved conflicts or warnings | Review every diagnostic; no switch authorization |
| `blocked` | Cancelled/partial/failed run, failed write or unverified fingerprint | Keep the source plugin active and resolve blockers |

The switch checklist keeps the source plugin installed, requires review items
to be resolved, then directs the administrator to deactivate without deleting,
purge WordPress/page/CDN/edge caches, inspect representative metadata, social
tags, schema, robots and redirects on the frontend, and retain source data plus
the JSON report until live verification succeeds.

## Operator errors

Admin notices use fixed translated messages. They distinguish oversize files,
unsupported extensions/signatures, source mismatches, ambiguous signatures,
unavailable private storage, write failure and permission-hardening failure.
They never echo the original filename, PHP temporary filename, managed path or
reader exception.

## Verification

The dependency-free certification suite covers all four automatic source
detections, private filename ownership and permissions, mismatch and generic
CSV rejection, HTTP/local-boundary enforcement, active-file preservation,
stale pruning, full purge and the four post-run decisions:

```sh
php tests/phase5-upload-certification.php
```

The real WordPress/MariaDB suite covers the same lifecycle through the actual
resumable queue and report store, including terminal and cancellation deletion,
redirect writes, report/UI decisions, capability denial and reset cleanup:

```sh
wp eval-file wp-content/plugins/easyrankly/tests/phase5-wordpress-integration.php
```

Phase 3 and Phase 4 integration suites remain mandatory regressions because
Phase 5 extends their worker admission, finalization and reporting paths.
