/* global eranklyEditor, wp */
( function () {
	'use strict';

	const shared = window.eranklyShared;
	const { apiFetch } = wp;
	const { Button, ComboboxControl, Notice, TextareaControl, TextControl } = wp.components;
	const { useDispatch, useSelect } = wp.data;
	// PluginDocumentSettingPanel moved from wp.editPost to wp.editor in WP 6.6.
	// Prefer wp.editor (6.6+, non-deprecated) and fall back to wp.editPost
	// (WP 5.3–6.5) so the post-editor panel still loads on older WordPress.
	const PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	const { createElement: el, Fragment, useEffect, useState } = wp.element;
	const { __, sprintf } = wp.i18n;
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
		noindex: '_erankly_noindex',
		nofollow: '_erankly_nofollow',
		noarchive: '_erankly_noarchive',
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
	};

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

	function SerpPreview( { data } ) {
		const { contentImageUrl, permalink, postTitle, thumbnailUrl } = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			const mediaId = editor.getEditedPostAttribute( 'featured_media' );
			const media = mediaId ? select( 'core' ).getMedia( mediaId ) : null;
			const sizes = ( media && media.media_details && media.media_details.sizes ) || {};
			const content = editor.getEditedPostAttribute( 'content' ) || '';

			return {
				contentImageUrl: shared.serpFirstContentImage( content ),
				permalink: editor.getPermalink() || '',
				postTitle: editor.getEditedPostAttribute( 'title' ) || '',
				thumbnailUrl: ( sizes.thumbnail && sizes.thumbnail.source_url )
					|| ( media && media.source_url )
					|| '',
			};
		}, [] );
		const title = shared.serpResolveVariables( data.get( 'title' ), postTitle, config.siteName )
			|| config.titlePlaceholder
			|| postTitle
			|| config.siteName;
		const description = shared.serpResolveVariables( data.get( 'description' ), postTitle, config.siteName )
			|| config.descriptionPlaceholder
			|| __( 'Add a meta description to control this text in search results.', 'easyrankly' );

		return el( shared.SerpPreviewView, {
			description,
			imageUrl: thumbnailUrl || contentImageUrl,
			permalink,
			siteIconUrl: config.siteIconUrl,
			siteName: config.siteName,
			title,
		} );
	}

	function AiGenerateButton( { data, target = 'seo', titleFields = [ 'title' ], descriptionFields = [ 'description' ] } ) {
		const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );
		const [ status, setStatus ] = useState( null );
		const [ busy, setBusy ] = useState( false );
		const [ busyAction, setBusyAction ] = useState( '' );
		const [ hasGenerated, setHasGenerated ] = useState( false );
		const [ instructions, setInstructions ] = useState( '' );
		const primaryTitleField = titleFields[ 0 ] || 'title';
		const primaryDescriptionField = descriptionFields[ 0 ] || 'description';

		function applyResult( result ) {
			if ( result.title ) {
				titleFields.forEach( ( field ) => data.set( field, result.title ) );
			}
			if ( result.description ) {
				descriptionFields.forEach( ( field ) => data.set( field, result.description ) );
			}
		}

		function generate( isImprovement = false ) {
			if ( ! postId ) {
				setStatus( { type: 'error', message: __( 'Save a draft first, then generate.', 'easyrankly' ) } );
				return;
			}

			if ( isImprovement && ! instructions.trim() ) {
				setStatus( { type: 'error', message: __( 'Add instructions before improving the results.', 'easyrankly' ) } );
				return;
			}

			const requestData = { object_id: postId, object_type: 'post', target };

			if ( isImprovement ) {
				requestData.instructions = instructions.trim();
				requestData.current_title = data.get( primaryTitleField );
				requestData.current_description = data.get( primaryDescriptionField );
			}

			setBusy( true );
			setBusyAction( isImprovement ? 'improve' : 'generate' );
			setStatus( null );

			apiFetch( {
				path: config.aiGeneratePath,
				method: 'POST',
				data: requestData,
			} )
				.then( ( result ) => {
					applyResult( result );
					setHasGenerated( true );
					setStatus( {
						type: 'success',
						message: isImprovement ? __( 'Results improved.', 'easyrankly' ) : __( 'Done.', 'easyrankly' ),
					} );
				} )
				.catch( ( err ) => {
					setStatus( {
						type: 'error',
						message: ( err && err.message ) || __( 'Generation failed. Please try again.', 'easyrankly' ),
					} );
				} )
				.finally( () => {
					setBusy( false );
					setBusyAction( '' );
				} );
		}

		return el(
			'div',
			{ className: 'erankly-ai-field' },
			el(
				'p',
				{ className: 'description erankly-ai-privacy' },
				sprintf(
					/* translators: %d: maximum plain-text body characters sent to the provider. */
					__( 'Generating sends page context (title and up to %1$d characters of plain-text content, plus site name and language) to the AI provider configured in WordPress Connectors. Improve also sends your current fields and instructions. EasyRankly does not operate that service.', 'easyrankly' ),
					config.aiContentLimit || 4000
				)
			),
			el(
				Button,
				{ variant: 'secondary', isBusy: busy, disabled: busy, onClick: () => generate( false ) },
				'generate' === busyAction ? __( 'Generating…', 'easyrankly' ) : __( 'Generate with AI', 'easyrankly' )
			),
			! config.simplifiedMode && hasGenerated && el(
				'div',
				{ className: 'erankly-ai-improve' },
				el( TextareaControl, {
					label: __( 'Improvement instructions', 'easyrankly' ),
					onChange: setInstructions,
					placeholder: __( 'Make the title more specific, shorten the description, change the tone…', 'easyrankly' ),
					rows: 3,
					value: instructions,
				} ),
				el(
					Button,
					{
						variant: 'secondary',
						isBusy: busy,
						disabled: busy || ! instructions.trim(),
						onClick: () => generate( true ),
					},
					'improve' === busyAction ? __( 'Improving…', 'easyrankly' ) : __( 'Improve results', 'easyrankly' )
				)
			),
			status && el(
				Notice,
				{ status: status.type, isDismissible: false, className: 'erankly-ai-notice' },
				status.message
			)
		);
	}

	// The document's current title, so {{post_title}} in a field can preview
	// against the real post instead of staying an unresolved raw token.
	function useConfigWithPostTitle() {
		const postTitle = useSelect(
			( select ) => select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '',
			[]
		);

		return { ...config, postTitle };
	}

	function GeneralPanel() {
		const data = usePostData();
		const panelConfig = useConfigWithPostTitle();

		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel erankly-panel--appearance',
				name: 'erankly-general',
				title: __( 'Search appearance', 'easyrankly' ),
			},
			config.simplifiedMode && el( SerpPreview, { data } ),
			...shared.searchAppearanceFields( { config: panelConfig, data, features: FEATURES } ),
			config.aiEnabled && el( AiGenerateButton, { data } )
		);
	}

	function SocialPanel() {
		const data = usePostData();
		const panelConfig = useConfigWithPostTitle();

		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel erankly-panel--social',
				name: 'erankly-social',
				title: __( 'Social sharing', 'easyrankly' ),
			},
			...shared.socialFields( { config: panelConfig, data, features: FEATURES } ),
			config.aiEnabled && el( AiGenerateButton, {
				data,
				descriptionFields: [ 'og_description', 'twitter_description' ],
				target: 'social',
				titleFields: [ 'og_title', 'twitter_title' ],
			} )
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

	function SeoChecklistPanel() {
		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel erankly-panel--checklist',
				name: 'erankly-checklist',
				title: __( 'SEO checklist', 'easyrankly' ),
			},
			...shared.seoChecklistFields()
		);
	}

	function TranslationControl( { onChange, row } ) {
		const [ options, setOptions ] = useState(
			row.object_id && row.title
				? [ { label: row.title, value: String( row.object_id ) } ]
				: []
		);
		const [ query, setQuery ] = useState( '' );
		const [ isLoading, setIsLoading ] = useState( false );
		const isLinked = row.object_id > 0 && 'unlink' !== row.action;

		useEffect( () => {
			if ( isLinked ) {
				return undefined;
			}

			let active = true;
			setIsLoading( true );
			const timer = window.setTimeout( () => {
				apiFetch( {
					path: config.translationSearchPath
						+ '?blog_id=' + encodeURIComponent( row.blog_id )
						+ '&object_type=post&q=' + encodeURIComponent( query ),
				} ).then( ( results ) => {
					if ( active && Array.isArray( results ) ) {
						setOptions( results.map( ( result ) => ( {
							label: result.title,
							url: result.url,
							value: String( result.id ),
						} ) ) );
					}
				} ).catch( () => {
					if ( active ) {
						setOptions( [] );
					}
				} ).then( () => {
					if ( active ) {
						setIsLoading( false );
					}
				} );
			}, 300 );

			return () => {
				active = false;
				window.clearTimeout( timer );
			};
		}, [ isLinked, query, row.blog_id ] );

		if ( isLinked ) {
			return el(
				'div',
				{ className: 'erankly-field' },
				el( TextControl, {
					disabled: true,
					label: row.site_name + ' - ' + row.hreflang.toUpperCase(),
					value: row.url || row.title,
				} ),
				el(
					'div',
					{ className: 'erankly-field__actions' },
					el(
						Button,
						{
							isDestructive: true,
							onClick: () => onChange( {
								...row,
								action: row.original_object_id > 0 ? 'unlink' : '',
								object_id: row.original_object_id || 0,
								title: '',
								url: '',
							} ),
							variant: 'secondary',
						},
						__( 'Remove', 'easyrankly' )
					)
				)
			);
		}

		return el( ComboboxControl, {
			isLoading,
			label: row.site_name + ' - ' + row.hreflang.toUpperCase(),
			onChange: ( objectId ) => {
				const selected = options.find( ( option ) => option.value === objectId );

				if ( selected ) {
					onChange( {
						...row,
						action: 'link',
						object_id: Number( selected.value ),
						title: selected.label,
						url: selected.url || '',
					} );
				}
			},
			onFilterValueChange: setQuery,
			options,
			placeholder: __( 'Search posts or pages…', 'easyrankly' ),
			value: '',
		} );
	}

	function TranslationsPanel() {
		const rows = useSelect( ( select ) => {
			const editor = select( 'core/editor' );

			return editor.getEditedPostAttribute( 'erankly_ml_links' )
				|| editor.getCurrentPostAttribute( 'erankly_ml_links' )
				|| [];
		}, [] );
		const { editPost } = useDispatch( 'core/editor' );
		const updateRow = ( index, row ) => {
			const nextRows = rows.slice();
			nextRows[ index ] = row;
			editPost( { erankly_ml_links: nextRows } );
		};

		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel erankly-panel--translations',
				name: 'erankly-translations',
				title: __( 'Translations', 'easyrankly' ),
			},
			rows.length
				? el(
					Fragment,
					null,
					el(
						'p',
						null,
						__( 'Link this content to its equivalents on other network sites.', 'easyrankly' )
					),
					rows.map( ( row, index ) => el( TranslationControl, {
						key: row.blog_id,
						onChange: ( nextRow ) => updateRow( index, nextRow ),
						row,
					} ) )
				)
				: el(
					Notice,
					{ isDismissible: false, status: 'info' },
					__( 'No other sites are enabled for multilingual links.', 'easyrankly' )
				)
		);
	}

	function ERanklyDocumentSettings() {
		shared.usePanelsAfterDefaults();

		return el(
			Fragment,
			null,
			el( GeneralPanel ),
			! config.simplifiedMode && el( SocialPanel ),
			el( VisibilityPanel ),
			el( SeoChecklistPanel ),
			config.multilingual && el( TranslationsPanel )
		);
	}

	registerPlugin( 'erankly-document-settings', {
		render: ERanklyDocumentSettings,
	} );
}() );
