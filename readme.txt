=== EasyRankly ===
Contributors: easyrankly
Tags: seo, schema, sitemap, redirects
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Take control of your WordPress SEO with simple, fast, and flexible tools.

== Description ==

EasyRankly gives you the essential tools to take control of your WordPress SEO. Sensible defaults help you get started quickly, while flexible features let you enable only what your site needs.

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

Yes, temporarily, such as during a migration, but two full SEO plugins should not remain responsible for the same output. If EasyRankly detects a recognised SEO plugin, it displays an admin notice and disables its own head metadata, structured data, sitemap output, and robots.txt customisations to reduce the risk of duplicates; redirects and breadcrumbs remain available. If you are switching from Yoast SEO, Rank Math, All in One SEO, or SEOPress, use EasyRankly's migration assistant and complete its guided checks before deactivating the previous plugin.

= Does it support WooCommerce? =

Yes. By default, EasyRankly leaves Product structured data to WooCommerce when WooCommerce's native schema is active, avoiding duplicate Product markup. Developers can opt into EasyRankly's Product JSON-LD with the `erankly_woocommerce_structured_data_enabled` and `erankly_render_woocommerce_product_schema` filters. When enabled, it supports core product fields, SKU, brand, GTIN, offers, aggregate ratings, and approved reviews; variable products use AggregateOffer.

= Does EasyRankly work on WordPress Multisite? =

Yes. EasyRankly is network-aware and stores global SEO settings at network level, while content metadata and special-page values remain scoped appropriately to each site. Multilingual functionality requires the separate EasyRankly Multilingual plugin, which integrates through the provider API.

Each site refreshes its own rewrite rules when needed, and network resets run in background batches. By default, networks with more than 100 sites require WP-CLI for network deactivation and uninstall to avoid HTTP timeouts: run `wp plugin deactivate easyrankly --network`, then `wp plugin uninstall easyrankly`.

= Does EasyRankly collect any personal data or phone home? =

EasyRankly does not send site or visitor data to EasyRankly and adds no external analytics or telemetry. Configuration data, including any optional business contact details you enter, and temporary migration files remain on your WordPress installation. When redirects are enabled, EasyRankly stores sampled aggregate hit counts and the last sampled hit time, but it does not store visitor IP addresses or the referrer, user-agent, language, or cookie values read temporarily when evaluating redirect conditions. Migration verification may make bounded same-origin requests to your own site; it does not phone home.

= How do I display breadcrumbs? =

Enable breadcrumbs under Settings > EasyRankly > Features, then call `erankly_breadcrumbs()` in your theme template. The function echoes its HTML by default; pass `array( 'echo' => false )` to return it without printing. You can customise the items and final markup with the `erankly_breadcrumb_items` and `erankly_breadcrumbs_html` filters. The legacy `easyrankly_breadcrumbs()` function and supported `easyrankly_*` hook aliases remain available for backward compatibility.

= Is there an extension API? =

Yes. Extension API v1 includes multilingual provider registration through `erankly_register_multilingual_provider()`, neutral SEO-state reads, localised-value reads and writes, and filtered hreflang output. Localised-value reads and writes are available only on single-site installations; the provider and SEO-state contracts support provider-defined site topologies. These contracts remain in core for add-ons even when they are not called by the core UI.

= Can I migrate from Yoast SEO, Rank Math, All in One SEO or SEOPress? =

Yes, for supported fields and certified storage signatures. Open Settings > EasyRankly > Import/Export. Edition-aware database adapters read supported data from Yoast SEO, Rank Math, AIOSEO, and SEOPress installations. Supported official exports are format-specific: Yoast redirect CSV, Rank Math metadata or redirect CSV, AIOSEO redirect CSV or JSON, and SEOPress metadata CSV.

