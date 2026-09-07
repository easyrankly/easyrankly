/* global eranklyEditor, wp */
( function () {
	'use strict';

	const shared = window.eranklyShared;
	const {
		Button,
		FormTokenField,
		Notice,
		SelectControl,
		TextareaControl,
	} = wp.components;
	const { useDispatch, useSelect } = wp.data;
	// PluginDocumentSettingPanel moved from wp.editPost to wp.editor in WP 6.6.
	// Prefer wp.editor (6.6+, non-deprecated) and fall back to wp.editPost
	// (WP 5.3–6.5) so the post-editor panel still loads on older WordPress.
	const PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	const { createElement: el, Fragment } = wp.element;
	const { __ } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const config = eranklyEditor;

	// Maps the shared builders' short field names to this editor's post meta keys.
	const META_MAP = {
		title: '_erankly_title',
		description: '_erankly_description',
		canonical: '_erankly_canonical',
		breadcrumb_name: '_erankly_breadcrumb_name',
		og_title: '_erankly_og_title',
		og_description: '_erankly_og_description',
		twitter_title: '_erankly_twitter_title',
		twitter_description: '_erankly_twitter_description',
		twitter_card_type: '_erankly_twitter_card_type',
		social_image_url: '_erankly_social_image_url',
		og_image_url: '_erankly_og_image_url',
		og_image_alt: '_erankly_og_image_alt',
		twitter_image_url: '_erankly_twitter_image_url',
		twitter_image_alt: '_erankly_twitter_image_alt',
		noindex: '_erankly_noindex',
		nofollow: '_erankly_nofollow',
		noarchive: '_erankly_noarchive',
		index_directive: '_erankly_index_directive',
		follow_directive: '_erankly_follow_directive',
		archive_directive: '_erankly_archive_directive',
		snippet_directive: '_erankly_snippet_directive',
		image_directive: '_erankly_image_directive',
		max_snippet: '_erankly_max_snippet',
		max_video_preview: '_erankly_max_video_preview',
		max_image_preview: '_erankly_max_image_preview',
		indexifembedded: '_erankly_indexifembedded',
		schema_mode: '_erankly_schema_mode',
		schema_blocks: '_erankly_schema_blocks',
		schema_disabled_types: '_erankly_schema_disabled_types',
		disable_sitemap: '_erankly_disable_sitemap',
		exclude_search: '_erankly_exclude_search',
		exclude_archive: '_erankly_exclude_archive',
		exclude_from_news: '_erankly_exclude_from_news',
	};

	// Optional controls available in the post editor.
	const FEATURES = {
		breadcrumbName: config.breadcrumbsEnabled && ! config.simplifiedMode,
		canonical: ! config.simplifiedMode,
		cardType: true,
		disableSitemap: true,
		excludeQueries: true,
		newsSitemap: config.newsSitemapEnabled,
		splitSocialImages: true,
		triStateRobots: true,
	};

	function uniqueSchemaTypes( tokens ) {
		const seen = new Set();
		const unique = [];

		( Array.isArray( tokens ) ? tokens : [] ).forEach( ( token ) => {
			const value = String( token || '' ).trim();

			if ( ! value ) {
				return;
			}

			const key = value.toLowerCase();

			if ( seen.has( key ) ) {
				return;
			}

			seen.add( key );
			unique.push( value );
		} );

		return unique;
	}

	function validateJsonLd( value ) {
		if ( window.eranklyJsonLd && typeof window.eranklyJsonLd.validate === 'function' ) {
			return window.eranklyJsonLd.validate( value );
		}

		const text = String( value || '' ).replace( /{{\s*[a-z0-9_]+\s*}}/gi, 'x' );

		if ( text.trim() === '' ) {
			return { valid: true, code: '', message: '' };
		}

		try {
			JSON.parse( text );
			return { valid: true, code: '', message: '' };
		} catch ( error ) {
			return {
				valid: false,
				code: 'syntax',
				message: __( 'This is not valid JSON, so it cannot be used as JSON-LD.', 'easyrankly' ),
			};
		}
	}

	function schemaBlocks( value ) {
		return Array.isArray( value ) ? value.filter( ( block ) => block && typeof block === 'object' ) : [];
	}

	function blockHasJson( block ) {
		return !!( block && block.fields && String( block.fields.custom_json || '' ).trim() );
	}

	// Post-meta data adapter shared builders read and write through.
	function usePostData() {
		const meta = useSelect(
			( select ) => select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {},
			[]
		);
		const { editPost } = useDispatch( 'core/editor' );

		return {
			get: ( field ) => {
				const value = meta[ META_MAP[ field ] ];

				return undefined === value || null === value ? '' : value;
			},
			set: ( field, value ) => editPost( { meta: { [ META_MAP[ field ] ]: value } } ),
		};
	}

	function useConfigWithPostContext() {
		const { canonicalPlaceholder, postTitle } = useSelect(
			( select ) => {
				const editor = select( 'core/editor' );

				return {
					canonicalPlaceholder: editor.getPermalink() || '',
					postTitle: editor.getEditedPostAttribute( 'title' ) || '',
				};
			},
			[]
		);

		return { ...config, canonicalPlaceholder, postTitle };
	}

	function GeneralPanel() {
		const data = usePostData();
		const panelConfig = useConfigWithPostContext();

		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel erankly-panel--appearance',
				name: 'erankly-general',
				title: __( 'Search appearance', 'easyrankly' ),
			},
			...shared.searchAppearanceFields( { config: panelConfig, data, features: FEATURES } ),
			...( wp.hooks && wp.hooks.applyFilters ? wp.hooks.applyFilters( 'erankly.editor.searchAppearanceExtras', [], { data, config } ) : [] )
		);
	}

	function SocialPanel() {
		const data = usePostData();
		const panelConfig = useConfigWithPostContext();

		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel erankly-panel--social',
				name: 'erankly-social',
				title: __( 'Social sharing', 'easyrankly' ),
			},
			...shared.socialFields( { config: panelConfig, data, features: FEATURES } ),
			...( wp.hooks && wp.hooks.applyFilters ? wp.hooks.applyFilters( 'erankly.editor.socialExtras', [], { data, config } ) : [] )
		);
	}

	function VisibilityPanel() {
		const data = usePostData();

		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel erankly-panel--visibility',
				name: 'erankly-visibility',
				title: __( 'Search visibility', 'easyrankly' ),
			},
			...shared.visibilityFields( { config, data, features: FEATURES } )
		);
	}

	function bindSchemaSaveFocus() {
		if ( bindSchemaSaveFocus.bound || ! wp.data || typeof wp.data.subscribe !== 'function' ) {
			return;
		}

		bindSchemaSaveFocus.bound = true;

		let wasSaving = false;
		let invalidSchemaDraft = null;

		wp.data.subscribe( function () {
			const editor = wp.data.select( 'core/editor' );

			if ( ! editor || typeof editor.isSavingPost !== 'function' ) {
				return;
			}

			const saving = editor.isSavingPost();

			if ( saving && ! wasSaving ) {
				const invalid = document.querySelector(
					'.erankly-panel--schema textarea[aria-invalid="true"], .erankly-panel--schema .erankly-is-invalid textarea'
				);

				if ( invalid ) {
					const meta = editor.getEditedPostAttribute( 'meta' ) || {};
					const currentBlocks = Array.isArray( meta[ META_MAP.schema_blocks ] )
						? meta[ META_MAP.schema_blocks ]
						: [];

					// The server keeps the previous valid value. Keep a separate
					// editor draft so the REST response cannot make invalid input
					// disappear before the author has a chance to correct it.
					invalidSchemaDraft = JSON.parse( JSON.stringify( currentBlocks ) );

					const panel = invalid.closest( '.components-panel__body' );
					const toggle = panel && ! panel.classList.contains( 'is-opened' )
						? panel.querySelector( '.components-panel__body-toggle' )
						: null;

					if ( toggle ) {
						toggle.click();
					}

					window.setTimeout( function () {
						invalid.focus();
					}, 0 );
				}
			}

			if ( ! saving && wasSaving && invalidSchemaDraft ) {
				const draft = invalidSchemaDraft;
				const meta = editor.getEditedPostAttribute( 'meta' ) || {};
				const savedBlocks = Array.isArray( meta[ META_MAP.schema_blocks ] )
					? meta[ META_MAP.schema_blocks ]
					: [];

				invalidSchemaDraft = null;

				if ( JSON.stringify( savedBlocks ) !== JSON.stringify( draft ) ) {
					wp.data.dispatch( 'core/editor' ).editPost( {
						meta: { [ META_MAP.schema_blocks ]: draft },
					} );
				}

				window.setTimeout( function () {
					const restored = document.querySelector(
						'.erankly-panel--schema textarea[aria-invalid="true"], .erankly-panel--schema .erankly-is-invalid textarea'
					);

					if ( restored ) {
						restored.focus();
					}
				}, 0 );
			}

			wasSaving = saving;
		} );
	}

	function SchemaPanel() {
		const data = usePostData();
		bindSchemaSaveFocus();
		const blocks = schemaBlocks( data.get( 'schema_blocks' ) );
		const mode = data.get( 'schema_mode' ) || 'default';
		const disabledTypes = uniqueSchemaTypes(
			Array.isArray( data.get( 'schema_disabled_types' ) ) ? data.get( 'schema_disabled_types' ) : []
		);
		const hasCustom = blocks.some( blockHasJson );
		const suggestions = Array.isArray( config.schemaTypeSuggestions ) ? config.schemaTypeSuggestions : [];
		const docUrl = String( config.schemaDocUrl || '' );
		const isDisabled = 'disabled' === mode;

		function setMode( value ) {
			data.set( 'schema_mode', value );
		}

		function setBlocks( nextBlocks ) {
			data.set( 'schema_blocks', nextBlocks );
		}

		function addBlock() {
			if ( 'default' === mode ) {
				setMode( 'merge' );
			}

			setBlocks( [ ...blocks, { type: 'custom', fields: { custom_json: '' } } ] );
		}

		const notices = [];

		if ( 'default' === mode && hasCustom ) {
			notices.push(
				el(
					Notice,
					{
						isDismissible: false,
						key: 'default-custom',
						status: 'warning',
					},
					__( 'This content already has custom JSON-LD. Automatic schema ignores those blocks until you switch to Automatic + custom schema.', 'easyrankly' ),
					el(
						'p',
						{ key: 'default-custom-action' },
						el(
							Button,
							{
								onClick: () => setMode( 'merge' ),
								variant: 'secondary',
							},
							__( 'Use Automatic + custom schema', 'easyrankly' )
						)
					)
				)
			);
		}

		if ( 'replace' === mode && ! hasCustom ) {
			notices.push(
				el(
					Notice,
					{
						isDismissible: false,
						key: 'replace-empty',
						status: 'warning',
					},
					__( 'Custom schema only emits the JSON-LD added below. No automatic or site-wide schema will be output. Add a JSON-LD block, or this page will have no EasyRankly structured data.', 'easyrankly' )
				)
			);
		}

		if ( isDisabled ) {
			notices.push(
				el(
					Notice,
					{
						isDismissible: false,
						key: 'disabled',
						status: 'info',
					},
					__( 'No EasyRankly JSON-LD will be emitted for this content, including automatic, site-wide, and custom blocks.', 'easyrankly' )
				)
			);
		}

		const fields = [
			el( SelectControl, {
				__next40pxDefaultSize: true,
				help: __( 'Automatic schema is the generated graph plus site-wide blocks. Custom schema only uses the JSON-LD on this content. Disable schema turns all EasyRankly JSON-LD off.', 'easyrankly' ),
				key: 'schema-mode',
				label: __( 'Schema mode', 'easyrankly' ),
				onChange: setMode,
				options: [
					{ label: __( 'Automatic schema', 'easyrankly' ), value: 'default' },
					{ label: __( 'Automatic + custom schema', 'easyrankly' ), value: 'merge' },
					{ label: __( 'Custom schema only', 'easyrankly' ), value: 'replace' },
					{ label: __( 'Disable schema', 'easyrankly' ), value: 'disabled' },
				],
				value: mode,
			} ),
		];

		if ( docUrl ) {
			fields.push(
				el(
					'p',
					{ key: 'schema-doc' },
					el(
						'a',
						{
							className: 'erankly-section-doc-link',
							href: docUrl,
							rel: 'noopener noreferrer',
							target: '_blank',
						},
						__( 'Learn more', 'easyrankly' )
					)
				)
			);
		}

		if ( isDisabled ) {
			fields.push( ...notices );
		} else {
			fields.push( ...notices );
			fields.push(
				el( FormTokenField, {
					__next40pxDefaultSize: true,
					autoCapitalize: 'none',
					autoComplete: 'off',
					help: __( 'Hides matching nodes from the automatic graph and from site-wide schema blocks. Custom JSON-LD on this content is never suppressed.', 'easyrankly' ),
					key: 'disabled-types',
					label: __( 'Suppress generated schema types', 'easyrankly' ),
					onChange: ( values ) => data.set( 'schema_disabled_types', uniqueSchemaTypes( values ) ),
					placeholder: __( 'Add a schema type', 'easyrankly' ),
					suggestions,
					tokenizeOnSpace: false,
					value: disabledTypes,
				} )
			);

			blocks.forEach( ( block, index ) => {
				const json = block && block.fields ? ( block.fields.custom_json || '' ) : '';
				const result = validateJsonLd( json );
				const errorId = 'erankly-schema-block-error-' + index;

				fields.push(
					el(
						Fragment,
						{ key: 'schema-block-' + index },
						el( TextareaControl, {
							className: result.valid ? undefined : 'erankly-is-invalid',
							help: result.valid
								? __( 'One JSON-LD object, an array of objects, or an object with @graph. Every node needs a valid @type or @id.', 'easyrankly' )
								: result.message,
							label: `${ __( 'Custom JSON-LD', 'easyrankly' ) } ${ index + 1 }`,
							onChange: ( value ) => {
								const nextBlocks = [ ...blocks ];
								nextBlocks[ index ] = { type: 'custom', fields: { custom_json: value } };
								setBlocks( nextBlocks );
							},
							rows: 10,
							value: json,
							'aria-describedby': result.valid ? undefined : errorId,
							'aria-invalid': result.valid ? 'false' : 'true',
						} ),
						! result.valid && el(
							'p',
							{
								className: 'erankly-schema-json-error',
								id: errorId,
								role: 'alert',
							},
							result.message
						),
						el( Button, {
							isDestructive: true,
							onClick: () => setBlocks( blocks.filter( ( unused, blockIndex ) => blockIndex !== index ) ),
							variant: 'link',
						}, __( 'Remove schema block', 'easyrankly' ) )
					)
				);
			} );

			fields.push(
				el( Button, {
					key: 'add-schema',
					onClick: addBlock,
					variant: 'secondary',
				}, __( 'Add JSON-LD schema', 'easyrankly' ) )
			);
		}

		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel erankly-panel--schema',
				name: 'erankly-schema',
				title: __( 'Schema', 'easyrankly' ),
			},
			...fields
		);
	}

	function ERanklyDocumentSettings() {
		shared.usePanelsAfterDefaults();

		return el(
			Fragment,
			null,
			el( GeneralPanel ),
			! config.simplifiedMode && el( SocialPanel ),
			! config.simplifiedMode && el( SchemaPanel ),
			el( VisibilityPanel )
		);
	}

	registerPlugin( 'erankly-document-settings', {
		render: ERanklyDocumentSettings,
	} );
}() );
