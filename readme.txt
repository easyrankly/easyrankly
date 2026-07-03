=== EasyRankly ===
Contributors: easyrankly
Tags: seo, schema, sitemap, redirects, ai
Requires at least: 6.2.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 2.0.0
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
* **Private health monitoring.** Optional 404 tracking that stays on your server — request paths are anonymized and cleaned up automatically, and no visitor data is ever collected.
* **Dynamic variables** for filling in titles, social tags, and schema fields automatically.

All of it lives in a redesigned, streamlined admin interface with a short setup wizard to get you configured in minutes.

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

No analytics, tracking, telemetry, or EasyRankly phone-home calls are added. The optional Health 404 monitor stores request paths in your own database only, with emails, long IDs, tokens, and usernames stripped on a best-effort basis before saving.

If you enable AI meta generation, EasyRankly sends page context to the AI provider connected in WordPress only when an editor clicks the Generate with AI button. EasyRankly does not provide its own AI service or receive that content.

= What do I need for AI meta generation to work? =

AI meta generation relies on WordPress' native AI and Connectors APIs, which are available in WordPress 7.0 and later. On earlier WordPress versions, or when no AI provider is connected, the feature stays completely inactive and EasyRankly falls back to its built-in, non-AI title and description logic — nothing else changes. Even where the APIs are available, the feature is off until you enable it under Settings > EasyRankly > AI and connect a provider on the WordPress Connectors screen.

= How do I display breadcrumbs? =

Call `erankly_breadcrumbs()` in your theme template. You can customise the markup with the `erankly_breadcrumb_items` and `erankly_breadcrumbs_html` filters. Legacy `easyrankly_breadcrumbs()` and `easyrankly_*` hook aliases remain available for backward compatibility.

= Can I import my settings from Yoast SEO or Rank Math? =

Yes. Open Settings > EasyRankly > Import/Export. You can export and re-import your EasyRankly settings, redirects, special page defaults and the SEO metadata for posts and terms as a single JSON file, and there are dedicated Yoast SEO and Rank Math importers for per-content meta. On Multisite the Import/Export tab lives in Network Admin, and the file covers the network-wide global settings plus the content (redirects, post and term metadata, special page defaults) of the primary site it runs on; it is not a whole-network export covering every site. (Translation links between network sites are not part of the file, as they reference site-specific IDs.)

== Changelog ==

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
* The floating SEO checklist is isolated from frontend theme button styles for a consistent appearance.
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

= 2.0.0 =
Major update: a rebuilt admin interface, optional AI-assisted meta generation, and a smarter redirect manager. Review your settings after updating.

= 1.0.0 =
First public release of EasyRankly.
