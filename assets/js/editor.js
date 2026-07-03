/* global eranklyEditor, wp */
( function () {
	'use strict';

	const { apiFetch } = wp;
	const { MediaUpload, MediaUploadCheck } = wp.blockEditor;
	const {
		Button,
		ComboboxControl,
		Dropdown,
		MenuGroup,
		MenuItem,
		Notice,
		SelectControl,
		TextareaControl,
		TextControl,
		ToggleControl,
	} = wp.components;
	const { useDispatch, useSelect } = wp.data;
	const { PluginDocumentSettingPanel } = wp.editPost;
	const { createElement: el, Fragment, useEffect, useState } = wp.element;
	const { __, sprintf } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const config = eranklyEditor;

	function useEditorMeta() {
		const meta = useSelect(
			( select ) => select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {},
			[]
		);
		const { editPost } = useDispatch( 'core/editor' );

		return [
			meta,
			( key, value ) => editPost( { meta: { [ key ]: value } } ),
		];
	}

	function VariableMenu( { onSelect } ) {
		return el(
			Dropdown,
			{
				popoverProps: { placement: 'bottom-end' },
				renderToggle: ( { isOpen, onToggle } ) => el(
					Button,
					{
						'aria-expanded': isOpen,
						onClick: onToggle,
						variant: 'secondary',
					},
					__( 'Insert variable', 'easyrankly' )
				),
				renderContent: ( { onClose } ) => el(
					'div',
					{ style: { maxHeight: '360px', minWidth: '280px', overflowY: 'auto' } },
					Object.keys( config.variables ).map( ( groupKey ) => {
						const group = config.variables[ groupKey ];

						return el(
							MenuGroup,
							{ key: groupKey, label: group.label },
							Object.keys( group.variables ).map( ( variableKey ) => {
								const variable = '{{' + variableKey + '}}';

								return el(
									MenuItem,
									{
										key: variableKey,
										onClick: () => {
											onSelect( variable );
											onClose();
										},
									},
									group.variables[ variableKey ] + ' ' + variable
								);
							} )
						);
					} )
				),
			}
		);
	}

	function VariableControl( {
		extraActions = null,
		help,
		label,
		limit,
		multiline = false,
		onChange,
		placeholder = '',
		value = '',
	} ) {
		const lengthHelp = limit
			? sprintf(
				/* translators: 1: current character count, 2: maximum character count. */
				__( '%1$d of %2$d characters.', 'easyrankly' ),
				value.length,
				limit
			)
			: help;
		const Control = multiline ? TextareaControl : TextControl;

		return el(
			'div',
			{ className: 'erankly-field' },
			el( Control, {
				help: lengthHelp,
				label,
				onChange,
				placeholder,
				rows: multiline ? 3 : undefined,
				value,
			} ),
			el(
				'div',
				{ className: 'erankly-field__actions' },
				el( VariableMenu, { onSelect: ( variable ) => onChange( value + variable ) } )
			),
			extraActions && el(
				'div',
				{ className: 'erankly-field__actions' },
				extraActions
			)
		);
	}

	// Resolves the variables a preview can know about in the editor; the rest
	// are stripped so raw {{tokens}} never show up in the SERP preview.
	function serpResolveVariables( text, postTitle ) {
		return text
			.replace( /{{\s*([a-z0-9_]+)\s*}}/gi, ( match, key ) => {
				switch ( key.toLowerCase() ) {
					case 'post_title':
					case 'seo_title':
						return postTitle;
					case 'site_name':
						return config.siteName;
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

	function SerpPreview() {
		const [ meta ] = useEditorMeta();
		const { contentImageUrl, permalink, postTitle, thumbnailUrl } = useSelect( ( select ) => {
			const editor = select( 'core/editor' );
			const mediaId = editor.getEditedPostAttribute( 'featured_media' );
			const media = mediaId ? select( 'core' ).getMedia( mediaId ) : null;
			const sizes = ( media && media.media_details && media.media_details.sizes ) || {};
			const content = editor.getEditedPostAttribute( 'content' ) || '';

			return {
				contentImageUrl: serpFirstContentImage( content ),
				permalink: editor.getPermalink() || '',
				postTitle: editor.getEditedPostAttribute( 'title' ) || '',
				thumbnailUrl: ( sizes.thumbnail && sizes.thumbnail.source_url )
					|| ( media && media.source_url )
					|| '',
			};
		}, [] );
		const title = serpResolveVariables( meta._erankly_title || '', postTitle )
			|| config.titlePlaceholder
			|| postTitle
			|| config.siteName;
		const description = serpResolveVariables( meta._erankly_description || '', postTitle )
			|| config.descriptionPlaceholder
			|| __( 'Add a meta description to control this text in search results.', 'easyrankly' );
		const previewImageUrl = thumbnailUrl || contentImageUrl;

		return el(
			'div',
			{ 'aria-hidden': 'true', className: 'erankly-serp-preview' },
			el(
				'div',
				{ className: 'erankly-serp-preview__source' },
				config.siteIconUrl
					? el( 'img', { alt: '', className: 'erankly-serp-preview__favicon', src: config.siteIconUrl } )
					: el( 'span', { className: 'erankly-serp-preview__favicon' } ),
				el(
					'div',
					{ className: 'erankly-serp-preview__origin' },
					el( 'div', { className: 'erankly-serp-preview__site' }, config.siteName ),
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
				previewImageUrl && el( 'img', {
					alt: '',
					className: 'erankly-serp-preview__thumbnail',
					src: previewImageUrl,
				} )
			)
		);
	}

	function GeneralPanel() {
		const [ meta, setMeta ] = useEditorMeta();

		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel',
				name: 'erankly-general',
				title: __( 'Search appearance', 'easyrankly' ),
			},
			config.simplifiedMode && el( SerpPreview ),
			el( VariableControl, {
				label: __( 'Meta title', 'easyrankly' ),
				limit: 65,
				onChange: ( value ) => setMeta( '_erankly_title', value ),
				placeholder: config.titlePlaceholder,
				value: meta._erankly_title || '',
			} ),
			el( VariableControl, {
				label: __( 'Meta description', 'easyrankly' ),
				limit: 160,
				multiline: true,
				onChange: ( value ) => setMeta( '_erankly_description', value ),
				placeholder: config.descriptionPlaceholder,
				value: meta._erankly_description || '',
			} ),
			! config.simplifiedMode && el( VariableControl, {
				label: __( 'Canonical URL', 'easyrankly' ),
				onChange: ( value ) => setMeta( '_erankly_canonical', value ),
				value: meta._erankly_canonical || '',
			} ),
			config.breadcrumbsEnabled && ! config.simplifiedMode && el( TextControl, {
				help: __( 'Optional short name used in visible breadcrumbs and BreadcrumbList schema.', 'easyrankly' ),
				label: __( 'Breadcrumb name', 'easyrankly' ),
				onChange: ( value ) => setMeta( '_erankly_breadcrumb_name', value ),
				value: meta._erankly_breadcrumb_name || '',
			} )
		);
	}

	function SocialImageControl( { onChange, value } ) {
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
			placeholder: config.socialImagePlaceholder,
			value,
		} );
	}

	function SocialPanel() {
		const [ meta, setMeta ] = useEditorMeta();

		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel',
				name: 'erankly-social',
				title: __( 'Social sharing', 'easyrankly' ),
			},
			el( VariableControl, {
				label: __( 'Open Graph title', 'easyrankly' ),
				limit: 60,
				onChange: ( value ) => setMeta( '_erankly_og_title', value ),
				placeholder: config.ogTitlePlaceholder,
				value: meta._erankly_og_title || '',
			} ),
			el( VariableControl, {
				label: __( 'Open Graph description', 'easyrankly' ),
				limit: 200,
				multiline: true,
				onChange: ( value ) => setMeta( '_erankly_og_description', value ),
				placeholder: config.ogDescriptionPlaceholder,
				value: meta._erankly_og_description || '',
			} ),
			el( VariableControl, {
				label: __( 'X (Twitter) title', 'easyrankly' ),
				limit: 70,
				onChange: ( value ) => setMeta( '_erankly_twitter_title', value ),
				placeholder: config.twitterTitlePlaceholder,
				value: meta._erankly_twitter_title || '',
			} ),
			el( VariableControl, {
				label: __( 'X (Twitter) description', 'easyrankly' ),
				limit: 200,
				multiline: true,
				onChange: ( value ) => setMeta( '_erankly_twitter_description', value ),
				placeholder: config.twitterDescriptionPlaceholder,
				value: meta._erankly_twitter_description || '',
			} ),
			el( SelectControl, {
				label: __( 'X (Twitter) card type', 'easyrankly' ),
				onChange: ( value ) => setMeta( '_erankly_twitter_card_type', value ),
				options: [
					{ label: __( 'Default (summary_large_image)', 'easyrankly' ), value: '' },
					{ label: 'summary', value: 'summary' },
				],
				value: meta._erankly_twitter_card_type || '',
			} ),
			el( SocialImageControl, {
				onChange: ( value ) => setMeta( '_erankly_social_image_url', value ),
				value: meta._erankly_social_image_url || '',
			} )
		);
	}

	function VisibilityPanel() {
		const [ meta, setMeta ] = useEditorMeta();
		const hideFromSearch = Boolean( meta._erankly_noindex && meta._erankly_disable_sitemap );
		const toggleMeta = ( key ) => ( value ) => setMeta( key, value );

		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel',
				name: 'erankly-visibility',
				title: __( 'Search visibility', 'easyrankly' ),
			},
			config.simplifiedMode
				? el( ToggleControl, {
					checked: hideFromSearch,
					help: __( 'Sets noindex and removes this page from the sitemap.', 'easyrankly' ),
					label: __( 'Hide from search results', 'easyrankly' ),
					onChange: ( value ) => {
						setMeta( '_erankly_noindex', value );
						setMeta( '_erankly_disable_sitemap', value );
					},
				} )
				: el(
					Fragment,
					null,
					el( ToggleControl, {
						checked: Boolean( meta._erankly_noindex ),
						label: __( 'Noindex', 'easyrankly' ),
						onChange: toggleMeta( '_erankly_noindex' ),
					} ),
					el( ToggleControl, {
						checked: Boolean( meta._erankly_nofollow ),
						label: __( 'Nofollow', 'easyrankly' ),
						onChange: toggleMeta( '_erankly_nofollow' ),
					} ),
					el( ToggleControl, {
						checked: Boolean( meta._erankly_noarchive ),
						label: __( 'Noarchive', 'easyrankly' ),
						onChange: toggleMeta( '_erankly_noarchive' ),
					} ),
					el( ToggleControl, {
						checked: Boolean( meta._erankly_disable_sitemap ),
						label: __( 'Disable sitemap', 'easyrankly' ),
						onChange: toggleMeta( '_erankly_disable_sitemap' ),
					} )
				),
			el( ToggleControl, {
				checked: Boolean( meta._erankly_exclude_search ),
				label: __( 'Exclude from site search queries', 'easyrankly' ),
				onChange: toggleMeta( '_erankly_exclude_search' ),
			} ),
			el( ToggleControl, {
				checked: Boolean( meta._erankly_exclude_archive ),
				label: __( 'Exclude from archive queries', 'easyrankly' ),
				onChange: toggleMeta( '_erankly_exclude_archive' ),
			} ),
			config.newsSitemapEnabled && el( ToggleControl, {
				checked: Boolean( meta._erankly_exclude_from_news ),
				label: __( 'Exclude from Google News sitemap', 'easyrankly' ),
				onChange: toggleMeta( '_erankly_exclude_from_news' ),
			} )
		);
	}

	function TranslationControl( { onChange, row } ) {
		const [ options, setOptions ] = useState(
			row.object_id && row.title
				? [ { label: row.title, value: String( row.object_id ) } ]
				: []
		);
		const [ query, setQuery ] = useState( '' );
		const isLinked = row.object_id > 0 && 'unlink' !== row.action;

		useEffect( () => {
			if ( isLinked || query.length < 2 ) {
				return undefined;
			}

			let active = true;
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
				className: 'erankly-panel',
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

	function EasyRanklyDocumentSettings() {
		return el(
			Fragment,
			null,
			el( GeneralPanel ),
			! config.simplifiedMode && el( SocialPanel ),
			el( VisibilityPanel ),
			config.multilingual && el( TranslationsPanel )
		);
	}

	registerPlugin( 'erankly-document-settings', {
		render: EasyRanklyDocumentSettings,
	} );
}() );
