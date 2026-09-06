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
* **Sitemaps, when you want them.** WordPress's native XML sitemap stays aligned with EasyRankly visibility rules; the optional module adds public custom-post-type archives plus News, Image, and Video sitemaps.
* **Control over what gets indexed.** Simple noindex, nofollow, noarchive, and sitemap-exclusion controls, per page or across your site.
* **Smart redirects built in.** An optional redirect manager with a streamlined editor for exact, wildcard, and regular-expression rules, essential query-string controls, permanent and temporary redirects, and gone (410) responses.
* **Breadcrumbs and robots.txt.** A breadcrumb function for your theme (with optional shorter names per page) and an editable virtual robots.txt.

All of it lives in a redesigned, responsive admin interface with consistent form patterns, accessible label and control relationships, and keyboard-friendly tabs and dialogs.

== Installation ==

1. Upload the `easyrankly` folder to `/wp-content/plugins/`.
2. Activate EasyRankly from the Plugins screen.
3. Configure the plugin under Settings > EasyRankly.

== Frequently Asked Questions ==

= Can I run EasyRankly alongside another SEO plugin such as Yoast SEO or Rank Math? =

Yes, temporarily, such as during a migration, but two full SEO plugins should not remain responsible for the same output. If EasyRankly detects a recognised SEO plugin, it displays an admin notice and disables its own head metadata, structured data, sitemap output, and robots.txt customisations to reduce the risk of duplicates; redirects and breadcrumbs remain available. If you are switching from Yoast SEO, Rank Math, All in One SEO, or SEOPress, use EasyRankly's migration assistant and complete its guided checks before deactivating the previous plugin.

= Does it support WooCommerce? =

Yes. By default, EasyRankly leaves Product structured data to WooCommerce when WooCommerce's native schema is active, avoiding duplicate Product markup. Developers can opt into EasyRankly's Product JSON-LD with the `erankly_woocommerce_structured_data_enabled` and `erankly_render_woocommerce_product_schema` filters. When enabled, it supports core product fields, SKU, brand, GTIN, offers, aggregate ratings, and approved reviews; variable products use AggregateOffer.

= Does EasyRankly work on WordPress Multisite? =

Yes. EasyRankly is network-aware and stores global SEO settings at network level, while content metadata and special-page values remain scoped appropriately to each site. Multilingual functionality requires the separate EasyRankly Multilingual plugin, which integrates through the provider API.

Each site refreshes its own rewrite rules when needed, and network resets run in background batches. By default, networks with more than 100 sites require WP-CLI for network deactivation and uninstall to avoid HTTP timeouts: run `wp plugin deactivate easyrankly --network`, then `wp plugin uninstall easyrankly`.

= Does EasyRankly collect any personal data or phone home? =

EasyRankly does not send site or visitor data to EasyRankly and adds no external analytics or telemetry. Configuration data, including any optional business contact details you enter, and temporary migration files remain on your WordPress installation. When redirects are enabled, EasyRankly stores sampled aggregate hit counts and the last sampled hit time, but it does not store visitor IP addresses, referrers, user agents, languages, or cookie values.

= How do I display breadcrumbs? =

Enable breadcrumbs under Settings > EasyRankly > Schema, then add a visible trail with the EasyRankly Breadcrumbs block, the `[erankly_breadcrumbs]` shortcode, or `erankly_breadcrumbs()` in your theme. The function echoes its HTML by default; pass `array( 'echo' => false )` to return it without printing. You can customise the items and final markup with the `erankly_breadcrumb_items` and `erankly_breadcrumbs_html` filters. The legacy `easyrankly_breadcrumbs()` function and `[easyrankly_breadcrumbs]` shortcode remain available.

Breadcrumb JSON-LD is separate from the visible trail. By default it is emitted only when a visible trail is present, so structured data matches what people see. You can emit JSON-LD always, or turn it off, from the Schema settings.

= Does EasyRankly add a sitelinks search box? =

No. Google retired the sitelinks search box. WebSite `SearchAction` is optional and off by default. Existing sites can re-enable it under Settings > EasyRankly > Schema.

= Are FAQ rich results included? =

EasyRankly can still emit FAQPage Schema.org markup from FAQ blocks and accordion content. Google no longer shows FAQ rich results for most sites as of 2026, so the markup is for Schema.org consumers rather than a guaranteed Google enhancement.

= Is there an extension API? =

