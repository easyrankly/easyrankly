<?php
/**
 * Health module constants.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


define( 'ERANKLY_HEALTH_404_THRESHOLD', 10 );
define( 'ERANKLY_HEALTH_404_WINDOW', DAY_IN_SECONDS );
define( 'ERANKLY_HEALTH_404_MAX_CANDIDATES', 200 );
define( 'ERANKLY_HEALTH_404_MAX_FREQUENT', 100 );
/** Maximum distinct internal referrers stored per 404 entry. */
define( 'ERANKLY_HEALTH_404_MAX_REFERRERS', 5 );
define( 'ERANKLY_HEALTH_404_CANDIDATES_OPTION', 'erankly_health_404_candidates' );
define( 'ERANKLY_HEALTH_404_FREQUENT_OPTION', 'erankly_health_404_frequent' );
/** Stores manual 404 resolution states ( hash => ignored|resolved ). */
define( 'ERANKLY_HEALTH_404_STATES_OPTION', 'erankly_health_404_states' );
/** Version marker written only after all 404 storage migrations succeed. */
define( 'ERANKLY_HEALTH_404_STORAGE_VERSION_OPTION', 'erankly_health_404_storage_version' );
/** Short-lived database lock serializing 404 storage migrations. */
define( 'ERANKLY_HEALTH_404_STORAGE_LOCK_OPTION', 'erankly_health_404_storage_lock' );
/** Current schema/anonymization version for persistent 404 data. */
define( 'ERANKLY_HEALTH_404_STORAGE_VERSION', 1 );

define( 'ERANKLY_HEALTH_THIN_MIN_CHARS', 300 );
define( 'ERANKLY_HEALTH_THIN_MAX_RESULTS', 100 );
define( 'ERANKLY_HEALTH_THIN_OPTION', 'erankly_health_thin_content' );
/** Number of posts whose post_content is loaded per batch during the thin-content scan. */
define( 'ERANKLY_HEALTH_THIN_SCAN_BATCH', 200 );

/** Number of days aggregate 404 data is kept before the retention cron removes it. */
define( 'ERANKLY_HEALTH_404_RETENTION_DAYS', 30 );
/** WP-Cron hook name for the daily 404 data retention sweep. */
define( 'ERANKLY_HEALTH_404_PRUNE_HOOK', 'erankly_health_prune_404_cron' );
/** Hard cap on the length of a stored path after anonymization (characters). */
define( 'ERANKLY_HEALTH_PATH_MAX_LENGTH', 255 );

/** Transient key prefix for cached 404 → redirect suggestions. */
define( 'ERANKLY_HEALTH_SUGGESTION_PREFIX', 'erankly_health_sugg_' );
/** Minimum text similarity (0..1) for a fuzzy slug/title suggestion to be offered. */
define( 'ERANKLY_HEALTH_SUGGESTION_MIN_RATIO', 0.8 );
/** Maximum published rows scanned when looking for a fuzzy suggestion. */
define( 'ERANKLY_HEALTH_SUGGESTION_CANDIDATE_LIMIT', 500 );
/** Transient key prefix for legacy suggestions and short-lived AI no-match results. */
define( 'ERANKLY_HEALTH_AI_SUGGESTION_PREFIX', 'erankly_health_aisugg_' );
/** Persistent AI suggestions, keyed by the source-path hash. */
define( 'ERANKLY_HEALTH_AI_SUGGESTIONS_OPTION', 'erankly_health_ai_suggestions' );

/*
 * Broken-Link Candidates crawler.
 *
 * A manually-started crawl that seeds from the site's indexable URLs, spiders
 * internal links up to a bounded depth, and records every discovered link (with
 * anchor text and the page it was found on) so their HTTP status can be checked.
 * The run is driven in small batches from the admin via REST to stay within PHP
 * time limits; control state and bounded working sets use separate no-autoload
 * options so a checking tick never rewrites the full discovery graph.
 */
/** Option holding the in-progress crawl state (queue, visited pages, link map). */
define( 'ERANKLY_HEALTH_BL_STATE_OPTION', 'erankly_health_bl_state' );
/** Segmented page-fetch queue. */
define( 'ERANKLY_HEALTH_BL_QUEUE_OPTION', 'erankly_health_bl_queue' );
/** Segmented visited-page set. */
define( 'ERANKLY_HEALTH_BL_VISITED_OPTION', 'erankly_health_bl_visited' );
/** Segmented discovered-link map. */
define( 'ERANKLY_HEALTH_BL_LINKS_OPTION', 'erankly_health_bl_links' );
/** Segmented status-check queue. */
define( 'ERANKLY_HEALTH_BL_CHECK_QUEUE_OPTION', 'erankly_health_bl_check_queue' );
/** Segmented broken/unreachable result accumulator. */
define( 'ERANKLY_HEALTH_BL_FOUND_OPTION', 'erankly_health_bl_found' );
/** Option holding the finished crawl results (broken links + occurrences). */
define( 'ERANKLY_HEALTH_BL_RESULTS_OPTION', 'erankly_health_bl_results' );
/** Hard cap on the number of pages fetched during one crawl (seeds + spidered). */
define( 'ERANKLY_HEALTH_BL_MAX_PAGES', 300 );
/** Maximum spider depth followed from the seed set (0 = seeds only). */
define( 'ERANKLY_HEALTH_BL_MAX_DEPTH', 3 );
/** Hard cap on distinct links tracked (and therefore HTTP-checked) per crawl. */
define( 'ERANKLY_HEALTH_BL_MAX_LINKS', 3000 );
/** Maximum broken-link rows stored in the final results. */
define( 'ERANKLY_HEALTH_BL_MAX_RESULTS', 200 );
/** Per-request HTTP timeout (seconds) for page fetches and link status checks. */
define( 'ERANKLY_HEALTH_BL_HTTP_TIMEOUT', 8 );
/** Pages fetched (and parsed) per discovery batch/tick. */
define( 'ERANKLY_HEALTH_BL_FETCH_BATCH', 8 );
/** Links status-checked per checking batch/tick. */
define( 'ERANKLY_HEALTH_BL_CHECK_BATCH', 25 );
/** Transient key prefix for cached per-URL HTTP status codes. */
define( 'ERANKLY_HEALTH_BL_CACHE_PREFIX', 'erankly_health_bl_st_' );
/** Cache TTL for internal URL status checks. */
define( 'ERANKLY_HEALTH_BL_CACHE_TTL_INTERNAL', HOUR_IN_SECONDS );
/** Cache TTL for external URL status checks. */
define( 'ERANKLY_HEALTH_BL_CACHE_TTL_EXTERNAL', 12 * HOUR_IN_SECONDS );
