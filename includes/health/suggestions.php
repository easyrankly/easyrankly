<?php
/**
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function erankly_health_compute_redirect_suggestion( array $entry, string $path ): ?array {
	$slug = erankly_health_404_slug_from_path( $path );

	if ( '' === $slug ) {
		return null;
	}

	$old_slug = erankly_health_match_old_slug( $slug );
	if ( null !== $old_slug ) {
		return $old_slug;
	}

	$exact = erankly_health_match_exact_slug( $path, $slug );
	if ( null !== $exact ) {
		return $exact;
	}

	return erankly_health_match_fuzzy( $slug, $entry );
}

/**
 * Suggests the most likely current target for a recorded 404, cached per path.
 *
 * @param array<string,mixed> $entry 404 entry (must contain 'path').
 * @return array<string,string>|null Suggestion payload, or null when none/ineligible.
 */
function erankly_health_suggest_redirect_target( array $entry ): ?array {
	$path = isset( $entry['path'] ) ? (string) $entry['path'] : '';

	if ( '' === $path || erankly_health_path_is_anonymized( $path ) ) {
		return null;
	}

	$cache_key = ERANKLY_HEALTH_SUGGESTION_PREFIX . md5( $path );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		// 'none' is the cached-miss sentinel; anything else is a suggestion array.
		return is_array( $cached ) ? $cached : null;
	}

	$suggestion = erankly_health_compute_redirect_suggestion( $entry, $path );

	/**
	 * Filters the computed 404 → redirect suggestion (or null when none).
	 *
	 * @param array<string,string>|null $suggestion Suggestion payload or null.
	 * @param array<string,mixed>       $entry      The 404 entry.
	 */
	$suggestion = apply_filters( 'erankly_health_404_suggestion', $suggestion, $entry );

	$ttl = (int) apply_filters( 'erankly_health_suggestion_ttl', 12 * HOUR_IN_SECONDS, $entry );

	set_transient( $cache_key, null === $suggestion ? 'none' : $suggestion, max( MINUTE_IN_SECONDS, $ttl ) );

	return $suggestion;
}

/*
 * ---------------------------------------------------------------------------
 * Operational Health: AI (semantic) 404 → redirect suggestions.
 *
 * Optional fallback used only when the lexical engine found nothing AND AI is
 * enabled (erankly_ai_enabled). A bounded, lexically-retrieved candidate pool is
 * sent to the site's configured AI provider, which picks the best same-topic
 * target from that list (or none). The chosen path is validated against the pool
 * so a hallucinated URL can never become a redirect. Results are cached per path.
 * ---------------------------------------------------------------------------
 */

/**
 * Builds a bounded pool of published candidates for the AI, ranked by lexical
 * proximity to the 404 slug. Each item is { path (site-relative), title }.
 *
 * @param string $slug  Normalized 404 slug.
 * @param int    $limit Maximum candidates (0 = filtered default).
 * @return array<int,array{path:string,title:string}>
 */
function erankly_health_ai_candidate_pool( string $slug, int $limit = 0 ): array {
	global $wpdb;

	if ( '' === $slug ) {
		return array();
	}

	$limit = $limit > 0 ? $limit : (int) apply_filters( 'erankly_health_ai_candidate_limit', 25 );
	$limit = max( 1, $limit );

	$post_types = array_keys( erankly_get_public_post_types() );

	if ( empty( $post_types ) ) {
		return array();
	}

	$scan_limit   = max( $limit, (int) apply_filters( 'erankly_health_suggestion_candidate_limit', ERANKLY_HEALTH_SUGGESTION_CANDIDATE_LIMIT ) );
	$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
	$args         = array_merge( $post_types, array( $scan_limit ) );

	$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core table lookup for bounded AI redirect suggestion candidates.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The placeholder list is generated from public post types and each value is bound via prepare().
		$wpdb->prepare(
			"SELECT ID, post_name, post_title FROM {$wpdb->posts}
				WHERE post_status = 'publish' AND post_name <> '' AND post_type IN ($placeholders)
				ORDER BY post_modified DESC LIMIT %d",
			$args
		)
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	);

	$scored = array();

	foreach ( (array) $rows as $row ) {
		$title_slug = sanitize_title( (string) $row->post_title );
		$scored[]   = array(
			'id'    => (int) $row->ID,
			'title' => (string) $row->post_title,
			'ratio' => max(
				erankly_health_similarity( $slug, (string) $row->post_name ),
				'' !== $title_slug ? erankly_health_similarity( $slug, $title_slug ) : 0.0
			),
		);
	}

	usort(
		$scored,
		static function ( array $a, array $b ): int {
			return $b['ratio'] <=> $a['ratio'];
		}
	);

	$pool = array();

	foreach ( array_slice( $scored, 0, $limit ) as $candidate ) {
		$path = erankly_health_permalink_path( (int) $candidate['id'] );

		if ( '' === $path ) {
			continue;
		}

		$pool[] = array(
			'path'  => $path,
			'title' => wp_strip_all_tags( (string) $candidate['title'] ),
		);
	}

	return $pool;
}

