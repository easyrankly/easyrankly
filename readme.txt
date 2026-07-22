=== EasyRankly ===
Contributors: easyrankly
Tags: seo, schema, sitemap, redirects, ai
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight, modular, developer-first SEO essentials for WordPress.

== Description ==

EasyRankly brings together the SEO essentials for WordPress in a modular toolkit with sensible defaults and optional features you can enable when needed.

Here's what it does:

* **Core metadata across your site.** SEO titles, meta descriptions, canonical URLs, and robots directives, set up sensibly out of the box.
* **Great social previews.** Open Graph and Twitter (X) cards with shared image alt text, an optional X-specific override, and Media Library alt-text fallbacks for local images.
* **Structured data that search engines understand.** A modular JSON-LD schema graph covering your Organization or Person, optional local business details, articles, breadcrumbs, FAQs, and WooCommerce product compatibility, plus reusable custom schema blocks you can target to specific pages.
* **Sitemaps, when you want them.** An optional XML sitemap index with sitemaps for your content (including images), taxonomies, and authors.
* **Control over what gets indexed.** Simple noindex, nofollow, noarchive, and sitemap-exclusion controls, per page or across your site.
* **Smart redirects built in.** An optional redirect manager with a streamlined rule editor, advanced audience and scheduling controls, and exact, contains, starts-with, ends-with, wildcard, and regex matching. Per-pattern safeguards keep even complex regex rules fast and safe.
* **Breadcrumbs and robots.txt.** A breadcrumb function for your theme (with optional shorter names per page) and an editable virtual robots.txt.
* **Optional AI meta generation.** Generate or improve SEO titles and descriptions right from the editor when WordPress has a connected AI provider. It's off by default and uses WordPress' native AI/Connectors APIs (available in WordPress 7.0 and later); on earlier versions it stays inactive and EasyRankly uses its built-in, non-AI logic instead.
* **Optional persistent AI content analysis.** Enable it from Features, target several focus keyphrases as WordPress-style tags, choose the primary keyword, and get an editorial focus report with measured signals, prioritized improvements, ready-to-use sentences, structure ideas, possible keyword cannibalization, and stricter pillar-content guidance.
* **Optional internal linking assistant.** Build a site-wide link graph, find orphan pages, get rule-based suggestions, and optionally refine with AI when AI features are enabled.
* **Private health monitoring.** Optional 404 tracking that stays on your server. EasyRankly stores no IP address or user agent; path identifiers are redacted on a best-effort basis and aggregate records are cleaned up automatically.
* **Optional WordPress cleanup.** Use a conservative one-click preset or choose granular controls for embeds, pingbacks, Heartbeat, revisions, frontend styles, and speculative loading.
* **Dynamic variables** for filling in titles, social tags, and schema fields automatically.

All of it lives in a redesigned, responsive admin interface with consistent form patterns, accessible label and control relationships, keyboard-friendly tabs and dialogs, and a short setup wizard to get you configured in minutes.

== Installation ==

1. Upload the `easyrankly` folder to `/wp-content/plugins/`.
2. Activate EasyRankly from the Plugins screen.
3. Complete the short setup wizard, or configure the plugin later under Settings > EasyRankly.

== Frequently Asked Questions ==

= Can I run EasyRankly alongside another SEO plugin such as Yoast SEO or Rank Math? =

No, running two full SEO plugins at the same time is not recommended because their overlapping features can produce conflicts or inconsistent output. If EasyRankly detects a recognised SEO plugin, it displays an admin notice and disables its own head metadata, structured data, sitemaps, and robots.txt customisations to reduce the risk of duplicates; redirects, health monitoring, and breadcrumbs remain available. If you are switching from Yoast SEO, Rank Math, All in One SEO, or SEOPress, use EasyRankly's migration tools and complete the guided checks before deactivating the previous plugin.

= Does it support WooCommerce? =

Yes. EasyRankly supports Product JSON-LD for WooCommerce products, including the product name, description, image, SKU, brand, GTIN, price and currency, stock status, sale end date, aggregate rating, and approved reviews when those values are available. Variable products can use an AggregateOffer with their price range and offer count. To avoid duplicate structured data, EasyRankly leaves Product schema to WooCommerce when WooCommerce's native structured data is active; developers can change this behaviour with the `erankly_woocommerce_structured_data_enabled` and `erankly_render_woocommerce_product_schema` filters.

