# Performance optimization record

This document is the reproducible checkpoint and verification protocol for the
settings, asset and bootstrap optimization completed on 2026-07-17. It does not
replace migration certification; it adds loading-boundary and performance gates
to that matrix.

## Preserved checkpoint

The optimization started from an already-dirty migration worktree. Those changes
were preserved and were not committed or rewritten as part of this work.

| Evidence | Value |
|---|---|
| Git branch | `main` |
| Git HEAD | `9b8f292730b76393b2363af9a7242ee6c5b179fe` |
| Pre-optimization working diff SHA-256 | `1ac3a4bb9f5ba919bfcf9d2f8816e908c62411f47270d39ea2a69bb06db8079e` |
| Pre-optimization porcelain SHA-256 | `6f565bff6741378ea3dce453f904e5da7bcf1c776b9f6efae0d5e4e6c98385ca` |
| Pre-optimization immutable migration gate | PASS |

The checkpoint is an evidence record, not a clean commit. Review optimization
changes by path and keep migration work and optimization work separate when the
worktree is eventually committed.

## Enforced budgets

Run `composer performance:contract` from the plugin root. The contract currently
records:

| Boundary | Result | Budget |
|---|---:|---:|
| Always-loaded helper kernel | 11,893 B | less than 30 KB |
| Frontend bootstrap source | 102,823 B | at most 146,250 B |
| AI implementation plus minimal REST helpers | 64,795 B | less than 72 KB |
| Health enabled, ordinary non-404 bootstrap | 8,925 B | less than 12 KB |
| Health Broken-Link REST route shell | 2,233 B | less than 6 KB |
| General settings PHP source (real WordPress) | 382,034 B | less than 550 KB |
| Import / Export JavaScript | 26,366 B raw / 6,727 B gzip | at most 35 KB raw |
| Import / Export CSS | 23,613 B raw / 5,679 B gzip | at most 25 KB raw |

The frontend source footprint is about 50% below the earlier 195 KB baseline. The
Import / Export asset budget excludes WordPress core assets and explicitly
requires that the Media Library is not enqueued.

The General measurement comes from the fresh-install WordPress integration cell,
not a static file estimate. The former settings baseline was about 985 KB, so the
active General request now parses approximately 62% less EasyRankly source.

The contract also fails if:

- the base settings loader includes `import-export.php`;
- the cron worker uses the Import / Export controller instead of
  `includes/migrations.php`;
- the settings navigation loses its real URLs or no-JavaScript routing;
- the deleted monolithic `assets/css/admin.css` is restored or enqueued.

## Runtime scenario matrix

`tests/performance/runtime-profiler.php` is an opt-in MU plugin. Copy or symlink
it into `wp-content/mu-plugins`, define `ERANKLY_PROFILE_OUTPUT` as an absolute
writable JSONL path, and optionally define `ERANKLY_PROFILE_SCENARIO`. A request
may instead carry the read-only `erankly_profile_scenario` query parameter.

Each JSONL row contains elapsed time, peak memory, query count, every included
EasyRankly PHP file with source bytes, queued script/style handles, the Media
Library flag and PCOV/Xdebug line-coverage totals when either extension is
available.

Collect at least these scenarios on single-site and Multisite:

| Surface | Required assertion |
|---|---|
| Frontend singular and archive | No admin or migration files; no EasyRankly CSS without a contextual frontend feature |
| REST and AJAX | No frontend renderer or migration UI unless the route/action owns it |
| Cron migration worker | `includes/migrations.php` is present; `includes/import-export.php` is absent |
| WP-CLI ordinary command | No migration classes unless the command invokes migration work |
| Robots and core/specialist sitemap | Only the relevant sitemap/content helpers are present |
| Every settings tab | Only the active renderer and declared asset manifest are present |
| Setup | Setup CSS and wizard JavaScript only |
| Classic editor and taxonomy | Classic editor CSS plus only the declared editor modules |
| Block Editor and Site Editor | Editor assets only; no settings CSS |
| Migration preview/import/resume/rollback/live verification | Migration backend present and state transitions certified |

For JavaScript and CSS coverage, capture the initial state and then repeat after
opening dropdowns, variable pickers, expandable tables, upload dropzones,
modals and responsive navigation. Initial-load coverage alone is not evidence
that interactive code is dead. Store desktop and mobile screenshots with the
JSONL row for the same scenario and inspect keyboard navigation, focus order,
labels, status announcements and reduced-width layout.

## Asset manifest contract

Core tabs use an explicit manifest. Unknown tabs registered by the public
`erankly_settings_tabs` filter retain the complete compatibility bundle. Modules
are independent; they no longer form a serial dependency chain.

`wp_enqueue_media()` is driven by the `media` manifest capability. It is present
for Social, special-page media fields, the classic editor and taxonomies, and is
absent from Import / Export, Health, Redirects, setup and ordinary tabs without
a media picker.

The CSS layout is now:

- `shared.css`: design tokens and cross-surface components;
- `admin-core.css`: settings shell, cards, controls and shared admin primitives;
- `admin-settings.css`: generic settings panels;
- `migration.css`: Import / Export, reports and migration state;
- `setup.css`: setup wizard;
- `health.css`: Health and broken-link surfaces;
- `classic-editor.css`: classic meta boxes and taxonomy forms;
- `reset.css`: destructive reset controls;
- existing editor and redirect styles remain separate; multilingual styles are
  owned exclusively by the EasyRankly Multilingual package from core 3.0.