/**
 * Normalizes a path for tolerant equality (lowercase, no trailing slash).
 *
 * @param string $path Path.
 * @return string
 */
function erankly_health_path_match_key( string $path ): string {
	$path = strtolower( trim( $path ) );

	return '/' === $path ? '/' : untrailingslashit( $path );
}

/**
 * Builds the system/user prompt for the AI redirect suggestion.
 *
 * @param string                                     $slug  Normalized 404 slug.
 * @param array<int,array{path:string,title:string}> $pool  Candidate pool.
 * @param array<string,mixed>                        $entry 404 entry.
 * @return array{system:string,user:string}
 */
function erankly_health_ai_build_prompt( string $slug, array $pool, array $entry ): array {
	$lines = array();

	foreach ( $pool as $i => $candidate ) {
		$lines[] = ( $i + 1 ) . '. ' . $candidate['title'] . ' - ' . $candidate['path'];
	}

	$system = __( 'You are an SEO assistant that picks redirect targets for broken URLs. You receive the slug words of a deleted or missing page and a numbered list of existing pages on the same site (title - path). Choose the ONE existing page that covers the same topic, or a closely related one. Choose ONLY from the list. If no page is a sensible match, return none. Infer the language from the words and do not translate. Respond with ONLY a JSON object: {"target": "<exact path from the list>" or null, "confidence": "high"|"medium"|"low", "reason": "<short>"}.', 'easyrankly' );

	$user = sprintf(
		/* translators: 1: slug words of the broken URL. 2: numbered candidate list. */
		__( "Broken URL topic (slug words): %1\$s\n\nExisting pages:\n%2\$s\n\nReturn the JSON object only.", 'easyrankly' ),
		str_replace( '-', ' ', $slug ),
		implode( "\n", $lines )
	);

	$prompt = array(
		'system' => $system,
		'user'   => $user,
	);

	/**
	 * Filters the AI redirect-suggestion prompt.
	 *
	 * @param array{system:string,user:string}            $prompt System/user prompt.
	 * @param string                                      $slug   Normalized 404 slug.
	 * @param array<int,array{path:string,title:string}>  $pool   Candidate pool.
	 * @param array<string,mixed>                         $entry  404 entry.
	 */
	return apply_filters( 'erankly_health_ai_suggestion_prompt', $prompt, $slug, $pool, $entry );
}

/**
 * Parses the model's JSON answer and validates the target against the pool.
 *
 * @param string                                     $raw  Raw model output.
 * @param array<int,array{path:string,title:string}> $pool Candidate pool.
 * @return array<string,string>|null Suggestion payload, or null when none/invalid.
 */
function erankly_health_ai_parse_suggestion( string $raw, array $pool ): ?array {
	$json = trim( $raw );
	$json = (string) preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', $json );

	if ( preg_match( '/\{.*\}/s', $json, $matches ) ) {
		$json = $matches[0];
	}

	$data = json_decode( $json, true );

	if ( ! is_array( $data ) || empty( $data['target'] ) || ! is_string( $data['target'] ) ) {
		return null;
	}

	// Anti-hallucination: the target must be one of the candidate paths.
	$target_key  = erankly_health_path_match_key( $data['target'] );
	$match_path  = '';
	$match_title = '';

	foreach ( $pool as $candidate ) {
		if ( erankly_health_path_match_key( (string) $candidate['path'] ) === $target_key ) {
			$match_path  = (string) $candidate['path'];
			$match_title = (string) $candidate['title'];
			break;
		}
	}

	if ( '' === $match_path ) {
		return null;
	}

	$confidence = isset( $data['confidence'] ) && in_array( $data['confidence'], array( 'high', 'medium', 'low' ), true ) ? (string) $data['confidence'] : 'medium';

	$suggestion                = erankly_health_build_suggestion( $match_path, $confidence, 'ai', $match_title );
	$suggestion['reason_text'] = isset( $data['reason'] ) ? sanitize_text_field( (string) $data['reason'] ) : '';

	return $suggestion;
}

