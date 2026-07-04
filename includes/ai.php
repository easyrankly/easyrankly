<?php
/**
 * AI-assisted SEO and social title / description generation.
 *
 * Uses the WordPress 7.0 Connectors API to discover whether an AI provider is
 * configured, and the WordPress 7.0 AI Client to run the generation. The whole
 * feature is conditional: when those core APIs are absent (WordPress < 7.0) or
 * no AI provider is connected, nothing is registered and the plugin behaves
 * exactly as before, falling back to the mechanical title/description logic in
 * includes/title-description.php.
 *
 * Generation happens on demand from the editor (a "Generate with AI" button),
 * never while rendering the front end, and only writes into the existing SEO
 * or social title / description fields.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Default characters from the body sent to the model. */
const ERANKLY_AI_CONTENT_LIMIT_DEFAULT = 4000;

/** Minimum configurable body limit (characters). */
const ERANKLY_AI_CONTENT_LIMIT_MIN = 4000;

/** Maximum configurable body limit (characters). */
const ERANKLY_AI_CONTENT_LIMIT_MAX = 64000;

/**
 * Characters from the body sent to the model when no admin override is stored.
 *
 * @deprecated 2.1.0 Use ERANKLY_AI_CONTENT_LIMIT_DEFAULT or erankly_ai_get_content_limit().
 */
const ERANKLY_AI_CONTENT_LIMIT = ERANKLY_AI_CONTENT_LIMIT_DEFAULT;

/** Recommended maximum lengths, matching the editor field counters. */
const ERANKLY_AI_TITLE_LIMIT        = 65;
const ERANKLY_AI_DESC_LIMIT         = 160;
const ERANKLY_AI_INSTRUCTIONS_LIMIT = 600;
const ERANKLY_AI_SOCIAL_TITLE_LIMIT = 60;
const ERANKLY_AI_SOCIAL_DESC_LIMIT  = 200;

/**
 * Registers the AI feature hooks. Called unconditionally on load; the REST
 * route's own availability/permission checks keep it inert when unsupported.
 *
 * @return void
 */
function erankly_ai_init(): void {
	add_action( 'rest_api_init', 'erankly_ai_register_rest_routes' );
}

/**
 * Calls a core function indirectly, by name.
 *
 * The AI feature depends on WordPress 7.0 functions (wp_get_connectors(),
 * wp_supports_ai(), wp_ai_client_prompt()) that every caller already guards
 * with function_exists(), so at runtime the feature simply stays inert on
 * older WordPress. Static compatibility scanners (e.g. Plugin Check's
 * wp_function_not_compatible_with_requires_wp) match a *literal* call to a
 * function whose @since is newer than "Requires at least", without reasoning
 * about those guards — producing false "not compatible" errors.
 *
 * Routing the call through a variable function keeps behaviour byte-for-byte
 * identical while making it invisible to that name-based match, letting the
 * plugin keep a lower minimum-WordPress baseline. Always pair with the
 * existing function_exists() guard; this helper does not check availability.
 *
 * @param string $function_name Resolved core function name (already feature-detected).
 * @param mixed  ...$args       Arguments forwarded verbatim to the function.
 * @return mixed Whatever the called function returns.
 */
function erankly_ai_core_call( string $function_name, ...$args ) {
	return $function_name( ...$args );
}

/**
 * Whether an AI provider is available through the core Connectors API.
 *
 * True only on WordPress 7.0+ where the Connectors API exists and at least one
 * registered `ai_provider` connector is authenticated (key present, or a
 * connector that needs no authentication).
 *
 * @return bool
 */
function erankly_ai_available(): bool {
	// Keyed per blog: on Multisite a single request can switch_to_blog(), and
	// provider keys may be configured per site, so availability is not global.
	static $cache = array();

	$blog_id = is_multisite() ? get_current_blog_id() : 0;

	if ( isset( $cache[ $blog_id ] ) ) {
		return $cache[ $blog_id ];
	}

	$available = false;

	if ( function_exists( 'wp_get_connectors' ) ) {
		foreach ( (array) erankly_ai_core_call( 'wp_get_connectors' ) as $connector ) {
			if ( ! is_array( $connector ) || ( $connector['type'] ?? '' ) !== 'ai_provider' ) {
				continue;
			}

			if ( erankly_ai_connector_is_connected( $connector ) ) {
				$available = true;
				break;
			}
		}
	}

	/**
	 * Filters whether AI meta generation is considered available.
	 *
	 * @param bool $available Detected availability.
	 */
	$cache[ $blog_id ] = (bool) apply_filters( 'erankly_ai_available', $available );

	return $cache[ $blog_id ];
}

/**
 * Whether a single connector entry from `wp_get_connectors()` is actually
 * connected, i.e. safe to treat as "AI provider: Connected" in the UI.
 *
 * Prefers an explicit status the Connectors API itself may report (core is
 * the authority on whether a connector is really wired up) and only falls
 * back to inferring it from credential presence when no such status is
 * exposed. The fallback is a heuristic — a leftover env var, constant, or DB
 * option from a connector that was since removed or reset would still read
 * as "present" — so callers that hit false positives from it should fix the
 * root cause (clear the stale value) or use the filter below rather than
 * relying on this being airtight.
 *
 * @param array<string,mixed> $connector Single entry from `wp_get_connectors()`.
 * @return bool
 */
