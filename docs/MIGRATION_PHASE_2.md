# Third-party migrations (Phase 2)

Phase 2 adds a source-adapter layer for Yoast SEO, Rank Math, All in One SEO
and SEOPress. The importer reads source data even when the original plugin is
inactive and includes the database surfaces left by each paid edition.

## Workflow and safety

The Import/Export screen offers two actions for every detected source:

1. **Preview migration** performs the same discovery, mapping, sanitization and
   conflict checks as a real migration without writing EasyRankly data.
2. **Run migration** applies only values that passed those checks.

Every run receives a UUID and stores a bounded report containing source and
version, timestamps, capabilities, object and field counts, redirect outcomes,
warnings and the first 100 record-level diagnostics. The ten most recent
reports are retained and each selected report can be downloaded as JSON.
The settings screen always shows the latest report, exposes the first 20
warnings and record-level diagnostics, and links to the retained report history.

The importer never deletes source-plugin data. Existing EasyRankly metadata is
never overwritten, including values deliberately set to an empty string. If
two source records propose different values for the same target field, the
first value is retained and the conflict is recorded. A repeated preview or
import is therefore safe and idempotent.

Redirects use the complete matching identity from the Phase 1 target model.
A matching redirect can be updated only when its stored `source_plugin` equals
the adapter that is running. A manual rule or a rule owned by another importer
is preserved as a conflict. Redirect writes run in bulk mode so runtime and
external page caches are invalidated once per migration.

## Source coverage

| Source | Free data | Paid-edition data |
| --- | --- | --- |
| Yoast SEO | Posts, current native term metadata, legacy taxonomy records, author archives, title/description, canonical, breadcrumb label, separate Open Graph and X fields, robots, primary terms, focus keyphrase, schema page/article selection and editorial scores | Multiple focus keyphrases, cornerstone state, Premium redirect store, legacy plain/regex redirect exports and per-post redirects |
| Rank Math | Posts, terms and authors; title/description, canonical, breadcrumb label, separate Facebook/X fields, robots, focus keywords, primary terms and schema entities | Advanced robots, pillar content, Content AI/editorial scores and redirect rules with multiple sources, regex, contains, starts-with and ends-with matching |
| All in One SEO | V4 post table and legacy V3 postmeta; title/description, canonical, separate Open Graph/X fields, robots, keyphrases, primary terms, pillar state and schema configuration | Terms table, additional keyphrases and the Pro redirect table, including regex, case and query behavior |
| SEOPress | Posts and terms; title/description, canonical, breadcrumb label, separate Facebook/X fields, robots, target keywords and primary category | Manual and legacy PRO schema records plus redirect-post and per-object redirect metadata, including regex, query and login-state conditions |

Source variables are converted to EasyRankly runtime variables before storage.
Common date, pagination, post type, search, taxonomy, author, image and site
variables remain dynamic rather than being frozen at import time. Unsupported
source variables are listed once in migration warnings instead of failing
silently. Delimiter-based variables are removed so they cannot leak into SEO
output; unrecognized AIOSEO hash tokens are preserved because they may be
literal hashtags rather than placeholders.
When a source social image is tied to a WordPress attachment, its current media
library alt text is copied into the corresponding Open Graph or X override.
Schema data that can be represented safely becomes sanitized JSON-LD blocks;
source-only analysis and configuration payloads are retained in bounded legacy
editorial metadata so the report can call out records that still need review.

## Report counters

- `fields_found`, `fields_ready` and `fields_written` describe valid target
  metadata. Ready/written counts are also split across posts, terms and users.
- `fields_skipped_existing`, `fields_duplicate` and `fields_conflicts` explain
  why discovered source values were preserved instead of written.
- `fields_invalid` and `fields_failed` separate validation failures from write
  failures.
- Redirect counters distinguish ready creates/updates, created/updated rules,
  unchanged rules, duplicates, ownership conflicts, invalid source rules and
  storage failures.

Report history is stored in the non-autoloaded
`erankly_migration_reports_v1` option. Reports contain IDs, field names and
diagnostics, not full source or target metadata values.

## Performance boundaries

Post, term, user and redirect discovery uses stable 200-record batches. Meta
caches are primed once per object batch, warnings/details are bounded and only
the current batch plus aggregate report state remains in memory. Phase 2's
synchronous manager remains available for backward compatibility; the settings
screen now uses the resumable Phase 3 worker documented in
[`MIGRATION_PHASE_3.md`](MIGRATION_PHASE_3.md).