/**
 * Caches an AI suggestion result ('none' sentinel when there is no match).
 *
 * @param string                    $cache_key  Transient key.
 * @param array<string,string>|null $suggestion Suggestion or null.
 * @return void
 */
function erankly_health_cache_ai_suggestion( string $cache_key, ?array $suggestion ): void {
	$ttl = (int) apply_filters( 'erankly_health_suggestion_ttl', 12 * HOUR_IN_SECONDS, array() );

	set_transient( $cache_key, null === $suggestion ? 'none' : $suggestion, max( MINUTE_IN_SECONDS, $ttl ) );
}

/**
 * Reads any cached AI suggestion for a 404 path without calling the provider.
 *
 * @param string $path Stored 404 path.
 * @return array{state:string,suggestion:array<string,string>|null}
 *               state = 'fresh' (never tried) | 'none' (tried, no match) | 'hit'.
 */
function erankly_health_ai_cached_suggestion( string $path ): array {
	$cached = get_transient( ERANKLY_HEALTH_AI_SUGGESTION_PREFIX . md5( $path ) );

	if ( is_array( $cached ) ) {
		return array(
			'state'      => 'hit',
			'suggestion' => $cached,
		);
	}

	if ( 'none' === $cached ) {
		return array(
			'state'      => 'none',
			'suggestion' => null,
		);
	}

	return array(
		'state'      => 'fresh',
		'suggestion' => null,
	);
}

/**
 * Suggests a redirect target for a 404 using the AI provider (on-demand).
 *
 * Gated by erankly_ai_enabled(); meant as a fallback when the lexical engine
 * returned nothing. The result is cached per path. Returns null on a provider
 * error WITHOUT caching, so the admin can retry.
 *
 * @param array<string,mixed> $entry 404 entry (must contain 'path').
 * @return array<string,string>|null
 */
function erankly_health_ai_suggest_redirect_target( array $entry ): ?array {
	if ( ! function_exists( 'erankly_ai_enabled' ) || ! erankly_ai_enabled() ) {
		return null;
	}

	$path = isset( $entry['path'] ) ? (string) $entry['path'] : '';

	if ( '' === $path || erankly_health_path_is_anonymized( $path ) ) {
		return null;
	}

	$cache_key = ERANKLY_HEALTH_AI_SUGGESTION_PREFIX . md5( $path );
	$cached    = get_transient( $cache_key );

	if ( false !== $cached ) {
		return is_array( $cached ) ? $cached : null;
	}

	$slug = erankly_health_404_slug_from_path( $path );
	$pool = '' !== $slug ? erankly_health_ai_candidate_pool( $slug ) : array();

	if ( empty( $pool ) ) {
		erankly_health_cache_ai_suggestion( $cache_key, null );

		return null;
	}

	$prompt = erankly_health_ai_build_prompt( $slug, $pool, $entry );
	$raw    = erankly_ai_call_model( (string) $prompt['system'], (string) $prompt['user'], 'health_suggest' );

	if ( is_wp_error( $raw ) ) {
		// Transient/provider failure: do not cache so the admin can retry.
		return null;
	}

	$suggestion = erankly_health_ai_parse_suggestion( (string) $raw, $pool );

	erankly_health_cache_ai_suggestion( $cache_key, $suggestion );

	return $suggestion;
}

/**
 * Handles the on-demand "Suggest with AI" admin-post request for one 404.
 *
 * @return void
 */
function erankly_health_handle_ai_suggest(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to update Health data.', 'easyrankly' ) );
	}

	check_admin_referer( 'erankly_health_ai_suggest' );

	$hash    = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( $_POST['hash'] ) ) : '';
	$outcome = 'error';

	if ( ! function_exists( 'erankly_ai_enabled' ) || ! erankly_ai_enabled() ) {
		$outcome = 'disabled';
	} elseif ( preg_match( '/^[a-f0-9]{32}$/', $hash ) ) {
		$entries = erankly_health_get_404_entries( ERANKLY_HEALTH_404_FREQUENT_OPTION );

		if ( isset( $entries[ $hash ] ) ) {
			$path       = (string) $entries[ $hash ]['path'];
			$suggestion = erankly_health_ai_suggest_redirect_target( $entries[ $hash ] );

			if ( null !== $suggestion ) {
				$outcome = 'suggested';
			} else {
				// Distinguish "AI ran, no match" (cached 'none') from a provider error (not cached).
				$outcome = 'none' === erankly_health_ai_cached_suggestion( $path )['state'] ? 'none' : 'error';
			}
		}
	}

	wp_safe_redirect(
		add_query_arg(
			array(
				'page'              => 'erankly',
				'erankly_tab'       => 'health',
				'erankly_health_ai' => $outcome,
			),
			admin_url( 'options-general.php' )
		)
	);
	exit;
}