Preview does not modify destination SEO metadata or redirects; it writes only temporary job, staging, and report data. Imports run in resumable background batches, recheck targets before applying them, preserve existing EasyRankly values, and pause if the source changes. Uploaded CSV/JSON files are staged privately and deleted when preview, import, or cancellation reaches a terminal state; if immediate deletion fails, stale-file cleanup retries it. Database migrations can capture and verify a same-origin output baseline and provide a seven-day conditional rollback. Native EasyRankly export/import covers settings, redirects, and registered metadata with size and structural safeguards.

== External Services ==

EasyRankly does not send server-side requests to third-party services and does not add analytics, tracking, telemetry, or phone-home calls. During migration verification, it may make bounded server-side requests only to URLs on the exact same origin as the WordPress site; redirects are not followed.

For posts containing YouTube or Vimeo URLs or embeds, EasyRankly may include provider player URLs in VideoObject structured data and video sitemaps. For YouTube videos without a featured image, it may also include a thumbnail URL derived from the public video ID. EasyRankly does not fetch video metadata or thumbnails server-side and does not use vumbnail.com. A browser or search engine that loads these provider URLs sends its normal request data to the provider. See YouTube terms (https://www.youtube.com/static?template=terms) and privacy policy (https://policies.google.com/privacy), and Vimeo terms (https://vimeo.com/legal) and privacy policy (https://vimeo.com/legal/privacy).

EasyRankly uses WordPress's avatar API when searching for a user in its administration screens and when a WordPress user is selected for Person schema. Depending on the site's avatar configuration and installed filters, this may return a Gravatar URL containing a hash derived from the user's email address. Loading that image sends the usual request data to Gravatar. See Gravatar (https://gravatar.com/), terms (https://wordpress.com/tos/) and privacy policy (https://automattic.com/privacy/).

== Changelog ==

= 2.0.0 =
EasyRankly 2.0.0 marks the transition from the initial foundation introduced with version 1.0.0 to a more mature, capable, and extensible SEO platform. This release revisits every major area of the plugin, with a renewed focus on delivering essential SEO tools through a fast, modular, and developer-friendly core.

The entire experience has been redesigned around a new setup wizard, clearer URL-addressable settings, native controls for the block editor, improved classic-editor and taxonomy panels, and contextual Site Editor integration for block themes. Accessibility has also been strengthened throughout, with more explicit labels, better semantic relationships, and keyboard-safe navigation and dialogs.

Metadata and structured data now offer substantially greater control. Primary taxonomy terms, ordered focus keyphrases, cornerstone content, advanced robots directives, dedicated Open Graph and X images, richer schema modes, FAQ and HowTo extraction, Event and VideoObject markup, and expanded WooCommerce support make it possible to describe and optimize content with far greater precision.

Redirect management and migration tools have evolved with the same attention to reliability. Redirects now support additional matching strategies, response codes, scheduling, audience targeting, and fine-grained URL controls, backed by a compiled runtime with per-pattern safety limits. Imports from Yoast SEO, Rank Math, AIOSEO, and SEOPress now include non-writing previews, resumable background processing, live-output verification, and a seven-day conditional rollback journal.

Behind the scenes, contextual module loading keeps inactive features from adding unnecessary overhead, while bounded background processing, stronger Multisite support, and dedicated WP-CLI workflows provide a more dependable foundation for sites of every size. EasyRankly 2.0.0 supports WordPress 6.2 and later with PHP 8.0 or newer.

As part of this clearer modular direction, AI generation, content analysis, internal linking, and Health monitoring are now provided through a separate add-on, allowing the core to remain focused, efficient, and easier to extend.

= 1.0.0 =
Release date: June 14, 2026

* First public release.

== Upgrade Notice ==

= 2.0.0 =
EasyRankly 2.0.0 introduces a substantially redesigned core. Before upgrading, create a full backup. Existing SEO content and redirects are preserved. AI generation, content analysis, internal linking, and Health monitoring have moved to a separate add-on. Review your settings after completing the upgrade.

= 1.0.0 =
First public release of EasyRankly.