Run `npm ci && npm run lint:css` to enforce duplicate-declaration, specificity
and `!important` rules. Narrow WordPress override exceptions are documented next
to the affected declarations.

## PHP loading boundaries

The always-loaded helper kernel contains only core compatibility/request helpers,
stored-setting access, feature switches and common text/URL sanitization. Sitemap
cache helpers, LocalBusiness sanitizers, content defaults, global metadata,
template variables, video and mixed UI/content utilities load only in a context
that consumes them. SEO checklist code is editor-only; meta visibility and
frontend rendering are separate files; WooCommerce compatibility loads only when
WooCommerce APIs exist, while legacy aliases remain isolated as a documented
always-on compatibility layer.

`erankly_get_setting()` reads one persisted key with an explicit fallback and
does not build the full dynamic defaults model. `erankly_get_settings()` remains
the intentional full-merge API for settings forms, reset, activation and writes.
Both APIs share request-level option caching and the integration gate rejects an
extra query for repeated key-level reads.

The three small bootstrap values (plugin version, rewrite generation and setup
status) share the autoloaded `erankly_runtime_state` option on single-site
installations. Legacy scalar options are mirrored for rollback compatibility;
existing sites migrate lazily once. Multisite keeps network-option storage,
where WordPress has no per-option autoload flag. The integration gate verifies
that all three runtime reads add zero queries after WordPress loads autoloaded
options.

When AI is enabled, its 51 KB implementation and 14 KB minimal helper set stay
out of ordinary frontend and WP-CLI bootstraps. Admin requests load them directly;
REST route initialization loads them through a priority-5 dispatcher, avoiding
request-URI heuristics while keeping route discovery intact. The AI helper loader
is contractually limited to content defaults and connector utilities; rich global
metadata and template-variable helpers are not part of that boundary.

When Health is enabled, ordinary requests parse only its entrypoint, constants
and lightweight dispatcher (8,925 B). The frontend 404 monitor loads after
WordPress has resolved an actual non-admin 404. REST discovery loads a 2,233 B
Broken-Link route shell, while the 32 KB crawler enters only when a start, tick
or cancel callback executes. Suggestions load for their retention cron and Health
admin work; thin-content scanning, crawler admin rendering and the Health panel
remain limited to Health admin actions or the active Health settings tab.

Admin bootstrap loads only `setup-wizard-loader.php` (under an 8 KB source
budget). The setup form processor and renderer remain in `setup-wizard.php` and
are parsed only for a wizard render, save or skip request. Turning Health off
also clears `erankly_health_prune_404_cron` immediately for the current site; a
minimal cron callback removes stale per-site schedules lazily on Multisite,
avoiding an unbounded network sweep in the settings request.

## Dead-code decision record

No public or dynamically reachable runtime API was deleted solely because a text
search or initial coverage did not reach it. The audit classified candidates as:

- contextual: migration, sitemap, video, editor, health and reset code that is
  valid but must load only on its owning surface;
- public/extension: settings-tab filters, dynamic render actions and template
  functions that can be called by themes or plugins;
- legacy: the `easyrankly_breadcrumbs()` alias and compatibility selectors that
  require a deprecation cycle before removal;
- rare hooks: cron, REST, AJAX, lifecycle, Multisite and WP-CLI callbacks covered
  by dedicated scenarios;
- test/certification: excluded from distributed runtime decisions.

The safe runtime deletion in this phase is the obsolete monolithic admin CSS
artifact after its rules were routed to contextual files. PHP and JavaScript
were reorganized and lazy-loaded, not removed without coverage evidence. Any
future deletion must record the scenario coverage, public-API check, replacement
test and raw/gzip bytes saved here or in a linked report.

## Certification commands

Run in this order:

```sh
composer performance:contract
npm ci
npm run lint:css
composer lint
composer compat
bash tests/certification/run.sh
```

The Docker matrix covers PHP 8.0 and 8.4, WordPress 6.2 and 7.0.1, MariaDB 10.11,
single-site and Multisite. Its single-site cells execute
`tests/performance-wordpress-integration.php`, which proves that ordinary and
General settings bootstraps exclude Import / Export and migration classes,
key-level reads do not build full defaults or add a query, and core asset
manifests preserve Media Library boundaries. It also proves that the compact
runtime state is autoloaded, the full setup wizard stays deferred on an ordinary
admin bootstrap, and an enabled-to-disabled Health transition removes its cron.

The 2026-07-18 certification completed with exit code 0 across PHP 8.0/8.4,
WordPress 6.2/7.0.1, MariaDB 10.11, single-site and Multisite. Browser verification
also opened every visible settings tab, confirmed the matching active URL and
contextual asset set, exercised the Social Media Library picker, and checked
Import / Export at 390 x 844 with no horizontal overflow. Import / Export loaded
three EasyRankly scripts, three styles and no WordPress Media Library assets.

Every WordPress matrix cell also enabled AI, Health and Link Building for a fresh
request and verified the contextual boundaries dynamically: ordinary bootstrap
excluded AI and all heavy Health files; REST initialization loaded AI and only
the Health route shell; executing a crawler callback loaded the crawler without
the frontend monitor or panel; explicit frontend and admin loaders then exposed
only their respective implementations.

The separate release-decision artifact remains blocked by the intentionally dirty
checkpoint worktree and by four licensed PRO evidence packages that are external
to this repository. All executable certification checks themselves pass.