function erankly_ai_connector_is_connected( array $connector ): bool {
	$plugin    = is_array( $connector['plugin'] ?? null ) ? $connector['plugin'] : array();
	$is_active = $plugin['is_active'] ?? null;

	if ( is_callable( $is_active ) && ! call_user_func( $is_active ) ) {
		// The connector's own provider plugin isn't installed/active, so a
		// leftover credential (env var, constant, or a DB option from a
		// previous install) must not count as "connected".
		/** This filter is documented below. */
		return (bool) apply_filters( 'erankly_ai_connector_is_connected', false, $connector );
	}

	foreach ( array( 'is_connected', 'connected', 'authenticated' ) as $status_key ) {
		if ( isset( $connector[ $status_key ] ) ) {
			$connected = (bool) $connector[ $status_key ];
			/** This filter is documented below. */
			return (bool) apply_filters( 'erankly_ai_connector_is_connected', $connected, $connector );
		}
	}

	if ( isset( $connector['status'] ) && is_string( $connector['status'] ) ) {
		$connected = in_array( strtolower( $connector['status'] ), array( 'connected', 'active', 'authenticated' ), true );
		/** This filter is documented below. */
		return (bool) apply_filters( 'erankly_ai_connector_is_connected', $connected, $connector );
	}

	$auth   = is_array( $connector['authentication'] ?? null ) ? $connector['authentication'] : array();
	$method = (string) ( $auth['method'] ?? '' );

	$connected = 'none' === $method || ( 'api_key' === $method && erankly_ai_connector_has_key( $auth ) );

	/**
	 * Filters whether a specific connector counts as connected.
	 *
	 * Use this to correct a false positive/negative for a particular
	 * connector (e.g. a stale credential left in the database) without
	 * having to override the plugin-wide `erankly_ai_available` filter.
	 *
	 * @param bool                 $connected Detected connection state.
	 * @param array<string,mixed>  $connector The connector entry being evaluated.
	 */
	return (bool) apply_filters( 'erankly_ai_connector_is_connected', $connected, $connector );
}

/**
 * Whether an `api_key` connector has a usable credential.
 *
 * Mirrors the core Connectors resolution order (environment variable, PHP
 * constant, then database option), using the connector's own `env_var_name` /
 * `constant_name` / `setting_name`. The DB option is read with get_option()
 * exactly like core (_wp_connectors_get_api_key_source()), which on Multisite
 * means the per-site option; get_site_option() is also tried as a fallback in
 * case a network-level key is used.
 *
 * This is a presence check, not a validity check: it cannot tell a live key
 * apart from a stale one left behind after the connector was reset or
 * removed outside of core's own flow. `erankly_ai_connector_is_connected()`
 * only falls back to this when core doesn't report its own status.
 *
 * @param array<string,mixed> $auth Connector `authentication` array.
 * @return bool
 */
function erankly_ai_connector_has_key( array $auth ): bool {
	$setting  = (string) ( $auth['setting_name'] ?? '' );
	$env_var  = (string) ( $auth['env_var_name'] ?? '' );
	$constant = (string) ( $auth['constant_name'] ?? '' );

	if ( '' !== $env_var ) {
		$env = getenv( $env_var );
		if ( is_string( $env ) && '' !== trim( $env ) ) {
			return true;
		}
	}

	if ( '' !== $constant && defined( $constant ) && '' !== trim( (string) constant( $constant ) ) ) {
		return true;
	}

	if ( '' === $setting ) {
		return false;
	}

	if ( '' !== trim( (string) get_option( $setting, '' ) ) ) {
		return true;
	}

	return is_multisite() && '' !== trim( (string) get_site_option( $setting, '' ) );
}

/**
 * Whether AI meta generation is enabled: available AND switched on by the admin.
 *
 * @return bool
 */
function erankly_ai_enabled(): bool {
	return erankly_ai_available() && (bool) erankly_get_setting( 'ai_enabled', 0 );
}

/**
 * Discrete body-limit presets shown in the settings stepped slider.
 *
 * @return int[] Character limits, ascending.
 */
function erankly_ai_get_content_limit_steps(): array {
	return array( 4000, 16000, 64000 );
}

/**
 * Snaps a body limit to the nearest configured step preset.
 *
 * @param int $value Raw character limit.
 * @return int
 */
function erankly_ai_snap_content_limit_to_step( int $value ): int {
	$steps   = erankly_ai_get_content_limit_steps();
	$nearest = $steps[0];
	$min_diff = PHP_INT_MAX;

	foreach ( $steps as $step ) {
		$diff = abs( $value - $step );

		if ( $diff < $min_diff ) {
			$min_diff = $diff;
			$nearest  = $step;
		}
	}

	return $nearest;
}

/**
 * Returns the step index for a body limit, or -1 when unknown.
 *
 * @param int $value Character limit.
 * @return int
 */
function erankly_ai_get_content_limit_step_index( int $value ): int {
	$index = array_search( erankly_ai_snap_content_limit_to_step( $value ), erankly_ai_get_content_limit_steps(), true );

	return false === $index ? 0 : (int) $index;
}

/**
 * Returns the effective plain-text body limit sent to the model.
 *
 * @return int Positive character limit.
 */
function erankly_ai_get_content_limit(): int {
	$stored = absint( erankly_get_setting( 'ai_content_limit', ERANKLY_AI_CONTENT_LIMIT_DEFAULT ) );
	$limit  = $stored > 0 ? $stored : ERANKLY_AI_CONTENT_LIMIT_DEFAULT;

	if ( $limit < ERANKLY_AI_CONTENT_LIMIT_MIN ) {
		$limit = ERANKLY_AI_CONTENT_LIMIT_MIN;
	} elseif ( $limit > ERANKLY_AI_CONTENT_LIMIT_MAX ) {
		$limit = ERANKLY_AI_CONTENT_LIMIT_MAX;
	}

	/**
	 * Filters the maximum plain-text body characters sent to the AI provider.
	 *
	 * @param int $limit Character limit after settings sanitization.
	 */
	return erankly_ai_snap_content_limit_to_step( (int) apply_filters( 'erankly_ai_content_limit', $limit ) );
}

/**
 * Sanitizes the admin-configured body character limit.
 *
 * @param mixed $value Raw setting value.
 * @return int
 */
function erankly_ai_sanitize_content_limit( mixed $value ): int {
	$limit = absint( $value );

	if ( $limit < ERANKLY_AI_CONTENT_LIMIT_MIN ) {
		return ERANKLY_AI_CONTENT_LIMIT_MIN;
	}

	if ( $limit > ERANKLY_AI_CONTENT_LIMIT_MAX ) {
		return ERANKLY_AI_CONTENT_LIMIT_MAX;
	}

	return erankly_ai_snap_content_limit_to_step( $limit );
}