/**
 * Returns a redirects repository instance when the Redirects module is active,
 * or null when it is disabled or its classes are unavailable.
 *
 * @return ERankly_Redirects_Repository|null
 */
function erankly_health_get_redirects_repository() {
	if (
		! function_exists( 'erankly_redirects_enabled' )
		|| ! erankly_redirects_enabled()
		|| ! class_exists( 'ERankly_Redirects_Repository' )
		|| ! class_exists( 'ERankly_Redirects_Normalizer' )
	) {
		return null;
	}

	return new ERankly_Redirects_Repository();
}

/**
 * Computes the redirect-normalized source hash for a stored 404 path.
 *
 * Returns '' for anonymized or empty paths, which cannot map to a real source.
 *
 * @param string $path Stored 404 path.
 * @return string
 */
function erankly_health_redirect_hash_for_path( string $path ): string {
	if ( '' === $path || erankly_health_path_is_anonymized( $path ) || ! class_exists( 'ERankly_Redirects_Normalizer' ) ) {
		return '';
	}

	$normalized = ERankly_Redirects_Normalizer::normalize_source( $path, false, false );

	return '' === $normalized ? '' : ERankly_Redirects_Normalizer::source_hash( $normalized );
}

/**
 * Splits frequent 404s into rows still needing attention and a count of those
 * already covered by an active redirect (which are hidden from the active list).
 *
 * @param array<string,array<string,mixed>> $frequent_404s Frequent 404 entries.
 * @return array{active:array<int,array<string,mixed>>,handled:int}
 */
function erankly_health_partition_404s( array $frequent_404s ): array {
	$repo    = erankly_health_get_redirects_repository();
	$states  = erankly_health_get_404_states();
	$active  = array();
	$managed = array();
	$handled = 0;

	foreach ( $frequent_404s as $entry_hash => $entry ) {
		$entry['hash'] = (string) $entry_hash;

		$redirect_hash = $repo ? erankly_health_redirect_hash_for_path( (string) $entry['path'] ) : '';

		// Covered by an active redirect → hidden (counts into the handled summary).
		if ( '' !== $redirect_hash && $repo->find_active_exact_by_hash( $redirect_hash ) ) {
			++$handled;
			continue;
		}

		// Manually ignored/resolved → moved to the "managed" list.
		if ( isset( $states[ $entry_hash ] ) ) {
			$entry['state'] = (string) $states[ $entry_hash ]['status'];
			$managed[]      = $entry;
			continue;
		}

		$active[] = $entry;
	}

	return array(
		'active'  => $active,
		'managed' => $managed,
		'handled' => $handled,
	);
}

/**
 * Renders the "Suggestion" cell for a frequent 404 row.
 *
 * @param array<string,string>|null $suggestion Suggestion payload or null.
 * @param bool                      $anonymized Whether the source path is anonymized.
 * @param bool                      $ai_tried   Whether the AI already ran and found nothing.
 * @return void
 */
function erankly_health_render_404_suggestion_cell( ?array $suggestion, bool $anonymized, bool $ai_tried = false ): void {
	if ( null !== $suggestion ) {
		printf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer"><code>%2$s</code></a><br><span class="description">%3$s</span>',
			esc_url( home_url( (string) $suggestion['target'] ) ),
			esc_html( (string) $suggestion['target'] ),
			esc_html( (string) $suggestion['label'] )
		);

		return;
	}

	echo '<span class="description">';
	if ( $anonymized ) {
		echo esc_html__( 'Path anonymized, no automatic match.', 'easyrankly' );
	} elseif ( $ai_tried ) {
		echo esc_html__( 'No match (AI included).', 'easyrankly' );
	} else {
		echo esc_html__( 'No automatic match.', 'easyrankly' );
	}
	echo '</span>';
}

