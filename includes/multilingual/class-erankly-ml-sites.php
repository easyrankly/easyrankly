<?php
/**
 * Multilingual module — site language map.
 *
 * Stores and retrieves the per-site hreflang code for each blog in the
 * network. If no override is set, the code is derived from the site locale.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the network option that maps blog IDs to hreflang codes.
 */
final class ERankly_ML_Sites {

	/**
	 * Network option name.
	 */
	private const OPTION = 'erankly_ml_sites';

	/**
	 * Returns the full site map from the network option.
	 *
	 * Shape: [ blog_id => [
	 *   'hreflang'     => string,
	 *   'enabled'      => bool,
	 *   'is_default'   => bool,
	 *   'notice_title' => string,
	 *   'notice_text'  => string,
	 *   'notice_link'  => string,
	 * ] ]
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_all(): array {
		$raw = get_site_option( self::OPTION, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Returns config for a single blog, with defaults.
	 *
	 * @param int $blog_id Blog ID.
	 * @return array{hreflang:string,enabled:bool,is_default:bool,notice_title:string,notice_text:string,notice_link:string}
	 */
	public static function get( int $blog_id ): array {
		$all  = self::get_all();
		$data = isset( $all[ $blog_id ] ) && is_array( $all[ $blog_id ] ) ? $all[ $blog_id ] : array();

		return array(
			'hreflang'     => isset( $data['hreflang'] ) ? (string) $data['hreflang'] : '',
			'enabled'      => ! empty( $data['enabled'] ),
			'is_default'   => ! empty( $data['is_default'] ),
			'notice_title' => isset( $data['notice_title'] ) ? (string) $data['notice_title'] : '',
			'notice_text'  => isset( $data['notice_text'] ) ? (string) $data['notice_text'] : '',
			'notice_link'  => isset( $data['notice_link'] ) ? (string) $data['notice_link'] : '',
		);
	}

	/**
	 * Returns the Translation Notice texts configured for a blog.
	 *
	 * These are managed globally (per site/language) in the network settings, so
	 * the notice shown to a visitor is rendered in the reader's own language —
	 * the language of the matched translation — rather than being authored inline
	 * in the shortcode.
	 *
	 * @param int $blog_id Blog ID.
	 * @return array{title:string,text:string,link:string}
	 */
	public static function get_notice( int $blog_id ): array {
		$data = self::get( $blog_id );

		return array(
			'title' => $data['notice_title'],
			'text'  => $data['notice_text'],
			'link'  => $data['notice_link'],
		);
	}

	/**
	 * Returns professional default Translation Notice texts for a language.
	 *
	 * The notice is shown to the reader in the language of the matched
	 * translation, so each entry is authored in its own language. The
	 * {language} token is replaced client-side with the native language name
	 * (e.g. "Italiano"). Unknown languages fall back to the English copy.
	 *
	 * @param string $hreflang BCP-47 language code (e.g. "en-US", "it").
	 * @return array{title:string,text:string,link:string}
	 */
	public static function default_notice( string $hreflang ): array {
		$lang = strtolower( explode( '-', $hreflang )[0] );

		$defaults = array(
			'en' => array(
				'title' => 'Also available in your language',
				'text'  => 'This article is also available in {language}.',
				'link'  => 'Read the {language} version',
			),
			'it' => array(
				'title' => 'Disponibile nella tua lingua',
				'text'  => 'Questo articolo è disponibile anche in {language}.',
				'link'  => 'Leggi la versione in {language}',
			),
			'es' => array(
				'title' => 'Disponible en tu idioma',
				'text'  => 'Este artículo también está disponible en {language}.',
				'link'  => 'Leer la versión en {language}',
			),
			'fr' => array(
				'title' => 'Disponible dans votre langue',
				'text'  => 'Cet article est également disponible en {language}.',
				'link'  => 'Lire la version en {language}',
			),
			'de' => array(
				'title' => 'In Ihrer Sprache verfügbar',
				'text'  => 'Dieser Artikel ist auch auf {language} verfügbar.',
				'link'  => 'Zur {language}-Version',
			),
			'pt' => array(
				'title' => 'Disponível no seu idioma',
				'text'  => 'Este artigo também está disponível em {language}.',
				'link'  => 'Ler a versão em {language}',
			),
			'nl' => array(
				'title' => 'Beschikbaar in uw taal',
				'text'  => 'Dit artikel is ook beschikbaar in het {language}.',
				'link'  => 'Lees de {language}-versie',
			),
		);

		$notice = isset( $defaults[ $lang ] ) ? $defaults[ $lang ] : $defaults['en'];

		/**
		 * Filters the default Translation Notice texts for a language.
		 *
		 * @param array{title:string,text:string,link:string} $notice   Default texts.
		 * @param string                                       $hreflang Original BCP-47 language code.
		 */
		return (array) apply_filters( 'erankly_ml_default_notice', $notice, $hreflang );
	}