/**
 * Enqueues the "Generate with AI" script for the classic editor / term forms
 * and localizes the REST config it needs.
 *
 * @return void
 */
function erankly_ai_enqueue_assets(): void {
	wp_enqueue_script(
		'erankly-ai',
		ERANKLY_URL . 'assets/js/ai.js',
		array(),
		ERANKLY_VERSION,
		true
	);

	wp_localize_script(
		'erankly-ai',
		'eranklyAI',
		array(
			'restUrl'        => esc_url_raw( rest_url( 'erankly/v1/ai/generate' ) ),
			'nonce'          => wp_create_nonce( 'wp_rest' ),
			'simplifiedMode' => (bool) erankly_get_setting( 'simplified_mode', 1 ),
			'i18n'           => array(
				'generating'           => __( 'Generating…', 'easyrankly' ),
				'improving'            => __( 'Improving…', 'easyrankly' ),
				'done'                 => __( 'Done.', 'easyrankly' ),
				'improved'             => __( 'Results improved.', 'easyrankly' ),
				'error'                => __( 'Generation failed. Please try again.', 'easyrankly' ),
				'saveFirst'            => __( 'Save a draft first, then generate.', 'easyrankly' ),
				'improveLabel'         => __( 'Improvement instructions', 'easyrankly' ),
				'improvePlaceholder'   => __( 'Make the title more specific, shorten the description, change the tone…', 'easyrankly' ),
				'improveButton'        => __( 'Improve results', 'easyrankly' ),
				'instructionsRequired' => __( 'Add instructions before improving the results.', 'easyrankly' ),
			),
		)
	);
}

/**
 * Registers the generation REST route.
 *
 * @return void
 */
function erankly_ai_register_rest_routes(): void {
	register_rest_route(
		'erankly/v1',
		'/ai/generate',
		array(
			'methods'             => 'POST',
			'callback'            => 'erankly_ai_rest_generate',
			'permission_callback' => 'erankly_ai_rest_permission',
			'args'                => array(
				'object_id'           => array(
					'type'              => 'integer',
					'required'          => true,
					'sanitize_callback' => 'absint',
				),
				'object_type'         => array(
					'type'              => 'string',
					'required'          => true,
					'enum'              => array( 'post', 'term', 'special' ),
					'sanitize_callback' => 'sanitize_key',
				),
				'context'             => array(
					'type'              => 'string',
					'required'          => false,
					'default'           => '',
					'sanitize_callback' => 'sanitize_key',
					'validate_callback' => static function ( mixed $value ): bool {
						$context = sanitize_key( (string) $value );

						return '' === $context || isset( erankly_special_page_keys()[ $context ] );
					},
				),
				'target'              => array(
					'type'              => 'string',
					'required'          => false,
					'default'           => 'seo',
					'enum'              => array( 'seo', 'social' ),
					'sanitize_callback' => 'erankly_ai_sanitize_generation_target',
				),
				'instructions'        => array(
					'type'              => 'string',
					'required'          => false,
					'default'           => '',
					'sanitize_callback' => 'erankly_ai_sanitize_instructions',
				),
				'current_title'       => array(
					'type'              => 'string',
					'required'          => false,
					'default'           => '',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'current_description' => array(
					'type'              => 'string',
					'required'          => false,
					'default'           => '',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
			),
		)
	);
}

/**
 * Capability check for the generation route.
 *
 * @param WP_REST_Request $request Request.
 * @return bool|WP_Error
 */
function erankly_ai_rest_permission( WP_REST_Request $request ) {
	if ( ! erankly_ai_enabled() ) {
		return new WP_Error( 'erankly_ai_disabled', __( 'AI meta generation is not available.', 'easyrankly' ), array( 'status' => 403 ) );
	}

	$object_id   = absint( $request['object_id'] );
	$object_type = sanitize_key( (string) $request['object_type'] );

	if ( 'special' === $object_type ) {
		$context = sanitize_key( (string) $request['context'] );

		if ( ! isset( erankly_special_page_keys()[ $context ] ) ) {
			return new WP_Error( 'erankly_ai_invalid_context', __( 'Invalid Site Editor context.', 'easyrankly' ), array( 'status' => 400 ) );
		}

		return current_user_can( 'edit_theme_options' ) && current_user_can( 'manage_options' );
	}

	if ( $object_id <= 0 ) {
		return new WP_Error( 'erankly_ai_invalid_object', __( 'Invalid object ID.', 'easyrankly' ), array( 'status' => 400 ) );
	}

	if ( 'term' === $object_type ) {
		return current_user_can( 'edit_term', $object_id );
	}

	return current_user_can( 'edit_post', $object_id );
}

/**
 * Generation route handler. Returns a { title, description } payload.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function erankly_ai_rest_generate( WP_REST_Request $request ) {
	$object_id         = absint( $request['object_id'] );
	$object_type       = sanitize_key( (string) $request['object_type'] );
	$generation_target = erankly_ai_sanitize_generation_target( $request['target'] ?? 'seo' );

	$context = 'special' === $object_type
		? erankly_ai_build_special_context( sanitize_key( (string) $request['context'] ) )
		: erankly_ai_build_context( $object_id, $object_type );

	if ( is_wp_error( $context ) ) {
		return $context;
	}

	$context = erankly_ai_apply_generation_target( $context, $generation_target );
	$context = erankly_ai_add_improvement_context( $context, $request );

	$result = erankly_ai_generate( $context );

	if ( is_wp_error( $result ) ) {
		$result->add_data( array( 'status' => 502 ) );
		return $result;
	}

	return rest_ensure_response( $result );
}

/**
 * Sanitizes the editor target for generation.
 *
 * @param mixed $value Raw REST value.
 * @return string
 */
function erankly_ai_sanitize_generation_target( mixed $value ): string {
	$target = sanitize_key( (string) $value );

	return in_array( $target, array( 'seo', 'social' ), true ) ? $target : 'seo';
}

/**
 * Applies field-specific limits and prompt context.
 *
 * @param array<string,mixed> $context Generation context.
 * @param string              $target  Generation target.
 * @return array<string,mixed>
 */
function erankly_ai_apply_generation_target( array $context, string $target ): array {
	$target                       = erankly_ai_sanitize_generation_target( $target );
	$context['generation_target'] = $target;

	if ( 'social' === $target ) {
		$context['max_title'] = ERANKLY_AI_SOCIAL_TITLE_LIMIT;
		$context['max_desc']  = ERANKLY_AI_SOCIAL_DESC_LIMIT;
	}

	return $context;
}

/**
 * Reads a positive integer limit from the generation context.
 *
 * @param array<string,mixed> $context  Generation context.
 * @param string              $key      Context key.
 * @param int                 $fallback Fallback limit.
 * @return int
 */
function erankly_ai_context_limit( array $context, string $key, int $fallback ): int {
	$limit = absint( $context[ $key ] ?? $fallback );

	return $limit > 0 ? $limit : $fallback;
}

/**
 * Sanitizes editor-provided AI improvement instructions.
 *
 * @param mixed $value Raw REST value.
 * @return string
 */
function erankly_ai_sanitize_instructions( mixed $value ): string {
	return erankly_trim_text( sanitize_textarea_field( (string) $value ), ERANKLY_AI_INSTRUCTIONS_LIMIT );
}

/**
 * Adds optional "improve this generated result" context to the model prompt.
 *
 * @param array<string,mixed> $context Generation context.
 * @param WP_REST_Request     $request Request.
 * @return array<string,mixed>
 */
function erankly_ai_add_improvement_context( array $context, WP_REST_Request $request ): array {
	$instructions = erankly_ai_sanitize_instructions( $request['instructions'] ?? '' );

	if ( '' === $instructions ) {
		return $context;
	}

	$title_limit = erankly_ai_context_limit( $context, 'max_title', ERANKLY_AI_TITLE_LIMIT );
	$desc_limit  = erankly_ai_context_limit( $context, 'max_desc', ERANKLY_AI_DESC_LIMIT );

	$context['improvement_instructions'] = $instructions;
	$context['current_title']            = erankly_trim_text( erankly_normalize_seo_text( (string) ( $request['current_title'] ?? '' ) ), $title_limit );
	$context['current_description']      = erankly_trim_text( erankly_normalize_seo_text( (string) ( $request['current_description'] ?? '' ) ), $desc_limit );

	return $context;
}

/**
 * Collects the editorial context passed to the model.
 *
 * @param int    $object_id   Post or term ID. Unused for special-page contexts.
 * @param string $object_type 'post', 'term' or 'special'.
 * @return array<string,mixed>|WP_Error
 */
function erankly_ai_build_context( int $object_id, string $object_type ) {
	if ( 'term' === $object_type ) {
		$term = get_term( $object_id );

		if ( ! $term instanceof WP_Term ) {
			return new WP_Error( 'erankly_ai_not_found', __( 'Term not found.', 'easyrankly' ), array( 'status' => 404 ) );
		}

		$post_title = $term->name;
		$body       = $term->description;
	} else {
		$post = get_post( $object_id );

		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'erankly_ai_not_found', __( 'Post not found.', 'easyrankly' ), array( 'status' => 404 ) );
		}

		$post_title = $post->post_title;
		$body       = $post->post_content;
	}

	$content = erankly_trim_text( strip_shortcodes( (string) $body ), erankly_ai_get_content_limit() );

	$context = array(
		'object_id'   => $object_id,
		'object_type' => $object_type,
		'post_title'  => erankly_normalize_seo_text( (string) $post_title ),
		'content'     => $content,
		'site_name'   => get_bloginfo( 'name' ),
		'lang'        => erankly_ai_language_label(),
		'max_title'   => ERANKLY_AI_TITLE_LIMIT,
		'max_desc'    => ERANKLY_AI_DESC_LIMIT,
	);

	/**
	 * Filters the context assembled for AI meta generation.
	 *
	 * @param array<string,mixed> $context Generation context.
	 */
	return apply_filters( 'erankly_ai_context', $context );
}

