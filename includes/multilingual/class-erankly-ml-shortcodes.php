<?php
/**
 * Frontend shortcodes for the Multilingual module.
 *
 * Registers:
 *  - [erankly_language_switcher] — a <select> to navigate between
 *    translations of the current article.
 *  - [erankly_translation_notice] — a dismissible <div> card that appears
 *    only when the visitor's browser language matches an available translation.
 *    Its texts are managed globally per language in the network settings, so the
 *    notice is shown in the reader's own language.
 *
 * Both shortcodes degrade gracefully to an empty string when:
 *  - the feature is off or the module classes are unavailable,
 *  - the current request is not a singular view,
 *  - the post has no linked translations on enabled sites.
 *
 * @package EasyRankly
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the frontend shortcodes and assets for the multilingual module.
 */
final class ERankly_ML_Shortcodes {

	/**
	 * Repository instance.
	 *
	 * @var ERankly_ML_Repository
	 */
	private ERankly_ML_Repository $repo;

	/**
	 * Constructor.
	 *
	 * @param ERankly_ML_Repository $repo Repository instance.
	 */
	public function __construct( ERankly_ML_Repository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Registers hooks and shortcodes.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_shortcode( 'erankly_language_switcher', array( $this, 'render_switcher' ) );
		add_shortcode( 'erankly_translation_notice', array( $this, 'render_notice' ) );
	}

	/**
	 * Registers (does not enqueue) the frontend stylesheet and script.
	 *
	 * Actual enqueueing happens on-demand inside each shortcode callback,
	 * so assets are only loaded on pages that include at least one shortcode.
	 *
	 * @return void
	 */
	public function register_assets(): void {
		wp_register_style(
			'erankly-multilingual-frontend',
			ERANKLY_URL . 'assets/css/multilingual-frontend.css',
			array(),
			ERANKLY_VERSION
		);

		wp_register_script(
			'erankly-multilingual-frontend',
			ERANKLY_URL . 'assets/js/multilingual-frontend.js',
			array(),
			ERANKLY_VERSION,
			true
		);
	}

	// Shortcode: [erankly_language_switcher].

	/**
	 * Renders the [erankly_language_switcher] shortcode.
	 *
	 * Supported attributes:
	 *  - class  (string) Extra CSS class(es) for the wrapper <form>.
	 *  - label  (string) Accessible label for the <select>. Default: "Choose a language".
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string HTML output or empty string.
	 */
	public function render_switcher( $atts ): string {
		if ( ! is_singular() || ! class_exists( 'ERankly_ML_Sites' ) ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'class' => '',
				'label' => __( 'Choose a language', 'easyrankly' ),
			),
			$atts,
			'erankly_language_switcher'
		);

		$post_id      = (int) get_queried_object_id();
		$translations = $this->get_translations( $post_id );

		// Need at least the current language + one alternative.
		if ( count( $translations ) < 2 ) {
			return '';
		}

		wp_enqueue_style( 'erankly-multilingual-frontend' );
		wp_enqueue_script( 'erankly-multilingual-frontend' );

		$uid         = 'erml-switcher-' . $post_id;
		$extra_class = sanitize_html_class( $atts['class'] );
		$label       = sanitize_text_field( $atts['label'] );

