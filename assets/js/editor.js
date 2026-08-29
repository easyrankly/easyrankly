/* global eranklyEditor, wp */
( function () {
	'use strict';

	const shared = window.eranklyShared;
	const {
		Button,
		SelectControl,
		TextareaControl,
		TextControl,
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
		focus_keywords: '_erankly_focus_keywords',
		cornerstone: '_erankly_cornerstone',
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
		editorial: true,
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
		const title = shared.serpResolveVariables( data.get( 'title' ), postTitle, config.siteName, config.siteDescription, config.variableExamples )
			|| config.titlePlaceholder
			|| postTitle
			|| config.siteName;
		const description = shared.serpResolveVariables( data.get( 'description' ), postTitle, config.siteName, config.siteDescription, config.variableExamples )
			|| config.descriptionPlaceholder
			|| __( 'Add a meta description for search results.', 'easyrankly' );

		return el( shared.SerpPreviewView, {
			description,
			imageUrl: thumbnailUrl || contentImageUrl,
			permalink,
			siteIconUrl: config.siteIconUrl,
			siteName: config.siteName,
			title,
		} );
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
			config.simplifiedMode && el( SerpPreview, { data } ),
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

	function SchemaPanel() {
		const data = usePostData();
		const blocks = Array.isArray( data.get( 'schema_blocks' ) ) ? data.get( 'schema_blocks' ) : [];
		const disabledTypes = Array.isArray( data.get( 'schema_disabled_types' ) ) ? data.get( 'schema_disabled_types' ).join( ', ' ) : '';

		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel erankly-panel--schema',
				name: 'erankly-schema',
				title: __( 'Schema', 'easyrankly' ),
			},
			el( SelectControl, {
				__next40pxDefaultSize: true,
				label: __( 'Schema mode', 'easyrankly' ),
				onChange: ( value ) => data.set( 'schema_mode', value ),
				options: [
					{ label: __( 'Automatic schema', 'easyrankly' ), value: 'default' },
					{ label: __( 'Automatic + custom schema', 'easyrankly' ), value: 'merge' },
					{ label: __( 'Custom schema only', 'easyrankly' ), value: 'replace' },
					{ label: __( 'Disable schema', 'easyrankly' ), value: 'disabled' },
				],
				value: data.get( 'schema_mode' ) || 'default',
			} ),
			el( TextControl, {
				label: __( 'Suppress automatic schema types', 'easyrankly' ),
				onChange: ( value ) => data.set( 'schema_disabled_types', value.split( /[,\n]+/ ).map( ( item ) => item.trim() ).filter( Boolean ) ),
				placeholder: 'Article, Product, FAQPage',
				value: disabledTypes,
			} ),
			...blocks.map( ( block, index ) => el(
				Fragment,
				{ key: `schema-block-${ index }` },
				el( TextareaControl, {
					help: __( 'One JSON-LD object, array or @graph.', 'easyrankly' ),
					label: `${ __( 'Custom JSON-LD', 'easyrankly' ) } ${ index + 1 }`,
					onChange: ( value ) => {
						const nextBlocks = [ ...blocks ];
						nextBlocks[ index ] = { type: 'custom', fields: { custom_json: value } };
						data.set( 'schema_blocks', nextBlocks );
					},
					rows: 10,
					value: block && block.fields ? ( block.fields.custom_json || '' ) : '',
				} ),
				el( Button, {
					isDestructive: true,
					onClick: () => data.set( 'schema_blocks', blocks.filter( ( unused, blockIndex ) => blockIndex !== index ) ),
					variant: 'link',
				}, __( 'Remove schema block', 'easyrankly' ) )
			) ),
			el( Button, {
				onClick: () => data.set( 'schema_blocks', [ ...blocks, { type: 'custom', fields: { custom_json: '' } } ] ),
				variant: 'secondary',
			}, __( 'Add JSON-LD schema', 'easyrankly' ) )
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

	function ERanklyDocumentSettings() {
		shared.usePanelsAfterDefaults();

		return el(
			Fragment,
			null,
			el( GeneralPanel ),
			! config.simplifiedMode && el( SocialPanel ),
			! config.simplifiedMode && el( SchemaPanel ),
			el( VisibilityPanel ),
			el( SeoChecklistPanel )
		);
	}

	registerPlugin( 'erankly-document-settings', {
		render: ERanklyDocumentSettings,
	} );
}() );
