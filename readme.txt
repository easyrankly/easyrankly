=== EasyRankly ===
Contributors: easyrankly
Tags: seo, schema, sitemap, redirects, ai
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.8.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight, modular, developer-first SEO essentials for WordPress.

== Description ==

EasyRankly handles the SEO essentials your WordPress site actually needs — nothing bloated, nothing you have to configure to death.

Here's what it does:

* **The basics on every page.** SEO titles, meta descriptions, canonical URLs, and robots directives, set up sensibly out of the box.
* **Great social previews.** Open Graph and Twitter (X) cards so your links look right when shared.
* **Structured data that search engines understand.** A modular JSON-LD schema graph covering your Organization or Person, optional local business details, articles, breadcrumbs, FAQs, and WooCommerce products — plus reusable custom schema blocks you can target to specific pages.
* **Sitemaps, when you want them.** An optional XML sitemap index with sitemaps for your content (including images), taxonomies, and authors.
* **Control over what gets indexed.** Simple noindex, nofollow, noarchive, and sitemap-exclusion controls, per page or across your site.
* **Smart redirects built in.** An optional redirect manager with exact, wildcard, and regex matching, with per-pattern safeguards that keep even complex regex rules fast and safe.
* **Breadcrumbs and robots.txt.** A breadcrumb function for your theme (with optional shorter names per page) and an editable virtual robots.txt.
* **Optional AI meta generation.** Generate or improve SEO titles and descriptions right from the editor when WordPress has a connected AI provider. It's off by default and uses WordPress' native AI/Connectors APIs (available in WordPress 7.0 and later); on earlier versions it stays inactive and EasyRankly uses its built-in, non-AI logic instead.
* **Optional internal linking assistant.** Build a site-wide link graph, find orphan pages, get rule-based suggestions, and optionally refine with AI when AI features are enabled.
* **Private health monitoring.** Optional 404 tracking that stays on your server. EasyRankly stores no IP address or user agent; path identifiers are redacted on a best-effort basis and aggregate records are cleaned up automatically.
* **Dynamic variables** for filling in titles, social tags, and schema fields automatically.

All of it lives in a redesigned, streamlined admin interface with a short setup wizard to get you configured in minutes.

And what it leaves out, on purpose: no keyword scoring, no readability nags, no analytics or tracking, no marketing widgets, and no upsells.

== Installation ==

1. Upload the `easyrankly` folder to `/wp-content/plugins/`.
2. Activate EasyRankly from the Plugins screen.
3. Complete the short setup wizard, or configure the plugin later under Settings > EasyRankly.

== Frequently Asked Questions ==

= Can I run EasyRankly alongside another SEO plugin such as Yoast SEO or Rank Math? =

Yes. EasyRankly detects active SEO plugins and automatically steps back from overlapping output (document title, meta description, canonical, robots meta, sitemaps, and robots.txt) so you never get duplicate tags. You can force any of it back on with the `erankly_enable_head_output`, `erankly_enable_sitemaps_with_external_seo`, and `erankly_enable_robots_txt_with_external_seo` filters.

= Does it support WooCommerce? =

Yes. EasyRankly can output Product JSON-LD structured data for WooCommerce products. It is controlled by the `erankly_woocommerce_structured_data_enabled` and `erankly_render_woocommerce_product_schema` filters.

= Does EasyRankly work on WordPress Multisite? =

Yes. There is full Multisite support with network-level global settings, plus an optional multilingual module that links posts, pages, and terms across network sites and outputs hreflang alternates in the head and XML sitemaps.

After activation, an upgrade, or a sitemap setting change, each site refreshes its own rewrite rules on its next request; no network-wide scan is required. Network resets run in small background batches and report their status in Network Admin. On installations with more than 100 sites, network deactivation and uninstall are intentionally routed through WP-CLI so every site can be cleaned without an HTTP timeout. Run `wp plugin deactivate easyrankly --network`, then `wp plugin uninstall easyrankly` (replace `easyrankly` if the installed plugin directory uses a different name).

= Does EasyRankly collect any personal data or phone home? =

No analytics, tracking, telemetry, or EasyRankly phone-home calls are added. The optional Health 404 monitor stores request paths in your own database only, with emails, long IDs, tokens, and usernames stripped on a best-effort basis before saving.

If you enable AI meta generation, EasyRankly sends page context to the AI provider connected in WordPress only when an editor clicks the Generate with AI button. EasyRankly does not provide its own AI service or receive that content.

= What data does EasyRankly send to the AI provider? =

Only when someone explicitly triggers an AI action — never automatically on page views.