= Does EasyRankly work on WordPress Multisite? =

Yes. EasyRankly supports WordPress Multisite with network-level global settings, plus an optional multilingual module that links posts, pages, and terms across network sites and outputs hreflang alternates in the document head. Each enabled site keeps its own XML sitemap; EasyRankly does not currently add `xhtml:link` alternates inside sitemap XML.

After activation, an upgrade, or a sitemap setting change, each site refreshes its own rewrite rules on its next request; no network-wide scan is required. Network resets run in small background batches and report their status in Network Admin. On installations with more than 100 sites, network deactivation and uninstall are intentionally routed through WP-CLI so every site can be cleaned without an HTTP timeout. Run `wp plugin deactivate easyrankly --network`, then `wp plugin uninstall easyrankly` (replace `easyrankly` if the installed plugin directory uses a different name).

= Does EasyRankly collect any personal data or phone home? =

No analytics, tracking, telemetry, or EasyRankly phone-home calls are added. The optional Health 404 monitor stores request paths in your own database only, with emails, long IDs, tokens, and usernames stripped on a best-effort basis before saving.

If you enable an AI feature, EasyRankly sends page context to the AI provider connected in WordPress only when an editor explicitly clicks its generation or analysis button. EasyRankly does not provide its own AI service or receive that content.

= What data does EasyRankly send to the AI provider? =

This happens only when someone explicitly triggers an AI action, never automatically on page views.

The editor displays an inline provider disclosure beside metadata generation, content analysis, and internal-link AI controls so editors can see when page context will be shared.

**Meta generation (editor):** when Generate with AI or Improve results is clicked, EasyRankly may send the site name, the site language (locale), the post/term/special-page title, plain-text body or description truncated to your configured character limit (shortcodes removed), and, when improving, the current title, description, and your instructions. Nothing is sent until the button is clicked.

**Content analysis (post editor):** when Analyze or Analyze again is clicked, EasyRankly sends the current editor title and ordered focus keyphrases, whether the post is marked as pillar content, measured keyword and link signals, the document outline, image alt text, internal anchor text, and a plain-text beginning/middle/end sample bounded by the configured AI content limit. This includes unsaved editor changes so the report reflects what is currently on screen. Possible cannibalization signals include the titles and overlapping keyphrases of editable posts; private admin edit URLs and post IDs are not sent to the provider. When Suggest keyword is clicked, EasyRankly sends only the current title, outline, word count, coverage and the same bounded text sample. The proposed keyphrase is placed first in the unsaved focus-keyword field and is not stored until the editor saves the post.

**Health redirect suggestions (optional):** when Suggest with AI is used for a 404, EasyRankly sends only the broken URL slug words and a numbered list of existing page titles and paths from your site. Full post bodies are never included. Anonymized 404 paths (containing privacy placeholders) are skipped.

**Link Building suggestions (optional):** when Get suggestions or Refresh is clicked in the post editor, EasyRankly sends the site name and language; the current page title, path, a plain-text excerpt of up to 1,200 characters, existing outbound links and inbound-link count; and up to 30 rule-selected candidate pages with their titles, paths, and plain-text excerpts of up to 220 characters each. Full post bodies are never included.

All AI requests go through the provider configured on Settings → Connectors in WordPress. Review that provider's terms and data processing policy for retention and training use.

For content analysis, EasyRankly stores only the latest bounded, structured report in private post metadata. It does not duplicate the raw post content, prompt, or raw model response. Turning the Content analysis feature off does not delete reports, focus keywords or pillar settings; they return when the feature is enabled again. A report remains available without another AI request until an editor deletes it in the analysis popup, deletes all stored analyses under Settings → EasyRankly, resets the plugin, or uninstalls it.

= What is the body character limit for AI features? =