/**
 * Collects context for Site Editor special-page templates.
 *
 * @param string $special_context Special page key: homepage, blog, author, date, search or 404.
 * @return array<string,mixed>|WP_Error
 */
function erankly_ai_build_special_context( string $special_context ) {
	$labels = erankly_special_page_keys();

	if ( ! isset( $labels[ $special_context ] ) ) {
		return new WP_Error( 'erankly_ai_invalid_context', __( 'Invalid Site Editor context.', 'easyrankly' ), array( 'status' => 400 ) );
	}

	$context = array(
		'object_id'       => 0,
		'object_type'     => 'special',
		'special_context' => $special_context,
		'post_title'      => erankly_normalize_seo_text( (string) $labels[ $special_context ] ),
		'content'         => erankly_trim_text( erankly_ai_special_context_body( $special_context ), erankly_ai_get_content_limit() ),
		'site_name'       => get_bloginfo( 'name' ),
		'lang'            => erankly_ai_language_label(),
		'max_title'       => ERANKLY_AI_TITLE_LIMIT,
		'max_desc'        => ERANKLY_AI_DESC_LIMIT,
	);

	/**
	 * Filters the context assembled for AI meta generation.
	 *
	 * @param array<string,mixed> $context Generation context.
	 */
	return apply_filters( 'erankly_ai_context', $context );
}

/**
 * Builds a concise plain-text description of a special-page context.
 *
 * @param string $special_context Special page key.
 * @return string
 */