**Meta generation (editor):** when Generate with AI or Improve results is clicked, EasyRankly may send the site name, the site language (locale), the post/term/special-page title, plain-text body or description truncated to your configured character limit (shortcodes removed), and — when improving — the current title, description, and your instructions. Nothing is sent until the button is clicked.

**Health redirect suggestions (optional):** when Suggest with AI is used for a 404, EasyRankly sends only the broken URL slug words and a numbered list of existing page titles and paths from your site. Full post bodies are never included. Anonymized 404 paths (containing privacy placeholders) are skipped.

**Link Building suggestions (optional):** when Suggest with AI is used on the Links tab, EasyRankly sends the target page title and path plus a numbered list of candidate source page titles and paths (rule-based matches only). Full post bodies are never included.

All AI requests go through the provider configured on Settings → Connectors in WordPress. Review that provider's terms and data processing policy for retention and training use.

= What is the body character limit for AI meta generation? =

In Advanced mode, Settings → EasyRankly → AI lets you set how many plain-text characters of body or description are included in the prompt (4,000–64,000 in ×4 steps; default 4,000). Lower values use fewer tokens; higher values give the model more context.

= What do I need for AI meta generation to work? =

AI meta generation relies on WordPress' native AI and Connectors APIs, which are available in WordPress 7.0 and later. On earlier WordPress versions, or when no AI provider is connected, the feature stays completely inactive and EasyRankly falls back to its built-in, non-AI title and description logic — nothing else changes. Even where the APIs are available, the feature is off until you enable it under Settings > EasyRankly > AI and connect a provider on the WordPress Connectors screen.

= How do I display breadcrumbs? =

Call `erankly_breadcrumbs()` in your theme template. You can customise the markup with the `erankly_breadcrumb_items` and `erankly_breadcrumbs_html` filters. Legacy `easyrankly_breadcrumbs()` and `easyrankly_*` hook aliases remain available for backward compatibility.

= Can I migrate from Yoast SEO, Rank Math, All in One SEO or SEOPress? =

Yes. Open Settings > EasyRankly > Import/Export. Dedicated adapters read Free and paid-edition data left by Yoast SEO/Premium, Rank Math/PRO, AIOSEO/Pro and SEOPress/PRO, including per-content SEO, separate social fields, robots directives, schema configuration, keyphrases and advanced redirects where the source supports them. Preview and import run in resumable background batches with live counters, restart-safe checkpoints, manual resume/cancel controls and a downloadable JSON report. Preview never writes; import rechecks every target before writing and never overwrites existing EasyRankly values.

Before a run, EasyRankly identifies the source edition, version, modules and exact database signature. Unknown future versions or malformed source tables are blocked before any write. A value-sensitive source fingerprint is verified again after discovery, so a source modified during migration is paused instead of producing a mixed snapshot. Official Yoast, Rank Math, AIOSEO and SEOPress CSV/JSON exports can be uploaded directly from the migration screen. EasyRankly detects their certified signature automatically, stages them outside the public WordPress tree with private permissions, and deletes its temporary copy after preview, import or cancellation.

The final report contains an authoritative fail-closed go-live gate. It separates `blocked`, `ready_for_cutover`, `go_live`, `rollback_required`, `rolled_back` and `rollback_failed`, displays every mandatory proof and fingerprints the exact decision with SHA-256. No live-verification action is available until preflight passes. Download the complete JSON report or its value-free CSV exception ledger.

For database migrations, EasyRankly captures a representative semantic HTML, robots.txt, sitemap and redirect baseline while the old plugin still owns frontend output. After controlled deactivation and cache purging, the live verifier repeats same-origin requests without following redirect chains. Real imports also retain a seven-day conditional rollback journal: a rollback restores only values that still equal what the migration wrote, so later manual edits are never lost.

You can also export and re-import EasyRankly settings, redirects, special-page defaults and SEO metadata for posts, terms and authors as a single JSON file. Complete JSON imports have a request-specific application limit (10 MB by default, reduced automatically when PHP memory is constrained) and a structural decode budget for nesting and value count; unsafe files are rejected before they can expand into PHP arrays. On Multisite the Import/Export tab lives in Network Admin, and the file covers the network-wide global settings plus the content of the primary site it runs on; it is not a whole-network content export. Translation links between network sites are not included because they reference site-specific IDs.

== Changelog ==

