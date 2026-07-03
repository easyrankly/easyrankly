<?php
/**
 * Multilingual (hreflang) module loader.
 *
 * Opt-in via the enable_multilingual setting. Only loaded on Multisite.
 * Hooks into the existing `erankly_hreflang_alternates` filter so that
 * the standard renderer in meta.php outputs the network-aware alternates
 * without any changes to the output pipeline.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database schema version for the ML relations table.
 */
define( 'ERANKLY_ML_DB_VERSION', '1.0.0' );

/**
 * Option name tracking the installed ML table version.
 */
define( 'ERANKLY_ML_DB_VERSION_OPTION', 'erankly_ml_db_version' );

/**
 * Whether the multilingual feature is enabled in settings.
 *
 * @return bool
 */
function erankly_multilingual_enabled(): bool {
	return ! empty( erankly_get_setting( 'enable_multilingual' ) );
}

/**
 * Boots the multilingual module.
 *
 * Called from erankly_bootstrap() when is_multisite() and the feature is on.
 * Idempotent: repeated calls (e.g. via erankly_ml_admin()) are no-ops so
 * runtime hooks are never registered twice.
 *
 * @return void
 */
function erankly_ml_boot(): void {
	static $booted = false;

	if ( $booted ) {
		return;
	}
	$booted = true;

	require_once ERANKLY_PATH . 'includes/multilingual/class-erankly-ml-activator.php';
	require_once ERANKLY_PATH . 'includes/multilingual/class-erankly-ml-sites.php';
	require_once ERANKLY_PATH . 'includes/multilingual/class-erankly-ml-repository.php';
	require_once ERANKLY_PATH . 'includes/multilingual/class-erankly-ml-resolver.php';
	require_once ERANKLY_PATH . 'includes/multilingual/class-erankly-ml-admin.php';
	require_once ERANKLY_PATH . 'includes/multilingual/class-erankly-ml-shortcodes.php';

	// The relations table is network-wide, so its cache must be shared across
	// sites; without a global group, an edit on site A would leave stale
	// hreflang data cached for site B on persistent object caches.
	wp_cache_add_global_groups( 'erankly_ml' );

	erankly_ml_maybe_upgrade_db();

	$repo     = new ERankly_ML_Repository();
	$resolver = new ERankly_ML_Resolver( $repo );

	// Wire the resolver into the existing hreflang filter (priority 20 so it
	// runs after Polylang's output at default priority; the ML result replaces
	// the Polylang result when the network module is active).
	add_filter( 'erankly_hreflang_alternates', array( $resolver, 'resolve' ), 20 );

	// Visitor-facing consumers (erankly_get_navigable_hreflang_alternates(),
	// used by add-on language-redirect features) resolve through this instance so
	// they can include noindex translations.
	$GLOBALS['erankly_ml_resolver'] = $resolver;

	// The admin instance is always created so the editor and Network Admin
	// renderers can use it, but its runtime hooks (REST route, save handlers)
	// are only registered where they can actually fire.
	$admin = new ERankly_ML_Admin( $repo );

	// Register unconditionally: the REST route must exist before rest_api_init fires, and
	// REST_REQUEST isn't defined yet during plugins_loaded. The rest (save_post, delete, …)
	// are harmless no-ops when they don't match the request.
	$admin->register_hooks();

	// Available to the editor and settings-page renderers.
	$GLOBALS['erankly_ml_admin'] = $admin;

	( new ERankly_ML_Shortcodes( $repo ) )->register_hooks();
}

/**
 * Returns the multilingual admin instance, booting the module on demand.
 *
 * Guarantees the renderers below never silently produce nothing because of
 * request-order quirks: if the module is enabled but not yet booted for this
 * request, it is booted here.
 *
 * @return ERankly_ML_Admin|null Instance, or null when the feature is off.
 */
function erankly_ml_admin(): ?ERankly_ML_Admin {
	if ( ! is_multisite() || ! erankly_multilingual_enabled() ) {
		return null;
	}

	$admin = $GLOBALS['erankly_ml_admin'] ?? null;

	if ( ! $admin instanceof ERankly_ML_Admin ) {
		erankly_ml_boot();
		$admin = $GLOBALS['erankly_ml_admin'] ?? null;
	}

	return $admin instanceof ERankly_ML_Admin ? $admin : null;
}

/**
 * Creates the relations table on first boot and ensures the schema is current.
 *
 * @return void
 */
function erankly_ml_maybe_upgrade_db(): void {
	$installed = (string) get_site_option( ERANKLY_ML_DB_VERSION_OPTION, '' );

	if ( ERANKLY_ML_DB_VERSION === $installed ) {
		return;
	}

	ERankly_ML_Activator::activate();
	update_site_option( ERANKLY_ML_DB_VERSION_OPTION, ERANKLY_ML_DB_VERSION );
}

/**
 * Renders the Translations tab in a post meta box.
 *
 * Called from admin/meta-box.php when the module is active.
 *
 * @param WP_Post $post Current post.
 * @return void
 */
function erankly_ml_render_post_translations( WP_Post $post ): void {
	$admin = erankly_ml_admin();
	if ( $admin instanceof ERankly_ML_Admin ) {
		$admin->render_post_translations_panel( $post );
	}
}

/**
 * Renders the Translations tab in a term edit screen.
 *
 * @param int    $term_id  Term ID.
 * @param string $taxonomy Taxonomy name.
 * @return void
 */
function erankly_ml_render_term_translations( int $term_id, string $taxonomy ): void {
	$admin = erankly_ml_admin();
	if ( $admin instanceof ERankly_ML_Admin ) {
		$admin->render_term_translations_panel( $term_id, $taxonomy );
	}
}

/**
 * Renders the Multilingual network settings panel.
 *
 * Called from admin/settings-page.php.
 *
 * @return void
 */
function erankly_ml_render_network_panel(): void {
	$admin = erankly_ml_admin();
	if ( $admin instanceof ERankly_ML_Admin ) {
		$admin->render_network_panel();
	}
}
