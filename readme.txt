=== EasyRankly ===
Contributors: easyrankly
Tags: seo, schema, sitemap, redirects
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

Yes. Product JSON-LD supports core product fields, GTIN, offers, ratings and approved reviews. Variable products can use AggregateOffer. EasyRankly leaves Product schema to WooCommerce when its native structured data is active; developers can override this with the documented filters.

= Does EasyRankly work on WordPress Multisite? =

Yes. EasyRankly supports WordPress Multisite with network-level global settings. Multilingual features are supplied only by the separate EasyRankly Multilingual plugin through the provider API; the core no longer contains multilingual storage, screens, routes, shortcodes, or assets.

Each site refreshes its own rewrite rules when needed. Network resets run in background batches. Networks above 100 sites use WP-CLI for deactivation and uninstall to avoid HTTP timeouts: run `wp plugin deactivate easyrankly --network`, then `wp plugin uninstall easyrankly`.

= Does EasyRankly collect any personal data or phone home? =

No analytics, tracking, telemetry, or EasyRankly phone-home calls are added.

= How do I display breadcrumbs? =

Call `erankly_breadcrumbs()` in your theme template. You can customise the markup with the `erankly_breadcrumb_items` and `erankly_breadcrumbs_html` filters. Legacy `easyrankly_breadcrumbs()` and `easyrankly_*` hook aliases remain available for backward compatibility.

= Is there an extension API? =

Yes. Extension API v1 includes `erankly_register_multilingual_provider()`, localized-value writes, neutral SEO-state reads, and filtered hreflang output. These contracts remain in core for add-ons even when they are not called by the core UI.

= Can I migrate from Yoast SEO, Rank Math, All in One SEO or SEOPress? =

Yes. Open Settings > EasyRankly > Import/Export. Edition-aware adapters read supported data from Yoast SEO, Rank Math, AIOSEO and SEOPress installations or official exports. Uploaded CSV/JSON files are staged privately and deleted after preview, import or cancellation.

Preview never writes. Imports run in resumable background batches, recheck targets, preserve existing EasyRankly values and stop if the source changes. Database migrations can capture and verify a same-origin output baseline and provide a seven-day conditional rollback. Native export/import covers settings, redirects and registered metadata with size and structural safeguards.

== External Services ==

EasyRankly makes no server-side external requests and adds no analytics or telemetry. For posts that already embed YouTube or Vimeo, video markup can expose provider player URLs and a YouTube thumbnail URL derived from the public video ID. A visitor or search engine loading those URLs sends its normal request data to the provider. See YouTube terms (https://www.youtube.com/static?template=terms) and privacy policy (https://policies.google.com/privacy), and Vimeo terms (https://vimeo.com/terms) and privacy policy (https://vimeo.com/privacy). EasyRankly does not use vumbnail.com.

== Changelog ==

= 2.0.0 =
Major upgrade from the public 1.0.0 release. Core now focuses on metadata, schema, sitemaps, redirects, breadcrumbs, and import/export. AI generation, content analysis, internal linking, and Health monitoring require a separate add-on. Multilingual runtime moved to the separate EasyRankly Multilingual plugin through the provider API — for uninterrupted multilingual operation, update EasyRankly Multilingual to 1.1.1 before upgrading; existing multilingual data is not inspected, converted, reset, or deleted.

* **Rebuilt admin experience:** new setup wizard, URL-addressable settings tabs, editor panels, and a shared responsive design system, with broad accessibility improvements (explicit labels, fieldsets, ARIA relationships, keyboard-safe tabs and dialogs).
* **Block editor and Site Editor integration:** native block-editor controls alongside the classic editor and taxonomy forms, plus contextual Site Editor panels for homepage, blog, author, date, search, and 404 metadata on block themes (WordPress 6.6+).
* **Richer metadata controls:** primary taxonomy terms, ordered focus keyphrases, a cornerstone/pillar flag, granular robots directives (max-snippet, max-image-preview, indexifembedded, and more), and an integrated editor SEO checklist replacing the 1.0.0 floating checklist.
* **Dedicated social images:** separate Open Graph and X image fields for posts and terms, `og:image:alt`/`twitter:image:alt` with a shared value and Media Library alt-text fallback; legacy shared images still work.
* **Expanded structured data:** per-content schema modes (automatic, merged, replacement, disabled), FAQ/HowTo extraction from Yoast and Rank Math blocks, Event and VideoObject output, and WooCommerce GTIN, AggregateOffer, and Review support.
* **Much stronger redirects:** contains, starts-with, and ends-with matching, 307/308/410/451 responses, query-string, trailing-slash, and case controls, scheduling and audience targeting, plus a compiled runtime with per-pattern safety limits.
* **Safer migrations:** edition-aware adapters for Yoast SEO, Rank Math, AIOSEO, and SEOPress with non-writing preview, resumable background batches, live-output verification, and a seven-day conditional rollback journal.
* **Under the hood:** contextual module loading so inactive features cost nothing, bounded background processing, Multisite hardening with WP-CLI flows for large networks, and a lowered WordPress baseline (6.2) with PHP 8.0+.

= 1.0.0 =
Release date: June 14, 2026

* First public release.

== Upgrade Notice ==

= 2.0.0 =
Back up first. SEO content and redirects are retained. Multilingual moved out of core; update EasyRankly Multilingual to 1.1.1 first if used. AI, analysis, linking and Health require an add-on. Review settings after upgrading.

= 1.0.0 =
First public release of EasyRankly.
