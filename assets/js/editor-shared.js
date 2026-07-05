/* global wp */
/**
 * Shared EasyRankly editor building blocks.
 *
 * Presentational, data-agnostic components and field-group builders reused by
 * both the post editor (assets/js/editor.js) and the Site Editor special-page
 * panels. Each builder receives a small data adapter ({ get, set } keyed by
 * short field names), a config object and a features map, so the same controls
 * can be bound to post meta or to the Site settings Core Data entity.
 */
( function () {
	'use strict';

	const { MediaUpload, MediaUploadCheck } = wp.blockEditor;
	const {
		Button,
		Popover,
		SelectControl,
		TextControl,
		ToggleControl,
	} = wp.components;
	const { createElement: el, useEffect, useRef, useState } = wp.element;
	const { useSelect } = wp.data;
	const { __, sprintf } = wp.i18n;
	const PANEL_ORDER = [
		'erankly-panel--appearance',
		'erankly-panel--social',
		'erankly-panel--visibility',
		'erankly-panel--internal-links',
		'erankly-panel--checklist',
		'erankly-panel--translations',
	];

	// Gutenberg renders plugin document panels before several Core panels and
	// does not expose a placement prop. Keep only EasyRankly panels at the end
	// while preserving their registration order.
	function movePanelsAfterDefaults() {
		const panels = Array.from( window.document.querySelectorAll( '.erankly-panel' ) );
		const panelsByParent = new Map();

		panels.forEach( ( panel ) => {
			const parent = panel.parentElement;

			if ( ! parent ) {
				return;
			}

			if ( ! panelsByParent.has( parent ) ) {
				panelsByParent.set( parent, [] );
			}

			panelsByParent.get( parent ).push( panel );
		} );

		panelsByParent.forEach( ( siblingPanels, parent ) => {
			siblingPanels.sort( ( firstPanel, secondPanel ) => {
				const firstOrder = PANEL_ORDER.findIndex(
					( className ) => firstPanel.classList.contains( className )
				);
				const secondOrder = PANEL_ORDER.findIndex(
					( className ) => secondPanel.classList.contains( className )
				);

				return firstOrder - secondOrder;
			} );

			const children = Array.from( parent.children );
			const trailingPanels = children.slice( -siblingPanels.length );
			const alreadyLast = siblingPanels.every(
				( panel, index ) => panel === trailingPanels[ index ]
			);

			if ( alreadyLast ) {
				return;
			}

			siblingPanels.forEach( ( panel ) => parent.appendChild( panel ) );
		} );
	}

	function mutationTouchesPanels( mutations ) {
		return mutations.some( ( mutation ) => {
			const targetChildren = mutation.target.children
				? Array.from( mutation.target.children )
				: [];
			const targetHasPanel = targetChildren.some(
				( child ) => child.classList && child.classList.contains( 'erankly-panel' )
			);

			if ( targetHasPanel ) {
				return true;
			}

			return Array.from( mutation.addedNodes ).some( ( node ) => {
				const isPanel = node.classList
					&& node.classList.contains( 'erankly-panel' );
				const containsPanel = 'function' === typeof node.querySelector
					&& node.querySelector( '.erankly-panel' );

				return Boolean( isPanel || containsPanel );
			} );
		} );
	}

	function usePanelsAfterDefaults( enabled = true ) {
		useEffect( () => {
			if ( ! enabled ) {
				return undefined;
			}

			let frameId = 0;
			const scheduleMove = () => {
				if ( frameId ) {
					return;
				}

				frameId = window.requestAnimationFrame( () => {
					frameId = 0;
					movePanelsAfterDefaults();
				} );
			};
			const observer = new window.MutationObserver( ( mutations ) => {
				if ( mutationTouchesPanels( mutations ) ) {
					scheduleMove();
				}
			} );

			observer.observe( window.document.body, {
				childList: true,
				subtree: true,
			} );
			scheduleMove();

			return () => {
				observer.disconnect();

				if ( frameId ) {
					window.cancelAnimationFrame( frameId );
				}
			};
		}, [ enabled ] );
	}

	// Resolves the subset of variables whose value is known outside of any
	// specific field (site_name, and the currently edited post's own title),
	// for the "show friendly value" field display. Unrecognized tokens are
	// left as-is (unlike serpResolveVariables, which blanks them for a clean
	// preview) so the field never silently drops data.
	function resolveDisplayVariables( text, { postTitle = '', siteName = '' } = {} ) {
		return text.replace( /{{\s*([a-z0-9_]+)\s*}}/gi, ( match, key ) => {
			switch ( key.toLowerCase() ) {
				case 'site_name':
					return siteName || match;
				case 'post_title':
				case 'seo_title':
					return postTitle || match;
				default:
					return match;
			}
		} );
	}

	function VariableControl( {
		extraActions = null,
		help,
		label,
		limit,
		multiline = false,
		onChange,
		placeholder = '',
		resolveDisplay = null,
		value = '',
		variables = {},
	} ) {
		const fieldRef = useRef( null );
		const controlIdRef = useRef( 'erankly-variable-control-' + Math.random().toString( 36 ).slice( 2 ) );
		const tokenRef = useRef( { start: 0, end: 0, text: '' } );
		const [ isFocused, setIsFocused ] = useState( false );
		const [ suggestOpen, setSuggestOpen ] = useState( false );
		const [ activeIndex, setActiveIndex ] = useState( -1 );

		const closeSuggest = () => {
			setSuggestOpen( false );
			setActiveIndex( -1 );
		};

		// While the suggestions are open, close them on a genuine outside
		// interaction. Relying on the field's blur is unreliable here: the
		// resolve-placeholders overlay swaps the field to its raw {{tag}} on
		// focus, and the suggestion popover renders in a portal, so a click can
		// momentarily blur the input — which previously closed the popup right
		// after it opened. A pointer-down check against both the field and the
		// popover keeps it open through those internal focus shifts.
		useEffect( () => {
			if ( ! suggestOpen ) {
				return undefined;
			}

			const onPointerDown = ( event ) => {
				const target = event.target;
				const inField = fieldRef.current && fieldRef.current.contains( target );
				const inPopover = target && typeof target.closest === 'function'
					? target.closest( '.erankly-editor-variable-popover' )
					: null;

				if ( ! inField && ! inPopover ) {
					setIsFocused( false );
					closeSuggest();
				}
			};

			window.document.addEventListener( 'mousedown', onPointerDown );

			return () => window.document.removeEventListener( 'mousedown', onPointerDown );
		}, [ suggestOpen ] );

		const displayValue = resolveDisplay && ! isFocused ? resolveDisplay( value ) : value;
		const lengthHelp = limit
			? sprintf(
				/* translators: 1: current character count, 2: maximum character count. */
				__( '%1$d of %2$d characters.', 'easyrankly' ),
				value.length,
				limit
			)
			: help;
		const helpId = lengthHelp ? controlIdRef.current + '-help' : undefined;

		// Flatten the grouped variables into a single suggestion list. Rather than
		// a separate "<>" button + popover, the field itself surfaces matching
		// variables as you type — the same interaction as the Redirect rules
		// search filter (focus/click/type opens a menu filtered by the token the
		// caret sits in; picking one replaces that token).
		const flatVariables = [];
		Object.keys( variables ).forEach( ( groupKey ) => {
			const group = variables[ groupKey ] || {};

			Object.keys( group.variables || {} ).forEach( ( variableKey ) => {
				const label = group.variables[ variableKey ];
				const variable = '{{' + variableKey + '}}';

				flatVariables.push( {
					key: variableKey,
					label,
					variable,
					searchText: ( label + ' ' + variableKey + ' ' + variable ).toLowerCase(),
				} );
			} );
		} );

		// The word the caret is inside (from the previous whitespace up to it).
		const computeToken = ( rawValue, caret ) => {
			let start = caret;

			while ( start > 0 && ! /\s/.test( rawValue.charAt( start - 1 ) ) ) {
				start--;
			}

			return { start, end: caret, text: rawValue.slice( start, caret ) };
		};

		const matchVariables = ( token ) => {
			const query = token.trim().toLowerCase();

			return flatVariables.filter( ( item ) => ! query || item.searchText.includes( query ) );
		};

		const suggestions = matchVariables( tokenRef.current.text );

		const refreshSuggest = ( rawValue, caret ) => {
			const token = computeToken( rawValue, caret );

			tokenRef.current = token;
			setActiveIndex( -1 );
			setSuggestOpen( matchVariables( token.text ).length > 0 );
		};

		const openFromControl = ( event ) => {
			const control = event.target;
			const caret = 'number' === typeof control.selectionStart ? control.selectionStart : value.length;

			refreshSuggest( value, caret );
		};

		const applyVariable = ( item ) => {
			const token = tokenRef.current;
			const nextValue = value.slice( 0, token.start ) + item.variable + value.slice( token.end );
			const caret = token.start + item.variable.length;
			const control = fieldRef.current
				? fieldRef.current.querySelector( 'input:not([type="search"]), textarea' )
				: null;

			onChange( nextValue );
			closeSuggest();

			if ( control && 'function' === typeof control.setSelectionRange ) {
				window.requestAnimationFrame( () => {
					control.focus();
					control.setSelectionRange( caret, caret );
				} );
			}
		};

		const controlProps = {
			'aria-describedby': helpId,
			'aria-expanded': suggestOpen,
			id: controlIdRef.current,
			onBlur: ( event ) => {
				const next = event.relatedTarget;

				// Focus moved into the suggestion popover or stayed within the
				// field wrapper — keep the raw tag + popup active.
				if ( next && typeof next.closest === 'function' && (
					next.closest( '.erankly-editor-variable-popover' ) ||
					( fieldRef.current && fieldRef.current.contains( next ) )
				) ) {
					return;
				}

				// A blur with no related target while the popup is open is almost
				// always the popover grabbing focus as it mounts; leave dismissal
				// to the mousedown handler so the popup doesn't vanish the instant
				// it opens. A real outside click still closes it there.
				if ( ! next && suggestOpen ) {
					return;
				}

				setIsFocused( false );
				closeSuggest();
			},
			onChange: ( event ) => {
				const nextValue = event.target.value;
				const caret = 'number' === typeof event.target.selectionStart ? event.target.selectionStart : nextValue.length;

				onChange( nextValue );
				refreshSuggest( nextValue, caret );
			},
			onClick: openFromControl,
			onFocus: ( event ) => {
				setIsFocused( true );
				openFromControl( event );
			},
			onKeyDown: ( event ) => {
				if ( ! suggestOpen || ! suggestions.length ) {
					return;
				}

				if ( 'ArrowDown' === event.key ) {
					event.preventDefault();
					setActiveIndex( ( index ) => Math.min( index + 1, suggestions.length - 1 ) );
				} else if ( 'ArrowUp' === event.key ) {
					event.preventDefault();
					setActiveIndex( ( index ) => Math.max( index - 1, 0 ) );
				} else if ( 'Enter' === event.key ) {
					// Only hijack Enter once a suggestion is highlighted, so it
					// still inserts newlines while typing prose.
					if ( activeIndex >= 0 && suggestions[ activeIndex ] ) {
						event.preventDefault();
						applyVariable( suggestions[ activeIndex ] );
					}
				} else if ( 'Escape' === event.key ) {
					closeSuggest();
				}
			},
			placeholder,
			value: displayValue,
		};

		const suggestMenu = suggestOpen && suggestions.length
			? el(
				Popover,
				{
					anchor: fieldRef.current,
					className: 'erankly-editor-variable-popover',
					focusOnMount: false,
					onClose: closeSuggest,
					placement: 'bottom-start',
				},
				el(
					'div',
					{ className: 'erankly-variable-menu erankly-editor-variable-menu', role: 'listbox' },
					suggestions.map( ( item, index ) => el(
						'button',
						{
							className: 'erankly-variable-option' + ( index === activeIndex ? ' is-active' : '' ),
							key: item.key,
							// Keep the field focused so the caret survives the click.
							onMouseDown: ( event ) => event.preventDefault(),
							onClick: () => applyVariable( item ),
							ref: index === activeIndex
								? ( node ) => {
									if ( node ) {
										node.scrollIntoView( { block: 'nearest' } );
									}
								}
								: undefined,
							role: 'option',
							type: 'button',
						},
						el( 'span', { className: 'erankly-variable-option-primary' }, item.variable ),
						el( 'span', { className: 'erankly-variable-option-secondary' }, item.label )
					) )
				)
			)
			: null;

		return el(
			'div',
			{ className: 'erankly-field' },
			el(
				'div',
				{ className: 'components-base-control erankly-editor-variable-control' },
				el(
					'div',
					{ className: 'components-base-control__field' },
					label && el(
						'label',
						{
							className: 'components-base-control__label',
							htmlFor: controlIdRef.current,
						},
						label
					),
					el(
						'div',
						{
							className: 'erankly-variable-field erankly-editor-variable-field' + ( multiline ? ' erankly-editor-variable-field--multiline' : '' ),
							ref: fieldRef,
						},
						multiline
							? el( 'textarea', Object.assign( {}, controlProps, {
								className: 'components-textarea-control__input',
								rows: 3,
							} ) )
							: el( 'input', Object.assign( {}, controlProps, {
								className: 'components-text-control__input',
								type: 'text',
							} ) ),
						suggestMenu
					)
				),
				lengthHelp && el(
					'p',
					{
						className: 'components-base-control__help',
						id: helpId,
					},
					lengthHelp
				)
			),
			extraActions && el(
				'div',
				{ className: 'erankly-field__actions' },
				extraActions
			)
		);
	}

	function SocialImageControl( { onChange, placeholder = '', value = '', variables = {} } ) {
		return el( VariableControl, {
			extraActions: [
				el(
					MediaUploadCheck,
					{ key: 'select' },
					el( MediaUpload, {
						allowedTypes: [ 'image' ],
						onSelect: ( media ) => onChange( media.url || '' ),
						render: ( { open } ) => el(
							Button,
							{ onClick: open, variant: 'secondary' },
							__( 'Select image', 'easyrankly' )
						),
					} )
				),
				value && el(
					Button,
					{ isDestructive: true, key: 'remove', onClick: () => onChange( '' ), variant: 'tertiary' },
					__( 'Remove', 'easyrankly' )
				),
			],
			label: __( 'Social image URL', 'easyrankly' ),
			onChange,
			placeholder,
			value,
			variables,
		} );
	}

	// Resolves the variables a preview can know about in the editor; the rest
	// are stripped so raw {{tokens}} never show up in the SERP preview.
	function serpResolveVariables( text, postTitle, siteName ) {
		return text
			.replace( /{{\s*([a-z0-9_]+)\s*}}/gi, ( match, key ) => {
				switch ( key.toLowerCase() ) {
					case 'post_title':
					case 'seo_title':
						return postTitle;
					case 'site_name':
						return siteName;
					default:
						return '';
				}
			} )
			.replace( /\s+/g, ' ' )
			.trim();
	}

	function serpBreadcrumb( permalink ) {
		try {
			const url = new URL( permalink );
			const segments = url.pathname.split( '/' ).filter( Boolean ).map( ( segment ) => {
				try {
					return decodeURIComponent( segment );
				} catch ( error ) {
					return segment;
				}
			} );

			return [ url.host ].concat( segments ).join( ' › ' );
		} catch ( error ) {
			return permalink;
		}
	}

	function serpFirstContentImage( content ) {
		if ( ! content ) {
			return '';
		}

		const document = new window.DOMParser().parseFromString( content, 'text/html' );
		const images = document.querySelectorAll( 'img[src]' );

		for ( const image of images ) {
			if ( image.closest( 'pre, code' ) ) {
				continue;
			}

			const src = image.getAttribute( 'src' ) || '';

			try {
				const url = new URL( src );

				if ( 'http:' === url.protocol || 'https:' === url.protocol ) {
					return url.href;
				}
			} catch ( error ) {
				// Ignore relative or malformed URLs, matching the frontend resolver.
			}
		}

		return '';
	}

	// Presentational SERP preview: callers compute the resolved title, description
	// and image and pass them in, so the same markup serves any context.
	function SerpPreviewView( { description, imageUrl = '', permalink = '', siteIconUrl = '', siteName = '', title } ) {
		return el(
			'div',
			{ 'aria-hidden': 'true', className: 'erankly-serp-preview' },
			el(
				'div',
				{ className: 'erankly-serp-preview__source' },
				siteIconUrl
					? el( 'img', { alt: '', className: 'erankly-serp-preview__favicon', src: siteIconUrl } )
					: el( 'span', { className: 'erankly-serp-preview__favicon' } ),
				el(
					'div',
					{ className: 'erankly-serp-preview__origin' },
					el( 'div', { className: 'erankly-serp-preview__site' }, siteName ),
					el( 'div', { className: 'erankly-serp-preview__breadcrumb' }, serpBreadcrumb( permalink ) )
				)
			),
			el(
				'div',
				{ className: 'erankly-serp-preview__body' },
				el(
					'div',
					{ className: 'erankly-serp-preview__text' },
					el( 'div', { className: 'erankly-serp-preview__title' }, title ),
					el( 'div', { className: 'erankly-serp-preview__description' }, description )
				),
				imageUrl && el( 'img', {
					alt: '',
					className: 'erankly-serp-preview__thumbnail',
					src: imageUrl,
				} )
			)
		);
	}

	// Builds the "Search appearance" controls. `data` is a { get, set } adapter
	// keyed by short field names; `features` toggles the optional controls.
	function searchAppearanceFields( { config, data, features = {} } ) {
		const resolveDisplay = config.resolvePlaceholders
			? ( text ) => resolveDisplayVariables( text, { postTitle: config.postTitle, siteName: config.siteName } )
			: null;
		const fields = [
			el( VariableControl, {
				key: 'title',
				label: __( 'Meta title', 'easyrankly' ),
				limit: 65,
				onChange: ( value ) => data.set( 'title', value ),
				placeholder: config.titlePlaceholder,
				resolveDisplay,
				value: data.get( 'title' ),
				variables: config.variables,
			} ),
			el( VariableControl, {
				key: 'description',
				label: __( 'Meta description', 'easyrankly' ),
				limit: 160,
				multiline: true,
				onChange: ( value ) => data.set( 'description', value ),
				placeholder: config.descriptionPlaceholder,
				resolveDisplay,
				value: data.get( 'description' ),
				variables: config.variables,
			} ),
		];

		if ( features.canonical ) {
			fields.push( el( VariableControl, {
				key: 'canonical',
				label: __( 'Canonical URL', 'easyrankly' ),
				onChange: ( value ) => data.set( 'canonical', value ),
				resolveDisplay,
				value: data.get( 'canonical' ),
				variables: config.variables,
			} ) );
		}

		if ( features.breadcrumbName ) {
			fields.push( el( TextControl, {
				help: __( 'Optional short name used in visible breadcrumbs and BreadcrumbList schema.', 'easyrankly' ),
				key: 'breadcrumb',
				label: __( 'Breadcrumb name', 'easyrankly' ),
				onChange: ( value ) => data.set( 'breadcrumb_name', value ),
				value: data.get( 'breadcrumb_name' ),
			} ) );
		}

		return fields;
	}

	// Builds the "Social sharing" controls.
	function socialFields( { config, data, features = {} } ) {
		const resolveDisplay = config.resolvePlaceholders
			? ( text ) => resolveDisplayVariables( text, { postTitle: config.postTitle, siteName: config.siteName } )
			: null;
		const fields = [
			el( VariableControl, {
				key: 'og_title',
				label: __( 'Open Graph title', 'easyrankly' ),
				limit: 60,
				onChange: ( value ) => data.set( 'og_title', value ),
				placeholder: config.ogTitlePlaceholder,
				resolveDisplay,
				value: data.get( 'og_title' ),
				variables: config.variables,
			} ),
			el( VariableControl, {
				key: 'og_description',
				label: __( 'Open Graph description', 'easyrankly' ),
				limit: 200,
				multiline: true,
				onChange: ( value ) => data.set( 'og_description', value ),
				placeholder: config.ogDescriptionPlaceholder,
				resolveDisplay,
				value: data.get( 'og_description' ),
				variables: config.variables,
			} ),
			el( VariableControl, {
				key: 'twitter_title',
				label: __( 'X (Twitter) title', 'easyrankly' ),
				limit: 70,
				onChange: ( value ) => data.set( 'twitter_title', value ),
				placeholder: config.twitterTitlePlaceholder,
				resolveDisplay,
				value: data.get( 'twitter_title' ),
				variables: config.variables,
			} ),
			el( VariableControl, {
				key: 'twitter_description',
				label: __( 'X (Twitter) description', 'easyrankly' ),
				limit: 200,
				multiline: true,
				onChange: ( value ) => data.set( 'twitter_description', value ),
				placeholder: config.twitterDescriptionPlaceholder,
				resolveDisplay,
				value: data.get( 'twitter_description' ),
				variables: config.variables,
			} ),
		];

		if ( features.cardType ) {
			fields.push( el( SelectControl, {
				key: 'card_type',
				label: __( 'X (Twitter) card type', 'easyrankly' ),
				onChange: ( value ) => data.set( 'twitter_card_type', value ),
				options: [
					{ label: __( 'Default (summary_large_image)', 'easyrankly' ), value: '' },
					{ label: 'summary', value: 'summary' },
				],
				value: data.get( 'twitter_card_type' ),
			} ) );
		}

		fields.push( el( SocialImageControl, {
			key: 'image',
			onChange: ( value ) => data.set( 'social_image_url', value ),
			placeholder: config.socialImagePlaceholder,
			value: data.get( 'social_image_url' ),
			variables: config.variables,
		} ) );

		return fields;
	}

	// Builds the "Search visibility" controls.
	function visibilityFields( { config, data, features = {} } ) {
		const toggle = ( key ) => ( value ) => data.set( key, value );
		const fields = [];

		if ( config.simplifiedMode ) {
			fields.push( el( ToggleControl, {
				checked: Boolean( data.get( 'noindex' ) && data.get( 'disable_sitemap' ) ),
				help: __( 'Sets noindex and removes this page from the sitemap.', 'easyrankly' ),
				key: 'hide',
				label: __( 'Hide from search results', 'easyrankly' ),
				onChange: ( value ) => {
					data.set( 'noindex', value );
					data.set( 'disable_sitemap', value );
				},
			} ) );
		} else {
			fields.push(
				el( ToggleControl, {
					checked: Boolean( data.get( 'noindex' ) ),
					key: 'noindex',
					label: __( 'Noindex', 'easyrankly' ),
					onChange: toggle( 'noindex' ),
				} ),
				el( ToggleControl, {
					checked: Boolean( data.get( 'nofollow' ) ),
					key: 'nofollow',
					label: __( 'Nofollow', 'easyrankly' ),
					onChange: toggle( 'nofollow' ),
				} ),
				el( ToggleControl, {
					checked: Boolean( data.get( 'noarchive' ) ),
					key: 'noarchive',
					label: __( 'Noarchive', 'easyrankly' ),
					onChange: toggle( 'noarchive' ),
				} )
			);

			if ( false !== features.disableSitemap ) {
				fields.push( el( ToggleControl, {
					checked: Boolean( data.get( 'disable_sitemap' ) ),
					key: 'disable_sitemap',
					label: __( 'Disable sitemap', 'easyrankly' ),
					onChange: toggle( 'disable_sitemap' ),
				} ) );
			}
		}

		if ( features.excludeQueries ) {
			fields.push(
				el( ToggleControl, {
					checked: Boolean( data.get( 'exclude_search' ) ),
					key: 'exclude_search',
					label: __( 'Exclude from site search queries', 'easyrankly' ),
					onChange: toggle( 'exclude_search' ),
				} ),
				el( ToggleControl, {
					checked: Boolean( data.get( 'exclude_archive' ) ),
					key: 'exclude_archive',
					label: __( 'Exclude from archive queries', 'easyrankly' ),
					onChange: toggle( 'exclude_archive' ),
				} )
			);
		}

		if ( features.newsSitemap ) {
			fields.push( el( ToggleControl, {
				checked: Boolean( data.get( 'exclude_from_news' ) ),
				key: 'exclude_from_news',
				label: __( 'Exclude from Google News sitemap', 'easyrankly' ),
				onChange: toggle( 'exclude_from_news' ),
			} ) );
		}

		return fields;
	}

	const SEO_CHECKLIST_TITLE_LIMIT = 65;
	const SEO_CHECKLIST_DESCRIPTION_LIMIT = 160;
	const SEO_CHECKLIST_MIN_CONTENT_LENGTH = 300;

	const SEO_CHECKLIST_GROUP_LABELS = {
		appearance: __( 'Search appearance', 'easyrankly' ),
		indexing: __( 'Indexing', 'easyrankly' ),
	};

	function getSeoChecklistItemDefinitions( simplifiedMode ) {
		const items = [
			{
				group: 'appearance',
				key: 'title',
				label: __( 'SEO title within recommended length', 'easyrankly' ),
			},
			{
				group: 'appearance',
				key: 'description',
				label: __( 'Meta description within recommended length', 'easyrankly' ),
			},
			{
				group: 'appearance',
				key: 'preview_image',
				label: __( 'Preview image available', 'easyrankly' ),
			},
			{
				group: 'indexing',
				key: 'indexable',
				label: __( 'Indexable by search engines', 'easyrankly' ),
			},
			{
				group: 'indexing',
				key: 'content',
				label: __( 'Minimum content length', 'easyrankly' ),
			},
		];

		if ( ! simplifiedMode ) {
			items.push(
				{
					group: 'appearance',
					key: 'social_image',
					label: __( 'Custom social image', 'easyrankly' ),
				},
				{
					group: 'appearance',
					key: 'canonical',
					label: __( 'Canonical URL set', 'easyrankly' ),
				}
			);
		}

		return items;
	}

	function checklistTextWithinLimit( text, limit ) {
		const normalized = String( text || '' ).replace( /\s+/g, ' ' ).trim();

		if ( '' === normalized ) {
			return false;
		}

		return normalized.length <= limit;
	}

	function checklistStripContent( content ) {
		const document = new window.DOMParser().parseFromString( String( content || '' ), 'text/html' );

		return ( document.body.textContent || '' ).replace( /\s+/g, ' ' ).trim();
	}

	function checklistEffectiveTitle( customTitle, postTitle, config ) {
		const resolved = serpResolveVariables( customTitle, postTitle, config.siteName || '' );

		return resolved
			|| config.titlePlaceholder
			|| postTitle
			|| config.siteName
			|| '';
	}

	function checklistEffectiveDescription( customDescription, postTitle, config, content, excerpt ) {
		const resolved = serpResolveVariables( customDescription, postTitle, config.siteName || '' );

		if ( resolved ) {
			return resolved;
		}

		if ( config.descriptionPlaceholder ) {
			return config.descriptionPlaceholder;
		}

		const source = String( excerpt || '' ).trim() || checklistStripContent( content );

		return source.slice( 0, config.descriptionLimit || SEO_CHECKLIST_DESCRIPTION_LIMIT );
	}

	function evaluateSeoChecklistState( state, config ) {
		const titleLimit = config.titleLimit || SEO_CHECKLIST_TITLE_LIMIT;
		const descriptionLimit = config.descriptionLimit || SEO_CHECKLIST_DESCRIPTION_LIMIT;
		const minContentLength = config.minContentLength || SEO_CHECKLIST_MIN_CONTENT_LENGTH;
		const title = checklistEffectiveTitle( state.customTitle, state.postTitle, config );
		const description = checklistEffectiveDescription(
			state.customDescription,
			state.postTitle,
			config,
			state.content,
			state.excerpt
		);
		const contentText = String( state.excerpt || '' ).trim() || checklistStripContent( state.content );

		return {
			title: checklistTextWithinLimit( title, titleLimit ),
			description: checklistTextWithinLimit( description, descriptionLimit ),
			preview_image: state.featuredMedia > 0
				|| '' !== serpFirstContentImage( state.content )
				|| Boolean( config.hasDefaultPreviewImage ),
			indexable: ! state.noindex,
			content: contentText.length >= minContentLength,
			social_image: state.ogImageId > 0 || '' !== String( state.socialImageUrl || '' ).trim(),
			canonical: '' !== String( state.canonical || '' ).trim(),
		};
	}

	function buildSeoChecklistItems( definitions, doneState ) {
		return definitions.map( ( item ) => ( {
			...item,
			done: Boolean( doneState[ item.key ] ),
		} ) );
	}

	function groupSeoChecklistItems( items ) {
		const groups = [];

		items.forEach( ( item ) => {
			let group = groups.find( ( entry ) => entry.key === item.group );

			if ( ! group ) {
				group = {
					items: [],
					key: item.group,
					label: SEO_CHECKLIST_GROUP_LABELS[ item.group ] || item.group,
				};
				groups.push( group );
			}

			group.items.push( item );
		} );

		return groups;
	}

	function SeoChecklistView( { items } ) {
		const done = items.filter( ( item ) => item.done ).length;
		let status = 'is-partial';

		if ( 0 === done ) {
			status = 'is-incomplete';
		} else if ( done === items.length ) {
			status = 'is-complete';
		}

		const groups = groupSeoChecklistItems( items );

		return el(
			'div',
			{ className: 'erankly-seo-checklist ' + status },
			el(
				'div',
				{ className: 'erankly-seo-checklist-intro' },
				el(
					'p',
					{ className: 'description erankly-seo-checklist-help' },
					__( 'Complete these items to improve this page\'s search appearance.', 'easyrankly' )
				),
				el( 'span', { className: 'erankly-seo-checklist-count' }, done + '/' + items.length )
			),
			groups.map( ( group ) => el(
				'div',
				{ className: 'erankly-seo-checklist-group', key: group.key },
				el( 'p', { className: 'erankly-seo-checklist-group-label' }, group.label ),
				el(
					'ul',
					{ className: 'erankly-seo-checklist-items' },
					group.items.map( ( item ) => el(
						'li',
						{
							className: 'erankly-seo-checklist-item' + ( item.done ? ' is-done' : '' ),
							key: item.key,
						},
						el(
							'span',
							{ 'aria-hidden': true, className: 'erankly-seo-checklist-check' },
							el( 'svg', {
								fill: 'none',
								stroke: 'currentColor',
								strokeLinecap: 'round',
								strokeLinejoin: 'round',
								strokeWidth: 3,
								viewBox: '0 0 24 24',
							}, el( 'path', { d: 'M5 12.5l4.5 4.5L19 7.5' } ) )
						),
						el( 'span', { className: 'erankly-seo-checklist-label' }, item.label )
					) )
				)
			) )
		);
	}

	function usePostSeoChecklistItems( config = window.eranklyEditor || {} ) {
		const state = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			const meta = editor.getEditedPostAttribute( 'meta' ) || {};

			return {
				canonical: String( meta._erankly_canonical || '' ),
				content: editor.getEditedPostAttribute( 'content' ) || '',
				customDescription: String( meta._erankly_description || '' ),
				customTitle: String( meta._erankly_title || '' ),
				excerpt: editor.getEditedPostAttribute( 'excerpt' ) || '',
				featuredMedia: editor.getEditedPostAttribute( 'featured_media' ) || 0,
				noindex: Boolean( meta._erankly_noindex ),
				ogImageId: parseInt( meta._erankly_og_image_id, 10 ) || 0,
				postTitle: editor.getEditedPostAttribute( 'title' ) || '',
				socialImageUrl: String( meta._erankly_social_image_url || '' ),
			};
		}, [] );

		const definitions = getSeoChecklistItemDefinitions( Boolean( config.simplifiedMode ) );
		const doneState = evaluateSeoChecklistState( state, config );

		return buildSeoChecklistItems( definitions, doneState );
	}

	function seoChecklistFields() {
		const items = usePostSeoChecklistItems( window.eranklyEditor || {} );

		return [ el( SeoChecklistView, { items, key: 'checklist' } ) ];
	}

	window.eranklyShared = {
		SeoChecklistView,
		SerpPreviewView,
		SocialImageControl,
		VariableControl,
		buildSeoChecklistItems,
		evaluateSeoChecklistState,
		getSeoChecklistItemDefinitions,
		searchAppearanceFields,
		seoChecklistFields,
		serpBreadcrumb,
		serpFirstContentImage,
		serpResolveVariables,
		socialFields,
		usePanelsAfterDefaults,
		usePostSeoChecklistItems,
		visibilityFields,
	};
}() );
