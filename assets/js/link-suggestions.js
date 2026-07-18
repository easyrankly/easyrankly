/* global eranklyLinkSuggestions, wp */
( function () {
	'use strict';

	const { apiFetch } = wp;
	const {
		Badge,
		Button,
		ExternalLink,
		Flex,
		FlexBlock,
		FlexItem,
		Notice,
		__experimentalDivider: Divider,
		__experimentalVStack: VStack,
	} = wp.components;
	const { createElement: el, Fragment, useEffect, useState } = wp.element;
	const { __ } = wp.i18n;

	const Stack = VStack || 'div';
	const stackProps = VStack ? { spacing: 4 } : { className: 'erankly-internal-links-stack' };

	function confidenceLabel( confidence ) {
		if ( 'high' === confidence ) {
			return __( 'High', 'easyrankly' );
		}

		if ( 'medium' === confidence ) {
			return __( 'Medium', 'easyrankly' );
		}

		return __( 'Low', 'easyrankly' );
	}

	function confidenceBadgeVariant( confidence ) {
		if ( 'high' === confidence ) {
			return 'success';
		}

		if ( 'medium' === confidence ) {
			return 'secondary';
		}

		return 'default';
	}

	function ConfidenceBadge( { confidence } ) {
		const label = confidenceLabel( confidence );

		if ( Badge ) {
			return el( Badge, { variant: confidenceBadgeVariant( confidence ) }, label );
		}

		return el( 'span', { className: 'erankly-internal-links-badge' }, label );
	}

	function SuggestionRow( { item } ) {
		const targetUrl = item.edit_url || item.url;

		return el(
			'li',
			{ className: 'erankly-internal-links-item' },
			el(
				Flex,
				{ align: 'flex-start', gap: 3 },
				el(
					FlexBlock,
					null,
					el( ExternalLink, { href: targetUrl }, item.title ),
					el(
						'span',
						{ className: 'erankly-internal-links-item__meta' },
						el( 'span', { className: 'erankly-internal-links-item__anchor' }, '\u201c' + item.anchor + '\u201d' ),
						item.placement_hint && el( 'span', { className: 'erankly-internal-links-item__detail' }, item.placement_hint )
					)
				),
				el( FlexItem, { className: 'erankly-internal-links-item__badge' }, el( ConfidenceBadge, { confidence: item.confidence } ) )
			)
		);
	}

	function SuggestionSection( { emptyMessage, items, title, type } ) {
		return el(
			Fragment,
			null,
			el( 'p', { className: 'erankly-internal-links-section-label' }, title ),
			items.length
				? el(
					'ul',
					{ className: 'erankly-internal-links-list' },
					items.map( ( item ) => el( SuggestionRow, { item, key: type + '-' + item.post_id } ) )
				)
				: el( 'p', { className: 'erankly-internal-links-empty' }, emptyMessage )
		);
	}

	function StatusLine( { message, type } ) {
		if ( ! message ) {
			return null;
		}

		return el(
			'p',
			{
				className: 'erankly-internal-links-status-line' + ( type ? ' is-' + type : '' ),
				role: 'status',
			},
			message
		);
	}

	function InternalLinksPanelContent( { postId } ) {
		const config = window.eranklyLinkSuggestions || {};
		const [ status, setStatus ] = useState( null );
		const [ busy, setBusy ] = useState( false );
		const [ inbound, setInbound ] = useState( [] );
		const [ outbound, setOutbound ] = useState( [] );
		const [ hasLoaded, setHasLoaded ] = useState( false );

		function applyResponse( response ) {
			setInbound( Array.isArray( response.inbound ) ? response.inbound : [] );
			setOutbound( Array.isArray( response.outbound ) ? response.outbound : [] );
			setHasLoaded( true );
		}

		function loadCached() {
			if ( ! postId ) {
				return;
			}

			setBusy( true );
			setStatus( null );

			apiFetch( {
				path: '/erankly/v1/links/ai-suggestions?post_id=' + encodeURIComponent( postId ),
			} )
				.then( ( response ) => {
					applyResponse( response );

					if ( response.cached && ( response.inbound.length || response.outbound.length ) ) {
						setStatus( { type: 'info', message: __( 'Showing cached suggestions.', 'easyrankly' ) } );
					}
				} )
				.catch( () => {
					// Ignore cache miss errors on first load.
				} )
				.finally( () => setBusy( false ) );
		}

		function generate( force ) {
			if ( ! postId ) {
				setStatus( { type: 'error', message: __( 'Save a draft first.', 'easyrankly' ) } );
				return;
			}

			setBusy( true );
			setStatus( null );

			apiFetch( {
				path: '/erankly/v1/links/ai-suggestions',
				method: 'POST',
				data: {
					force: Boolean( force ),
					post_id: postId,
				},
			} )
				.then( ( response ) => {
					applyResponse( response );

					if ( ! response.inbound.length && ! response.outbound.length ) {
						setStatus( { type: 'info', message: __( 'No strong link opportunities found.', 'easyrankly' ) } );
						return;
					}

					setStatus( { type: 'success', message: __( 'Suggestions updated.', 'easyrankly' ) } );
				} )
				.catch( ( error ) => {
					setStatus( {
						type: 'error',
						message: error.message || __( 'Could not generate link suggestions.', 'easyrankly' ),
					} );
				} )
				.finally( () => setBusy( false ) );
		}

		useEffect( () => {
			loadCached();
		}, [ postId ] );

		if ( ! config.graphBuilt ) {
			return el(
				Notice,
				{ isDismissible: false, status: 'warning' },
				__( 'The link graph has not been built yet. It will build on first use, or rebuild it under EasyRankly → Internal links.', 'easyrankly' )
			);
		}

		if ( ! config.aiEnabled ) {
			return el(
				Notice,
				{ isDismissible: false, status: 'warning' },
				__( 'Enable AI features under EasyRankly → Features.', 'easyrankly' )
			);
		}

		return el(
			Stack,
			stackProps,
			el(
				Button,
				{
					disabled: busy,
					isBusy: busy,
					onClick: () => generate( hasLoaded ),
					variant: 'secondary',
					__next40pxDefaultSize: true,
				},
				hasLoaded ? __( 'Refresh', 'easyrankly' ) : __( 'Get suggestions', 'easyrankly' )
			),
			status && 'error' === status.type && el(
				Notice,
				{
					isDismissible: false,
					status: 'error',
				},
				status.message
			),
			status && 'error' !== status.type && el( StatusLine, { message: status.message, type: status.type } ),
			hasLoaded && el(
				Fragment,
				null,
				el( SuggestionSection, {
					emptyMessage: __( 'None suggested.', 'easyrankly' ),
					items: outbound,
					title: __( 'Add on this page', 'easyrankly' ),
					type: 'outbound',
				} ),
				Divider && el( Divider, null ),
				el( SuggestionSection, {
					emptyMessage: __( 'None suggested.', 'easyrankly' ),
					items: inbound,
					title: __( 'Add on other pages', 'easyrankly' ),
					type: 'inbound',
				} )
			)
		);
	}

	function internalLinksPanel( PluginDocumentSettingPanel, postId ) {
		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel erankly-panel--internal-links',
				name: 'erankly-internal-links',
				title: __( 'Internal links', 'easyrankly' ),
			},
			el( InternalLinksPanelContent, { postId } )
		);
	}

	function renderClassicConfidenceBadge( confidence ) {
		const span = document.createElement( 'span' );
		span.className = 'erankly-internal-links-badge';
		span.textContent = confidenceLabel( confidence );
		return span;
	}

	function renderClassicResults( container, inbound, outbound ) {
		const results = container.querySelector( '[data-erankly-links-results]' );

		if ( ! results ) {
			return;
		}

		results.innerHTML = '';
		results.hidden = false;

		function appendSection( title, items ) {
			const label = document.createElement( 'p' );
			label.className = 'erankly-internal-links-section-label';
			label.textContent = title;
			results.appendChild( label );

			if ( ! items.length ) {
				const empty = document.createElement( 'p' );
				empty.className = 'erankly-internal-links-empty';
				empty.textContent = eranklyLinkSuggestions.i18n.noneSuggested;
				results.appendChild( empty );
				return;
			}

			const list = document.createElement( 'ul' );
			list.className = 'erankly-internal-links-list';

			items.forEach( function ( item ) {
				const li = document.createElement( 'li' );
				li.className = 'erankly-internal-links-item';

				const row = document.createElement( 'div' );
				row.className = 'erankly-internal-links-item__row';

				const main = document.createElement( 'div' );
				main.className = 'erankly-internal-links-item__main';

				const titleLink = document.createElement( 'a' );
				titleLink.className = 'erankly-internal-links-item__title';
				titleLink.href = item.edit_url || item.url || '#';
				titleLink.target = '_blank';
				titleLink.rel = 'noopener noreferrer';
				titleLink.textContent = item.title;
				main.appendChild( titleLink );

				const meta = document.createElement( 'span' );
				meta.className = 'erankly-internal-links-item__meta';

				const anchor = document.createElement( 'span' );
				anchor.className = 'erankly-internal-links-item__anchor';
				anchor.textContent = '\u201c' + ( item.anchor || '' ) + '\u201d';
				meta.appendChild( anchor );

				if ( item.placement_hint ) {
					const detail = document.createElement( 'span' );
					detail.className = 'erankly-internal-links-item__detail';
					detail.textContent = item.placement_hint;
					meta.appendChild( detail );
				}

				main.appendChild( meta );
				row.appendChild( main );

				const badgeWrap = document.createElement( 'div' );
				badgeWrap.className = 'erankly-internal-links-item__badge';
				badgeWrap.appendChild( renderClassicConfidenceBadge( item.confidence ) );
				row.appendChild( badgeWrap );

				li.appendChild( row );
				list.appendChild( li );
			} );

			results.appendChild( list );
		}

		appendSection( eranklyLinkSuggestions.i18n.outboundTitle, outbound );

		const divider = document.createElement( 'hr' );
		divider.className = 'erankly-internal-links-divider';
		results.appendChild( divider );

		appendSection( eranklyLinkSuggestions.i18n.inboundTitle, inbound );
	}

	function bindClassicInternalLinks( root ) {
		const panel = root.querySelector( '[data-erankly-internal-links]' );

		if ( ! panel || ! window.eranklyLinkSuggestions ) {
			return;
		}

		const postId = parseInt( panel.getAttribute( 'data-erankly-post-id' ), 10 );
		const generateButton = panel.querySelector( '[data-erankly-links-generate]' );
		const refreshButton = panel.querySelector( '[data-erankly-links-refresh]' );
		const statusEl = panel.querySelector( '[data-erankly-links-status]' );
		let hasLoaded = false;

		function setStatus( message, type ) {
			if ( ! statusEl ) {
				return;
			}

			statusEl.textContent = message || '';
			statusEl.classList.toggle( 'is-error', 'error' === type );
			statusEl.classList.toggle( 'is-success', 'success' === type );
		}

		function setBusy( busy ) {
			if ( generateButton ) {
				generateButton.disabled = busy;
			}

			if ( refreshButton ) {
				refreshButton.disabled = busy;
			}
		}

		function request( force ) {
			if ( ! postId ) {
				setStatus( eranklyLinkSuggestions.i18n.saveDraft, 'error' );
				return;
			}

			setBusy( true );
			setStatus( eranklyLinkSuggestions.i18n.working, '' );

			window.fetch( eranklyLinkSuggestions.restUrl, {
				body: JSON.stringify( {
					force: Boolean( force ),
					post_id: postId,
				} ),
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': eranklyLinkSuggestions.nonce,
				},
				method: 'POST',
			} )
				.then( function ( response ) {
					return response.json().then( function ( data ) {
						if ( ! response.ok ) {
							throw new Error( data.message || eranklyLinkSuggestions.i18n.error );
						}

						return data;
					} );
				} )
				.then( function ( data ) {
					hasLoaded = true;
					renderClassicResults( panel, data.inbound || [], data.outbound || [] );

					if ( generateButton ) {
						generateButton.classList.add( 'hidden' );
					}

					if ( refreshButton ) {
						refreshButton.classList.remove( 'hidden' );
					}

					if ( ! ( data.inbound || [] ).length && ! ( data.outbound || [] ).length ) {
						setStatus( eranklyLinkSuggestions.i18n.empty, '' );
						return;
					}

					setStatus( eranklyLinkSuggestions.i18n.updated, 'success' );
				} )
				.catch( function ( error ) {
					setStatus( error.message || eranklyLinkSuggestions.i18n.error, 'error' );
				} )
				.finally( function () {
					setBusy( false );
				} );
		}

		function loadCached() {
			if ( ! postId ) {
				return;
			}

			window.fetch(
				eranklyLinkSuggestions.restUrl + '?post_id=' + encodeURIComponent( postId ),
				{
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': eranklyLinkSuggestions.nonce,
					},
				}
			)
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( data ) {
					if ( ! data.cached || ( ! data.inbound.length && ! data.outbound.length ) ) {
						return;
					}

					hasLoaded = true;
					renderClassicResults( panel, data.inbound || [], data.outbound || [] );

					if ( generateButton ) {
						generateButton.classList.add( 'hidden' );
					}

					if ( refreshButton ) {
						refreshButton.classList.remove( 'hidden' );
					}

					setStatus( eranklyLinkSuggestions.i18n.cached, '' );
				} )
				.catch( function () {
					// Ignore cache miss.
				} );
		}

		if ( generateButton ) {
			generateButton.addEventListener( 'click', function () {
				request( hasLoaded );
			} );
		}

		if ( refreshButton ) {
			refreshButton.addEventListener( 'click', function () {
				request( true );
			} );
		}

		loadCached();
	}

	window.eranklyLinkSuggestionsUi = {
		bindClassicInternalLinks,
		internalLinksPanel,
	};
}() );
