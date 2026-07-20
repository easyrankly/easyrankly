/* global wp */
/**
 * EasyRankly Site Editor panels.
 *
 * Adds the Search appearance / Social sharing / Search visibility panels to the
 * Site Editor template inspector when the edited block template maps to a
 * WordPress special-page context. Values are edited through the native
 * root/site Core Data entity so they participate in the Site Editor save flow.
 */
( function () {
	'use strict';

	const shared = window.eranklyShared;
	const config = window.eranklySiteEditor;
	const wpEditor = wp.editor || {};
	const PluginDocumentSettingPanel = wpEditor.PluginDocumentSettingPanel;

	// Bail quietly if another plugin or a non-standard editor environment removes
	// one of the required editor APIs.
	if ( ! shared || ! config || ! PluginDocumentSettingPanel || ! wp.apiFetch || ! wp.plugins ) {
		return;
	}

	const { apiFetch } = wp;
	const { Button, Notice, SelectControl, Spinner, TextareaControl } = wp.components;
	const { useDispatch, useSelect } = wp.data;
	const { createElement: el, Fragment, useState } = wp.element;
	const { __ } = wp.i18n;
	const { registerPlugin } = wp.plugins;
	const specialMetaSetting = config.specialMetaSetting;

	function addContext( contextsByTemplate, templateSlug, context ) {
		if ( ! templateSlug ) {
			return;
		}

		if ( ! contextsByTemplate[ templateSlug ] ) {
			contextsByTemplate[ templateSlug ] = [];
		}

		if ( -1 === contextsByTemplate[ templateSlug ].indexOf( context ) ) {
			contextsByTemplate[ templateSlug ].push( context );
		}
	}

	function firstAvailableTemplate( available, hierarchy ) {
		return hierarchy.find( ( templateSlug ) => available.has( templateSlug ) ) || '';
	}

	// Builds the effective context map from the active templates and Reading
	// settings. Each context follows the same hierarchy WordPress uses on the
	// front end, so generic archive/index templates expose only their real
	// fallbacks.
	function getTemplateContexts( site, templates ) {
		if ( ! site || ! Array.isArray( templates ) ) {
			return {};
		}

		const available = new Set(
			templates
				.map( ( template ) => template && template.slug )
				.filter( Boolean )
		);
		const contextsByTemplate = {};
		const addResolvedContext = ( context, hierarchy ) => {
			addContext(
				contextsByTemplate,
				firstAvailableTemplate( available, hierarchy ),
				context
			);
		};

		if ( 'posts' === site.show_on_front ) {
			addResolvedContext( 'homepage', [ 'front-page', 'home', 'index' ] );
		} else if ( Number( site.page_for_posts ) > 0 ) {
			addResolvedContext( 'blog', [ 'home', 'index' ] );
		}

		addResolvedContext( 'author', [ 'author', 'archive', 'index' ] );
		addResolvedContext( 'date', [ 'date', 'archive', 'index' ] );
		addResolvedContext( 'search', [ 'search', 'index' ] );
		addResolvedContext( '404', [ '404', 'index' ] );

		// A user-specific author template controls only that author, while the
		// generic author/archive/index fallback still controls the others.
		available.forEach( ( templateSlug ) => {
			if ( templateSlug.startsWith( 'author-' ) ) {
				addContext( contextsByTemplate, templateSlug, 'author' );
			}
		} );

		return contextsByTemplate;
	}

	function contextsForTemplate( postType, slug, site, templates ) {
		if ( 'wp_template' !== postType ) {
			return [];
		}

		return getTemplateContexts( site, templates )[ slug ] || [];
	}

	// Among special pages only the author archive feeds the XML sitemap, so the
	// "Disable sitemap" toggle is offered only there (mirrors the settings tab).
	function featuresForContext( context ) {
		return {
			breadcrumbName: false,
			canonical: false,
			cardType: false,
			disableSitemap: 'author' === context,
			excludeQueries: false,
			newsSitemap: false,
		};
	}

	// Core Data adapter for the native Site settings entity. Calling
	// editEntityRecord marks root/site as dirty; the Site Editor then includes
	// these changes in its standard save review and persists them with the
	// template through the same Save action.
	function useSpecialMeta( context ) {
		const site = useSelect( ( select ) => {
			const core = select( 'core' );
			const record = core.getEntityRecord( 'root', 'site' );
			const editedRecord = core.getEditedEntityRecord( 'root', 'site' );

			return editedRecord || record;
		}, [] );
		const { editEntityRecord } = useDispatch( 'core' );
		const map = site && site[ specialMetaSetting ];
		const row = map && map[ context ];
		const available = site && Object.prototype.hasOwnProperty.call( site, specialMetaSetting );

		return {
			available,
			get: ( field ) => ( row && undefined !== row[ field ] && null !== row[ field ] ? row[ field ] : '' ),
			ready: !! row,
			set: ( field, value ) => {
				editEntityRecord( 'root', 'site', undefined, {
					[ specialMetaSetting ]: {
						...map,
						[ context ]: {
							...row,
							[ field ]: value,
						},
					},
				} );
			},
		};
	}

	function AiGenerateButton( { context, data, target = 'seo', titleFields = [ 'title' ], descriptionFields = [ 'description' ] } ) {
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
			if ( isImprovement && ! instructions.trim() ) {
				setStatus( { type: 'error', message: __( 'Add instructions before improving the results.', 'easyrankly' ) } );
				return;
			}

			const requestData = { object_id: 0, object_type: 'special', context, target };

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

	function SerpPreview( { context, data } ) {
		const labels = config.contextLabels || {};
		const descriptionPlaceholders = config.specialDescriptionPlaceholders || {};
		const titlePlaceholders = config.specialTitlePlaceholders || {};
		const previewUrls = config.specialPreviewUrls || {};
		const siteName = config.siteName || '';
		const contextLabel = labels[ context ] || context;
		const titleFallback = titlePlaceholders[ context ] || contextLabel || siteName;
		const title = shared.serpResolveVariables( data.get( 'title' ), titleFallback, siteName )
			|| titleFallback;
		const description = shared.serpResolveVariables( data.get( 'description' ), titleFallback, siteName )
			|| descriptionPlaceholders[ context ]
			|| __( 'Add a meta description to control this text in search results.', 'easyrankly' );

		return el( shared.SerpPreviewView, {
			description,
			permalink: previewUrls[ context ] || config.homeUrl || '',
			siteIconUrl: config.siteIconUrl || '',
			siteName,
			title,
		} );
	}

	// Panels for a single special-page context.
	function ContextPanels( { context, contexts, onSelectContext } ) {
		const data = useSpecialMeta( context );
		const features = featuresForContext( context );
		const labels = config.contextLabels || {};

		const selector = contexts.length > 1 && el( SelectControl, {
			__next40pxDefaultSize: true,
			label: __( 'WordPress context', 'easyrankly' ),
			onChange: onSelectContext,
			options: contexts.map( ( key ) => ( { label: labels[ key ] || key, value: key } ) ),
			value: context,
		} );

		if ( data.available === false ) {
			return el(
				PluginDocumentSettingPanel,
				{ className: 'erankly-panel erankly-panel--appearance', name: 'erankly-special-appearance', title: __( 'Search appearance', 'easyrankly' ) },
				selector,
				el(
					Notice,
					{ isDismissible: false, status: 'error' },
					__( 'EasyRankly settings are not available in the Site Editor.', 'easyrankly' )
				)
			);
		}

		if ( ! data.ready ) {
			return el(
				PluginDocumentSettingPanel,
				{ className: 'erankly-panel erankly-panel--appearance', name: 'erankly-special-appearance', title: __( 'Search appearance', 'easyrankly' ) },
				selector,
				el( Spinner )
			);
		}

		return el(
			Fragment,
			null,
			el(
				PluginDocumentSettingPanel,
				{ className: 'erankly-panel erankly-panel--appearance', name: 'erankly-special-appearance', title: __( 'Search appearance', 'easyrankly' ) },
				selector,
				config.simplifiedMode && el( SerpPreview, { context, data } ),
				...shared.searchAppearanceFields( { config, data, features } ),
				config.aiEnabled && el( AiGenerateButton, { context, data } )
			),
			! config.simplifiedMode && el(
				PluginDocumentSettingPanel,
				{ className: 'erankly-panel erankly-panel--social', name: 'erankly-special-social', title: __( 'Social sharing', 'easyrankly' ) },
				...shared.socialFields( { config, data, features } ),
				config.aiEnabled && el( AiGenerateButton, {
					context,
					data,
					descriptionFields: [ 'og_description', 'twitter_description' ],
					target: 'social',
					titleFields: [ 'og_title', 'twitter_title' ],
				} )
			),
			el(
				PluginDocumentSettingPanel,
				{ className: 'erankly-panel erankly-panel--visibility', name: 'erankly-special-visibility', title: __( 'Search visibility', 'easyrankly' ) },
				...shared.visibilityFields( { config, data, features } )
			)
		);
	}

	function SpecialMetaPanels( { contexts } ) {
		const [ active, setActive ] = useState( contexts[ 0 ] );
		const context = -1 !== contexts.indexOf( active ) ? active : contexts[ 0 ];

		return el( ContextPanels, {
			context,
			contexts,
			onSelectContext: setActive,
		} );
	}

	function ERanklySiteEditorSettings() {
		const { postType, site, slug, templates } = useSelect( ( select ) => {
			const ed = select( 'core/editor' );
			const core = select( 'core' );

			return {
				postType: ed.getCurrentPostType(),
				site: core.getEditedEntityRecord( 'root', 'site' )
					|| core.getEntityRecord( 'root', 'site' ),
				slug: ed.getEditedPostAttribute( 'slug' ) || '',
				templates: core.getEntityRecords( 'postType', 'wp_template' ),
			};
		}, [] );
		const contexts = contextsForTemplate( postType, slug, site, templates );
		shared.usePanelsAfterDefaults( Boolean( contexts.length ) );

		if ( ! contexts.length ) {
			return null;
		}

		// Remount when the edited template changes so the active context resets.
		return el( SpecialMetaPanels, { contexts, key: slug } );
	}

	registerPlugin( 'erankly-site-editor', {
		render: ERanklySiteEditorSettings,
	} );
}() );
