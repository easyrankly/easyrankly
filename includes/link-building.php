<?php
/**
 * Link Building module.
 *
 * Optional internal-linking assistant: builds a site-wide link graph and
 * surfaces AI-powered link suggestions in the post editor.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ERANKLY_PATH . 'includes/helpers/link-graph.php';

/** Maximum candidate pages sent to the AI model. */
define( 'ERANKLY_LB_AI_CANDIDATE_LIMIT', 30 );

/** Maximum outbound suggestions returned to the editor. */
define( 'ERANKLY_LB_AI_MAX_OUTBOUND', 5 );

/** Maximum inbound suggestions returned to the editor. */
define( 'ERANKLY_LB_AI_MAX_INBOUND', 5 );

/** Excerpt length for candidate pages in the AI prompt. */
define( 'ERANKLY_LB_AI_CANDIDATE_EXCERPT', 220 );

/** Excerpt length for the current page in the AI prompt. */
define( 'ERANKLY_LB_AI_CURRENT_EXCERPT', 1200 );

/** Transient key prefix for cached editor AI link suggestions. */
define( 'ERANKLY_LB_AI_EDITOR_PREFIX', 'erankly_lb_editor_' );

/**
 * Registers Link Building hooks.
 *
 * @return void
 */
function erankly_lb_boot(): void {
	add_action( 'rest_api_init', 'erankly_lb_register_rest_routes' );
	add_action( 'admin_enqueue_scripts', 'erankly_lb_register_editor_assets', 5 );
	add_action( 'admin_enqueue_scripts', 'erankly_lb_enqueue_editor_assets' );

	if ( is_admin() ) {
		add_action( 'admin_post_erankly_lb_rebuild_graph', 'erankly_lb_handle_rebuild_graph' );
	}
}

/**
 * Registers internal link suggestion assets.
 *
 * @return void
 */
function erankly_lb_register_editor_assets(): void {
	if ( ! erankly_internal_links_available() ) {
		return;
	}

	wp_register_script(
		'erankly-link-suggestions',
		ERANKLY_URL . 'assets/js/link-suggestions.js',
		array(
			'wp-api-fetch',
			'wp-components',
			'wp-element',
			'wp-i18n',
		),
		ERANKLY_VERSION,
		true
	);
	wp_set_script_translations( 'erankly-link-suggestions', 'easyrankly', ERANKLY_PATH . 'languages' );
}

/**
 * Enqueues the internal link suggestions UI for post editors.
 *
 * @param string $hook_suffix Admin hook.
 * @return void
 */
function erankly_lb_enqueue_editor_assets( string $hook_suffix ): void {
	if ( ! erankly_internal_links_available() ) {
		return;
	}

	if ( ! wp_script_is( 'erankly-link-suggestions', 'registered' ) ) {
		return;
	}

	$screen = get_current_screen();

	if ( ! $screen instanceof WP_Screen || ! in_array( $screen->base, array( 'post', 'page' ), true ) ) {
		return;
	}

	if ( ! in_array( $screen->post_type, array_keys( erankly_get_public_post_types() ), true ) ) {
		return;
	}

	wp_enqueue_script( 'erankly-link-suggestions' );

	erankly_enqueue_shared_styles();

	wp_enqueue_style(
		'erankly-editor',
		ERANKLY_URL . 'assets/css/editor.css',
		array( 'erankly-shared' ),
		ERANKLY_VERSION
	);

	wp_localize_script(
		'erankly-link-suggestions',
		'eranklyLinkSuggestions',
		array(
			'aiEnabled'  => erankly_internal_links_available(),
			'graphBuilt' => null !== erankly_lb_get_graph(),
			'i18n'       => array(
				'cached'        => __( 'Showing cached suggestions.', 'easyrankly' ),
				'editSource'    => __( 'Edit source', 'easyrankly' ),
				'empty'         => __( 'No strong link opportunities found.', 'easyrankly' ),
				'error'         => __( 'Could not generate link suggestions.', 'easyrankly' ),
				'inboundTitle'  => __( 'Add on other pages', 'easyrankly' ),
				'noneSuggested' => __( 'None suggested.', 'easyrankly' ),
				'openPage'      => __( 'Open page', 'easyrankly' ),
				'outboundTitle' => __( 'Add on this page', 'easyrankly' ),
				'saveDraft'     => __( 'Save a draft first.', 'easyrankly' ),
				'updated'       => __( 'Suggestions updated.', 'easyrankly' ),
				'working'       => __( 'Generating…', 'easyrankly' ),
			),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'restUrl'    => esc_url_raw( rest_url( 'erankly/v1/links/ai-suggestions' ) ),
		)
	);
}

