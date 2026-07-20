=== EasyRankly ===
Contributors: easyrankly
Tags: seo, schema, sitemap, redirects, ai
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight, modular, developer-first SEO essentials for WordPress.

== Description ==

EasyRankly brings together the SEO essentials for WordPress in a modular toolkit with sensible defaults and optional features you can enable when needed.

Here's what it does:

* **Core metadata across your site.** SEO titles, meta descriptions, canonical URLs, and robots directives, set up sensibly out of the box.
* **Great social previews.** Open Graph and Twitter (X) cards so your links look right when shared.
* **Structured data that search engines understand.** A modular JSON-LD schema graph covering your Organization or Person, optional local business details, articles, breadcrumbs, FAQs, and WooCommerce product compatibility, plus reusable custom schema blocks you can target to specific pages.
* **Sitemaps, when you want them.** An optional XML sitemap index with sitemaps for your content (including images), taxonomies, and authors.
* **Control over what gets indexed.** Simple noindex, nofollow, noarchive, and sitemap-exclusion controls, per page or across your site.
* **Smart redirects built in.** An optional redirect manager with exact, wildcard, and regex matching, with per-pattern safeguards that keep even complex regex rules fast and safe.
* **Breadcrumbs and robots.txt.** A breadcrumb function for your theme (with optional shorter names per page) and an editable virtual robots.txt.
* **Optional AI meta generation.** Generate or improve SEO titles and descriptions right from the editor when WordPress has a connected AI provider. It's off by default and uses WordPress' native AI/Connectors APIs (available in WordPress 7.0 and later); on earlier versions it stays inactive and EasyRankly uses its built-in, non-AI logic instead.
* **Optional persistent AI content analysis.** Enable it from Features, target several focus keyphrases as WordPress-style tags, choose the primary keyword, and get an editorial focus report with measured signals, prioritized improvements, ready-to-use sentences, structure ideas, possible keyword cannibalization, and stricter pillar-content guidance.
* **Optional internal linking assistant.** Build a site-wide link graph, find orphan pages, get rule-based suggestions, and optionally refine with AI when AI features are enabled.
* **Private health monitoring.** Optional 404 tracking that stays on your server. EasyRankly stores no IP address or user agent; path identifiers are redacted on a best-effort basis and aggregate records are cleaned up automatically.
* **Dynamic variables** for filling in titles, social tags, and schema fields automatically.

All of it lives in a redesigned, streamlined admin interface with a short setup wizard to get you configured in minutes.

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

Yes. EasyRankly supports WordPress Multisite with network-level global settings, plus an optional multilingual module that links posts, pages, and terms across network sites and outputs hreflang alternates in the head and XML sitemaps.

After activation, an upgrade, or a sitemap setting change, each site refreshes its own rewrite rules on its next request; no network-wide scan is required. Network resets run in small background batches and report their status in Network Admin. On installations with more than 100 sites, network deactivation and uninstall are intentionally routed through WP-CLI so every site can be cleaned without an HTTP timeout. Run `wp plugin deactivate easyrankly --network`, then `wp plugin uninstall easyrankly` (replace `easyrankly` if the installed plugin directory uses a different name).

= Does EasyRankly collect any personal data or phone home? =

No analytics, tracking, telemetry, or EasyRankly phone-home calls are added. The optional Health 404 monitor stores request paths in your own database only, with emails, long IDs, tokens, and usernames stripped on a best-effort basis before saving.

If you enable an AI feature, EasyRankly sends page context to the AI provider connected in WordPress only when an editor explicitly clicks its generation or analysis button. EasyRankly does not provide its own AI service or receive that content.

= What data does EasyRankly send to the AI provider? =

This happens only when someone explicitly triggers an AI action, never automatically on page views.

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

= How do I display breadcrumbs? =

Call `erankly_breadcrumbs()` in your theme template. You can customise the markup with the `erankly_breadcrumb_items` and `erankly_breadcrumbs_html` filters. Legacy `easyrankly_breadcrumbs()` and `easyrankly_*` hook aliases remain available for backward compatibility.

= Can I migrate from Yoast SEO, Rank Math, All in One SEO or SEOPress? =