In Advanced mode, Settings → EasyRankly → AI lets you set how many plain-text characters of body or description are included in a prompt (4,000–64,000 in ×4 steps; default 4,000). Content analysis distributes that budget across the beginning, middle, and end of longer posts. Lower values use fewer tokens; higher values give the model more context.

= What do I need for AI features to work? =

AI features rely on WordPress' native AI and Connectors APIs, which are available in WordPress 7.0 and later. On earlier WordPress versions, or when no AI provider is connected, generation stays inactive and EasyRankly continues to use its built-in, non-AI logic. Previously saved content-analysis reports remain readable and deletable while the Content analysis feature is enabled. Even where the APIs are available, AI is off until you enable AI features—and Content analysis when you want that editor module—under Settings > EasyRankly > Features and connect a provider on the WordPress Connectors screen.

= Are the WordPress cleanup options enabled automatically? =

No. Every cleanup is off by default under Settings > EasyRankly > Bloat. Simplified mode offers a one-click Lighten WordPress preset limited to conservative metadata and self-pingback cleanups; Advanced mode exposes each switch individually, including controls for embeds, trackbacks and pingbacks, Heartbeat, revisions, frontend style assets, and speculative loading.

The more invasive options include contextual safeguards: global styles are retained for block themes, block-library CSS is removed only from singular classic-theme pages that contain no blocks, and the revision limit preserves any stricter limit already configured. Test the public site after enabling advanced cleanups because themes and plugins may depend on the assets or APIs they remove.

= How do I display breadcrumbs? =

Call `erankly_breadcrumbs()` in your theme template. You can customise the markup with the `erankly_breadcrumb_items` and `erankly_breadcrumbs_html` filters. Legacy `easyrankly_breadcrumbs()` and `easyrankly_*` hook aliases remain available for backward compatibility.

= Can I migrate from Yoast SEO, Rank Math, All in One SEO or SEOPress? =

Yes. Open Settings > EasyRankly > Import/Export. Dedicated adapters read Free and paid-edition data left by Yoast SEO/Premium, Rank Math/PRO, AIOSEO/Pro and SEOPress/PRO, including per-content SEO, separate social fields, robots directives, schema configuration, keyphrases and advanced redirects where the source supports them. Preview and import run in resumable background batches with live counters, restart-safe checkpoints, manual resume/cancel controls and a downloadable JSON report. Preview never writes; import rechecks every target before writing and never overwrites existing EasyRankly values.

Before a run, EasyRankly identifies the source edition, version, modules and exact database signature. Unknown future versions or malformed source tables are blocked before any write. A value-sensitive source fingerprint is verified again after discovery, so a source modified during migration is paused instead of producing a mixed snapshot. Official Yoast, Rank Math, AIOSEO and SEOPress CSV/JSON exports can be uploaded directly from the migration screen. EasyRankly detects their certified signature automatically, stages them outside the public WordPress tree with private permissions, and deletes its temporary copy after preview, import or cancellation.

The final report contains an authoritative fail-closed go-live gate. It separates `blocked`, `ready_for_cutover`, `go_live`, `rollback_required`, `rolled_back` and `rollback_failed`, displays every mandatory proof and fingerprints the exact decision with SHA-256. No live-verification action is available until preflight passes. Download the complete JSON report or its value-free CSV exception ledger.

For database migrations, EasyRankly captures a representative semantic HTML, robots.txt, sitemap and redirect baseline while the old plugin still owns frontend output. After controlled deactivation and cache purging, the live verifier repeats same-origin requests without following redirect chains. Real imports also retain a seven-day conditional rollback journal: a rollback restores only values that still equal what the migration wrote, so later manual edits are never lost.

You can also export and re-import EasyRankly settings, redirects, special-page defaults and SEO metadata for posts, terms and authors as a single JSON file. Complete JSON imports have a request-specific application limit (10 MB by default, reduced automatically when PHP memory is constrained) and a structural decode budget for nesting and value count; unsafe files are rejected before they can expand into PHP arrays. On Multisite the Import/Export tab lives in Network Admin, and the file covers the network-wide global settings plus the content of the primary site it runs on; it is not a whole-network content export. Translation links between network sites are not included because they reference site-specific IDs.

== Changelog ==