	/**
	 * Saves the full site map after sanitization.
	 *
	 * @param array<int,array<string,mixed>> $map Raw input from admin form.
	 * @return void
	 */
	public static function save( array $map ): void {
		$clean       = array();
		$has_default = false;

		foreach ( $map as $blog_id => $data ) {
			$blog_id = absint( $blog_id );
			if ( $blog_id < 1 || ! is_array( $data ) ) {
				continue;
			}

			$hreflang   = isset( $data['hreflang'] ) ? self::sanitize_hreflang( (string) $data['hreflang'] ) : '';
			$enabled    = ! empty( $data['enabled'] );
			$is_default = ! empty( $data['is_default'] );

			if ( $is_default ) {
				$has_default = true;
			}

			$clean[ $blog_id ] = array(
				'hreflang'     => $hreflang,
				'enabled'      => $enabled,
				'is_default'   => $is_default,
				'notice_title' => isset( $data['notice_title'] ) ? sanitize_text_field( (string) $data['notice_title'] ) : '',
				'notice_text'  => isset( $data['notice_text'] ) ? sanitize_text_field( (string) $data['notice_text'] ) : '',
				'notice_link'  => isset( $data['notice_link'] ) ? sanitize_text_field( (string) $data['notice_link'] ) : '',
			);
		}

		// Auto-assign default to first enabled site if none is set.
		if ( ! $has_default ) {
			foreach ( $clean as $blog_id => $data ) {
				if ( $data['enabled'] ) {
					$clean[ $blog_id ]['is_default'] = true;
					break;
				}
			}
		}

		update_site_option( self::OPTION, $clean );
	}

	/**
	 * Registers a newly created network site in the language map.
	 *
	 * Called when a site is added to the network so its sitemap is exposed in
	 * robots.txt without a manual save. Existing entries are never overwritten,
	 * so admin choices are preserved. The hreflang override is left empty and
	 * derived from the site locale at runtime by get_hreflang().
	 *
	 * @param int  $blog_id Blog ID of the new site.
	 * @param bool $enabled Whether the site should take part in multilingual output.
	 * @return void
	 */
	public static function add_site( int $blog_id, bool $enabled ): void {
		$blog_id = absint( $blog_id );

		if ( $blog_id < 1 ) {
			return;
		}

		$all = self::get_all();

		// Never override an existing entry: respect prior admin configuration.
		if ( isset( $all[ $blog_id ] ) ) {
			return;
		}

		$all[ $blog_id ] = array(
			'hreflang'   => '',
			'enabled'    => $enabled,
			// First enabled site becomes the x-default fallback when none is set.
			'is_default' => $enabled && 0 === self::get_default_blog_id(),
		);

		update_site_option( self::OPTION, $all );
	}

	/**
	 * Returns the effective BCP-47 hreflang code for a blog.
	 *
	 * Uses the override if set; otherwise derives from the site locale.
	 *
	 * @param int $blog_id Blog ID.
	 * @return string e.g. "it", "en", "de"
	 */
	public static function get_hreflang( int $blog_id ): string {
		$data = self::get( $blog_id );

		if ( '' !== $data['hreflang'] ) {
			return $data['hreflang'];
		}

		return self::locale_to_hreflang( (string) get_blog_option( $blog_id, 'WPLANG', '' ) );
	}

	/**
	 * Returns only enabled sites (for front-end resolver).
	 *
	 * @return array<int,array{hreflang:string,enabled:bool,is_default:bool}>
	 */
	public static function get_enabled(): array {
		return array_filter(
			self::get_all(),
			static fn( $data ) => is_array( $data ) && ! empty( $data['enabled'] )
		);
	}