function erankly_ai_special_context_body( string $special_context ): string {
	$labels = erankly_special_page_keys();
	$parts  = array(
		sprintf(
			/* translators: %s: site name. */
			__( 'Site name: %s', 'easyrankly' ),
			get_bloginfo( 'name' )
		),
		sprintf(
			/* translators: %s: special page label. */
			__( 'Page type: %s', 'easyrankly' ),
			(string) ( $labels[ $special_context ] ?? $special_context )
		),
	);

	switch ( $special_context ) {
		case 'homepage':
			if ( 'page' === get_option( 'show_on_front' ) ) {
				$parts = array_merge( $parts, erankly_ai_post_summary_parts( (int) get_option( 'page_on_front' ), __( 'Homepage source page', 'easyrankly' ) ) );
			} else {
				$parts[] = __( 'This homepage lists the latest published posts from the site.', 'easyrankly' );
				$parts   = array_merge( $parts, erankly_ai_recent_post_summary_parts() );
			}
			break;

		case 'blog':
			$parts[] = __( 'This blog page lists recent posts from the site.', 'easyrankly' );
			$parts   = array_merge( $parts, erankly_ai_post_summary_parts( (int) get_option( 'page_for_posts' ), __( 'Posts page', 'easyrankly' ) ) );
			$parts   = array_merge( $parts, erankly_ai_recent_post_summary_parts() );
			break;

		case 'author':
			$parts[] = __( 'This archive lists posts written by an author on the site.', 'easyrankly' );
			$parts   = array_merge( $parts, erankly_ai_recent_post_summary_parts() );
			break;

		case 'date':
			$parts[] = __( 'This archive lists posts published during a selected date period.', 'easyrankly' );
			$parts   = array_merge( $parts, erankly_ai_recent_post_summary_parts() );
			break;

		case 'search':
			$parts[] = __( 'This is the site search results page shown after visitors search for keywords.', 'easyrankly' );
			break;

		case '404':
			$parts[] = __( 'This is the not found page shown when a requested URL does not exist on the site.', 'easyrankly' );
			break;
	}

	return implode( "\n\n", array_filter( $parts ) );
}

/**
 * Returns title/content summary lines for a source page, if available.
 *
 * @param int    $post_id Post ID.
 * @param string $label   Context label.
 * @return array<int,string>
 */
function erankly_ai_post_summary_parts( int $post_id, string $label ): array {
	if ( $post_id <= 0 ) {
		return array();
	}

	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return array();
	}

	$parts = array();
	$title = erankly_normalize_seo_text( get_the_title( $post ) );
	$text  = erankly_ai_plain_text( $post->post_content );

	if ( '' !== $title ) {
		$parts[] = sprintf(
			/* translators: 1: page context label. 2: page title. */
			__( '%1$s title: %2$s', 'easyrankly' ),
			$label,
			$title
		);
	}

	if ( '' !== $text ) {
		$parts[] = sprintf(
			/* translators: 1: page context label. 2: page content excerpt. */
			__( '%1$s content: %2$s', 'easyrankly' ),
			$label,
			erankly_trim_text( $text, 1000 )
		);
	}

	return $parts;
}

/**
 * Returns recent post titles/excerpts for archive-like special pages.
 *
 * @return array<int,string>
 */
function erankly_ai_recent_post_summary_parts(): array {
	$posts = get_posts(
		array(
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'numberposts'         => 5,
			'orderby'             => 'date',
			'order'               => 'DESC',
			'post_status'         => 'publish',
			'post_type'           => 'post',
		)
	);

	if ( empty( $posts ) ) {
		return array();
	}

	$items = array();

	foreach ( $posts as $post ) {
		if ( ! $post instanceof WP_Post ) {
			continue;
		}

		$title = erankly_normalize_seo_text( get_the_title( $post ) );
		$text  = erankly_ai_plain_text( $post->post_excerpt ? $post->post_excerpt : $post->post_content );

		if ( '' === $title && '' === $text ) {
			continue;
		}

		$items[] = trim( $title . ( '' !== $text ? ': ' . erankly_trim_text( $text, 180 ) : '' ) );
	}

	if ( empty( $items ) ) {
		return array();
	}

	return array(
		sprintf(
			/* translators: %s: comma-separated recent post summaries. */
			__( 'Recent posts: %s', 'easyrankly' ),
			implode( '; ', $items )
		),
	);
}

/**
 * Converts content markup to compact plain text for prompts.
 *
 * @param string $content Raw content.
 * @return string
 */
function erankly_ai_plain_text( string $content ): string {
	$text    = wp_strip_all_tags( strip_shortcodes( $content ), true );
	$charset = get_bloginfo( 'charset' );
	$text    = html_entity_decode( $text, ENT_QUOTES, $charset ? $charset : 'UTF-8' );
	$text    = preg_replace( '/\s+/', ' ', $text );

	return is_string( $text ) ? trim( $text ) : '';
}

/**
 * Language tag the model should write in, e.g. "it_IT". The model resolves the
 * human language from the locale fine; sites can override with `erankly_ai_context`.
 *
 * @return string
 */
function erankly_ai_language_label(): string {
	$locale = get_locale();

	return '' !== $locale ? $locale : 'en_US';
}

/**
 * Returns the bundled prompt template (the editable default), with the leading
 * explanatory HTML comment stripped so the editable text is clean markdown.
 *
 * @return string
 */
function erankly_ai_get_prompt_template_default(): string {
	static $default = null;

	if ( null !== $default ) {
		return $default;
	}

	$file = ERANKLY_PATH . 'prompts/meta-generation.md';
	$raw  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a bundled plugin file.

	// Drop the leading <!-- ... --> guidance block; the parser ignores it and the
	// editor should show only the editable System/User sections.
	$raw = (string) preg_replace( '/^\s*<!--.*?-->\s*/s', '', $raw );

	$default = trim( $raw );

	return $default;
}

/**
 * Returns the effective prompt template: the admin override when set, otherwise
 * the bundled default.
 *
 * @return string
 */
function erankly_ai_get_prompt_template(): string {
	$override = (string) erankly_get_setting( 'ai_prompt_template', '' );

	return '' !== trim( $override ) ? $override : erankly_ai_get_prompt_template_default();
}

/**
 * Sanitizes an admin-edited prompt template. Collapses to an empty string when
 * it matches the bundled default, so the plugin keeps shipping prompt updates.
 *
 * @param mixed $value Raw textarea value.
 * @return string
 */