= 2.8.0 =
* Added an authoritative fail-closed gate that is embedded in every migration report and distinguishes preflight authorization, full go-live, rollback-required and terminal rollback states.
* Made invalid, conflicting, unsupported, preserved or semantically mismatched values, unresolved diagnostics, unsafe redirects, incomplete rollback coverage and missing frontend baselines explicit cutover blockers.
* Added server-side enforcement for live verification, a prominent proof checklist, a deterministic decision SHA-256 and an honest contract-only boundary for official exports that cannot provide an old-plugin HTML baseline.
* Added a separate release-level gate tied to the exact Phase 7 workspace, commit, matrix age and clean-worktree state.
* Made authorized real-package evidence for Yoast Premium, Rank Math PRO, AIOSEO Pro and SEOPress PRO mandatory for a release GO-LIVE verdict; bundled contract fixtures cannot satisfy this proof.
* Added machine-readable `migration-go-live.json`, a strict `composer migration:go-live` command and regression coverage for every runtime and release blocker.

= 2.7.0 =
* Added a single fail-closed migration certification command with immutable fixture hashes and a machine-readable evidence record tied to the exact workspace bytes under test.
* Added a PHP 8.0/8.4 and WordPress 6.2/7.0.1 matrix on MariaDB 10.11, covering both single-site and Multisite installations.
* Added real WordPress database imports for Yoast Premium, Rank Math PRO, AIOSEO Pro and SEOPress PRO plus real application of every certified official export signature.
* Added a bounded 500-post performance fixture with explicit time and incremental-memory budgets, alongside the existing interruption, retry, cancellation, security, live verification and rollback regressions.
* Added Multisite isolation certification for queue tables, reports, target metadata and conditional rollback journals.
* Separated bundled storage-contract fixtures from externally supplied licensed PRO binary evidence so certification reports cannot overstate their provenance.

= 2.6.0 =
* Added an exact post-import evidence ledger whose invariant requires every discovered object, metadata field and redirect occurrence to have one terminal outcome.
* Added normalized before/after hashes for title, canonical, robots, social and JSON-LD data, unresolved-variable diagnostics and direct editor links for exceptions.
* Added redirect graph audits for loops, chains, collisions and dangerous regex, plus status/Location storage and live probes that never follow redirect chains.
* Added representative old-plugin HTML, robots.txt, sitemap and redirect baseline capture with a controlled post-cutover live comparison; raw responses are never retained.
* Added nonce-protected complete JSON and CSV exception exports with CSV formula-injection hardening.
* Added a seven-day conditional rollback journal for metadata and redirects. Rollback restores only unchanged migration-owned values and always preserves later manual edits.
* Added real WordPress/MariaDB certification for accounting, semantic/live proof, redirect diagnostics, admin controls and mixed safe rollback.

= 2.5.0 =
* Added secure admin uploads for official Yoast, Rank Math, AIOSEO and SEOPress CSV/JSON exports, with capability and nonce gates plus strict certified-signature auto-detection.
* Stages source exports only in a per-site private directory outside WordPress and wp-content, uses random filenames and restrictive filesystem permissions, and never exposes server paths in notices or reports.
* Deletes managed exports after preview, import or cancellation, preserves them across resumable pauses/deactivation, prunes abandoned uploads and integrates complete cleanup with reset/uninstall.
* Added deterministic post-run `ready`, `safe`, `review` and `blocked` decisions, machine-readable verification checks and a controlled source-plugin switch checklist.
* Added direct preview-to-import approval for database migrations and explicit re-upload approval for official exports whose preview copy has already been securely erased.
* Added standalone and real WordPress/MariaDB certification for source auto-detection, private permissions, mismatch rejection, lifecycle cleanup, admin authorization and switch decisions.

= 2.4.0 =
* Added certified source profiles for Yoast SEO Free/Premium, Rank Math Free/PRO, AIOSEO Lite/Pro and SEOPress Free/PRO, with explicit version gates, module/add-on reporting and per-surface inventories.
* Added strict database-signature validation so unknown versions, missing required columns and unrecognized storage layouts fail closed before migration writes.
* Added value-sensitive source fingerprints that pause a job if source SEO data changes between discovery and apply.
* Added resumable readers for official Yoast and Rank Math CSV exports, AIOSEO Complete Data JSON/CSV exports and SEOPress metadata CSV exports.
* Added official-format fixtures plus real WordPress/MariaDB certification for profiles, drift detection, malformed tables and full export-backed worker execution.

= 2.3.0 =
* Added resumable, keyset-paginated migrations with WP-Cron processing, atomic job admission and worker locks, saved checkpoints and manual resume/cancel controls.
* Added a crash-safe temporary queue and durable finalization checkpoint so replayed batches, interrupted writes and cleanup retries remain idempotent and report counters stay accurate.
* Made cancellation durable while another worker owns the lock and exposed its pending state without competing admin controls.
* Added live migration progress, paused-job recovery and complete reset, deactivation and uninstall lifecycle handling.
* Added representative Free/PRO snapshot coverage for all four source plugins and a real WordPress/MySQL end-to-end worker test.
* Fixed author SEO metadata registration so migrated author overrides use WordPress' supported user-meta API.