/**
 * Registers REST routes for editor suggestions.
 *
 * @return void
 */
function erankly_lb_register_rest_routes(): void {
	register_rest_route(
		'erankly/v1',
		'/links/ai-suggestions',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'erankly_lb_rest_get_ai_suggestions',
				'permission_callback' => 'erankly_lb_rest_ai_permission',
				'args'                => erankly_lb_rest_ai_suggestion_args(),
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'erankly_lb_rest_generate_ai_suggestions',
				'permission_callback' => 'erankly_lb_rest_ai_permission',
				'args'                => erankly_lb_rest_ai_suggestion_args(),
			),
		)
	);
}

/**
 * REST argument schema for editor link suggestions.
 *
 * @return array<string,array<string,mixed>>
 */
function erankly_lb_rest_ai_suggestion_args(): array {
	return array(
		'post_id' => array(
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
		),
		'force'   => array(
			'type'              => 'boolean',
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		),
	);
}

/**
 * Permission check for link suggestion routes.
 *
 * @param WP_REST_Request $request Request.
 * @return bool|WP_Error
 */
function erankly_lb_rest_ai_permission( WP_REST_Request $request ) {
	if ( ! erankly_link_building_enabled() ) {
		return new WP_Error( 'erankly_lb_disabled', __( 'Internal link suggestions are not enabled.', 'easyrankly' ), array( 'status' => 403 ) );
	}

	if ( ! erankly_ai_module_enabled() ) {
		return new WP_Error( 'erankly_lb_ai_disabled', __( 'Enable AI features to use internal link suggestions.', 'easyrankly' ), array( 'status' => 403 ) );
	}

	if ( ! erankly_ai_provider_available() ) {
		return new WP_Error( 'erankly_lb_ai_unavailable', __( 'Connect an AI provider to use internal link suggestions.', 'easyrankly' ), array( 'status' => 403 ) );
	}

	$post_id = absint( $request['post_id'] );

	if ( $post_id < 1 || ! current_user_can( 'edit_post', $post_id ) ) {
		return new WP_Error( 'erankly_lb_forbidden', __( 'You cannot view link suggestions for this content.', 'easyrankly' ), array( 'status' => 403 ) );
	}

	return true;
}

/**
 * REST handler: returns cached editor link suggestions when available.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function erankly_lb_rest_get_ai_suggestions( WP_REST_Request $request ) {
	$post_id = absint( $request['post_id'] );
	$cached  = erankly_lb_get_cached_editor_suggestions( $post_id );

	return new WP_REST_Response(
		array(
			'post_id'     => $post_id,
			'cached'      => null !== $cached,
			'graph_built' => null !== erankly_lb_get_graph(),
			'inbound'     => $cached['inbound'] ?? array(),
			'outbound'    => $cached['outbound'] ?? array(),
		),
		200
	);
}

/**
 * REST handler: generates AI inbound/outbound link suggestions for a post.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function erankly_lb_rest_generate_ai_suggestions( WP_REST_Request $request ) {
	$post_id = absint( $request['post_id'] );
	$force   = rest_sanitize_boolean( $request['force'] ?? false );
	$result  = erankly_lb_ai_suggest_for_post( $post_id, $force );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	return new WP_REST_Response(
		array_merge(
			array(
				'post_id'     => $post_id,
				'cached'      => true,
				'graph_built' => null !== erankly_lb_get_graph(),
			),
			$result
		),
		200
	);
}

/**
 * Handles the admin "Rebuild link graph" action.
 *
 * @return void
 */
function erankly_lb_handle_rebuild_graph(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to rebuild the link graph.', 'easyrankly' ) );
	}

	check_admin_referer( 'erankly_lb_rebuild_graph' );
	erankly_lb_build_graph();

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'             => 'erankly',
				'erankly_tab'      => 'links',
				'erankly_lb_built' => '1',
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}

/**
 * Returns a plain-text excerpt of post content for AI prompts.
 *
 * @param WP_Post $post  Post object.
 * @param int     $limit Character limit.
 * @return string
 */
