/**
 * EasyRankly — Frontend Multilingual Script
 *
 * Handles two shortcodes:
 *
 * 1. Language Switcher [erankly_language_switcher]
 *    Navigates to the selected language URL when the <select> value changes.
 *
 * 2. Translation Notice [erankly_translation_notice]
 *    Reveals the notice card when the visitor's browser language matches an
 *    available translation, fills it with that language's globally configured
 *    texts, and remembers dismissals via localStorage.
 *
 * Vanilla JS, no dependencies.
 *
 * @package EasyRankly
 */
( function () {
	'use strict';

	/**
	 * Returns the primary BCP-47 language subtag (lowercase) from any locale string.
	 * Examples: "en-US" → "en", "zh-Hant" → "zh", "IT" → "it"
	 *
	 * @param {string} lang
	 * @returns {string}
	 */
	function primaryLang( lang ) {
		return ( lang || '' ).split( '-' )[ 0 ].toLowerCase();
	}

	// Language Switcher.
	document.querySelectorAll( 'select[data-erml-switcher]' ).forEach( function ( select ) {
		select.addEventListener( 'change', function () {
			var url = this.value;
			if ( url ) {
				window.location.assign( url );
			}
		} );
	} );

	// Translation Notice.
	document.querySelectorAll( '[data-erml-notice]' ).forEach( function ( el ) {
		var postId      = el.dataset.postId      || '';
		var currentLang = el.dataset.currentLang || '';
		var raw         = el.dataset.translations || '[]';

		// Parse the translations JSON embedded by the shortcode.
		var translations;
		try {
			translations = JSON.parse( raw );
		} catch ( e ) {
			return;
		}

		if ( ! Array.isArray( translations ) || 0 === translations.length ) {
			return;
		}

		// Build a map: primary subtag → first matching translation entry.
		var byLang = {};
		translations.forEach( function ( t ) {
			if ( t && t.hreflang && t.url ) {
				var key = primaryLang( t.hreflang );
				if ( ! byLang[ key ] ) {
					byLang[ key ] = t;
				}
			}
		} );

		// Walk the visitor's language preferences to find the best match.
		var preferred = ( navigator.languages && navigator.languages.length )
			? Array.prototype.slice.call( navigator.languages )
			: [ navigator.language || '' ];

		var currentPrimary = primaryLang( currentLang );
		var match = null;

		for ( var i = 0; i < preferred.length; i++ ) {
			var prim = primaryLang( preferred[ i ] );

			// Reached the article's own language in the preference walk: every
			// remaining preference ranks lower, so the current version already
			// is the best available match — no banner needed.
			if ( prim === currentPrimary ) {
				return;
			}

			if ( byLang[ prim ] ) {
				match = byLang[ prim ];
				break;
			}
		}

		if ( ! match ) {
			return;
		}

		// Respect a previous dismissal stored in localStorage.
		var storageKey = 'erml-notice-dismissed:' + postId + ':' + primaryLang( match.hreflang );
		try {
			if ( window.localStorage.getItem( storageKey ) ) {
				return;
			}
		} catch ( storageErr ) {
			// localStorage not available (private mode, cross-origin iframe, etc.)
			// — show the notice anyway.
		}

		// Replace {language} tokens with the matched translation's native name.
		var nativeName = match.native || match.hreflang.toUpperCase();
		function withLang( value ) {
			return ( value || '' ).replace( /\{language\}/g, nativeName );
		}

		// Fill each element with the matched language's globally configured text,
		// then reveal only the ones that actually have content.
		var titleText = withLang( match.title );
		var bodyText  = withLang( match.text );
		var linkText  = withLang( match.link );

		// Nothing to show in this language — keep the card invisible.
		if ( '' === titleText && '' === bodyText && '' === linkText ) {
			return;
		}

		var titleEl = el.querySelector( '.erml-notice__title' );
		if ( titleEl && '' !== titleText ) {
			titleEl.textContent = titleText;
			titleEl.removeAttribute( 'hidden' );
		}

		var textEl = el.querySelector( '.erml-notice__text' );
		if ( textEl && '' !== bodyText ) {
			textEl.textContent = bodyText;
			textEl.removeAttribute( 'hidden' );
		}

		var link = el.querySelector( '.erml-notice__link' );
		if ( link && '' !== linkText ) {
			link.textContent = linkText;
			link.setAttribute( 'href', match.url );
			link.removeAttribute( 'hidden' );
		}

		// Reveal the notice (remove the server-side [hidden] attribute).
		el.removeAttribute( 'hidden' );

		// Wire the dismiss (×) button.
		var closeBtn = el.querySelector( '.erml-notice__close' );
		if ( closeBtn ) {
			closeBtn.addEventListener( 'click', function () {
				// Persist the dismissal so the banner stays hidden after reload.
				try {
					window.localStorage.setItem( storageKey, '1' );
				} catch ( err ) {
					// Ignore write failures (full storage, etc.).
				}
				el.setAttribute( 'hidden', '' );
			} );
		}
	} );

}() );