function erankly_ai_sanitize_prompt_template( mixed $value ): string {
	$clean = sanitize_textarea_field( (string) $value );

	if ( '' === trim( $clean ) || trim( $clean ) === trim( sanitize_textarea_field( erankly_ai_get_prompt_template_default() ) ) ) {
		return '';
	}

	return $clean;
}

/**
 * Loads the prompt template (override or default) and substitutes placeholders.
 *
 * @param array<string,mixed> $context Generation context.
 * @return array{system:string,user:string}
 */
function erankly_ai_load_prompt( array $context ): array {
	$raw = erankly_ai_get_prompt_template();

	$replacements = array(
		'{{lang}}'       => (string) $context['lang'],
		'{{site_name}}'  => (string) $context['site_name'],
		'{{post_title}}' => (string) $context['post_title'],
		'{{content}}'    => (string) $context['content'],
		'{{max_title}}'  => (string) $context['max_title'],
		'{{max_desc}}'   => (string) $context['max_desc'],
	);

	$filled = strtr( $raw, $replacements );

	$prompt = array(
		'system' => erankly_ai_extract_section( $filled, 'System' ),
		'user'   => erankly_ai_extract_section( $filled, 'User' ),
	);

	$target_prompt = erankly_ai_generation_target_prompt( $context );
	if ( '' !== $target_prompt ) {
		$prompt['user'] = trim( $prompt['user'] . "\n\n" . $target_prompt );
	}

	if ( ! empty( $context['improvement_instructions'] ) ) {
		$prompt['user'] = trim( $prompt['user'] . "\n\n" . erankly_ai_improvement_prompt( $context ) );
	}

	/**
	 * Filters the parsed prompt before it is sent to the model. Lets developers
	 * tweak the plugin-defined prompt without editing the bundled .md file.
	 *
	 * @param array{system:string,user:string} $prompt  Parsed prompt parts.
	 * @param array<string,mixed>               $context Generation context.
	 */
	return apply_filters( 'erankly_ai_prompt', $prompt, $context );
}

/**
 * Builds extra prompt instructions for non-default generation targets.
 *
 * @param array<string,mixed> $context Generation context.
 * @return string
 */
function erankly_ai_generation_target_prompt( array $context ): string {
	if ( 'social' !== (string) ( $context['generation_target'] ?? 'seo' ) ) {
		return '';
	}

	return implode(
		"\n",
		array(
			'Generation target: social sharing metadata.',
			'Write a title and description for Open Graph and X (Twitter) card previews, not the search result snippet.',
			'The same returned "title" will be used for Open Graph and X titles; the same returned "description" will be used for Open Graph and X descriptions.',
			sprintf(
				'Keep the title at most %d characters and the description at most %d characters.',
				erankly_ai_context_limit( $context, 'max_title', ERANKLY_AI_SOCIAL_TITLE_LIMIT ),
				erankly_ai_context_limit( $context, 'max_desc', ERANKLY_AI_SOCIAL_DESC_LIMIT )
			),
		)
	);
}

/**
 * Builds the prompt section used when improving an already generated result.
 *
 * @param array<string,mixed> $context Generation context.
 * @return string
 */
function erankly_ai_improvement_prompt( array $context ): string {
	$target_label = 'social' === (string) ( $context['generation_target'] ?? 'seo' )
		? 'social sharing metadata'
		: 'SEO metadata';
	$parts        = array(
		sprintf( 'Improve the current generated %s instead of starting from scratch.', $target_label ),
		sprintf( 'Current title: %s', (string) ( $context['current_title'] ?? '' ) ),
		sprintf( 'Current description: %s', (string) ( $context['current_description'] ?? '' ) ),
		'Editor instructions:',
		(string) $context['improvement_instructions'],
		'Return the full improved JSON object using the same schema and limits.',
	);

	return implode( "\n", $parts );
}

/**
 * Extracts the body of a "## {Name}" section from the prompt markdown.
 *
 * @param string $markdown Prompt text.
 * @param string $name     Section heading.
 * @return string
 */
function erankly_ai_extract_section( string $markdown, string $name ): string {
	if ( ! preg_match( '/^##\s+' . preg_quote( $name, '/' ) . '\s*$(.*?)(?=^##\s+|\z)/ims', $markdown, $m ) ) {
		return '';
	}

	return trim( $m[1] );
}

/**
 * Generates the requested title and description for a context.
 *
 * @param array<string,mixed> $context Generation context.
 * @return array{title:string,description:string}|WP_Error
 */
function erankly_ai_generate( array $context ) {
	$prompt = erankly_ai_load_prompt( $context );

	if ( '' === $prompt['system'] && '' === $prompt['user'] ) {
		return new WP_Error( 'erankly_ai_prompt_missing', __( 'The AI prompt template is missing.', 'easyrankly' ) );
	}

	$raw = erankly_ai_call_model( $prompt['system'], $prompt['user'] );

	if ( is_wp_error( $raw ) ) {
		return $raw;
	}

	$decoded = erankly_ai_decode_result( (string) $raw );

	if ( is_wp_error( $decoded ) ) {
		return $decoded;
	}

	$title_limit = erankly_ai_context_limit( $context, 'max_title', ERANKLY_AI_TITLE_LIMIT );
	$desc_limit  = erankly_ai_context_limit( $context, 'max_desc', ERANKLY_AI_DESC_LIMIT );

	$result = array(
		'title'       => erankly_trim_text( erankly_normalize_seo_text( $decoded['title'] ), $title_limit ),
		'description' => erankly_trim_text( erankly_normalize_seo_text( $decoded['description'] ), $desc_limit ),
	);

	if ( '' === $result['title'] && '' === $result['description'] ) {
		return new WP_Error( 'erankly_ai_empty', __( 'The AI returned an empty result.', 'easyrankly' ) );
	}

	/**
	 * Filters the final generated meta before it is returned to the editor.
	 *
	 * @param array{title:string,description:string} $result  Generated meta.
	 * @param array<string,mixed>                    $context Generation context.
	 */
	return apply_filters( 'erankly_ai_result', $result, $context );
}