function erankly_lb_post_excerpt_for_ai( WP_Post $post, int $limit ): string {
	$source = has_excerpt( $post ) ? get_the_excerpt( $post ) : $post->post_content;

	return erankly_trim_text( strip_shortcodes( wp_strip_all_tags( (string) $source ) ), $limit );
}

/**
 * Returns taxonomy term IDs shared between two posts.
 *
 * @param int    $post_a   Post ID.
 * @param int    $post_b   Post ID.
 * @param string $taxonomy Taxonomy slug.
 * @return array<int,int>
 */
function erankly_lb_shared_term_ids( int $post_a, int $post_b, string $taxonomy ): array {
	$terms_a = wp_get_post_terms( $post_a, $taxonomy, array( 'fields' => 'ids' ) );
	$terms_b = wp_get_post_terms( $post_b, $taxonomy, array( 'fields' => 'ids' ) );

	if ( is_wp_error( $terms_a ) || is_wp_error( $terms_b ) ) {
		return array();
	}

	return array_intersect( array_map( 'intval', (array) $terms_a ), array_map( 'intval', (array) $terms_b ) );
}

/**
 * Scores topical relevance between two posts for editor suggestions.
 *
 * @param int                      $post_a Post ID.
 * @param int                      $post_b Post ID.
 * @param array<string,mixed>|null $graph  Optional link graph for orphan weighting.
 * @return float Score between 0 and 1, or -1 when disqualified.
 */
function erankly_lb_score_editor_relevance( int $post_a, int $post_b, ?array $graph = null ): float {
	if ( $post_a === $post_b ) {
		return -1.0;
	}

	$post_a_obj = get_post( $post_a );
	$post_b_obj = get_post( $post_b );

	if ( ! ( $post_a_obj instanceof WP_Post ) || ! ( $post_b_obj instanceof WP_Post ) ) {
		return -1.0;
	}

	$score = 0.0;

	if ( ! empty( erankly_lb_shared_term_ids( $post_a, $post_b, 'category' ) ) ) {
		$score += 0.35;
	}

	if ( taxonomy_exists( 'post_tag' ) && ! empty( erankly_lb_shared_term_ids( $post_a, $post_b, 'post_tag' ) ) ) {
		$score += 0.15;
	}

	similar_text( strtolower( $post_a_obj->post_title ), strtolower( $post_b_obj->post_title ), $title_pct );
	$score += min( 0.35, ( $title_pct / 100 ) * 0.35 );

	if ( null !== $graph && ! empty( $graph['posts'][ $post_b ]['is_orphan'] ) ) {
		$score += 0.1;
	}

	return min( 1.0, $score );
}

/**
 * Returns the effective link-suggestion prompt template.
 *
 * @return string
 */
function erankly_lb_ai_get_prompt_template(): string {
	$override = (string) erankly_get_setting( 'ai_link_suggestions_prompt_template', '' );

	return '' !== trim( $override ) ? $override : erankly_lb_ai_get_prompt_template_default();
}

/**
 * Sanitizes an admin-edited link-suggestion prompt template.
 *
 * @param mixed $value Raw textarea value.
 * @return string
 */
function erankly_lb_ai_sanitize_prompt_template( mixed $value ): string {
	$clean = sanitize_textarea_field( (string) $value );

	if ( '' === trim( $clean ) || trim( $clean ) === trim( sanitize_textarea_field( erankly_lb_ai_get_prompt_template_default() ) ) ) {
		return '';
	}

	return $clean;
}

/**
 * Returns the bundled link-suggestion prompt template.
 *
 * @return string
 */
function erankly_lb_ai_get_prompt_template_default(): string {
	static $default = null;

	if ( null !== $default ) {
		return $default;
	}

	$file = ERANKLY_PATH . 'prompts/link-suggestions.md';
	$raw  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Bundled plugin file.

	$raw = (string) preg_replace( '/^\s*<!--.*?-->\s*/s', '', $raw );

	$default = trim( $raw );

	return $default;
}

/**
 * Loads and fills the link-suggestion AI prompt.
 *
 * @param array<string,mixed> $context Prompt context.
 * @return array{system:string,user:string}
 */