= 2.2.0 =
* Added a lossless migration target model for explicit canonical, social, robots, editorial, author, and per-content schema data.
* Added advanced redirect semantics, provenance, scheduling, 307/308 support, and bulk-safe cache invalidation.
* Added previewable, conflict-safe Free/PRO migrations from Yoast SEO, Rank Math, All in One SEO, and SEOPress, including redirects and downloadable post-migration reports.
* Made Simplified mode presentation-only so imported overrides remain effective on the frontend.

= 2.1.0 =
**New**
* Health → Broken-Link Candidates: an on-demand crawler that spiders your indexable pages, checks the HTTP status of every distinct link found (internal and external), and lists those returning 4xx/5xx with their anchor text and the page they were found on. Internal broken links reuse the 404 workflow (301 redirect with an optional AI-suggested target); external broken links link back to the page to edit. The scan runs in batches so it stays within PHP time limits, and per-URL status results are cached briefly to keep re-scans fast.

= 2.0.0 =
Major release: the admin interface has been rebuilt from the ground up, with AI-assisted meta generation and a smarter redirect manager.

**New**
* AI-assisted SEO titles and descriptions, generated or improved from the editor when WordPress has a connected AI provider (WordPress 7.0+). Off by default, and EasyRankly falls back to its built-in, non-AI logic on earlier versions or when no provider is connected.
* Smarter redirect manager with exact, wildcard, and regex matching. Backtracking and depth limits are now scoped per pattern, removing request-wide PHP configuration changes while still preventing catastrophic backtracking.
* Dedicated Open Graph and X (Twitter) social fields for special-page SEO defaults, editable from the classic-theme settings or the contextual Site Editor panels.
* Expanded setup wizard: site identity (Organization or Person, with a reference user), site name, interface mode, and X (Twitter) account.

**Interface & UX**
* Redesigned admin interface, rebuilt from scratch with cleaner, streamlined options and less to configure.
* Block themes can configure special-page SEO defaults (homepage, blog, author, date, search, and 404) directly in the Site Editor, saved through the editor's native Save action (WordPress 6.6 or later).
* Added a classic settings fallback for block themes on WordPress 6.2–6.5, where the Site Editor special-page panels are not available.
* Reorganized the Advanced settings tab into clearer, consistently titled sections (Indexing & robots directives, robots.txt, Pagination, Attachment pages).
* Saving settings now keeps you on the active tab instead of returning to General.
* EasyRankly document panels now appear after the default WordPress editor panels.
* On Multisite block-theme sites, the per-site menu is hidden when no local panel (Health or Redirects) is available.
* Removed a redundant notice on the multilingual setting when the site is not running WordPress Multisite.

**Developers**
* Canonical `erankly_*` filter hooks and the `erankly_breadcrumbs()` template function, with legacy `easyrankly_*` aliases kept for backward compatibility.

**Compatibility & hardening**
* Minimum supported WordPress version kept at 6.2.0; Site Editor SEO panels load only on WordPress 6.6 or later.
* Hardened to meet the latest WordPress.org plugin guidelines: unique prefixes, output escaping, input sanitization, and proper script/style enqueuing.

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 2.8.0 =
Migration cutovers now use a fail-closed proof gate, while release GO-LIVE additionally requires a fresh clean Phase 7 record and authorized evidence for every supported PRO plugin.

= 2.7.0 =
Third-party migrations now ship with a reproducible Free/PRO contract, runtime, scale and Multisite certification matrix plus a machine-readable evidence record.

= 2.6.0 =
Migration reports now include cutover evidence, live semantic verification, a CSV exception ledger and a time-limited conditional rollback that preserves later manual edits.

= 2.5.0 =
Official source-plugin exports can now be uploaded privately from the migration screen, and terminal reports explicitly determine whether importing or switching SEO plugins is safe.

= 2.4.0 =
Migration adapters now validate source editions, versions and storage signatures, verify immutable source fingerprints, and support strict official-export fallbacks.

= 2.3.0 =
Third-party SEO migrations now continue in restart-safe background batches and expose live progress and recovery controls.

= 2.1.0 =
Adds Health → Broken-Link Candidates: an on-demand crawler that finds internal and external links returning 4xx/5xx across your indexable pages.

= 2.0.0 =
Major update: a rebuilt admin interface, optional AI-assisted meta generation, and a smarter redirect manager. Review your settings after updating.

= 1.0.0 =
First public release of EasyRankly.
