# Third-party migrations (Phase 3)

Phase 3 turns the source adapters from Phase 2 into a production-grade,
resumable workflow for large WordPress sites. Preview and import now use the
same background state machine; preview stops before the write phase.

## Job lifecycle

Only one third-party migration can be active per site. Starting a job claims
the non-autoloaded `erankly_migration_active_job_v1` option atomically, then
creates its UUID checkpoint and a single WP-Cron event named
`erankly_migration_process_batch`. A concurrent start returns the original job
instead of replacing its checkpoint or leaving an orphaned queue.

The worker advances through these states:

1. `content`: discover and normalize SEO metadata;
2. `redirect`: discover and normalize redirect rules;
3. `apply`: write only staged records that passed validation and conflict
   checks (skipped by preview);
4. `finish`: rebuild counters from durable events, persist the report, remove
   staging rows and clear scheduled events.

Each invocation handles at most 100 source records or writes by default. The
`erankly_migration_batch_size` filter can set a value between 10 and 500. The
admin screen displays the current stage, saved batch count, checkpoint time and
live metadata/redirect counters. WP-Cron requests the next batch automatically;
**Process next batch now** provides a deterministic fallback when loopback
requests or WP-Cron are disabled.

## Real cursors, not offset rescans

Every bundled adapter implements stable source cursors so a resumed batch does
not enumerate earlier records again:

- post, term and user metadata use object-ID keyset pagination;
- AIOSEO v4 and Pro tables use their source row IDs;
- Rank Math redirect rows retain both the table row ID and the position within
  a multi-source rule;
- Yoast legacy taxonomy and Premium redirect options use deterministic option
  entry offsets because WordPress stores each of those sources as one
  monolithic option;
- SEOPress content and redirect metadata use post/term keysets.

Deleted or malformed source objects still advance the cursor, so a bad record
cannot trap the worker on one page. Source data should remain installed and
unchanged until the final report is complete; new records with IDs below an
already-saved keyset are intentionally outside the running snapshot.

## Crash consistency and idempotence

The temporary `{prefix}erankly_migration_queue` table stores one event for each
source occurrence. An occurrence hash prevents the same page from being
counted twice if a PHP process stops after staging records but before saving its
cursor. A separate target-identity hash detects duplicates and conflicting
proposals without keeping a site-sized PHP array in memory.

Validated writes remain `pending` until their result is stored on the queue
row. If execution stops after a metadata or redirect write, the retry compares
the current target with the staged payload and records the original outcome
instead of writing or counting it twice. An atomic, five-minute option lock
prevents concurrent cron, manual and loopback workers from processing the same
job. Expired locks are taken over with a database compare-and-swap, and releases
match the ownership token, so a late worker cannot delete its successor's lock.

Existing EasyRankly metadata is checked during discovery and again immediately
before a write. A value created by an editor or another process after discovery
is preserved and reported. Redirects are updated only when their stored
`source_plugin` still matches the running adapter. Two source redirects with
the same matching identity but different targets or behavior are reported as a
source conflict, not mislabeled as duplicates.

## Recovery, cancellation and cleanup

Unexpected exceptions move the job to `paused` while retaining its exact
stream cursor and staged writes. The admin can inspect the PHP/database log and
resume from that checkpoint. Stale locks expire after five minutes.

Cancellation is also checkpoint-safe. A separate non-autoloaded cancellation
option records the request even when a worker currently owns the job lock. It
stops future work, keeps any values already written, records a `cancelled`
report, removes staging rows and clears the scheduled event. The admin screen
shows the pending cancellation and suppresses competing controls until the
current batch releases its lock.

Before successful or cancelled cleanup, the complete terminal report is saved
inside the active checkpoint with a finalization marker. If PHP stops after the
queue is removed but before the active option is cleared, the retry reuses that
durable report instead of rebuilding zero counters from an empty table. Reset
and uninstall remove the active checkpoint, dynamic lock/cancellation options,
queue schema/version and report history. Deactivation unschedules the worker
without deleting its checkpoint, allowing an administrator to resume after
reactivation.

Reports keep the Phase 2 counter contract and add an `execution` object with
`resumable`, `batches` and worker fields. Full SEO values are held only in the
temporary queue while a job is active; retained reports contain counts,
references, target field names and bounded diagnostics, not metadata payloads.

## Verification

The dependency-free fixture suite uses representative Free/PRO database
snapshots for Yoast, Rank Math, AIOSEO and SEOPress. With a batch size of one it
replays every pre-page checkpoint and asserts byte-identical output/cursors,
complete traversal, no duplicate source references, and field-level survival
of canonical, social, robots, primary-term, schema and paid redirect behavior:

```sh
php tests/phase3-migration-integration.php
```

`tests/phase3-wordpress-integration.php` runs through WP-CLI on an ephemeral
WordPress/MySQL site. It verifies preview isolation, multi-batch import,
existing-value preservation, Yoast Premium and per-post redirect writes,
checkpoint replay, retry after write-before-checkpoint and
cleanup-before-option-removal crashes, atomic job admission, durable concurrent
cancellation, stale-lock takeover, report persistence and staging cleanup:

```sh
wp eval-file wp-content/plugins/easyrankly/tests/phase3-wordpress-integration.php
```