function erankly_lb_ai_load_prompt( array $context ): array {
	$replacements = array(
		'{{lang}}'              => (string) ( $context['lang'] ?? '' ),
		'{{site_name}}'         => (string) ( $context['site_name'] ?? '' ),
		'{{current_title}}'     => (string) ( $context['current_title'] ?? '' ),
		'{{current_path}}'      => (string) ( $context['current_path'] ?? '' ),
		'{{current_excerpt}}'   => (string) ( $context['current_excerpt'] ?? '' ),
		'{{existing_outbound}}' => (string) ( $context['existing_outbound'] ?? '' ),
		'{{inbound_count}}'     => (string) ( $context['inbound_count'] ?? '0' ),
		'{{candidate_pages}}'   => (string) ( $context['candidate_pages'] ?? '' ),
		'{{max_outbound}}'      => (string) ( $context['max_outbound'] ?? ERANKLY_LB_AI_MAX_OUTBOUND ),
		'{{max_inbound}}'       => (string) ( $context['max_inbound'] ?? ERANKLY_LB_AI_MAX_INBOUND ),
	);

	$filled = strtr( erankly_lb_ai_get_prompt_template(), $replacements );

	$prompt = array(
		'system' => function_exists( 'erankly_ai_extract_section' )
			? erankly_ai_extract_section( $filled, 'System' )
			: '',
		'user'   => function_exists( 'erankly_ai_extract_section' )
			? erankly_ai_extract_section( $filled, 'User' )
			: '',
	);

	/** @var array{system:string,user:string} $prompt */
	return apply_filters( 'erankly_lb_ai_prompt', $prompt, $context );
}

/**
 * Returns a cache key for editor link suggestions.
 *
 * @param int $post_id Post ID.
 * @return string
 */
function erankly_lb_editor_cache_key( int $post_id ): string {
	$post = get_post( $post_id );

	if ( ! $post instanceof WP_Post ) {
		return ERANKLY_LB_AI_EDITOR_PREFIX . $post_id;
	}

	return ERANKLY_LB_AI_EDITOR_PREFIX . $post_id . '_' . md5( (string) $post->post_modified_gmt );
}

/**
 * Reads cached editor suggestions for a post.
 *
 * @param int $post_id Post ID.
 * @return array{inbound:array<int,array<string,mixed>>,outbound:array<int,array<string,mixed>>}|null
 */
function erankly_lb_get_cached_editor_suggestions( int $post_id ): ?array {
	$cached = get_transient( erankly_lb_editor_cache_key( $post_id ) );

	if ( ! is_array( $cached ) ) {
		return null;
	}

	return array(
		'inbound'  => isset( $cached['inbound'] ) && is_array( $cached['inbound'] ) ? $cached['inbound'] : array(),
		'outbound' => isset( $cached['outbound'] ) && is_array( $cached['outbound'] ) ? $cached['outbound'] : array(),
	);
}

/**
 * Stores editor link suggestions in a transient.
 *
 * @param int   $post_id Post ID.
 * @param array $payload Suggestion payload.
 * @return void
 */
function erankly_lb_cache_editor_suggestions( int $post_id, array $payload ): void {
	$ttl = (int) apply_filters( 'erankly_link_building_suggestion_ttl', 12 * HOUR_IN_SECONDS );

	set_transient(
		erankly_lb_editor_cache_key( $post_id ),
		array(
			'inbound'  => $payload['inbound'] ?? array(),
			'outbound' => $payload['outbound'] ?? array(),
		),
		max( MINUTE_IN_SECONDS, $ttl )
	);
}

/**
 * Returns post IDs the current page already links to.
 *
 * @param int                 $post_id Current post ID.
 * @param array<string,mixed> $graph   Link graph.
 * @return array<int,int>
 */
function erankly_lb_existing_outbound_targets( int $post_id, array $graph ): array {
	$targets = array();
	$meta    = $graph['posts'][ $post_id ] ?? array();
	$links   = isset( $meta['outbound'] ) && is_array( $meta['outbound'] ) ? $meta['outbound'] : array();

	foreach ( $links as $link ) {
		$target_id = (int) ( $link['to'] ?? 0 );

		if ( $target_id > 0 ) {
			$targets[ $target_id ] = $target_id;
		}
	}

	return $targets;
}

/**
 * Formats existing outbound links for the AI prompt.
 *
 * @param int                 $post_id Current post ID.
 * @param array<string,mixed> $graph   Link graph.
 * @return string
 */
