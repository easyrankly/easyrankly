=== EasyRankly ===
Contributors: easyrankly
Tags: seo, schema, sitemap, redirects
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.1.0
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
* **Smart redirects built in.** An optional redirect manager with a streamlined rule editor for exact-path redirects, including permanent, temporary, and gone (410/451) responses.
* **Breadcrumbs and robots.txt.** A breadcrumb function for your theme (with optional shorter names per page) and an editable virtual robots.txt.

All of it lives in a redesigned, responsive admin interface with consistent form patterns, accessible label and control relationships, keyboard-friendly tabs and dialogs, and a short setup wizard to get you configured in minutes.

== Installation ==

1. Upload the `easyrankly` folder to `/wp-content/plugins/`.
2. Activate EasyRankly from the Plugins screen.
3. Complete the short setup wizard, or configure the plugin later under Settings > EasyRankly.

== Frequently Asked Questions ==

= Can I run EasyRankly alongside another SEO plugin such as Yoast SEO or Rank Math? =

No, running two full SEO plugins at the same time is not recommended because their overlapping features can produce conflicts or inconsistent output. If EasyRankly detects a recognised SEO plugin, it displays an admin notice and disables its own head metadata, structured data, sitemaps, and robots.txt customisations to reduce the risk of duplicates; redirects and breadcrumbs remain available. If you are switching from Yoast SEO, Rank Math, All in One SEO, or SEOPress, use EasyRankly's migration tools and complete the guided checks before deactivating the previous plugin.

= Does it support WooCommerce? =

Yes. EasyRankly supports Product JSON-LD for WooCommerce products, including the product name, description, image, SKU, brand, GTIN, price and currency, stock status, sale end date, aggregate rating, and approved reviews when those values are available. Variable products can use an AggregateOffer with their price range and offer count. To avoid duplicate structured data, EasyRankly leaves Product schema to WooCommerce when WooCommerce's native structured data is active; developers can change this behaviour with the `erankly_woocommerce_structured_data_enabled` and `erankly_render_woocommerce_product_schema` filters.

= Does EasyRankly work on WordPress Multisite? =

Yes. EasyRankly supports WordPress Multisite with network-level global settings. Multilingual features are supplied only by the separate EasyRankly Multilingual plugin through the provider API; the core no longer contains multilingual storage, screens, routes, shortcodes, or assets.

After activation, an upgrade, or a sitemap setting change, each site refreshes its own rewrite rules on its next request; no network-wide scan is required. Network resets run in small background batches and report their status in Network Admin. On installations with more than 100 sites, network deactivation and uninstall are intentionally routed through WP-CLI so every site can be cleaned without an HTTP timeout. Run `wp plugin deactivate easyrankly --network`, then `wp plugin uninstall easyrankly` (replace `easyrankly` if the installed plugin directory uses a different name).

= Does EasyRankly collect any personal data or phone home? =

No analytics, tracking, telemetry, or EasyRankly phone-home calls are added.

= How do I display breadcrumbs? =

Call `erankly_breadcrumbs()` in your theme template. You can customise the markup with the `erankly_breadcrumb_items` and `erankly_breadcrumbs_html` filters. Legacy `easyrankly_breadcrumbs()` and `easyrankly_*` hook aliases remain available for backward compatibility.

= Can I migrate from Yoast SEO, Rank Math, All in One SEO or SEOPress? =

Yes. Open Settings > EasyRankly > Import/Export. Dedicated, edition-aware adapters read Free and paid-edition data left by Yoast SEO/Premium, Rank Math/PRO, AIOSEO/Pro and SEOPress/PRO — including per-content SEO, separate social fields, robots directives, schema configuration, keyphrases and advanced redirects where the source supports them. Official CSV/JSON exports from those plugins can be uploaded directly from the migration screen; they are staged privately and deleted after preview, import, or cancellation.

Migrations are designed to be safe to try. Preview never writes; import runs in resumable background batches with live counters, rechecks every target before writing, and never overwrites existing EasyRankly values. Unknown source versions or malformed tables are blocked before any write, and a source modified during migration is paused instead of producing a mixed snapshot.

Database migrations can capture a pre-cutover baseline of HTML, robots.txt, sitemaps, and redirects, verify same-origin live output after the controlled switch, and roll back conditionally for seven days without reverting later manual edits. The final report includes a fail-closed go-live gate, with a downloadable JSON report and a value-free CSV exception ledger.

You can also export and re-import EasyRankly's own settings, redirects, special-page defaults, and post, term, and author metadata as a single JSON file, with size and structural safeguards. On Multisite the Import/Export tab lives in Network Admin and covers network-wide global settings plus the primary site's content; EasyRankly Multilingual owns its separate export/import format.

== External Services ==

EasyRankly does not phone home, and it adds no analytics, tracking, or telemetry.

== Changelog ==

= 2.1.0 =
AI generation, content analysis, internal linking, and Health monitoring left core and now require a separate add-on. Core keeps metadata, schema, sitemaps, redirects, breadcrumbs, and import/export. Existing settings for those modules stay stored and stay inactive until the add-on is installed.

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
* **Under the hood:** contextual module loading so inactive features cost nothing, bounded background processing, Multisite hardening with WP-CLI flows for large networks, and a lowered WordPress baseline (6.2) with PHP 8.0+.

= 1.0.0 =
Release date: June 14, 2026

* First public release.

== Upgrade Notice ==

= 2.1.0 =
AI, content analysis, internal linking, and Health left core. Existing settings for those modules are kept and stay inactive until a compatible add-on is installed.

= 2.0.0 =
Back up first. SEO content and redirects are retained; redirects auto-upgrade. Multilingual left core — update EasyRankly Multilingual to 1.1.1 first if you use it. Checklist is now in the editor. Review settings and Multisite special-page defaults. AI stays opt-in (WP 7.0 + provider).

= 1.0.0 =
First public release of EasyRankly.