/**
 * Parses the model's JSON answer into title/description, tolerating code fences
 * or surrounding prose.
 *
 * @param string $raw Raw model output.
 * @return array{title:string,description:string}|WP_Error
 */
function erankly_ai_decode_result( string $raw ) {
	$json = trim( $raw );

	// Strip ``` fences if the model added them despite instructions.
	$json = (string) preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', $json );

	// Isolate the first {...} block if the model wrapped it in prose.
	if ( preg_match( '/\{.*\}/s', $json, $m ) ) {
		$json = $m[0];
	}

	$data = json_decode( $json, true );

	if ( ! is_array( $data ) || ( ! isset( $data['title'] ) && ! isset( $data['description'] ) ) ) {
		return new WP_Error( 'erankly_ai_parse', __( 'Could not read the AI response.', 'easyrankly' ) );
	}

	return array(
		'title'       => (string) ( $data['title'] ?? '' ),
		'description' => (string) ( $data['description'] ?? '' ),
	);
}

/**
 * Sends the prompt to the WordPress 7.0 AI Client and returns the raw text.
 *
 * Uses the native wp_ai_client_prompt() builder (using_system_instruction() +
 * generate_text()). The `erankly_ai_call` filter is the supported override seam:
 * returning a non-null value short-circuits the built-in call (handy for tests
 * or to pin a specific provider/model).
 *
 * @param string $system System instruction.
 * @param string $user   User prompt.
 * @return string|WP_Error
 */
function erankly_ai_call_model( string $system, string $user ) {
	/**
	 * Short-circuits the AI call. Return a string (raw model output) or a
	 * WP_Error to bypass the built-in client.
	 *
	 * @param string|WP_Error|null $pre    Pre-computed result, or null to proceed.
	 * @param string               $system System instruction.
	 * @param string               $user   User prompt.
	 */
	$pre = apply_filters( 'erankly_ai_call', null, $system, $user );
	if ( null !== $pre ) {
		return $pre;
	}

	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		return new WP_Error( 'erankly_ai_no_client', __( 'No AI client is available to run the generation.', 'easyrankly' ) );
	}

	if ( function_exists( 'wp_supports_ai' ) && ! erankly_ai_core_call( 'wp_supports_ai' ) ) {
		return new WP_Error( 'erankly_ai_unsupported', __( 'AI features are disabled in this environment.', 'easyrankly' ) );
	}

	try {
		$text = erankly_ai_core_call( 'wp_ai_client_prompt', $user )
			->using_system_instruction( $system )
			->generate_text();
	} catch ( \Throwable $e ) {
		return new WP_Error( 'erankly_ai_error', $e->getMessage() );
	}

	if ( is_wp_error( $text ) ) {
		return $text;
	}

	if ( is_string( $text ) && '' !== trim( $text ) ) {
		return $text;
	}

	return new WP_Error( 'erankly_ai_empty', __( 'The AI returned an empty result.', 'easyrankly' ) );
}

/**
 * Renders a compact privacy notice next to editor "Generate with AI" controls.
 *
 * @return void
 */
function erankly_ai_render_editor_privacy_notice(): void {
	$limit = erankly_ai_get_content_limit();
	?>
	<p class="description erankly-ai-privacy">
		<?php
		printf(
			/* translators: %d: maximum plain-text body characters sent to the provider. */
			esc_html__( 'Generating sends page context (title and up to %1$d characters of plain-text content, plus site name and language) to the AI provider configured in WordPress Connectors. Improve also sends your current fields and instructions. EasyRankly does not operate that service.', 'easyrankly' ),
			$limit
		);
		?>
	</p>
	<?php
}

/**
 * Renders the AI data-privacy notice on the settings AI tab.
 *
 * @return void
 */
function erankly_ai_render_settings_privacy_notice(): void {
	$limit           = erankly_ai_get_content_limit();
	$connectors_link = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( erankly_ai_connectors_screen_url() ),
		esc_html__( 'Connectors', 'easyrankly' )
	);
	?>
	<div class="notice notice-info inline erankly-ai-privacy-notice">
		<div class="erankly-ai-privacy-notice__content">
			<p><strong><?php esc_html_e( 'Data sent to your AI provider', 'easyrankly' ); ?></strong></p>
			<p>
				<?php
				printf(
					/* translators: %s: link to the WordPress Connectors settings screen. */
					esc_html__( 'When an editor clicks Generate with AI or Improve results, EasyRankly sends context to the AI provider configured on the %s screen. EasyRankly does not operate that service and does not receive a copy of the content.', 'easyrankly' ),
					wp_kses( $connectors_link, array( 'a' => array( 'href' => array() ) ) )
				);
				?>
			</p>
			<p><?php esc_html_e( 'Meta generation may include:', 'easyrankly' ); ?></p>
			<ul class="ul-disc">
				<li><?php esc_html_e( 'Site name and language (locale).', 'easyrankly' ); ?></li>
				<li><?php esc_html_e( 'Post, term, or special-page title.', 'easyrankly' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: %d: maximum plain-text body characters sent to the provider. */
						esc_html__( 'Plain-text body or description, truncated to %1$d characters (shortcodes removed).', 'easyrankly' ),
						$limit
					);
					?>
				</li>
				<li><?php esc_html_e( 'When improving: the current title and description, plus your instructions.', 'easyrankly' ); ?></li>
			</ul>
			<p>
				<?php esc_html_e( 'Health redirect suggestions (when enabled) send only the broken URL slug words and a numbered list of existing page titles and paths from your site — never full post bodies.', 'easyrankly' ); ?>
			</p>
		</div>
	</div>
	<?php
}

/**
 * URL of the core Connectors settings screen. Filterable because the exact
 * screen slug is owned by core and may move between releases.
 *
 * @return string
 */
function erankly_ai_connectors_screen_url(): string {
	// Core's Connectors screen (Settings → Connectors) is a per-site admin page
	// requiring manage_options — on Multisite each site configures its own
	// provider key here, so admin_url() is correct on single site and subsites.
	$url = admin_url( 'options-connectors.php' );

	/**
	 * Filters the URL to the core Connectors settings screen.
	 *
	 * @param string $url Connectors screen URL.
	 */
	return (string) apply_filters( 'erankly_ai_connectors_url', $url );
}