function erankly_lb_format_existing_outbound( int $post_id, array $graph ): string {
	$meta  = $graph['posts'][ $post_id ] ?? array();
	$links = isset( $meta['outbound'] ) && is_array( $meta['outbound'] ) ? $meta['outbound'] : array();

	if ( empty( $links ) ) {
		return __( 'none', 'easyrankly' );
	}

	$lines = array();

	foreach ( $links as $link ) {
		$target_id = (int) ( $link['to'] ?? 0 );
		$target    = get_post( $target_id );

		if ( ! ( $target instanceof WP_Post ) ) {
			continue;
		}

		$path = erankly_lb_normalize_link_path( (string) get_permalink( $target ) );

		if ( '' === $path ) {
			continue;
		}

		$lines[] = $path . ' (' . get_the_title( $target ) . ')';
	}

	return empty( $lines ) ? __( 'none', 'easyrankly' ) : implode( '; ', $lines );
}

/**
 * Builds the candidate page pool for editor AI suggestions.
 *
 * @param int                 $post_id Current post ID.
 * @param array<string,mixed> $graph   Link graph.
 * @return array<int,array{post_id:int,path:string,title:string,excerpt:string,score:float}>
 */
function erankly_lb_editor_candidate_pool( int $post_id, array $graph ): array {
	if ( empty( $graph['posts'] ) ) {
		return array();
	}

	$existing_outbound = erankly_lb_existing_outbound_targets( $post_id, $graph );
	$existing_inbound  = erankly_lb_graph_inbound_sources( $graph, $post_id );
	$candidates        = array();

	foreach ( array_keys( $graph['posts'] ) as $candidate_id ) {
		$candidate_id = (int) $candidate_id;

		if ( $candidate_id === $post_id ) {
			continue;
		}

		$candidate_post = get_post( $candidate_id );

		if ( ! ( $candidate_post instanceof WP_Post ) || 'publish' !== $candidate_post->post_status ) {
			continue;
		}

		$permalink = get_permalink( $candidate_post );
		$path      = $permalink ? erankly_lb_normalize_link_path( $permalink ) : '';

		if ( '' === $path ) {
			continue;
		}

		$out_score = erankly_lb_score_editor_relevance( $post_id, $candidate_id, $graph );
		$in_score  = erankly_lb_score_editor_relevance( $candidate_id, $post_id, $graph );

		if ( isset( $existing_outbound[ $candidate_id ] ) ) {
			$out_score = -1.0;
		}

		if ( isset( $existing_inbound[ $candidate_id ] ) ) {
			$in_score = -1.0;
		}

		$score = max( $out_score, $in_score );

		if ( $score < 0.05 ) {
			continue;
		}

		$candidates[] = array(
			'excerpt' => erankly_lb_post_excerpt_for_ai( $candidate_post, ERANKLY_LB_AI_CANDIDATE_EXCERPT ),
			'path'    => $path,
			'post_id' => $candidate_id,
			'score'   => $score,
			'title'   => get_the_title( $candidate_post ),
		);
	}

	usort(
		$candidates,
		static function ( array $a, array $b ): int {
			return ( $b['score'] <=> $a['score'] ) ?: strcmp( (string) $a['title'], (string) $b['title'] );
		}
	);

	$pool = array_slice( $candidates, 0, ERANKLY_LB_AI_CANDIDATE_LIMIT );

	return apply_filters( 'erankly_link_building_editor_candidate_pool', $pool, $post_id, $graph );
}

/**
 * Formats candidate pages for the AI prompt.
 *
 * @param array<int,array{post_id:int,path:string,title:string,excerpt:string,score:float}> $pool Candidates.
 * @return string
 */
function erankly_lb_format_candidate_pages_for_prompt( array $pool ): string {
	$lines = array();

	foreach ( $pool as $index => $candidate ) {
		$lines[] = sprintf(
			"%d. %s\n   Path: %s\n   Excerpt: %s",
			$index + 1,
			(string) $candidate['title'],
			(string) $candidate['path'],
			'' !== (string) $candidate['excerpt'] ? (string) $candidate['excerpt'] : __( '(no excerpt)', 'easyrankly' )
		);
	}

	return implode( "\n\n", $lines );
}

/**
 * Maps a normalized path to a candidate row.
 *
 * @param string                                                                              $path Requested path.
 * @param array<int,array{post_id:int,path:string,title:string,excerpt:string,score:float}> $pool Candidate pool.
 * @return array{post_id:int,path:string,title:string,excerpt:string,score:float}|null
 */