Yes. Extension API v1 includes multilingual provider registration through `erankly_register_multilingual_provider()`, neutral SEO-state reads, localised-value reads and writes, and filtered hreflang output. Localised-value reads and writes are available only on single-site installations; the provider and SEO-state contracts support provider-defined site topologies. These contracts remain in core for add-ons even when they are not called by the core UI.

= Can I migrate from Yoast SEO, Rank Math, All in One SEO or SEOPress? =

Yes, for supported fields and detected storage layouts. Open Settings > EasyRankly > Import/Export. Edition-aware database adapters read supported data directly from Yoast SEO, Rank Math, AIOSEO, and SEOPress installations on the same WordPress site.

Preview does not modify destination SEO metadata or redirects; it records only a resumable job checkpoint and report. Imports run in resumable background batches, recheck targets before applying them, preserve existing EasyRankly values, and pause if the source changes. Before a write migration starts, EasyRankly creates a private complete backup of its own settings, redirects, and registered metadata; it remains downloadable and restorable for seven days. Native EasyRankly export/import covers the same data with size and structural safeguards.

== External Services ==

EasyRankly does not send server-side requests to third-party services and does not add analytics, tracking, telemetry, or phone-home calls.

For posts containing YouTube or Vimeo URLs or embeds, EasyRankly may include provider player URLs in VideoObject structured data and video sitemaps. For YouTube videos without a featured image, it may also include a thumbnail URL derived from the public video ID. EasyRankly does not fetch video metadata or thumbnails server-side and does not use vumbnail.com. A browser or search engine that loads these provider URLs sends its normal request data to the provider. See YouTube terms (https://www.youtube.com/static?template=terms) and privacy policy (https://policies.google.com/privacy), and Vimeo terms (https://vimeo.com/legal) and privacy policy (https://vimeo.com/legal/privacy).

EasyRankly uses WordPress's avatar API when searching for a user in its administration screens and when a WordPress user is selected for Person schema. Depending on the site's avatar configuration and installed filters, this may return a Gravatar URL containing a hash derived from the user's email address. Loading that image sends the usual request data to Gravatar. See Gravatar (https://gravatar.com/), terms (https://wordpress.com/tos/) and privacy policy (https://automattic.com/privacy/).

== Changelog ==

= 2.0.0 =
EasyRankly 2.0.0 marks the transition from the initial foundation introduced with version 1.0.0 to a more mature, capable, and extensible SEO platform. This release revisits every major area of the plugin, with a renewed focus on delivering essential SEO tools through a fast, modular, and developer-friendly core.

The entire experience has been redesigned around clearer URL-addressable settings, native controls for the block editor, improved classic-editor and taxonomy panels, and contextual Site Editor integration for block themes. Accessibility has also been strengthened throughout, with more explicit labels, better semantic relationships, and keyboard-safe navigation and dialogs.

Metadata and structured data now offer substantially greater control. Primary taxonomy terms, advanced robots directives, dedicated Open Graph and X images, richer schema modes, FAQ and HowTo extraction, Event and VideoObject markup, and expanded WooCommerce support make it possible to describe and optimize content with far greater precision.

Redirect management and migration tools have evolved with the same attention to reliability. Redirects now focus on exact, wildcard, and regular-expression matching, essential query-string behavior, automatic rule precedence, response codes, and per-pattern safety limits. Audience targeting, request conditions, scheduling, and manual priority were removed from the public model; incompatible imported rules are skipped or disabled for review instead of being broadened silently. Imports from Yoast SEO, Rank Math, AIOSEO, and SEOPress now include non-writing previews, resumable background processing, and a complete pre-import backup retained for seven days.

Behind the scenes, contextual module loading keeps inactive features from adding unnecessary overhead, while bounded background processing, stronger Multisite support, and dedicated WP-CLI workflows provide a more dependable foundation for sites of every size. EasyRankly 2.0.0 supports WordPress 6.2 and later with PHP 8.0 or newer.

As part of this clearer modular direction, AI generation, content analysis, internal linking, and Health monitoring are now provided through a separate add-on, allowing the core to remain focused, efficient, and easier to extend.

= 1.0.0 =
Release date: June 14, 2026

* First public release.

== Upgrade Notice ==

= 2.0.0 =
Before upgrading, create a full backup. Existing SEO content and redirects are preserved. AI generation, content analysis, internal linking and Health monitoring have moved to a separate add-on. Review settings after upgrade.

= 1.0.0 =
First public release of EasyRankly.
