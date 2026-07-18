# Migration target model (Phase 1)

This document defines the EasyRankly storage contract that migration adapters
must target. The contract is intentionally independent from Simplified mode:
that setting changes which controls are visible, never whether stored data is
used on the frontend.

## Content metadata

- Explicit title, description, canonical, breadcrumb, Open Graph and X values
  always win over generated or global fallbacks.
- Open Graph and X images have separate URL and alt-text fields. The legacy
  shared social image remains a read-only fallback for backward compatibility.
- Robots use tri-state directives (`inherit`, explicit allow, explicit deny),
  with legacy boolean fields read only when no tri-state value exists.
- Primary terms are stored as a taxonomy-to-term-ID map. Focus keywords,
  cornerstone status and a sanitized legacy editorial payload are retained for
  reporting and future feature parity.
- Authors can receive the same core SEO, social and robots metadata through
  registered user meta.

## Schema

`_erankly_schema_mode` accepts:

- `default`: automatic graph only;
- `merge`: automatic graph plus per-content JSON-LD blocks;
- `replace`: per-content JSON-LD plus matching global blocks, with automatic
  nodes and breadcrumbs suppressed;
- `disabled`: no EasyRankly JSON-LD for the object.

`_erankly_schema_disabled_types` can suppress selected automatic node types
before custom blocks are merged. Graph deduplication still runs last.

## Redirect rules

Rule identity includes source path, source query, match type, case sensitivity,
trailing-slash mode, query mode, visibility, request conditions and schedule.
It does not include the target, status code, priority or provenance, allowing a
later import of the same source rule to update those attributes idempotently.

Supported status codes are 301, 302, 307, 308, 410 and 451. Supported match
types are exact, wildcard, regex, contains, starts-with and ends-with. Query
modes are ignore, preserve on target and exact. Optional fields cover priority,
schedule, visitor visibility, portable request conditions, source plugin,
source reference and migration run ID.

Imports must call `begin_bulk()` and `end_bulk()` around redirect mutations so
runtime rules and external page caches are invalidated once per import.

## Compatibility guarantees

- Existing shared social images and boolean robots metadata remain readable.
- Existing redirect rows are backfilled with explicit match type and rule hash.
- Existing exact redirects retain the fast normalized-path lookup.
- Export format 2.0 preserves complex post, term and user metadata as decoded
  values and includes every advanced redirect field.