/**
 * Renders the "Action" cell for a frequent 404 row.
 *
 * The primary action deep-links to the Redirects tab with the add form
 * pre-filled (source, suggested target, 301, provenance note). The redirect is
 * only created once the admin reviews and saves it there.
 *
 * @param array<string,mixed>       $entry             404 entry.
 * @param array<string,string>|null $suggestion        Suggestion payload or null.
 * @param bool                      $anonymized        Whether the source path is anonymized.
 * @param bool                      $redirects_enabled Whether the Redirects module is on.
 * @param bool                      $ai_button         Whether to offer the on-demand "Suggest with AI" button.
 * @return void
 */
function erankly_health_render_404_action_cell( array $entry, ?array $suggestion, bool $anonymized, bool $redirects_enabled, bool $ai_button = false ): void {
	if ( ! $redirects_enabled ) {
		printf(
			'<a href="%1$s">%2$s</a>',
			esc_url( add_query_arg( 'erankly_tab', 'features', erankly_setup_wizard_settings_url() ) ),
			esc_html__( 'Enable Redirects to fix', 'easyrankly' )
		);
	} elseif ( $anonymized ) {
		printf(
			'<a class="button button-secondary" href="%1$s">%2$s</a>',
			esc_url( add_query_arg( 'erankly_tab', 'redirects', erankly_setup_wizard_settings_url() ) ),
			esc_html__( 'Create manually', 'easyrankly' )
		);
	} else {
		$normalized_source = class_exists( 'ERankly_Redirects_Normalizer' )
			? ERankly_Redirects_Normalizer::normalize_source( (string) $entry['path'], false, false )
			: (string) $entry['path'];

		$create_url = add_query_arg(
			array(
				'erankly_tab'                      => 'redirects',
				'erankly_redirects_prefill_source' => $normalized_source,
				'erankly_redirects_prefill_target' => null !== $suggestion ? (string) $suggestion['target'] : '',
				'erankly_redirects_prefill_status' => 301,
				'erankly_redirects_prefill_note'   => __( 'Created from Health 404 scanner', 'easyrankly' ),
			),
			erankly_setup_wizard_settings_url()
		);

		printf(
			'<a class="button button-primary" href="%1$s">%2$s</a>',
			esc_url( $create_url ),
			esc_html__( 'Create 301 redirect', 'easyrankly' )
		);
	}

	if ( $ai_button ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="erankly-inline-form">
			<input type="hidden" name="action" value="erankly_health_ai_suggest">
			<input type="hidden" name="hash" value="<?php echo esc_attr( isset( $entry['hash'] ) ? (string) $entry['hash'] : '' ); ?>">
			<?php wp_nonce_field( 'erankly_health_ai_suggest' ); ?>
			<button type="submit" class="button-link"><?php esc_html_e( 'Suggest with AI', 'easyrankly' ); ?></button>
		</form>
		<?php
	}

	erankly_health_render_404_state_forms( isset( $entry['hash'] ) ? (string) $entry['hash'] : '', 'active' );
}

/**
 * Renders the manual-state admin-post forms (Ignore / Mark resolved / Restore).
 *
 * @param string $hash          md5 hash (frequent-entry key) identifying the 404.
 * @param string $current_state 'active'|'ignored'|'resolved'.
 * @return void
 */
function erankly_health_render_404_state_forms( string $hash, string $current_state ): void {
	if ( '' === $hash ) {
		return;
	}

	$buttons = 'active' === $current_state
		? array(
			'ignored'  => __( 'Ignore', 'easyrankly' ),
			'resolved' => __( 'Mark resolved', 'easyrankly' ),
		)
		: array( 'active' => __( 'Restore', 'easyrankly' ) );

	foreach ( $buttons as $state => $label ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="erankly-inline-form">
			<input type="hidden" name="action" value="erankly_health_404_set_state">
			<input type="hidden" name="hash" value="<?php echo esc_attr( $hash ); ?>">
			<input type="hidden" name="state" value="<?php echo esc_attr( $state ); ?>">
			<?php wp_nonce_field( 'erankly_health_404_state' ); ?>
			<button type="submit" class="button-link"><?php echo esc_html( $label ); ?></button>
		</form>
		<?php
	}
}

/**
 * Normalizes a URL or path to a root-relative path for internal link matching.
 *
 * @param string $url URL or path to normalize.
 * @return string Normalized root-relative path, or empty string if not resolvable.
 */
function erankly_health_normalize_link_path( string $url ): string {
	$path = wp_parse_url( $url, PHP_URL_PATH );

	if ( ! is_string( $path ) || '' === $path ) {
		return '';
	}

	$path = '/' . ltrim( $path, '/' );

	return '/' === $path ? $path : untrailingslashit( $path );
}
