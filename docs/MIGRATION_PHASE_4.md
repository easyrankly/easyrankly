# Third-party migrations (Phase 4)

Phase 4 certifies each source adapter against explicit Free/paid profiles,
known database signatures and official export formats. A migration is admitted
only when the detected version and storage shape are recognized; an unknown
future version, malformed table or wrong export fails closed before any target
write.

## Certified source matrix

| Source profile | Certified versions | Direct database coverage | Official export fallback |
| --- | --- | --- | --- |
| Yoast SEO Free / Premium | `3.0.0`–`28.x` | Post, term and author meta; legacy taxonomy option; Premium per-post, plain and regex redirects | Premium redirect CSV with exact `Origin,Target,Type,Format` signature |
| Rank Math Free / PRO | `0.9.0`–`1.x` | Post, term and author meta; schema entities; advanced robots; PRO redirection table with multi-source rules | PRO metadata CSV and redirection CSV |
| AIOSEO Lite / Pro | `3.0.0`–`4.x` | Legacy v3 postmeta; v4 post and Pro term tables; Pro redirection table | Pro Complete Data JSON and redirect CSV |
| SEOPress Free / PRO | `3.0.0`–`10.x` | Post/term meta; manual and legacy Pro schema; redirect CPT and per-object redirects | Metadata CSV, including redirects and login visibility |

The upper bounds are intentional compatibility gates, not predictions. When a
source releases a new major storage family, EasyRankly reports the source as
unsupported until its signatures and fixtures are certified.

## Adapter profile contract

Every adapter exposes one read-only profile containing:

- source name, detected version and `certified`, `unversioned` or `unsupported`
  version state;
- Free/Lite or Premium/PRO edition;
- detected modules and a support state for each module;
- database or official-export source mode;
- exact recognized storage surfaces and format;
- capabilities and a per-surface inventory.

For export-backed jobs the edition is derived from the file signature, not from
source code that may no longer be installed. A Premium/PRO-only format is
labelled accordingly; a shared official format is reported as `free-or-pro`
instead of guessing.

The profile and inventory are captured in the migration report before
discovery. Reports therefore explain exactly what EasyRankly found, what it
recognized and which add-on data still needs a manual post-import review.

## Add-on and module profiles

Paid core storage is imported, not merely detected. Yoast Premium redirects,
Rank Math PRO advanced metadata and redirects, AIOSEO Pro terms and redirects,
and SEOPress PRO schema/redirect data use the same preview, conflict and
idempotence rules as Free data.

Product-specific surfaces that do not have an equivalent EasyRankly target are
kept as separate profiles instead of being silently flattened:

- Yoast News, Video, WooCommerce SEO and Local SEO are detected separately;
- every enabled Rank Math module is listed; schema, redirections, advanced
  robots and image SEO have certified mappings;
- AIOSEO Local SEO and Video Sitemap payloads are detected separately;
- SEOPress PRO schema and redirect surfaces are independently reported.

A detected module without a lossless target receives `review_required` and a
bounded report warning. This is deliberate: EasyRankly never turns a source-only
setting into a guessed target value.

## Storage signatures and fail-closed behavior

Meta surfaces are whitelisted by exact keys or certified prefixes. Table-backed
sources must contain their required columns before any row scan:

- `rank_math_redirections`: `id`, `sources`, `url_to`, `header_code`, `status`;
- `aioseo_posts`: `id`, `post_id`, `title`, `description`, `canonical_url`;
- `aioseo_terms`: `id`, `term_id`, `title`, `description`;
- `aioseo_redirects`: `id`, `source_url`, `target_url`, `type`,
  `source_url_match`, `query_param`, `enabled`.

Option and custom-post-type sources also require their declared array or post
type signature. A partial table with a familiar name is rejected rather than
queried optimistically.

At job start, the adapter records a SHA-256 fingerprint derived from values,
not just row counts. Database fingerprints include counts, maximum row IDs and
checksums of the certified source columns; option surfaces hash their serialized
value; export files hash their bytes. The worker recomputes the fingerprint
after discovery and before preview completion or the first write. A mismatch
pauses the job with `source_changed_after_start`, leaves target data untouched
and preserves the report/checkpoint for inspection or cancellation.

## Official export backend

The backend accepts a readable local `.csv` or `.json` file through:

```php
erankly_migration_job_runner()->start_from_export(
	'yoast',
	'/absolute/private/path/yoast-redirects.csv',
	true
);
```

Files are restricted to 100 MB by default through
`erankly_migration_export_max_bytes`. CSV readers are resumable, support comma
and semicolon delimiters, strip UTF-8 BOMs and replay deterministically from a
physical row checkpoint. AIOSEO Complete Data JSON is decoded only up to 20 MB
in one request; larger AIOSEO sets should use its official CSV route so batch
processing remains bounded. Phase 5 exposes this backend through a secured admin
upload, private-file lifecycle and automatic certified-signature selector; see
`docs/MIGRATION_PHASE_5.md`.

Recognized signatures are source-specific. A Yoast CSV cannot be selected for
AIOSEO, a generic two-column CSV is not accepted as an AIOSEO export, and an
unknown JSON shape is rejected.

## Verification

The standalone certification suite validates official-format signatures,
mapping and deterministic replay:

```sh
php tests/phase4-adapter-certification.php
```

The real WordPress/MariaDB suite validates version gates, edition/module
profiles, table-schema rejection, value-sensitive fingerprints, source-drift
pause semantics and the complete resumable official-export worker:

```sh
wp eval-file wp-content/plugins/easyrankly/tests/phase4-wordpress-integration.php
```

Phase 3's full worker regression suite remains mandatory because Phase 4 adds
admission and snapshot checks to that state machine.
