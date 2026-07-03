=== EasyRankly ===
Contributors: easyrankly
Tags: seo, schema, sitemap, redirects, breadcrumbs
Requires at least: 6.5
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight, modular, developer-first SEO essentials for WordPress.

== Description ==

EasyRankly provides only the technical SEO primitives a lean WordPress site needs:

* Document title integration.
* Meta description.
* Canonical URL.
* Robots meta through the native WordPress robots API.
* Open Graph and Twitter cards.
* Modular JSON-LD schema graph.
* Extended Organization identity details and optional single-location LocalBusiness schema.
* Optional XML sitemap index, post type sitemaps with image entries, taxonomy sitemaps, and SEO-aware author sitemap.
* Virtual robots.txt integration.
* Breadcrumb function.
* Granular noindex, nofollow, noarchive, and sitemap exclusion controls.
* Per-content search and archive query exclusion controls.
* Global Schema settings with repeatable custom JSON-LD blocks.
* Targeting rules for applying global schema blocks to front page, posts page, singular content, post type archives, and search results.
* Dynamic variables for content metadata, social metadata, and schema fields.
* Optional redirect management with exact, wildcard, and regex matching.
* Optional Health monitoring with frequent 404 detection (no user data is tracked; paths are anonymized and stored locally).
* Optional per-content breadcrumb names for shorter visible breadcrumbs and BreadcrumbList schema labels.

It does not include keyword scoring, readability analysis, AI, analytics, tracking, internal linking suggestions, marketing widgets, or upsells.

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

= Does EasyRankly collect any personal data or phone home? =

No. EasyRankly makes no outbound requests from your server: there is no analytics, tracking, or telemetry, and no data is sent anywhere. The optional Health 404 monitor stores only anonymized request paths in your own database; no user data is recorded. The only third-party reference is the public YouTube thumbnail URL added to the optional XML video sitemap for YouTube videos you have embedded yourself — see the External services section below.

= How do I display breadcrumbs? =

Call `erankly_breadcrumbs()` in your theme template. You can customise the markup with the `erankly_breadcrumb_items` and `erankly_breadcrumbs_html` filters.

= Can I import my settings from Yoast SEO or Rank Math? =

Yes. Open Settings > EasyRankly > Import/Export. It can import all plugin data from a JSON file and includes dedicated Yoast SEO and Rank Math importers for per-content meta.

== Developer API ==

Available filters include:

* `erankly_breadcrumb_items`
* `erankly_breadcrumbs_html`
* `erankly_canonical`
* `erankly_description`
* `erankly_enable_head_output`
* `erankly_enable_robots_txt_with_external_seo`
* `erankly_enable_sitemaps_with_external_seo`
* `erankly_faq_items`
* `erankly_head_credit_name`
* `erankly_health_404_sample_rate`
* `erankly_hreflang_alternates`
* `erankly_image_sitemap_url`
* `erankly_include_user_sitemap`
* `erankly_local_business_types`
* `erankly_localized_url`
* `erankly_ml_default_notice`
* `erankly_ml_language_native_name`
* `erankly_navigable_hreflang_alternates`
* `erankly_news_sitemap_post_types`
* `erankly_news_sitemap_publication_language`
* `erankly_news_sitemap_publication_name`
* `erankly_news_sitemap_url`
* `erankly_og_description`
* `erankly_og_image`
* `erankly_og_title`
* `erankly_opengraph_tags`
* `erankly_organization_schema_details`
* `erankly_post_breadcrumb_name`
* `erankly_post_types`
* `erankly_redirect_hit_sample_rate`
* `erankly_render_woocommerce_product_schema`
* `erankly_robots`
* `erankly_robots_txt_lines`
* `erankly_schema`
* `erankly_schema_article`
* `erankly_schema_blogposting`
* `erankly_schema_breadcrumb_list`
* `erankly_schema_faq`
* `erankly_schema_local_business`
* `erankly_schema_localbusiness`
* `erankly_schema_organization`
* `erankly_schema_person`
* `erankly_schema_service`
* `erankly_schema_webpage`
* `erankly_schema_website`
* `erankly_seo_checklist_docs_url`
* `erankly_settings_tabs`
* `erankly_sitemap_images`
* `erankly_sitemap_post_types`
* `erankly_special_pages`
* `erankly_taxonomies`
* `erankly_title`
* `erankly_twitter_card_type`
* `erankly_twitter_description`
* `erankly_twitter_image`
* `erankly_twitter_site`
* `erankly_twitter_title`
* `erankly_video_sitemap_url`
* `erankly_woocommerce_structured_data_enabled`

Available actions include:

* `erankly_redirects_caches_flushed`
* `erankly_render_settings_tab_{$slug}`

Use `erankly_breadcrumbs()` to render breadcrumbs.

When the Multisite multilingual module is enabled, the `[erankly_language_switcher]` and `[erankly_translation_notice]` shortcodes are available.

== External services ==

EasyRankly does not call any external service from your server. It performs no analytics, tracking, or telemetry, and sends no data to any remote endpoint.

The only third-party reference appears in the optional XML video sitemap. When the video sitemap is enabled and a post embeds a YouTube video, EasyRankly adds that video's public thumbnail URL (https://img.youtube.com/vi/&lt;video-id&gt;/0.jpg) to the sitemap so search engines can show a preview. The video ID is taken from the YouTube video you embedded yourself; EasyRankly does not contact YouTube — the URL is only written into your sitemap and is fetched by search-engine crawlers, not by your site. No personal data is involved.

This thumbnail service is provided by YouTube (Google LLC). Terms of Service: https://www.youtube.com/t/terms — Privacy Policy: https://policies.google.com/privacy

== Changelog ==

= 1.0.0 =
Release date: June 14, 2026

* First public release.

== Upgrade Notice ==

= 1.0.0 =
First public release of EasyRankly.
