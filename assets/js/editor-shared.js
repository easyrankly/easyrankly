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
		__experimentalSpacer: Spacer,
		Button,
		FormTokenField,
		Notice,
		Popover,
		SelectControl,
		TextControl,
		ToggleControl,
	} = wp.components;
	const { createElement: el, useEffect, useRef, useState } = wp.element;
	const { __, sprintf } = wp.i18n;
	const PANEL_ORDER = (
		window.eranklyEditorShared &&
		Array.isArray( window.eranklyEditorShared.panelOrder ) &&
		window.eranklyEditorShared.panelOrder.length
	)
		? window.eranklyEditorShared.panelOrder
		: [
				'erankly-panel--appearance',
				'erankly-panel--social',
				'erankly-panel--schema',
				'erankly-panel--visibility',
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

	// Resolves variables whose preview value is known in this editor
	// (site-level keys, dates, and the currently edited post or sample).
	// Unrecognized tokens are left as-is so the field never silently drops data.
	function resolveDisplayVariables( text, { postTitle = '', siteName = '', siteDescription = '', examples = null } = {} ) {
		const resolved = examples ? { ...examples } : {};

		if ( siteName && ! Object.prototype.hasOwnProperty.call( resolved, 'site_name' ) ) {
			resolved.site_name = siteName;
		}

		if ( siteDescription && ! Object.prototype.hasOwnProperty.call( resolved, 'site_description' ) ) {
			resolved.site_description = siteDescription;
		}

		if ( postTitle ) {
			if ( ! resolved.post_title ) {
				resolved.post_title = postTitle;
			}

			if ( ! resolved.seo_title ) {
				resolved.seo_title = postTitle;
			}
		}

		return text.replace( /{{\s*([a-z0-9_]+)\s*}}/gi, ( match, key ) => {
			const normalizedKey = key.toLowerCase();

			if ( Object.prototype.hasOwnProperty.call( resolved, normalizedKey ) && resolved[ normalizedKey ] ) {
				return resolved[ normalizedKey ];
			}

			return match;
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
		// momentarily blur the input, which previously closed the popup right
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
		// variables as you type, the same interaction as the Redirect rules
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
				// field wrapper. Keep the raw tag + popup active.
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
					{
						className: 'erankly-variable-menu erankly-editor-variable-menu',
						role: 'listbox',
						// Gutenberg popovers size to their content, so the menu can end up wider than
						// the field it hangs from. Pin the menu to the anchor's width so the editor
						// matches the settings pages, where the menu is absolutely positioned inside
						// the field and therefore exactly as wide.
						style: fieldRef.current
							? { width: fieldRef.current.getBoundingClientRect().width }
							: undefined,
					},
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

	function SocialImageControl( { label = '', onChange, placeholder = '', value = '', variables = {} } ) {
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
			label: label || __( 'Social image URL', 'easyrankly' ),
			onChange,
			placeholder,
			value,
			variables,
		} );
	}

	function XImageAltOverride( { data } ) {
		const [ isOpen, setIsOpen ] = useState( '' !== data.get( 'twitter_image_alt' ) );
		const ogImage = data.get( 'og_image_url' ) || data.get( 'social_image_url' );
		const twitterImage = data.get( 'twitter_image_url' ) || data.get( 'social_image_url' );
		const hasOverride = '' !== data.get( 'twitter_image_alt' );
		const imagesDiffer = '' !== twitterImage && twitterImage !== ogImage;

		if ( ! hasOverride && ! imagesDiffer ) {
			return null;
		}

		return el(
			'div',
			{ className: 'erankly-social-image-alt-override' },
			el(
				Button,
				{
					'aria-expanded': isOpen,
					onClick: () => setIsOpen( ! isOpen ),
					variant: 'tertiary',
				},
				__( 'X image alt text override', 'easyrankly' )
			),
			isOpen && el( TextControl, {
				help: __( 'For a different X image only. If blank, uses that image’s Media Library alt text.', 'easyrankly' ),
				label: __( 'X image alt text override', 'easyrankly' ),
				onChange: ( value ) => data.set( 'twitter_image_alt', value ),
				value: data.get( 'twitter_image_alt' ),
			} )
		);
	}

	// Builds the "Search appearance" controls. `data` is a { get, set } adapter
	// keyed by short field names; `features` toggles the optional controls.
	function searchAppearanceFields( { config, data, features = {} } ) {
		const resolveDisplay = config.resolvePlaceholders
			? ( text ) => resolveDisplayVariables( text, { postTitle: config.postTitle, siteName: config.siteName, siteDescription: config.siteDescription, examples: config.variableExamples } )
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
				placeholder: config.canonicalPlaceholder || '',
				resolveDisplay,
				value: data.get( 'canonical' ),
				variables: config.variables,
			} ) );
		}

		if ( features.breadcrumbName ) {
			fields.push( el( TextControl, {
				key: 'breadcrumb',
				label: __( 'Breadcrumb name', 'easyrankly' ),
				onChange: ( value ) => data.set( 'breadcrumb_name', value ),
				placeholder: config.postTitle || '',
				value: data.get( 'breadcrumb_name' ),
			} ) );
		}

		return fields;
	}

	// Builds the "Social sharing" controls.
	function socialFields( { config, data, features = {} } ) {
		const resolveDisplay = config.resolvePlaceholders
			? ( text ) => resolveDisplayVariables( text, { postTitle: config.postTitle, siteName: config.siteName, siteDescription: config.siteDescription, examples: config.variableExamples } )
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
				__next40pxDefaultSize: true,
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

		if ( features.splitSocialImages ) {
			fields.push( el( SocialImageControl, {
				key: 'og_image',
				label: __( 'Open Graph image URL', 'easyrankly' ),
				onChange: ( value ) => data.set( 'og_image_url', value ),
				placeholder: config.socialImagePlaceholder,
				value: data.get( 'og_image_url' ),
				variables: config.variables,
			} ) );
			fields.push( el( TextControl, {
				key: 'og_image_alt',
				help: __( 'Shared by Open Graph and X. If blank, uses the Media Library alt text.', 'easyrankly' ),
				label: __( 'Social image alt text', 'easyrankly' ),
				onChange: ( value ) => data.set( 'og_image_alt', value ),
				value: data.get( 'og_image_alt' ),
			} ) );

			fields.push( el( SocialImageControl, {
				key: 'twitter_image',
				label: __( 'X (Twitter) image URL', 'easyrankly' ),
				onChange: ( value ) => data.set( 'twitter_image_url', value ),
				placeholder: config.socialImagePlaceholder,
				value: data.get( 'twitter_image_url' ),
				variables: config.variables,
			} ) );
			fields.push( el( XImageAltOverride, { data, key: 'twitter_image_alt_override' } ) );
		} else {
			fields.push( el( SocialImageControl, {
				key: 'image',
				onChange: ( value ) => data.set( 'social_image_url', value ),
				placeholder: config.socialImagePlaceholder,
				value: data.get( 'social_image_url' ),
				variables: config.variables,
			} ) );
		}

		return fields;
	}

	function normalizeRobotsDirectiveToken( value ) {
		const token = value && 'object' === typeof value ? value.value : value;

		return String( token || '' ).trim().toLowerCase();
	}

	/**
	 * Resolves the next value emitted by a single-rule token field.
	 *
	 * FormTokenField briefly returns both the existing token and the newly
	 * selected suggestion. Treat the new token as a replacement so opposite
	 * robots rules can never be persisted together.
	 *
	 * @param {Array<string|Object>} values  Tokens emitted by FormTokenField.
	 * @param {string}               current Current directive value.
	 * @param {Array<string>}        allowed Allowed rules for this axis.
	 * @return {{ conflict: boolean, value: string }} Normalized selection.
	 */
	function selectRobotsDirectiveToken( values, current, allowed ) {
		const normalizedCurrent = allowed.includes( normalizeRobotsDirectiveToken( current ) )
			? normalizeRobotsDirectiveToken( current )
			: 'inherit';
		const valid = ( Array.isArray( values ) ? values : [] )
			.map( normalizeRobotsDirectiveToken )
			.filter( ( value ) => allowed.includes( value ) );

		if ( ! valid.length ) {
			return { conflict: false, value: 'inherit' };
		}

		const replacements = valid.filter( ( value ) => value !== normalizedCurrent );

		return {
			conflict: new Set( valid ).size > 1,
			value: replacements.length ? replacements[ replacements.length - 1 ] : valid[ valid.length - 1 ],
		};
	}

	/**
	 * Resolves every robots axis from one shared token field.
	 *
	 * @param {Array<string|Object>} values     Tokens emitted by FormTokenField.
	 * @param {Object}               current    Current value keyed by meta field.
	 * @param {Array<Object>}        directives Robots directive definitions.
	 * @return {{ conflicts: Array<Object>, selections: Object }} Resolved values.
	 */
	function resolveRobotsDirectiveTokens( values, current, directives ) {
		const conflicts = [];
		const selections = {};

		directives.forEach( ( directive ) => {
			const selection = selectRobotsDirectiveToken(
				values,
				current[ directive.key ],
				[ directive.allow, directive.deny ]
			);

			selections[ directive.key ] = selection.value;
			if ( selection.conflict ) {
				conflicts.push( {
					current: current[ directive.key ],
					key: directive.key,
					value: selection.value,
				} );
			}
		} );

		return { conflicts, selections };
	}

	/**
	 * Finds advanced robots combinations whose second setting has no effect.
	 * Codes are translated by the UI, keeping this helper deterministic and
	 * independently testable.
	 *
	 * @param {Object} values Current robots values.
	 * @return {Array<string>} Inconsistency codes.
	 */
	function getRobotsDirectiveInconsistencies( values ) {
		const issues = [];
		const maxSnippet = String(
			undefined === values.maxSnippet || null === values.maxSnippet ? '' : values.maxSnippet
		).trim();
		const maxImagePreview = String(
			undefined === values.maxImagePreview || null === values.maxImagePreview ? '' : values.maxImagePreview
		).trim();

		if ( 'nosnippet' === values.snippet && '' !== maxSnippet ) {
			issues.push( 'nosnippet_max_snippet' );
		} else if ( 'snippet' === values.snippet && '0' === maxSnippet ) {
			issues.push( 'snippet_zero' );
		}

		if (
			'noimageindex' === values.image
			&& [ 'none', 'standard', 'large' ].includes( maxImagePreview )
		) {
			issues.push( 'noimageindex_max_image_preview' );
		}

		if ( 'index' === values.index && Boolean( values.indexIfEmbedded ) ) {
			issues.push( 'index_indexifembedded' );
		}

		return issues;
	}

	function RobotsDirectivesTokenControl( { data, directives } ) {
		const allowed = [];
		const current = {};

		directives.forEach( ( directive ) => {
			const pair = [ directive.allow, directive.deny ];
			const legacyValue = directive.legacy && data.get( directive.legacy )
				? directive.deny
				: 'inherit';
			const storedValue = normalizeRobotsDirectiveToken( data.get( directive.key ) || legacyValue );

			allowed.push( ...pair );
			current[ directive.key ] = pair.includes( storedValue ) ? storedValue : 'inherit';
		} );

		const value = directives
			.map( ( directive ) => current[ directive.key ] )
			.filter( ( directive ) => 'inherit' !== directive );
		const [ feedback, setFeedback ] = useState( null );

		function updateDirective( directive, nextValue ) {
			if ( current[ directive.key ] === nextValue ) {
				return;
			}

			data.set( directive.key, nextValue );

			if ( directive.legacy ) {
				data.set( directive.legacy, directive.deny === nextValue );
			}
		}

		function validateInput( input ) {
			const valid = allowed.includes( normalizeRobotsDirectiveToken( input ) );

			if ( ! valid ) {
				setFeedback( {
					message: __( 'Use one of the suggested robots rules.', 'easyrankly' ),
					status: 'error',
				} );
			}

			return valid;
		}

		function onChange( values ) {
			const result = resolveRobotsDirectiveTokens( values, current, directives );

			directives.forEach( ( directive ) => {
				updateDirective( directive, result.selections[ directive.key ] );
			} );
			setFeedback( null );
		}

		return el(
			Spacer,
			{ marginBottom: 4 },
			el( FormTokenField, {
				__experimentalExpandOnFocus: true,
				__experimentalShowHowTo: false,
				__experimentalValidateInput: validateInput,
				__next40pxDefaultSize: true,
				autoCapitalize: 'none',
				autoComplete: 'off',
				label: __( 'Robots directives', 'easyrankly' ),
				messages: {
					__experimentalInvalid: __( 'Unknown robots rule.', 'easyrankly' ),
					added: __( 'Robots rule applied.', 'easyrankly' ),
					remove: __( 'Remove robots rule', 'easyrankly' ),
					removed: __( 'Robots rule removed. The global setting is inherited.', 'easyrankly' ),
				},
				onChange,
				onInputChange: ( input ) => {
					const normalized = normalizeRobotsDirectiveToken( input );

					if ( ! normalized || allowed.some( ( rule ) => rule.startsWith( normalized ) ) ) {
						setFeedback( null );
					}
				},
				placeholder: __( 'Add robots rule', 'easyrankly' ),
				saveTransform: normalizeRobotsDirectiveToken,
				suggestions: allowed,
				value,
			} ),
			feedback && el( Notice, {
				isDismissible: false,
				status: feedback.status,
			}, feedback.message )
		);
	}

	// Builds the "Search visibility" controls.
	function visibilityFields( { config, data, features = {} } ) {
		const toggle = ( key ) => ( value ) => data.set( key, value );
		const fields = [];

		if ( config.simplifiedMode ) {
			fields.push( el( ToggleControl, {
				checked: Boolean( ( features.triStateRobots ? 'noindex' === ( data.get( 'index_directive' ) || ( data.get( 'noindex' ) ? 'noindex' : 'inherit' ) ) : data.get( 'noindex' ) ) && data.get( 'disable_sitemap' ) ),
				help: __( 'Adds noindex and removes this page from the XML sitemap.', 'easyrankly' ),
				key: 'hide',
				label: __( 'Hide from search results', 'easyrankly' ),
				onChange: ( value ) => {
					if ( features.triStateRobots ) {
						data.set( 'index_directive', value ? 'noindex' : 'inherit' );
					}
					data.set( 'noindex', value );
					data.set( 'disable_sitemap', value );
				},
			} ) );
		} else {
			if ( features.triStateRobots ) {
				const robotsDirectives = [
					{ allow: 'index', deny: 'noindex', key: 'index_directive', legacy: 'noindex' },
					{ allow: 'follow', deny: 'nofollow', key: 'follow_directive', legacy: 'nofollow' },
					{ allow: 'archive', deny: 'noarchive', key: 'archive_directive', legacy: 'noarchive' },
					{ allow: 'snippet', deny: 'nosnippet', key: 'snippet_directive' },
					{ allow: 'imageindex', deny: 'noimageindex', key: 'image_directive' },
				];

				fields.push( el( RobotsDirectivesTokenControl, {
					data,
					directives: robotsDirectives,
					key: 'robots_directives',
				} ) );

				fields.push(
					el( TextControl, {
						key: 'max_snippet',
						label: __( 'Max snippet', 'easyrankly' ),
						min: -1,
						onChange: ( value ) => data.set( 'max_snippet', value ),
						type: 'number',
						value: data.get( 'max_snippet' ),
					} ),
					el( TextControl, {
						key: 'max_video_preview',
						label: __( 'Max video preview', 'easyrankly' ),
						min: -1,
						onChange: ( value ) => data.set( 'max_video_preview', value ),
						type: 'number',
						value: data.get( 'max_video_preview' ),
					} ),
					el( SelectControl, {
						__next40pxDefaultSize: true,
						key: 'max_image_preview',
						label: __( 'Max image preview', 'easyrankly' ),
						onChange: ( value ) => data.set( 'max_image_preview', value ),
						options: [
							{ label: __( 'Inherit', 'easyrankly' ), value: 'inherit' },
							{ label: 'none', value: 'none' },
							{ label: 'standard', value: 'standard' },
							{ label: 'large', value: 'large' },
						],
						value: data.get( 'max_image_preview' ) || 'inherit',
					} ),
					el( ToggleControl, {
						checked: Boolean( data.get( 'indexifembedded' ) ),
						help: __( 'Indexes embedded content despite noindex.', 'easyrankly' ),
						key: 'indexifembedded',
						label: __( 'Index if embedded when noindex applies', 'easyrankly' ),
						onChange: toggle( 'indexifembedded' ),
					} )
				);

				const inconsistencyMessages = {
					index_indexifembedded: __( 'indexifembedded requires noindex; ignored while index is selected.', 'easyrankly' ),
					noimageindex_max_image_preview: __( 'noimageindex disables image previews.', 'easyrankly' ),
					nosnippet_max_snippet: __( 'nosnippet disables text snippets, so Max snippet has no effect.', 'easyrankly' ),
					snippet_zero: __( 'Max snippet 0 disables text snippets.', 'easyrankly' ),
				};
				const inconsistencies = getRobotsDirectiveInconsistencies( {
					image: data.get( 'image_directive' ),
					index: data.get( 'index_directive' ),
					indexIfEmbedded: data.get( 'indexifembedded' ),
					maxImagePreview: data.get( 'max_image_preview' ),
					maxSnippet: data.get( 'max_snippet' ),
					snippet: data.get( 'snippet_directive' ),
				} );

				inconsistencies.forEach( ( issue ) => {
					fields.push( el( Notice, {
						isDismissible: false,
						key: 'robots-inconsistency-' + issue,
						status: 'warning',
					}, inconsistencyMessages[ issue ] ) );
				} );
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
			}

			if ( false !== features.disableSitemap ) {
				fields.push( el( ToggleControl, {
					checked: Boolean( data.get( 'disable_sitemap' ) ),
					help: __( 'Removes content from the XML sitemap; robots unchanged.', 'easyrankly' ),
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
					help: __( 'Internal site search only.', 'easyrankly' ),
					key: 'exclude_search',
					label: __( 'Exclude from site search queries', 'easyrankly' ),
					onChange: toggle( 'exclude_search' ),
				} ),
				el( ToggleControl, {
					checked: Boolean( data.get( 'exclude_archive' ) ),
					help: __( 'Category, date and other archive listings.', 'easyrankly' ),
					key: 'exclude_archive',
					label: __( 'Exclude from archive queries', 'easyrankly' ),
					onChange: toggle( 'exclude_archive' ),
				} )
			);
		}

		if ( features.newsSitemap ) {
			fields.push( el( ToggleControl, {
				checked: Boolean( data.get( 'exclude_from_news' ) ),
				help: __( 'Google News sitemap only.', 'easyrankly' ),
				key: 'exclude_from_news',
				label: __( 'Exclude from Google News sitemap', 'easyrankly' ),
				onChange: toggle( 'exclude_from_news' ),
			} ) );
		}

		return fields;
	}

	// Bottom-of-panel documentation link. URLs come from the shared bundle's
	// docLinks map (erankly_section_doc_links() in PHP); empty entries keep the
	// "#" placeholder until documentation URLs are filled in.
	function PanelDocLink( { section } ) {
		const links = ( window.eranklyEditorShared && window.eranklyEditorShared.docLinks ) || {};
		const url = links[ section ] || '#';

		return el(
			'p',
			{ className: 'erankly-panel-doc-link' },
			el(
				'a',
				{
					className: 'erankly-section-doc-link',
					'data-erankly-doc-section': section,
					href: url,
					rel: 'noopener noreferrer',
					target: '_blank',
				},
				__( 'Learn more' )
			)
		);
	}

	window.eranklyShared = {
		PanelDocLink,
		SocialImageControl,
		VariableControl,
		getRobotsDirectiveInconsistencies,
		normalizeRobotsDirectiveToken,
		resolveRobotsDirectiveTokens,
		searchAppearanceFields,
		selectRobotsDirectiveToken,
		socialFields,
		usePanelsAfterDefaults,
		visibilityFields,
	};
}() );
