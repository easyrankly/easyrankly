=== EasyRankly ===
Contributors: easyrankly
Tags: seo, schema, sitemap, redirects, ai
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight, modular, developer-first SEO essentials for WordPress.

== Description ==

EasyRankly brings together the SEO essentials for WordPress in a modular toolkit with sensible defaults and optional features you can enable when needed.

Here's what it does:

* **Core metadata across your site.** SEO titles, meta descriptions, canonical URLs, and robots directives, set up sensibly out of the box, with dynamic variables to fill in titles, social tags, and schema fields automatically.
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

Yes. EasyRankly supports WordPress Multisite with network-level global settings. Multilingual features are supplied only by the separate EasyRankly Multilingual plugin through the provider API; the core no longer contains multilingual storage, screens, routes, shortcodes, or assets.

After activation, an upgrade, or a sitemap setting change, each site refreshes its own rewrite rules on its next request; no network-wide scan is required. Network resets run in small background batches and report their status in Network Admin. On installations with more than 100 sites, network deactivation and uninstall are intentionally routed through WP-CLI so every site can be cleaned without an HTTP timeout. Run `wp plugin deactivate easyrankly --network`, then `wp plugin uninstall easyrankly` (replace `easyrankly` if the installed plugin directory uses a different name).

= Does EasyRankly collect any personal data or phone home? =

No analytics, tracking, telemetry, or EasyRankly phone-home calls are added. The optional Health 404 monitor stores request paths in your own database only, with emails, long IDs, tokens, and usernames stripped on a best-effort basis before saving.

If you enable an AI feature, EasyRankly sends page context to the AI provider connected in WordPress only when an editor explicitly clicks its generation or analysis button. EasyRankly does not provide its own AI service or receive that content.

= What data does EasyRankly send to the AI provider? =

Only when someone explicitly clicks an AI action — never automatically on page views. An inline disclosure appears beside every AI control so editors can see when page context will be shared.

* **Meta generation:** the site name and language, the post/term/special-page title, plain-text body or description up to your configured character limit (shortcodes removed), and, when improving, the current title, description, and your instructions.
* **Content analysis:** the current editor title, ordered focus keyphrases, pillar flag, measured keyword and link signals, document outline, image alt text, internal anchor text, and a bounded beginning/middle/end plain-text sample, including unsaved changes. Cannibalization checks include only titles and overlapping keyphrases of editable posts, never admin URLs or post IDs. Suggest keyword sends only the title, outline, word count, coverage, and the same bounded sample.
* **Health redirect suggestions:** the broken URL slug words and a numbered list of existing page titles and paths. Full post bodies are never included, and anonymized paths are skipped.
* **Link Building suggestions:** the site name and language; the current page title, path, and a plain-text excerpt of up to 1,200 characters; existing outbound links and inbound-link count; and up to 30 rule-selected candidate pages with titles, paths, and excerpts of up to 220 characters.

All AI requests go through the provider configured on Settings → Connectors in WordPress; review that provider's terms and data processing policy for retention and training use.

EasyRankly stores only the latest bounded, structured report in private post metadata — never the raw content, prompt, or raw model response. Reports can be reopened and deleted without another AI request, and remain available until an editor deletes them, all analyses are reset under Settings → EasyRankly, or the plugin is reset or uninstalled.

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

Yes. Open Settings > EasyRankly > Import/Export. Dedicated, edition-aware adapters read Free and paid-edition data left by Yoast SEO/Premium, Rank Math/PRO, AIOSEO/Pro and SEOPress/PRO — including per-content SEO, separate social fields, robots directives, schema configuration, keyphrases and advanced redirects where the source supports them. Official CSV/JSON exports from those plugins can be uploaded directly from the migration screen; they are staged privately and deleted after preview, import, or cancellation.

Migrations are designed to be safe to try. Preview never writes; import runs in resumable background batches with live counters, rechecks every target before writing, and never overwrites existing EasyRankly values. Unknown source versions or malformed tables are blocked before any write, and a source modified during migration is paused instead of producing a mixed snapshot.

Database migrations can capture a pre-cutover baseline of HTML, robots.txt, sitemaps, and redirects, verify same-origin live output after the controlled switch, and roll back conditionally for seven days without reverting later manual edits. The final report includes a fail-closed go-live gate, with a downloadable JSON report and a value-free CSV exception ledger.