	/**
	 * Returns the blog ID flagged as x-default, or 0.
	 *
	 * @return int
	 */
	public static function get_default_blog_id(): int {
		foreach ( self::get_all() as $blog_id => $data ) {
			if ( is_array( $data ) && ! empty( $data['is_default'] ) ) {
				return (int) $blog_id;
			}
		}
		return 0;
	}

	/**
	 * Converts a WordPress locale string to a BCP-47 language code.
	 *
	 * Returns the region-independent language subtag by default so the output
	 * targets a language rather than a single country (recommended practice).
	 * Country targeting is still possible via the per-site admin override.
	 *
	 * "it_IT" → "it", "de_DE_formal" → "de", "pt_BR" → "pt", "en" → "en"
	 *
	 * @param string $locale WordPress locale.
	 * @return string
	 */
	public static function locale_to_hreflang( string $locale ): string {
		if ( '' === $locale ) {
			return 'en';
		}

		// Keep only the language subtag: it_IT → it, de_DE_formal → de.
		$parts = explode( '_', $locale );

		return $parts[0];
	}

	/**
	 * Validates and normalises a BCP-47 hreflang value.
	 *
	 * Accepts: "en", "en-US", "en-us", "zh-Hant", "x-default".
	 * Rejects anything that doesn't match the pattern.
	 *
	 * @param string $value Raw input.
	 * @return string Sanitised value, or empty string on failure.
	 */
	public static function sanitize_hreflang( string $value ): string {
		$value = strtolower( trim( sanitize_text_field( $value ) ) );

		if ( 'x-default' === $value ) {
			return 'x-default';
		}

		// BCP-47 primary subtag (language) + optional region subtag.
		if ( 1 !== preg_match( '/^[a-z]{2,3}(-[a-z0-9]{2,8})*$/', $value ) ) {
			return '';
		}

		// Normalise: "en-us" → "en-US" (only for 2-char region subtags like ISO 3166-1).
		$sub = explode( '-', $value );
		if ( isset( $sub[1] ) && 2 === strlen( $sub[1] ) ) {
			$sub[1] = strtoupper( $sub[1] );
		}

		return implode( '-', $sub );
	}

	// Cross-site permalink helpers.

	/**
	 * Switches to another blog AND realigns the rewrite engine with it.
	 *
	 * WordPress core's switch_to_blog() swaps the options table but leaves the
	 * global $wp_rewrite — together with the category/tag/post-type permastructs
	 * that bake in the site front and category_base/tag_base — pointing at the
	 * site that was active before the switch. As a result get_permalink() and
	 * get_term_link() build URLs with the *calling* site's permalink structure,
	 * not the target site's. This is why a linked translation keeps showing the
	 * old URL (e.g. /blog/tag/novita/) after a site's permalinks change to
	 * /tag/novita/: the stored relation is fine, but the URL is rebuilt against
	 * the wrong structure.
	 *
	 * Always pair with restore_blog_for_link() so the original site's rewrite
	 * state is put back.
	 *
	 * @param int $blog_id Target blog ID.
	 * @return void
	 */
	public static function switch_to_blog_for_link( int $blog_id ): void {
		switch_to_blog( $blog_id );
		self::sync_rewrite_to_current_blog();
	}

	/**
	 * Restores the previous blog AND realigns the rewrite engine with it.
	 *
	 * @return void
	 */
	public static function restore_blog_for_link(): void {
		restore_current_blog();
		self::sync_rewrite_to_current_blog();
	}

	/**
	 * Rebuilds the global rewrite state for the currently active blog.
	 *
	 * Reloads the permalink structure (and site front) from the now-active
	 * options, then re-registers the built-in post types and taxonomies so their
	 * permastructs are regenerated with this site's front and category_base/
	 * tag_base. Custom post types/taxonomies registered in code keep the slug
	 * they were registered with (identical across sites in practice), so they are
	 * intentionally left untouched.
	 *
	 * No-op under plain permalinks: get_permalink()/get_term_link() then build
	 * query-string URLs from home_url(), which switch_to_blog() already corrects.
	 *
	 * @return void
	 */
	private static function sync_rewrite_to_current_blog(): void {
		global $wp_rewrite;

		if ( ! $wp_rewrite instanceof WP_Rewrite ) {
			return;
		}

		$wp_rewrite->init();

		if ( ! $wp_rewrite->using_permalinks() ) {
			return;
		}

		create_initial_post_types();
		create_initial_taxonomies();
	}
}