function erankly_lb_match_candidate_path( string $path, array $pool ): ?array {
	$needle = strtolower( untrailingslashit( $path ) );

	foreach ( $pool as $candidate ) {
		$candidate_path = strtolower( untrailingslashit( (string) $candidate['path'] ) );

		if ( $candidate_path === $needle ) {
			return $candidate;
		}
	}

	return null;
}

/**
 * Parses AI editor link suggestions and validates them against the candidate pool.
 *
 * @param string                                                                              $raw  Model output.
 * @param array<int,array{post_id:int,path:string,title:string,excerpt:string,score:float}> $pool Candidate pool.
 * @return array{inbound:array<int,array<string,mixed>>,outbound:array<int,array<string,mixed>>}
 */
function erankly_lb_ai_parse_editor_suggestions( string $raw, array $pool ): array {
	$json = trim( $raw );
	$json = (string) preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', $json );

	if ( preg_match( '/\{.*\}/s', $json, $matches ) ) {
		$json = $matches[0];
	}

	$data   = json_decode( $json, true );
	$result = array(
		'inbound'  => array(),
		'outbound' => array(),
	);

	if ( ! is_array( $data ) ) {
		return $result;
	}

	foreach ( (array) ( $data['outbound'] ?? array() ) as $row ) {
		if ( ! is_array( $row ) || empty( $row['path'] ) ) {
			continue;
		}

		$match = erankly_lb_match_candidate_path( (string) $row['path'], $pool );

		if ( null === $match ) {
			continue;
		}

		$confidence = isset( $row['confidence'] ) && in_array( $row['confidence'], array( 'high', 'medium', 'low' ), true )
			? (string) $row['confidence']
			: 'medium';

		if ( 'low' === $confidence ) {
			continue;
		}

		$result['outbound'][] = array(
			'anchor'         => sanitize_text_field( (string) ( $row['anchor'] ?? $match['title'] ) ),
			'confidence'     => $confidence,
			'edit_url'       => (string) get_edit_post_link( (int) $match['post_id'], 'raw' ),
			'path'           => (string) $match['path'],
			'placement_hint' => sanitize_text_field( (string) ( $row['placement_hint'] ?? '' ) ),
			'post_id'        => (int) $match['post_id'],
			'reason'         => sanitize_text_field( (string) ( $row['reason'] ?? '' ) ),
			'title'          => (string) $match['title'],
			'url'            => (string) get_permalink( (int) $match['post_id'] ),
		);

		if ( count( $result['outbound'] ) >= ERANKLY_LB_AI_MAX_OUTBOUND ) {
			break;
		}
	}

	foreach ( (array) ( $data['inbound'] ?? array() ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$source_path = isset( $row['source_path'] ) ? (string) $row['source_path'] : (string) ( $row['path'] ?? '' );

		if ( '' === $source_path ) {
			continue;
		}

		$match = erankly_lb_match_candidate_path( $source_path, $pool );

		if ( null === $match ) {
			continue;
		}

		$confidence = isset( $row['confidence'] ) && in_array( $row['confidence'], array( 'high', 'medium', 'low' ), true )
			? (string) $row['confidence']
			: 'medium';

		if ( 'low' === $confidence ) {
			continue;
		}

		$result['inbound'][] = array(
			'anchor'         => sanitize_text_field( (string) ( $row['anchor'] ?? '' ) ),
			'confidence'     => $confidence,
			'edit_url'       => (string) get_edit_post_link( (int) $match['post_id'], 'raw' ),
			'path'           => (string) $match['path'],
			'placement_hint' => sanitize_text_field( (string) ( $row['placement_hint'] ?? '' ) ),
			'post_id'        => (int) $match['post_id'],
			'reason'         => sanitize_text_field( (string) ( $row['reason'] ?? '' ) ),
			'title'          => (string) $match['title'],
			'url'            => (string) get_permalink( (int) $match['post_id'] ),
		);

		if ( count( $result['inbound'] ) >= ERANKLY_LB_AI_MAX_INBOUND ) {
			break;
		}
	}

	return $result;
}

/**
 * Generates AI inbound/outbound internal link suggestions for a post.
 *
 * @param int  $post_id Post ID.
 * @param bool $force   Skip cache when true.
 * @return array{inbound:array<int,array<string,mixed>>,outbound:array<int,array<string,mixed>>}|WP_Error
 */
function erankly_lb_ai_suggest_for_post( int $post_id, bool $force = false ) {
	if ( ! erankly_ai_module_enabled() ) {
		return new WP_Error( 'erankly_lb_ai_disabled', __( 'AI features are not enabled.', 'easyrankly' ), array( 'status' => 403 ) );
	}

	if ( ! erankly_ai_provider_available() ) {
		return new WP_Error( 'erankly_lb_ai_unavailable', __( 'No AI provider is available.', 'easyrankly' ), array( 'status' => 403 ) );
	}

	if ( ! $force ) {
		$cached = erankly_lb_get_cached_editor_suggestions( $post_id );

		if ( null !== $cached ) {
			return $cached;
		}
	}

	$post = get_post( $post_id );

	if ( ! ( $post instanceof WP_Post ) ) {
		return new WP_Error( 'erankly_lb_not_found', __( 'Post not found.', 'easyrankly' ), array( 'status' => 404 ) );
	}

	$graph = erankly_lb_get_graph();

	if ( null === $graph ) {
		$graph = erankly_lb_build_graph();
	}

	$pool = erankly_lb_editor_candidate_pool( $post_id, $graph );

	if ( empty( $pool ) ) {
		return array(
			'inbound'  => array(),
			'outbound' => array(),
		);
	}

	$current_path  = erankly_lb_normalize_link_path( (string) get_permalink( $post ) );
	$inbound_count = (int) ( $graph['posts'][ $post_id ]['inbound_count'] ?? 0 );

	$context = array(
		'candidate_pages'   => erankly_lb_format_candidate_pages_for_prompt( $pool ),
		'current_excerpt'   => erankly_lb_post_excerpt_for_ai( $post, ERANKLY_LB_AI_CURRENT_EXCERPT ),
		'current_path'      => $current_path,
		'current_title'     => $post->post_title,
		'existing_outbound' => erankly_lb_format_existing_outbound( $post_id, $graph ),
		'inbound_count'     => (string) $inbound_count,
		'lang'              => function_exists( 'erankly_ai_language_label' ) ? erankly_ai_language_label() : get_locale(),
		'max_inbound'       => ERANKLY_LB_AI_MAX_INBOUND,
		'max_outbound'      => ERANKLY_LB_AI_MAX_OUTBOUND,
		'site_name'         => get_bloginfo( 'name' ),
	);

	$prompt = erankly_lb_ai_load_prompt( $context );

	if ( '' === $prompt['system'] && '' === $prompt['user'] ) {
		return new WP_Error( 'erankly_lb_prompt_missing', __( 'The link suggestion prompt template is missing.', 'easyrankly' ), array( 'status' => 500 ) );
	}

	if ( ! function_exists( 'erankly_ai_call_model' ) ) {
		return new WP_Error( 'erankly_lb_ai_unavailable', __( 'AI provider is not available.', 'easyrankly' ), array( 'status' => 503 ) );
	}

	$raw = erankly_ai_call_model( (string) $prompt['system'], (string) $prompt['user'], 'link_suggestions' );

	if ( is_wp_error( $raw ) ) {
		return $raw;
	}

	$result = erankly_lb_ai_parse_editor_suggestions( (string) $raw, $pool );

	erankly_lb_cache_editor_suggestions( $post_id, $result );

	return apply_filters( 'erankly_link_building_editor_suggestions', $result, $post_id, $pool );
}

/**
 * Renders the internal links panel for the classic post meta box.
 *
 * @param WP_Post $post Post object.
 * @return void
 */
function erankly_render_post_internal_links_panel( WP_Post $post ): void {
	$graph_built = null !== erankly_lb_get_graph();
	?>
	<div class="erankly-internal-links-panel" data-erankly-internal-links data-erankly-post-id="<?php echo esc_attr( (string) $post->ID ); ?>">
		<?php if ( ! $graph_built ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'The link graph has not been built yet. It will build on first use, or rebuild it under EasyRankly → Internal links.', 'easyrankly' ); ?></p></div>
		<?php endif; ?>
		<?php if ( function_exists( 'erankly_ai_render_editor_privacy_notice' ) ) : ?>
			<?php erankly_ai_render_editor_privacy_notice(); ?>
		<?php endif; ?>
		<p>
			<button type="button" class="button" data-erankly-links-generate><?php esc_html_e( 'Get suggestions', 'easyrankly' ); ?></button>
			<button type="button" class="button hidden" data-erankly-links-refresh><?php esc_html_e( 'Refresh', 'easyrankly' ); ?></button>
		</p>
		<p class="description erankly-internal-links-status" data-erankly-links-status aria-live="polite"></p>
		<div class="erankly-internal-links-results" data-erankly-links-results hidden></div>
	</div>
	<?php
}

/**
 * Renders the Internal links settings tab.
 *
 * @return void
 */
function erankly_lb_render_panel(): void {
	$graph        = erankly_lb_get_graph();
	$built_notice = isset( $_GET['erankly_lb_built'] ) && '1' === $_GET['erankly_lb_built']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display-only flag.
	$rebuild_url  = wp_nonce_url(
		admin_url( 'admin-post.php?action=erankly_lb_rebuild_graph' ),
		'erankly_lb_rebuild_graph'
	);
	?>
	<div class="erankly-settings-fields">
		<div class="erankly-settings-section">
			<h3 class="erankly-section-title"><?php esc_html_e( 'Internal link graph', 'easyrankly' ); ?></h3>
			<section class="erankly-card">
				<p class="description">
					<?php esc_html_e( 'Scans published content for internal links between pages. Rebuild after large content changes. Suggestions appear in the post editor under Internal links.', 'easyrankly' ); ?>
				</p>

				<?php if ( $built_notice ) : ?>
					<div class="notice notice-success inline"><p><?php esc_html_e( 'Link graph rebuilt.', 'easyrankly' ); ?></p></div>
				<?php endif; ?>

				<?php if ( null === $graph ) : ?>
					<p><?php esc_html_e( 'No link graph has been built yet.', 'easyrankly' ); ?></p>
				<?php else : ?>
					<ul class="erankly-lb-stats">
						<li>
							<?php
							printf(
								/* translators: %d: number of indexed posts. */
								esc_html__( 'Indexed posts: %d', 'easyrankly' ),
								absint( $graph['post_count'] ?? 0 )
							);
							?>
						</li>
						<li>
							<?php
							printf(
								/* translators: %d: number of orphan pages. */
								esc_html__( 'Orphan pages (no inbound links): %d', 'easyrankly' ),
								absint( $graph['orphan_count'] ?? 0 )
							);
							?>
						</li>
						<li>
							<?php
							printf(
								/* translators: %s: localized date/time. */
								esc_html__( 'Last built: %s', 'easyrankly' ),
								esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) ( $graph['built_at'] ?? 0 ) ) )
							);
							?>
						</li>
					</ul>
				<?php endif; ?>

				<p><a href="<?php echo esc_url( $rebuild_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Rebuild link graph', 'easyrankly' ); ?></a></p>
			</section>
		</div>
	</div>
	<?php
}

