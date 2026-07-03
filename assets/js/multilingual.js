/* global eranklyML */
( function () {
	'use strict';

	// Network Admin "Default" (x-default) radios. Runs independently of the editor search,
	// so it must work even when the localized `eranklyML` object is absent (settings screen).
	function initDefaultRadios() {
		const radios = document.querySelectorAll( 'input[name="erankly_ml_default_site"]' );

		if ( ! radios.length ) {
			return;
		}

		function sync() {
			document.querySelectorAll( '.erankly-ml-is-default-hidden' ).forEach( ( hidden ) => {
				hidden.value = '0';
			} );

			const checked = document.querySelector( 'input[name="erankly_ml_default_site"]:checked' );
			if ( checked ) {
				const hidden = document.getElementById( 'erankly-ml-is-default-' + checked.value );
				if ( hidden ) {
					hidden.value = '1';
				}
			}
		}

		radios.forEach( ( radio ) => radio.addEventListener( 'change', sync ) );
		sync();
	}

	// Editor — cross-site translation search.
	function initSearch() {
		if ( typeof eranklyML === 'undefined' ) {
			return;
		}

		const { restUrl, nonce, i18n } = eranklyML;

		/**
		 * Fetches search results from the REST endpoint.
		 *
		 * @param {string} blogId     Target blog ID.
		 * @param {string} objectType 'post' or 'term'.
		 * @param {string} query      Search string (may be empty for recent items).
		 * @return {Promise<Array>} Resolves to an array of { id, title, url }.
		 */
		async function fetchResults( blogId, objectType, query ) {
			const url = new URL( restUrl );
			url.searchParams.set( 'blog_id', blogId );
			url.searchParams.set( 'object_type', objectType );
			url.searchParams.set( 'q', query );

			try {
				const response = await fetch( url.toString(), {
					headers: { 'X-WP-Nonce': nonce },
					credentials: 'same-origin',
				} );

				if ( ! response.ok ) {
					return [];
				}

				const data = await response.json();
				return Array.isArray( data ) ? data : [];
			} catch ( e ) {
				return [];
			}
		}

		/**
		 * Wires a single site row.
		 *
		 * @param {HTMLElement} siteEl The .erankly-ml-site element.
		 */
		function initSiteRow( siteEl ) {
			const searchEl  = siteEl.querySelector( '[data-erankly-ml-search]' );
			const linkedEl  = siteEl.querySelector( '[data-erankly-ml-linked]' );
			const input     = siteEl.querySelector( '.erankly-ml-search-input' );
			const listEl    = siteEl.querySelector( '.erankly-ml-results' );
			const idInput     = siteEl.querySelector( '.erankly-ml-id-input' );
			const actionEl    = siteEl.querySelector( '.erankly-ml-action-input' );
			const linkedInput = siteEl.querySelector( '.erankly-ml-linked-input' );
			const unlinkBtn   = siteEl.querySelector( '[data-erankly-ml-unlink]' );

			if ( ! searchEl || ! input || ! listEl || ! idInput || ! actionEl ) {
				return;
			}

			const blogId  = input.dataset.eranklyMlBlog;
			const objType = input.dataset.eranklyMlType || 'post';
			let timer     = null;

			function closeList() {
				listEl.hidden = true;
				input.setAttribute( 'aria-expanded', 'false' );
			}

			function renderResults( results ) {
				listEl.innerHTML = '';

				if ( ! results.length ) {
					const empty = document.createElement( 'li' );
					empty.className = 'erankly-autocomplete-status erankly-ml-no-results';
					empty.textContent = i18n.noResults;
					listEl.appendChild( empty );
					listEl.hidden = false;
					input.setAttribute( 'aria-expanded', 'true' );
					return;
				}

				results.forEach( ( item ) => {
					const li = document.createElement( 'li' );
					li.setAttribute( 'role', 'option' );

					const btn = document.createElement( 'button' );
					btn.type = 'button';
					btn.className = 'erankly-autocomplete-item erankly-ml-result-item';
					btn.textContent = item.title;
					btn.addEventListener( 'click', () => select( item ) );

					li.appendChild( btn );
					listEl.appendChild( li );
				} );

				listEl.hidden = false;
				input.setAttribute( 'aria-expanded', 'true' );
			}

			function search( query ) {
				listEl.innerHTML = '';
				const loading = document.createElement( 'li' );
				loading.className = 'erankly-autocomplete-status erankly-ml-loading';
				loading.textContent = i18n.searching;
				listEl.appendChild( loading );
				listEl.hidden = false;
				input.setAttribute( 'aria-expanded', 'true' );

				fetchResults( blogId, objType, query ).then( renderResults );
			}

			function select( item ) {
				idInput.value     = item.id;
				actionEl.value    = 'link';
				if ( linkedInput ) {
					linkedInput.value = item.url || item.title;
				}
				input.value = '';
				closeList();
				searchEl.hidden = true;
				if ( linkedEl ) {
					linkedEl.hidden = false;
				}
			}

			function unlink() {
				// Keep the object id so the save handler deletes the right row, but only
				// flag it for deletion when it was actually linked. A pending (unsaved)
				// selection is just cleared.
				if ( idInput.value && idInput.value !== '0' ) {
					actionEl.value = 'unlink';
				} else {
					actionEl.value = '';
					idInput.value  = '0';
				}
				if ( linkedEl ) {
					linkedEl.hidden = true;
				}
				searchEl.hidden = false;
				input.value = '';
				input.focus();
			}

			input.addEventListener( 'focus', () => {
				if ( listEl.hidden ) {
					search( input.value.trim() );
				}
			} );

			input.addEventListener( 'input', () => {
				clearTimeout( timer );
				timer = setTimeout( () => search( input.value.trim() ), 300 );
			} );

			input.addEventListener( 'keydown', ( e ) => {
				if ( e.key === 'Escape' ) {
					closeList();
				}
			} );

			if ( unlinkBtn ) {
				unlinkBtn.addEventListener( 'click', unlink );
			}

			// Close the dropdown when interacting outside this row.
			document.addEventListener( 'click', ( e ) => {
				if ( ! siteEl.contains( e.target ) ) {
					closeList();
				}
			} );
		}

		document.querySelectorAll( '[data-erankly-ml-site]' ).forEach( initSiteRow );
	}

	document.addEventListener( 'DOMContentLoaded', () => {
		initDefaultRadios();
		initSearch();
	} );
}() );