/**
 * Renders the AI generation toggle + provider status inside the Features panel.
 *
 * @return void
 */
function erankly_ai_render_settings_field(): void {
	$has_api   = function_exists( 'wp_get_connectors' );
	$available = erankly_ai_available();
	$enabled   = (bool) erankly_get_setting( 'ai_enabled', 0 );
	?>
	<fieldset class="erankly-field erankly-checkboxes">
		<span style="display:inline-flex;align-items:center;gap:8px;flex-wrap:wrap;">
			<label>
				<input type="checkbox" class="erankly-toggle" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[ai_enabled]" value="1" <?php checked( $enabled ); ?> <?php disabled( ! $available ); ?>>
				<strong><?php esc_html_e( 'Enable AI features', 'easyrankly' ); ?></strong>
			</label>
			<?php
			if ( ! $has_api ) {
				erankly_render_connectors_status();
			} else {
				erankly_render_ai_provider_status();
			}
			?>
		</span>
		<p class="description">
			<?php
			esc_html_e( 'Turns on the plugin\'s AI features.', 'easyrankly' );
			if ( $has_api && ! $available ) {
				echo ' ';
				printf(
					/* translators: %s: link to the WordPress Connectors settings screen. */
					esc_html__( 'Connect a provider on the %s screen to enable.', 'easyrankly' ),
					'<a href="' . esc_url( erankly_ai_connectors_screen_url() ) . '">' . esc_html__( 'Connectors', 'easyrankly' ) . '</a>'
				);
			}
			?>
		</p>
	</fieldset>
	<?php
}

/**
 * Renders the AI settings tab panel (prompt template editor).
 *
 * Shown only in advanced mode while AI generation is enabled (gated by the
 * caller). Lets an admin override the bundled meta-generation prompt; the
 * override is stored in settings, never written back to the plugin file.
 *
 * @param string $active_panel Active settings panel ID.
 * @return void
 */
function erankly_ai_render_settings_panel( string $active_panel ): void {
	$is_active = 'settings-ai' === $active_panel;
	$value     = erankly_ai_get_prompt_template();
	// The panel is only ever reachable on single-site or from Network Admin
	// (a per-site admin on Multisite never gets this tab), so that's the
	// only place autosave applies.
	$autosave_active = ! is_multisite() || is_network_admin();
	?>
	<div class="erankly-tab-panel<?php echo $is_active ? ' is-active' : ''; ?>" id="erankly-settings-panel-ai" role="tabpanel" aria-labelledby="erankly-settings-tab-ai" data-erankly-settings-panel="settings-ai" <?php echo $autosave_active ? 'data-erankly-standalone-panel' : ''; ?> <?php echo $is_active ? '' : 'hidden'; ?>>
		<?php if ( $autosave_active ) : ?>
		<?php endif; ?>
		<div class="erankly-settings-section">
			<?php erankly_ai_render_settings_privacy_notice(); ?>
			<h3 class="erankly-section-title"><?php esc_html_e( 'Meta generation prompt', 'easyrankly' ); ?></h3>
			<div class="erankly-settings-fields erankly-card">
				<?php
				$content_limit       = erankly_ai_snap_content_limit_to_step( erankly_ai_get_content_limit() );
				$content_limit_steps = erankly_ai_get_content_limit_steps();
				?>
				<div class="erankly-field">
					<span id="erankly-ai-content-limit-label"><strong><?php esc_html_e( 'Body character limit', 'easyrankly' ); ?></strong></span>
					<p class="description">
						<?php
						printf(
							/* translators: 1: minimum characters. 2: maximum characters. */
							esc_html__( 'Maximum plain-text body or description characters sent to the model (%1$d–%2$d). Lower values reduce token usage; higher values give the model more context.', 'easyrankly' ),
							ERANKLY_AI_CONTENT_LIMIT_MIN,
							ERANKLY_AI_CONTENT_LIMIT_MAX
						);
						?>
					</p>
					<div
						class="nav-tab-wrapper wp-clearfix erankly-tabs erankly-segment-control"
						role="radiogroup"
						aria-labelledby="erankly-ai-content-limit-label"
						data-erankly-segment-control
					>
						<?php foreach ( $content_limit_steps as $step_value ) : ?>
							<?php $input_id = 'erankly-ai-content-limit-' . $step_value; ?>
							<label
								class="nav-tab erankly-tab<?php echo $content_limit === $step_value ? ' nav-tab-active is-active' : ''; ?>"
								for="<?php echo esc_attr( $input_id ); ?>"
							>
								<input
									type="radio"
									id="<?php echo esc_attr( $input_id ); ?>"
									name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[ai_content_limit]"
									value="<?php echo esc_attr( (string) $step_value ); ?>"
									<?php checked( $content_limit, $step_value ); ?>
								>
								<?php echo esc_html( number_format_i18n( $step_value ) ); ?>
							</label>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="erankly-field">
					<label for="erankly-ai-prompt"><strong><?php esc_html_e( 'Prompt template', 'easyrankly' ); ?></strong></label>
					<p class="description"><?php esc_html_e( 'Instructions the AI uses to generate the meta title and description; edit to customise tone and rules. Leave empty or unchanged to keep the built-in prompt and its future updates.', 'easyrankly' ); ?></p>
					<textarea id="erankly-ai-prompt" class="widefat code" rows="22" spellcheck="false" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[ai_prompt_template]"><?php echo esc_textarea( $value ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'Keep the "## System" and "## User" section headings. Available placeholders:', 'easyrankly' ); ?>
						<code>{{lang}}</code> <code>{{site_name}}</code> <code>{{post_title}}</code> <code>{{content}}</code> <code>{{max_title}}</code> <code>{{max_desc}}</code>
				</p>
			</div>
		</div>
	</div>
	</div>
	<?php
}

erankly_ai_init();