Yes. Open Settings > EasyRankly > Import/Export. Dedicated adapters read Free and paid-edition data left by Yoast SEO/Premium, Rank Math/PRO, AIOSEO/Pro and SEOPress/PRO, including per-content SEO, separate social fields, robots directives, schema configuration, keyphrases and advanced redirects where the source supports them. Preview and import run in resumable background batches with live counters, restart-safe checkpoints, manual resume/cancel controls and a downloadable JSON report. Preview never writes; import rechecks every target before writing and never overwrites existing EasyRankly values.

Before a run, EasyRankly identifies the source edition, version, modules and exact database signature. Unknown future versions or malformed source tables are blocked before any write. A value-sensitive source fingerprint is verified again after discovery, so a source modified during migration is paused instead of producing a mixed snapshot. Official Yoast, Rank Math, AIOSEO and SEOPress CSV/JSON exports can be uploaded directly from the migration screen. EasyRankly detects their certified signature automatically, stages them outside the public WordPress tree with private permissions, and deletes its temporary copy after preview, import or cancellation.

The final report contains an authoritative fail-closed go-live gate. It separates `blocked`, `ready_for_cutover`, `go_live`, `rollback_required`, `rolled_back` and `rollback_failed`, displays every mandatory proof and fingerprints the exact decision with SHA-256. No live-verification action is available until preflight passes. Download the complete JSON report or its value-free CSV exception ledger.

For database migrations, EasyRankly captures a representative semantic HTML, robots.txt, sitemap and redirect baseline while the old plugin still owns frontend output. After controlled deactivation and cache purging, the live verifier repeats same-origin requests without following redirect chains. Real imports also retain a seven-day conditional rollback journal: a rollback restores only values that still equal what the migration wrote, so later manual edits are never lost.

You can also export and re-import EasyRankly settings, redirects, special-page defaults and SEO metadata for posts, terms and authors as a single JSON file. Complete JSON imports have a request-specific application limit (10 MB by default, reduced automatically when PHP memory is constrained) and a structural decode budget for nesting and value count; unsafe files are rejected before they can expand into PHP arrays. On Multisite the Import/Export tab lives in Network Admin, and the file covers the network-wide global settings plus the content of the primary site it runs on; it is not a whole-network content export. Translation links between network sites are not included because they reference site-specific IDs.

== Changelog ==

= 2.0.0 =
Major release bringing together EasyRankly's complete modular SEO toolkit, a rebuilt administration experience, optional AI assistance, advanced site-health tools, and a production-grade migration system.

* Expanded the SEO foundation across titles and descriptions, canonicals and indexing controls, Open Graph and X cards, dynamic variables, breadcrumbs, reusable JSON-LD schema, WooCommerce data, robots.txt, and XML sitemaps for standard content, images, news, video, and multilingual alternates.
* Rebuilt setup, settings, editor, and Site Editor workflows with Simplified and Advanced modes, contextual panels, clearer guidance, SEO checklists, and a more consistent, Multisite-aware interface.
* Added opt-in AI tools through WordPress' native AI and Connectors APIs for generating or improving metadata and assisting with redirects and internal links, with non-AI fallbacks, bounded context, and rate limits.
* Added persistent AI content analysis with multi-keyword tags, primary-keyword targeting, pillar guidance, measured focus signals, copy-ready recommendations, stale-report detection, and explicit per-post or site-wide cleanup.
* Expanded site health and content discovery with privacy-conscious 404 monitoring, broken-link crawling, thin-content checks, orphan-page detection, and rule-based internal-link suggestions.
* Upgraded redirects with exact, wildcard, and regex matching, additional status and scheduling controls, loop and chain diagnostics, safer pattern execution, and bulk-friendly cache handling.
* Added comprehensive, conflict-safe migrations from Yoast SEO, Rank Math, AIOSEO, and SEOPress, including Free and paid editions and official exports, resumable background processing, private uploads, source validation, detailed reports, live verification, cutover gates, and conditional rollback.
* Strengthened Multisite and multilingual support with network settings, hreflang and sitemap integration, translation workflows, per-site isolation, and resumable maintenance operations.
* Improved modular loading, performance, privacy, security, and compatibility throughout, including WordPress.org Plugin Check hardening, guarded support for newer WordPress APIs, broader PHP and WordPress test coverage, developer API compatibility, interface polish, and updated translation support.

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 2.0.0 =
Major update with rebuilt administration and editor workflows, optional AI tools, expanded SEO and site-health features, and comprehensive third-party migration workflows. Review your settings after updating.

= 1.0.0 =
First public release of EasyRankly.
