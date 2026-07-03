=== EasyRankly ===
Contributors: easyrankly
Tags: seo, schema, sitemap, redirects, ai
Requires at least: 6.2.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.0.0-beta
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
* **Control over what gets indexed.** Simple noindex, nofollow, noarchive, and sitemap-exclusion controls, per page or across your site, including keeping content out of search and archive results.
* **Redirects built in.** An optional redirect manager with exact, wildcard, and regex matching.
* **Breadcrumbs and robots.txt.** A breadcrumb function for your theme (with optional shorter names per page) and an editable virtual robots.txt.
* **Private health monitoring.** Optional 404 tracking that stays on your server — request paths are anonymized and cleaned up automatically, and no visitor data is ever collected.
* **Optional AI meta generation.** Generate or improve SEO titles and descriptions from the editor when WordPress has a connected AI provider. The feature is off by default and uses WordPress' native AI/Connectors APIs.
* **Dynamic variables** for filling in titles, social tags, and schema fields automatically.

And what it leaves out, on purpose: no keyword scoring, no readability nags, no analytics or tracking, no internal-linking suggestions, no marketing widgets, and no upsells.

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

No analytics, tracking, telemetry, or EasyRankly phone-home calls are added. The optional Health 404 monitor stores request paths in your own database only, with emails, long IDs, tokens and usernames stripped on a best-effort basis before saving.

If you enable AI meta generation, EasyRankly sends page context to the AI provider connected in WordPress only when an editor clicks the Generate with AI button. EasyRankly does not provide its own AI service or receive that content.

= How do I display breadcrumbs? =

Call `erankly_breadcrumbs()` in your theme template. You can customise the markup with the `erankly_breadcrumb_items` and `erankly_breadcrumbs_html` filters. Legacy `easyrankly_breadcrumbs()` and `easyrankly_*` hook aliases remain available for backward compatibility.

= Can I import my settings from Yoast SEO or Rank Math? =

Yes. Open Settings > EasyRankly > Import/Export. You can export and re-import your EasyRankly settings, redirects, special page defaults and the SEO metadata for posts and terms as a single JSON file, and there are dedicated Yoast SEO and Rank Math importers for per-content meta. On Multisite the Import/Export tab lives in Network Admin, and the file covers the network-wide global settings plus the content (redirects, post and term metadata, special page defaults) of the primary site it runs on; it is not a whole-network export covering every site. (Translation links between network sites are not part of the file, as they reference site-specific IDs.)

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
* `erankly_health_404_sample_rate`
* `erankly_hreflang_alternates`
* `erankly_image_sitemap_url`
* `erankly_include_user_sitemap`
* `erankly_local_business_types`
* `erankly_localized_url`
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

Use `erankly_breadcrumbs()` to render breadcrumbs. Legacy `easyrankly_*` filter aliases and `easyrankly_breadcrumbs()` are still supported for sites that used earlier documentation.

== Changelog ==

= 2.6.1 =
* Maintenance release.

= 2.3.0 =
* Maintenance release.

= 2.2.0 =
* Aligned the developer API documentation with the canonical `erankly_*` hooks and `erankly_breadcrumbs()` function, while keeping legacy `easyrankly_*` aliases for backward compatibility.
* Added a classic settings fallback for block themes on WordPress 6.2-6.5, where the Site Editor special-page panels are not available.

= 2.1.2 =
* Reorganized the Advanced settings tab into clearer, consistently titled sections (Indexing & robots directives, robots.txt, Pagination, Attachment pages) without changing any setting.

= 2.1.1 =
* Removed a redundant notice on the multilingual setting when the site is not running WordPress Multisite.

= 2.0.1 =
* Saving plugin settings now keeps you on the active tab instead of returning to General.

= 2.0.0 =
* Block themes can configure special-page SEO defaults (homepage, blog, author, date, search, and 404) directly in the Site Editor, with Search appearance, Social sharing, and Search visibility panels saved through the editor's native Save action. Requires WordPress 6.6 or later.
* Special-page SEO defaults now include dedicated Open Graph and X (Twitter) social fields, editable from the classic-theme settings fallback or the contextual Site Editor panels.
* Setup wizard now asks for the site identity (Organization or Person, with a reference user) and name, alongside the interface mode and X (Twitter) account.
* Scoped redirect regex backtracking and depth limits to each pattern, removing request-wide PHP configuration changes while preserving protection against catastrophic backtracking.
* Isolated the floating SEO checklist controls from frontend theme button styles for a consistent appearance.
* Moved EasyRankly document panels after the default WordPress editor panels.
* On Multisite block-theme sites, the per-site menu is now hidden when no local panel (Health or Redirects) is available.
* Kept the minimum supported WordPress version at 6.2.0, with Site Editor SEO panels loaded only on WordPress 6.6 or later.
* Hardened the codebase to meet the latest WordPress.org plugin guidelines (unique prefixes, output escaping, input sanitization, and proper script/style enqueuing).

= 1.0.0 =
* First public release.

== Upgrade Notice ==

= 2.0.0 =
Adds Site Editor SEO panels for special pages on WordPress 6.6 or later, social fields for special-page defaults, and an expanded setup wizard.

= 1.0.0 =
First public release of EasyRankly.