You can also export and re-import EasyRankly's own settings, redirects, special-page defaults, and post, term, and author metadata as a single JSON file, with size and structural safeguards. On Multisite the Import/Export tab lives in Network Admin and covers network-wide global settings plus the primary site's content; EasyRankly Multilingual owns its separate export/import format.

== External Services ==

EasyRankly does not phone home, and it adds no analytics, tracking, or telemetry.

The optional AI features (metadata generation, content analysis, and internal-link suggestions) connect to a third-party AI provider only through WordPress' native Connectors API, using the provider and credentials the site administrator configures under Settings → Connectors. EasyRankly operates no AI service of its own and never receives the content sent.

Data is transmitted only when an editor explicitly clicks an AI action button — never automatically, and never during frontend page views. Depending on the action, this can include the site name and language, content titles, bounded plain-text excerpts, focus keyphrases, and measured content signals; see "What data does EasyRankly send to the AI provider?" above for the exact per-action fields. Data retention and processing are governed by the chosen provider's terms and privacy policy.

On WordPress versions earlier than 7.0, or when no provider is connected, AI features stay inactive and EasyRankly uses its built-in, non-AI logic instead.

== Changelog ==

= 2.0.0 =
Major upgrade from the public 1.0.0 release. The original metadata, schema, sitemap, redirect, Health, and Multisite foundations remain. Multilingual runtime moved to the separate EasyRankly Multilingual plugin through the provider API — for uninterrupted multilingual operation, update EasyRankly Multilingual to 1.1.1 before upgrading; existing multilingual data is not inspected, converted, reset, or deleted.

* **Rebuilt admin experience:** new setup wizard, URL-addressable settings tabs, editor panels, and a shared responsive design system, with broad accessibility improvements (explicit labels, fieldsets, ARIA relationships, keyboard-safe tabs and dialogs).
* **Block editor and Site Editor integration:** native block-editor controls alongside the classic editor and taxonomy forms, plus contextual Site Editor panels for homepage, blog, author, date, search, and 404 metadata on block themes (WordPress 6.6+).
* **Richer metadata controls:** primary taxonomy terms, ordered focus keyphrases, a cornerstone/pillar flag, granular robots directives (max-snippet, max-image-preview, indexifembedded, and more), and an integrated editor SEO checklist replacing the 1.0.0 floating checklist.
* **Dedicated social images:** separate Open Graph and X image fields for posts and terms, `og:image:alt`/`twitter:image:alt` with a shared value and Media Library alt-text fallback; legacy shared images still work.
* **Optional AI tools:** Generate/Improve metadata actions, persistent content analysis with focus keyphrases and cannibalization signals, and an internal-linking assistant with orphan-page detection — all opt-in and explicit-click only, built on the WordPress 7.0 AI and Connectors APIs.
* **Expanded structured data:** per-content schema modes (automatic, merged, replacement, disabled), FAQ/HowTo extraction from Yoast and Rank Math blocks, Event and VideoObject output, and WooCommerce GTIN, AggregateOffer, and Review support.
* **Much stronger redirects:** contains, starts-with, and ends-with matching, 307/308/410/451 responses, query-string, trailing-slash, and case controls, scheduling and audience targeting, plus a compiled runtime with per-pattern safety limits.
* **Health tools:** an improved private 404 monitor with local and optional AI redirect suggestions, and a new manual broken-link crawler with one-click prefilled redirect creation.
* **Safer migrations:** edition-aware adapters for Yoast SEO, Rank Math, AIOSEO, and SEOPress with non-writing preview, resumable background batches, live-output verification, and a seven-day conditional rollback journal.
* **Under the hood:** contextual module loading so inactive features cost nothing, bounded background processing, expanded opt-in WordPress cleanup controls, Multisite hardening with WP-CLI flows for large networks, and a lowered WordPress baseline (6.2) with PHP 8.0+.

= 1.0.0 =
Release date: June 14, 2026

* First public release.

== Upgrade Notice ==

= 2.0.0 =
Back up first. SEO content and redirects are retained; redirects auto-upgrade. Multilingual left core — update EasyRankly Multilingual to 1.1.1 first if you use it. Checklist is now in the editor. Review settings and Multisite special-page defaults. AI stays opt-in (WP 7.0 + provider).

= 1.0.0 =
First public release of EasyRankly.