= 2.1.0 =
Bridge release for multilingual extensions. Adds the public provider API v1, deterministic fail-closed provider selection, a replaceable bundled fallback, neutral context-aware URL and alternate filters, independent hreflang ownership, and a stable per-object SEO-state API.

Adds journaled storage ownership with lease/CAS protection, a shared settings mutex, legacy-toggle interlocks, and ownership-aware reset/uninstall behavior across Multisite and multi-network installations. Existing bundled output, REST routes, shortcodes, assets, and legacy storage remain in place when no external provider is installed.

Freezes the generic settings-tab descriptor/render action for future extensions and corrects the earlier XML sitemap alternate claim. EasyRankly Multilingual is not included or announced as an available add-on in this release.

= 2.0.0 =
Major upgrade from the public 1.0.0 release. The original metadata, schema, sitemap, redirect, Health, Multisite, and multilingual foundations remain; the changes below describe what is new or materially expanded.

* **Administration and setup:** Rebuilt the setup wizard, settings screens, classic editor meta box, block editor document panels, and shared responsive design system. The wizard now configures Simplified or Advanced mode, Organization or Person identity, an optional searchable WordPress reference user, the identity name, and the X account.
* **Settings experience:** Added URL-addressable, server-rendered settings tabs that retain the active panel after saving and avoid rendering unrelated panels. Added expandable data sections, resolved-variable previews that reveal the raw template on focus, consistent cards and fields, and clearer feature dependencies and guidance. Fresh installs hide the optional EasyRankly HTML credit by default; an existing 1.0.0 choice is preserved.
* **Accessibility:** Added explicit label/control relationships, fieldsets and legends for grouped choices, ARIA relationships for composite widgets, keyboard-safe tabs and dialogs, focus-managed modals, and accessible user, translation, and redirect-filter autocompletes.
* **Editors and special pages:** Added native block-editor controls while retaining the classic editor and taxonomy forms. Block themes on WordPress 6.6+ can edit homepage, blog, author, date, search, and 404 metadata in contextual Site Editor panels through the native Save flow; classic themes and WordPress 6.2-6.5 use a dedicated fallback screen.
* **SEO checklist:** Replaced the 1.0.0 floating editor/frontend checklist with an integrated editor checklist. It now evaluates the effective, variable-resolved SEO title and description, available preview image, indexability, and a 300-character content minimum; Advanced mode also checks a custom social image and canonical URL.
* **Metadata and robots controls:** Added primary taxonomy terms, ordered focus keyphrases, a cornerstone/pillar flag, and explicit inherit/allow/deny directives for indexing, link following, cached copies, text snippets, and image indexing. Added per-object max-snippet, max-video-preview, max-image-preview, and indexifembedded controls while retaining the 1.0.0 boolean metadata for backward compatibility.
* **Social metadata:** Split the former shared social-image field into dedicated Open Graph and X image fields for posts and terms, with equivalent author metadata supported in output and import/export; special pages retain one shared image while gaining separate Open Graph and X titles/descriptions. Added `og:image:alt` and `twitter:image:alt`, a shared alt value with an optional X override, and automatic Media Library alt-text fallback for local attachment images; legacy shared images remain supported as fallbacks.
* **AI metadata generation:** Added opt-in Generate and Improve actions for SEO and social titles/descriptions on posts, terms, and special pages. The feature uses the native WordPress 7.0 AI Client and Connectors APIs, stays inactive on earlier WordPress versions or without an authenticated provider, supports an editable prompt and 4,000/16,000/64,000-character context presets, and never runs during frontend rendering.
* **AI safeguards and disclosure:** Added inline disclosure beside every AI editor action, bounded prompts and outputs, provider-result validation, capability and nonce checks, per-user atomic rate limits, and a pluggable provider seam. EasyRankly operates no AI service and sends context only after an editor explicitly requests an action.
* **Content analysis:** Added an optional persistent analysis workflow to the block and classic editors. Editors can keep up to ten ordered focus keyphrases, make one primary, request a suggested keyphrase, mark pillar content, and receive a bounded score/verdict report with measured keyword placement, outline, image-alt, internal-anchor, internal-link, possible cannibalization, search-intent, copy, structure, and pillar-specific signals.
* **Stored analysis reports:** The latest structured report is stored in private post metadata without duplicating the raw content, prompt, or model response. Reports can be reopened without another request, are marked stale when saved inputs change, and can be rerun or deleted per post; a separate Reset action removes every stored report while preserving content, focus keyphrases, and pillar flags.
* **Internal linking:** Added an optional AI-dependent site-wide link graph with inbound/outbound counts and orphan-page detection. The editor ranks a bounded candidate pool locally, excludes links already present, and can request up to five validated inbound and five outbound suggestions from the connected provider; suggestions are cached and the graph can be rebuilt on demand.
* **Per-content schema control:** Added schema modes for automatic output, automatic output merged with repeatable custom JSON-LD, replacement of the automatic page graph with per-content JSON-LD, or complete EasyRankly schema disablement. Matching global schema blocks still apply in replacement mode; individual automatic schema types can be suppressed, and configured JSON-LD continues to support dynamic variables and global targeting rules.
* **Automatic structured data:** Added FAQ extraction from Yoast and Rank Math FAQ blocks, opt-in FAQ output for the core Accordion block, HowTo extraction from Yoast and Rank Math blocks, Event output for The Events Calendar and configurable event post types, and VideoObject nodes for detected YouTube, Vimeo, and self-hosted videos. Article schema now uses the selected primary category and can include saved focus keyphrases.
* **WooCommerce schema:** Expanded the existing Product compatibility with GTIN, AggregateOffer price ranges and offer counts for variable products, and up to ten approved Review nodes. EasyRankly still defers to WooCommerce's native Product structured data by default to prevent duplicate markup.
* **Redirect rules:** Expanded the 1.0.0 exact, wildcard, and regex manager with contains, starts-with, and ends-with matching; 307, 308, 410, and 451 responses; query-string ignore/preserve/exact behavior; trailing-slash and case controls; priorities; start/end scheduling; logged-in, logged-out, and role targeting; portable imported request conditions; structured search filters; and sortable, paginated administration.
* **Redirect runtime:** Migrated the existing table in place, preserving 1.0.0 rules while backfilling full rule identities so several semantically different rules may share one source path. Added compiled runtime indexes, generation-scoped cache invalidation, a REST interface, status-only 410/451 handling, self-loop prevention, method-preserving redirects, and per-pattern PCRE match/depth limits without changing request-wide PHP settings.
* **404 monitoring:** Kept the private aggregate 404 monitor and added stricter normalized/anonymized storage migration, up to five same-site referrer paths, ignored/resolved states with restore actions, 30-day pruning, old-slug/exact/fuzzy local redirect suggestions, optional validated AI fallback suggestions, and one-click prefilled redirect creation. IP addresses, user agents, external referrers, and raw query strings are not retained.
* **Broken-link crawler:** Added a manual, cancellable crawler that renders up to 300 indexable pages, follows internal links up to three levels, and checks up to 3,000 distinct internal and external URLs in bounded REST batches. Results distinguish HTTP failures from unreachable/rate-limited URLs and retain anchor text and source pages; internal failures can open a prefilled 301 rule, while external failures link back to the content to edit.
* **Health and discovery:** Retained the 1.0.0 thin-content heuristic and moved large scans to bounded reads. Health implementations, crawler state, suggestion data, and editor link-graph data are split into contextual storage and loaders so normal requests do not pay for inactive tools.
* **WordPress cleanup:** Expanded the existing opt-in cleanup controls with a conservative Simplified-mode preset and Advanced switches for the wp-embed script, adjacent-post links, trackbacks/pingbacks, a 60-second admin Heartbeat limit, global/classic styles, duotone assets, block-library CSS when safely unused, a five-revision cap, and speculative loading. New cleanup switches remain disabled by default, saved 1.0.0 choices are preserved, and the new style/revision controls include contextual safeguards.
* **Sitemaps and rewrites:** Split standard, image, news, and video sitemap implementations into contextual modules; aligned legacy and explicit noindex exclusions across content and taxonomy queries; expanded image/social-source discovery; and added generation-based cache invalidation. Versioned per-site rewrite signatures refresh lazily after activation, upgrade, or network sitemap changes instead of scanning an entire network.
* **EasyRankly backup and restore:** Expanded JSON export/import to cover settings, redirects, special-page defaults, and registered post, term, and author metadata. Exports stream in bounded pages; imports use managed private uploads, resumable batches where required, size and structural decode budgets, value sanitization, progress state, cancellation, and cleanup instead of loading an unbounded payload into memory.
* **Third-party migrations:** Replaced the basic 1.0.0 Yoast/Rank Math meta importers with dedicated, edition-aware adapters for Yoast SEO/Premium, Rank Math/PRO, AIOSEO/Pro, and SEOPress/PRO. Database and certified official CSV/JSON sources can migrate titles, descriptions, canonicals, separate social fields, advanced robots directives, focus keyphrases, cornerstone/pillar state, primary terms, schema data, and advanced redirects where the source exposes them.
* **Migration safety:** Added non-writing preview, exact source discovery and version/storage-signature checks, immutable source fingerprints, restart-safe background checkpoints, live counters, resume/cancel controls, conflict preservation, and reports that account for every discovered value. Existing EasyRankly values and unrelated redirect rules are never silently overwritten.
* **Migration verification and recovery:** Added redirect loop, chain, collision, and dangerous-regex audits; unresolved-variable checks; a value-free CSV exception ledger; downloadable JSON evidence; and a fail-closed cutover gate. Database migrations can capture a pre-cutover HTML/robots.txt/sitemap/redirect baseline, verify same-origin live output after controlled deactivation, and conditionally roll back for seven days without reverting later manual edits.
* **Reset, deactivation, and uninstall:** Added explicit per-site and whole-network reset flows with confirmation dialogs and verified cleanup of options, metadata, redirects, caches, scheduled events, private uploads, migration staging/evidence, and multilingual relations. Network reset is resumable in small background batches; networks over 100 sites route network deactivation and uninstall through WP-CLI to avoid partial HTTP-timeout cleanup.
* **Multisite and multilingual hardening:** Preserved network-wide post-type, taxonomy, and feature settings while storing special-page metadata per site; a site without a local 2.0.0 special-page map uses the new defaults rather than copying the former network-shared map, so those values should be reviewed after upgrading. Added per-network/per-site cache generations, safer creation and cleanup of translation relations, contextual loading for language-switcher assets, and clearer Network Admin/local-site boundaries. EasyRankly exports intentionally cover shared settings plus the primary site's content rather than pretending to be a whole-network content backup.
* **Performance and loading:** Split monolithic helpers, admin scripts/styles, Health, sitemap, migration, WooCommerce, and editor code into contextual modules. Optional AI, Content analysis, Internal links, Redirects, Health, Sitemap, and Multilingual implementations load only when enabled and relevant; inactive settings panels are not rendered, and the default frontend adds no plugin CSS or JavaScript.
* **Security, compatibility, and distribution:** Added SSRF-resistant crawler validation, safer private upload handling through WordPress APIs, bounded database and JSON work, stronger output escaping and capability checks, WordPress.org Plugin Check hardening, PHP 8.5 compatibility coverage, and guarded calls to WordPress 7.0-only APIs. Lowered the supported WordPress baseline from 6.5 to 6.2 while retaining PHP 8.0+, expanded canonical and legacy developer hooks, refreshed translations and packaging rules, and updated EasyRankly author branding.

= 1.0.0 =
Release date: June 14, 2026

* First public release.

== Upgrade Notice ==

= 2.1.0 =
Adds a fail-closed multilingual provider bridge and ownership-safe lifecycle while retaining the bundled Multisite feature. No storage conversion occurs. Complete verified rollback before any downgrade if storage has been claimed by a provider.

= 2.0.0 =
Major 1.0 upgrade: back up first. Existing content SEO is retained; redirect data is retained and upgraded automatically. The checklist moves into the editor. Review settings and Multisite special-page defaults. AI stays opt-in and requires WordPress 7.0 plus a connected provider.

= 1.0.0 =
First public release of EasyRankly.