		ob_start();
		?>
<form class="<?php echo esc_attr( trim( 'erml-switcher ' . $extra_class ) ); ?>">
	<label class="erml-switcher__label screen-reader-text" for="<?php echo esc_attr( $uid ); ?>">
		<?php echo esc_html( $label ); ?>
	</label>
	<select
		class="erml-switcher__select"
		id="<?php echo esc_attr( $uid ); ?>"
		aria-label="<?php echo esc_attr( $label ); ?>"
		data-erml-switcher
	>
		<?php foreach ( $translations as $t ) : ?>
		<option
			class="erml-switcher__option"
			value="<?php echo esc_url( $t['url'] ); ?>"
			lang="<?php echo esc_attr( $t['hreflang'] ); ?>"
			hreflang="<?php echo esc_attr( $t['hreflang'] ); ?>"
			<?php selected( $t['is_current'] ); ?>
		>
			<?php echo esc_html( $t['native'] ); ?>
		</option>
		<?php endforeach; ?>
	</select>
</form>
		<?php
		return (string) ob_get_clean();
	}

	// Shortcode: [erankly_translation_notice].

	/**
	 * Renders the [erankly_translation_notice] shortcode.
	 *
	 * The notice is rendered server-side but starts hidden; JavaScript reveals it
	 * only when the visitor's browser language matches an available translation
	 * that differs from the current article language. When no such translation
	 * exists the card stays completely invisible.
	 *
	 * The notice texts are not authored in the shortcode: they are configured
	 * globally per language in the network settings (Network Admin → Settings →
	 * EasyRankly → Multilingual). Each enabled site supplies the title, body and
	 * link label in its own language, so the banner is shown to the reader in the
	 * reader's language — the language of the matched translation.
	 *
	 * Supported attributes (presentation only — no text):
	 *  - title_tag  (string) HTML tag for the heading: h1–h6, p, span, div. Default: h6.
	 *  - text_tag   (string) HTML tag for the paragraph: p, span, div. Default: p.
	 *  - class      (string) Extra CSS class(es) for the <div> wrapper.
	 *
	 * The {language} token (usable inside the global texts) is replaced
	 * client-side with the matched translation's native language name (e.g.
	 * "Italiano").
	 *
	 * @param array<string,string>|string $atts Shortcode attributes.
	 * @return string HTML output or empty string.
	 */
	public function render_notice( $atts ): string {
		if ( ! is_singular() || ! class_exists( 'ERankly_ML_Sites' ) ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'title_tag' => 'h6',
				'text_tag'  => 'p',
				'class'     => '',
			),
			$atts,
			'erankly_translation_notice'
		);

		// Sanitise HTML tag choices against allow-lists.
		$allowed_title_tags = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'div' );
		$allowed_text_tags  = array( 'p', 'span', 'div' );
		$title_tag          = in_array( $atts['title_tag'], $allowed_title_tags, true ) ? $atts['title_tag'] : 'h6';
		$text_tag           = in_array( $atts['text_tag'], $allowed_text_tags, true ) ? $atts['text_tag'] : 'p';

		$post_id      = (int) get_queried_object_id();
		$translations = $this->get_translations( $post_id );

		// The notice only needs the non-current translations.
		$others = array_values( array_filter( $translations, static fn( $t ) => ! $t['is_current'] ) );

		if ( empty( $others ) ) {
			return '';
		}

		// Build the JSON payload: for each alternative language, attach the texts
		// configured globally for that language's site. Languages without any
		// configured text are skipped — there is nothing to show in that language.
		$json_data = array();

		foreach ( $others as $t ) {
			$notice = ERankly_ML_Sites::get_notice( (int) $t['blog_id'] );

			if ( '' === $notice['title'] && '' === $notice['text'] && '' === $notice['link'] ) {
				continue;
			}

			$json_data[] = array(
				'hreflang' => $t['hreflang'],
				'url'      => $t['url'],
				'native'   => $t['native'],
				'title'    => $notice['title'],
				'text'     => $notice['text'],
				'link'     => $notice['link'],
			);
		}

		// No language has notice texts configured: render nothing.
		if ( empty( $json_data ) ) {
			return '';
		}

		$json = wp_json_encode( $json_data );
		if ( false === $json ) {
			return '';
		}

		wp_enqueue_style( 'erankly-multilingual-frontend' );
		wp_enqueue_script( 'erankly-multilingual-frontend' );

		$current_hreflang = ERankly_ML_Sites::get_hreflang( get_current_blog_id() );
		$extra_class      = sanitize_html_class( $atts['class'] );
		$title_tag        = tag_escape( $title_tag );
		$text_tag         = tag_escape( $text_tag );

		// JavaScript fills the texts client-side; the markup ships empty and hidden.
		// Built as a single line (no newlines) so wpautop can't inject stray <br>/<p>
		// tags when the shortcode sits inside post content. Each fragment is escaped
		// as it's concatenated, so the result is safe to return.
		$html  = '<div class="' . esc_attr( trim( 'erml-notice ' . $extra_class ) ) . '" hidden data-erml-notice';
		$html .= ' data-post-id="' . esc_attr( (string) $post_id ) . '"';
		$html .= ' data-current-lang="' . esc_attr( $current_hreflang ) . '"';
		$html .= ' data-translations="' . esc_attr( $json ) . '">';
		$html .= '<button type="button" class="erml-notice__close" aria-label="' . esc_attr__( 'Dismiss', 'easyrankly' ) . '">';
		$html .= '<span aria-hidden="true">&times;</span>';
		$html .= '</button>';
		$html .= '<' . $title_tag . ' class="erml-notice__title" hidden></' . $title_tag . '>';
		$html .= '<' . $text_tag . ' class="erml-notice__text" hidden></' . $text_tag . '>';
		$html .= '<a class="erml-notice__link" href="#" hidden></a>';
		$html .= '</div>';

		return $html;
	}

	// Data helpers.

	/**
	 * Returns the full list of language alternatives for a post, including the
	 * current site's version as the entry where is_current === true.
	 *
	 * Each entry is an array with keys:
	 *  - blog_id    int    Network site ID.
	 *  - hreflang   string BCP-47 language code (e.g. "it", "en-US").
	 *  - url        string Permalink (empty when post is not publicly accessible).
	 *  - name       string Site blog name.
	 *  - native     string Native language display name (e.g. "Italiano").
	 *  - is_current bool   True for the entry that belongs to the current blog.
	 *
	 * @param int $post_id Post ID on the current blog.
	 * @return array<int,array<string,mixed>>
	 */
	private function get_translations( int $post_id ): array {
		$current_blog_id = get_current_blog_id();
		$members         = $this->repo->get_group_for_object( $current_blog_id, 'post', $post_id );

		// Post not in any translation group: return only the current version so
		// the caller can detect "fewer than 2 entries" and bail.
		if ( empty( $members ) ) {
			return array(
				array(
					'blog_id'    => $current_blog_id,
					'hreflang'   => ERankly_ML_Sites::get_hreflang( $current_blog_id ),
					'url'        => (string) get_permalink( $post_id ),
					'name'       => (string) get_blog_option( $current_blog_id, 'blogname' ),
					'native'     => self::native_name( ERankly_ML_Sites::get_hreflang( $current_blog_id ) ),
					'is_current' => true,
				),
			);
		}

		$results = array();
		$seen    = array();

		foreach ( $members as $row ) {
			// Only handle posts; home/term objects are not relevant for shortcodes.
			if ( 'post' !== ( $row['object_type'] ?? '' ) ) {
				continue;
			}

			$bid = (int) $row['blog_id'];

			// Defensive de-duplication: a group must never list the same blog twice.
			// Legacy data can still contain duplicate slots, which would otherwise
			// show the same language more than once in the switcher.
			if ( isset( $seen[ $bid ] ) ) {
				continue;
			}
			$seen[ $bid ] = true;
			$oid          = (int) $row['object_id'];
			$site         = ERankly_ML_Sites::get( $bid );

			// Skip sites that are not enabled in the multilingual settings.
			if ( empty( $site['enabled'] ) ) {
				continue;
			}

			$hreflang   = ERankly_ML_Sites::get_hreflang( $bid );
			$is_current = ( $bid === $current_blog_id );
			$url        = $this->resolve_url( $bid, $oid );

			// Skip entries with no navigable URL (draft, private, trashed, missing),
			// unless this is the current article (always include it for the switcher).
			// Noindex pages are intentionally kept: the switcher is for human
			// navigation, not for search-engine hreflang signalling.
			if ( '' === $url && ! $is_current ) {
				continue;
			}

			// For the current article, always use the canonical permalink.
			if ( $is_current ) {
				$url = (string) get_permalink( $oid );
			}

			$results[] = array(
				'blog_id'    => $bid,
				'hreflang'   => $hreflang,
				'url'        => $url,
				'name'       => (string) get_blog_option( $bid, 'blogname' ),
				'native'     => self::native_name( $hreflang ),
				'is_current' => $is_current,
			);
		}

		return $results;
	}

	/**
	 * Resolves the navigable permalink for a post on a given blog.
	 *
	 * The switcher is a human-facing navigation control, so it intentionally links
	 * to any *published* translation regardless of its `_erankly_noindex` flag —
	 * a reader must still be able to reach a published page even when it is hidden
	 * from search engines. This differs from ERankly_ML_Resolver::resolve_url(),
	 * which builds the hreflang <head> alternates and must exclude noindex pages.
	 *
	 * Returns an empty string only when the post is not publicly viewable
	 * (draft, private, pending, trashed, or missing).
	 *
	 * @param int $blog_id Blog ID (may differ from the current blog).
	 * @param int $post_id Post ID on that blog.
	 * @return string Permalink, or '' if the post is not published.
	 */
	private function resolve_url( int $blog_id, int $post_id ): string {
		$current_blog = get_current_blog_id();
		$is_same      = ( $blog_id === $current_blog );

		if ( ! $is_same ) {
			ERankly_ML_Sites::switch_to_blog_for_link( $blog_id );
		}

		$url = '';

		try {
			$post = get_post( $post_id );

			if ( $post instanceof WP_Post && 'publish' === $post->post_status ) {
				$url = (string) get_permalink( $post );
			}
		} finally {
			if ( ! $is_same ) {
				ERankly_ML_Sites::restore_blog_for_link();
			}
		}

		return $url;
	}

	// Native language name helper.

	/**
	 * Returns the native display name for a BCP-47 language code.
	 *
	 * The built-in map covers the most widely used languages. The result is
	 * filterable via the `erankly_ml_language_native_name` filter for custom
	 * overrides or additions.
	 *
	 * Examples: "it" → "Italiano", "en-US" → "English", "zh-Hant" → "中文".
	 *
	 * @param string $hreflang BCP-47 language code.
	 * @return string Native language name.
	 */
	public static function native_name( string $hreflang ): string {
		// Normalise to the primary language subtag only (e.g. "en-US" → "en").
		$lang = strtolower( explode( '-', $hreflang )[0] );

		$map = array(
			'af' => 'Afrikaans',
			'ar' => 'العربية',
			'bg' => 'Български',
			'bn' => 'বাংলা',
			'ca' => 'Català',
			'cs' => 'Čeština',
			'cy' => 'Cymraeg',
			'da' => 'Dansk',
			'de' => 'Deutsch',
			'el' => 'Ελληνικά',
			'en' => 'English',
			'eo' => 'Esperanto',
			'es' => 'Español',
			'et' => 'Eesti',
			'eu' => 'Euskara',
			'fa' => 'فارسی',
			'fi' => 'Suomi',
			'fr' => 'Français',
			'gl' => 'Galego',
			'he' => 'עברית',
			'hi' => 'हिन्दी',
			'hr' => 'Hrvatski',
			'hu' => 'Magyar',
			'hy' => 'Հայերեն',
			'id' => 'Bahasa Indonesia',
			'is' => 'Íslenska',
			'it' => 'Italiano',
			'ja' => '日本語',
			'ka' => 'ქართული',
			'ko' => '한국어',
			'lt' => 'Lietuvių',
			'lv' => 'Latviešu',
			'mk' => 'Македонски',
			'ms' => 'Bahasa Melayu',
			'mt' => 'Malti',
			'nl' => 'Nederlands',
			'nb' => 'Norsk bokmål',
			'nn' => 'Nynorsk',
			'no' => 'Norsk',
			'pl' => 'Polski',
			'pt' => 'Português',
			'ro' => 'Română',
			'ru' => 'Русский',
			'sk' => 'Slovenčina',
			'sl' => 'Slovenščina',
			'sq' => 'Shqip',
			'sr' => 'Српски',
			'sv' => 'Svenska',
			'sw' => 'Kiswahili',
			'th' => 'ภาษาไทย',
			'tl' => 'Filipino',
			'tr' => 'Türkçe',
			'uk' => 'Українська',
			'ur' => 'اردو',
			'vi' => 'Tiếng Việt',
			'zh' => '中文',
		);

		$name = isset( $map[ $lang ] ) ? $map[ $lang ] : strtoupper( $hreflang );

		/**
		 * Filters the native language display name.
		 *
		 * @param string $name     Native language name.
		 * @param string $hreflang Original BCP-47 language code (e.g. "en-US").
		 */
		return (string) apply_filters( 'erankly_ml_language_native_name', $name, $hreflang );
	}
}