/**
 * Renders the internal link suggestions prompt editor on the AI settings tab.
 *
 * @return void
 */
function erankly_lb_render_ai_prompt_settings(): void {
	$value = erankly_lb_ai_get_prompt_template();
	?>
	<div class="erankly-settings-section">
		<h3 class="erankly-section-title"><?php esc_html_e( 'Internal link suggestions prompt', 'easyrankly' ); ?></h3>
		<div class="erankly-settings-fields erankly-card">
			<div class="erankly-field">
				<label for="erankly-ai-link-suggestions-prompt"><strong><?php esc_html_e( 'Prompt template', 'easyrankly' ); ?></strong></label>
				<p class="description"><?php esc_html_e( 'Instructions the AI uses to suggest inbound and outbound internal links in the post editor. Leave empty or unchanged to keep the built-in prompt and its future updates.', 'easyrankly' ); ?></p>
				<textarea id="erankly-ai-link-suggestions-prompt" class="widefat code" rows="22" spellcheck="false" name="<?php echo esc_attr( ERANKLY_OPTION ); ?>[ai_link_suggestions_prompt_template]"><?php echo esc_textarea( $value ); ?></textarea>
				<p class="description">
					<?php esc_html_e( 'Keep the "## System" and "## User" section headings. Available placeholders:', 'easyrankly' ); ?>
					<code>{{lang}}</code> <code>{{site_name}}</code> <code>{{current_title}}</code> <code>{{current_path}}</code> <code>{{current_excerpt}}</code> <code>{{existing_outbound}}</code> <code>{{inbound_count}}</code> <code>{{candidate_pages}}</code> <code>{{max_outbound}}</code> <code>{{max_inbound}}</code>
				</p>
			</div>
		</div>
	</div>
	<?php
}
