/* global eranklyEditor, wp */
( function () {
	'use strict';

	const shared = window.eranklyShared;
	const { apiFetch } = wp;
	const {
		Button,
		FormTokenField,
		Modal,
		Notice,
		SelectControl,
		Spinner,
		TextareaControl,
		TextControl,
		ToggleControl,
	} = wp.components;
	const { useDispatch, useSelect } = wp.data;
	// PluginDocumentSettingPanel moved from wp.editPost to wp.editor in WP 6.6.
	// Prefer wp.editor (6.6+, non-deprecated) and fall back to wp.editPost
	// (WP 5.3–6.5) so the post-editor panel still loads on older WordPress.
	const PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	const { createElement: el, Fragment, useEffect, useState } = wp.element;
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
		focus_keywords: '_erankly_focus_keywords',
		cornerstone: '_erankly_cornerstone',
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
				setStatus( { type: 'error', message: __( 'Save a draft first.', 'easyrankly' ) } );
				return;
			}

			if ( isImprovement && ! instructions.trim() ) {
				setStatus( { type: 'error', message: __( 'Add improvement instructions.', 'easyrankly' ) } );
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
						message: isImprovement ? __( 'Improved.', 'easyrankly' ) : __( 'Done.', 'easyrankly' ),
					} );
				} )
				.catch( ( err ) => {
					setStatus( {
						type: 'error',
						message: ( err && err.message ) || __( 'Generation failed. Try again.', 'easyrankly' ),
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
				__( 'Generating shares page context with your configured WordPress AI provider. Improving also shares your current fields and instructions. EasyRankly does not operate the AI service.', 'easyrankly' )
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
					label: __( 'How to improve', 'easyrankly' ),
					onChange: setInstructions,
					placeholder: __( 'Make the title specific, shorten the description, or change the tone…', 'easyrankly' ),
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
					'improve' === busyAction ? __( 'Improving…', 'easyrankly' ) : __( 'Improve', 'easyrankly' )
				)
			),
			status && el(
				Notice,
				{ status: status.type, isDismissible: false, className: 'erankly-ai-notice' },
				status.message
			)
		);
	}

	// The document's current values keep field previews and the canonical
	// placeholder aligned with unsaved title or permalink edits.
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
			config.aiEnabled && el( AiGenerateButton, { data } )
		);
	}

	function normalizeAnalysisKeywords( values ) {
		const seen = new Set();
		const keywords = [];

		( Array.isArray( values ) ? values : [] ).forEach( ( value ) => {
			const keyword = String( value || '' ).trim().replace( /\s+/g, ' ' ).slice( 0, 120 );
			const key = keyword.toLocaleLowerCase();

			if ( keyword && ! seen.has( key ) ) {
				seen.add( key );
				keywords.push( keyword );
			}
		} );

		return keywords;
	}

	function contentAnalysisSnapshot( payload ) {
		return JSON.stringify( [
			payload.title || '',
			payload.content || '',
			payload.keywords || [],
			Boolean( payload.cornerstone ),
		] );
	}

	function analysisList( values, className = '' ) {
		if ( ! Array.isArray( values ) || ! values.length ) {
			return null;
		}

		return el(
			'ul',
			{ className },
			values.map( ( value, index ) => el( 'li', { key: `${ index }-${ value }` }, value ) )
		);
	}

	function analysisSection( title, ...children ) {
		return el(
			'section',
			{ className: 'erankly-analysis-section' },
			el( 'h3', null, title ),
			...children.filter( Boolean )
		);
	}

	function analysisStatusLabel( status ) {
		const labels = {
			high: __( 'High priority', 'easyrankly' ),
			low: __( 'Low priority', 'easyrankly' ),
			medium: __( 'Medium priority', 'easyrankly' ),
			missing: __( 'Missing', 'easyrankly' ),
			not_applicable: __( 'Not applicable', 'easyrankly' ),
			overused: __( 'Overused', 'easyrankly' ),
			partial: __( 'Partial', 'easyrankly' ),
			strong: __( 'Strong', 'easyrankly' ),
			weak: __( 'Weak', 'easyrankly' ),
		};

		return labels[ status ] || status;
	}

	function copyAnalysisText( value, onCopied ) {
		const fallback = () => {
			const textarea = document.createElement( 'textarea' );
			textarea.value = value;
			textarea.style.position = 'fixed';
			textarea.style.opacity = '0';
			document.body.appendChild( textarea );
			textarea.select();
			document.execCommand( 'copy' );
			textarea.remove();
			onCopied();
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( value ).then( onCopied ).catch( fallback );
			return;
		}

		fallback();
	}

	function ContentAnalysisReport( { analysis, copiedSentence, onCopy, stale } ) {
		const [ detailsOpen, setDetailsOpen ] = useState( false );
		const report = ( analysis && analysis.report ) || {};
		const signals = report.signals || {};
		const source = signals.source || {};
		const primaryKeyword = ( analysis.keywords_snapshot && analysis.keywords_snapshot[ 0 ] ) || '';
		const verdictLabels = {
			in_focus: __( 'In focus', 'easyrankly' ),
			out_of_focus: __( 'Out of focus', 'easyrankly' ),
			partially_in_focus: __( 'Partially in focus', 'easyrankly' ),
		};
		const metrics = [
			[ __( 'Words', 'easyrankly' ), source.word_count || 0 ],
			[ __( 'Content coverage', 'easyrankly' ), `${ source.coverage_percent || 0 }%` ],
			[ __( 'Headings', 'easyrankly' ), source.heading_count || 0 ],
		];
		const priorityActions = Array.isArray( report.priority_actions ) ? report.priority_actions : [];
		const visiblePriorityActions = priorityActions.filter( ( row ) => 'high' === row.priority );
		const detailPriorityActions = priorityActions.filter( ( row ) => 'high' !== row.priority );
		const hasPillarDetails = report.pillar && ( report.pillar.summary || 'not_applicable' !== report.pillar.readiness );
		const hasDetails = Boolean(
			detailPriorityActions.length ||
			report.search_intent ||
			( Array.isArray( report.strengths ) && report.strengths.length ) ||
			( Array.isArray( report.keyword_results ) && report.keyword_results.length ) ||
			( Array.isArray( report.missing_topics ) && report.missing_topics.length ) ||
			( Array.isArray( report.suggested_headings ) && report.suggested_headings.length ) ||
			( Array.isArray( report.suggested_sentences ) && report.suggested_sentences.length ) ||
			hasPillarDetails ||
			( Array.isArray( signals.cannibalization ) && signals.cannibalization.length ) ||
			( Array.isArray( report.warnings ) && report.warnings.length )
		);
		const detailsId = 'erankly-content-analysis-details';

		if ( signals.links && signals.links.available ) {
			metrics.push(
				[ __( 'Inbound links', 'easyrankly' ), signals.links.inbound_count || 0 ],
				[ __( 'Outbound links', 'easyrankly' ), signals.links.outbound_count || 0 ]
			);
		}

		return el(
			Fragment,
			null,
			el(
				'div',
				{ className: `erankly-analysis-hero is-${ report.verdict || 'out_of_focus' }` },
				el(
					'div',
					{ className: 'erankly-analysis-hero__text' },
					el( 'span', { className: 'erankly-analysis-verdict' }, verdictLabels[ report.verdict ] || verdictLabels.out_of_focus ),
					el( 'p', null, report.summary || '' )
				),
				el(
					'div',
					{ className: 'erankly-analysis-score' },
					el(
						'strong',
						{ className: 'erankly-analysis-score__value' },
						String( report.score || 0 ),
						el( 'span', { className: 'erankly-analysis-score__max' }, '/100' )
					),
					el( 'span', { className: 'erankly-analysis-score__label' }, __( 'Focus score', 'easyrankly' ) )
				)
			),
			stale && el(
				Notice,
				{ className: 'erankly-analysis-stale', isDismissible: false, status: 'warning' },
				__( 'Content changed after this analysis.', 'easyrankly' )
			),
			analysisSection(
				__( 'Metrics', 'easyrankly' ),
				el(
					'dl',
					{ className: 'erankly-analysis-metrics' },
					metrics.map( ( metric ) => el(
						'div',
						{ className: 'erankly-analysis-metric', key: metric[ 0 ] },
						el( 'dt', null, metric[ 0 ] ),
						el( 'dd', null, String( metric[ 1 ] ) )
					) )
				)
			),
			visiblePriorityActions.length > 0 && analysisSection(
				__( 'Priorities', 'easyrankly' ),
				...visiblePriorityActions.map( ( row, index ) => el(
					'article',
					{ className: `erankly-analysis-card erankly-analysis-priority is-${ row.priority }`, key: `${ index }-${ row.title }` },
					el(
						'h4',
						null,
						el( 'span', { className: `erankly-analysis-status is-${ row.priority }` }, analysisStatusLabel( row.priority ) ),
						row.title || ''
					),
					el( 'p', null, row.reason || '' ),
					el( 'p', { className: 'erankly-analysis-action' }, row.action || '' )
				) )
			),
			hasDetails && el(
				Fragment,
				null,
				el(
					'button',
					{
						'aria-controls': detailsId,
						'aria-expanded': detailsOpen,
						className: 'erankly-analysis-details-toggle',
						onClick: () => setDetailsOpen( ( open ) => ! open ),
						type: 'button',
					},
					detailsOpen ? __( 'Hide details', 'easyrankly' ) : __( 'Show details', 'easyrankly' )
				),
				el(
					'div',
					{ className: 'erankly-analysis-details', hidden: ! detailsOpen, id: detailsId },
					detailPriorityActions.length > 0 && analysisSection(
						__( 'More improvements', 'easyrankly' ),
						...detailPriorityActions.map( ( row, index ) => el(
							'article',
							{ className: `erankly-analysis-card erankly-analysis-priority is-${ row.priority }`, key: `${ index }-${ row.title }` },
							el(
								'h4',
								null,
								el( 'span', { className: `erankly-analysis-status is-${ row.priority }` }, analysisStatusLabel( row.priority ) ),
								row.title || ''
							),
							el( 'p', null, row.reason || '' ),
							el( 'p', { className: 'erankly-analysis-action' }, row.action || '' )
						) )
					),
					report.search_intent && analysisSection(
						__( 'Search intent', 'easyrankly' ),
						el( 'p', null, report.search_intent )
					),
					Array.isArray( report.strengths ) && report.strengths.length > 0 && analysisSection(
						__( 'Strengths', 'easyrankly' ),
						analysisList( report.strengths )
					),
					Array.isArray( report.keyword_results ) && report.keyword_results.length > 0 && analysisSection(
						__( 'Keyword review', 'easyrankly' ),
						...report.keyword_results.map( ( row ) => {
							const check = ( signals.keyword_checks || [] ).find( ( item ) => item.keyword === row.keyword );

							return el(
								'article',
								{ className: 'erankly-analysis-card erankly-analysis-keyword', key: row.keyword },
								el(
									'h4',
									null,
									el( 'span', { className: `erankly-analysis-status is-${ row.status }` }, analysisStatusLabel( row.status ) ),
									row.keyword || '',
									primaryKeyword && row.keyword === primaryKeyword && el( 'span', { className: 'erankly-analysis-primary' }, __( 'Primary', 'easyrankly' ) )
								),
								check && el(
									'div',
									{ className: 'erankly-analysis-keyword-signals' },
									el( 'span', null, `${ __( 'Exact mentions', 'easyrankly' ) }: ${ check.occurrences || 0 }` ),
									el( 'span', { className: check.in_title ? 'is-present' : '' }, `${ __( 'Title', 'easyrankly' ) }: ${ check.in_title ? __( 'Yes', 'easyrankly' ) : __( 'No', 'easyrankly' ) }` ),
									el( 'span', { className: check.in_intro ? 'is-present' : '' }, `${ __( 'Opening', 'easyrankly' ) }: ${ check.in_intro ? __( 'Yes', 'easyrankly' ) : __( 'No', 'easyrankly' ) }` ),
									el( 'span', { className: check.in_headings ? 'is-present' : '' }, `${ __( 'Headings', 'easyrankly' ) }: ${ check.in_headings ? __( 'Yes', 'easyrankly' ) : __( 'No', 'easyrankly' ) }` )
								),
								el( 'p', null, row.assessment || '' ),
								analysisList( row.evidence, 'erankly-analysis-evidence' ),
								analysisList( row.recommendations )
							);
						} )
					),
					Array.isArray( report.missing_topics ) && report.missing_topics.length > 0 && analysisSection(
						__( 'Missing topics', 'easyrankly' ),
						analysisList( report.missing_topics )
					),
					Array.isArray( report.suggested_headings ) && report.suggested_headings.length > 0 && analysisSection(
						__( 'Suggested structure', 'easyrankly' ),
						...report.suggested_headings.map( ( row, index ) => el(
							'article',
							{ className: 'erankly-analysis-card', key: `${ index }-${ row.text }` },
							el( 'h4', null, `${ String( row.level || 'h2' ).toUpperCase() }: ${ row.text || '' }` ),
							el( 'p', null, row.reason || '' )
						) )
					),
					Array.isArray( report.suggested_sentences ) && report.suggested_sentences.length > 0 && analysisSection(
						__( 'Suggested sentences', 'easyrankly' ),
						...report.suggested_sentences.map( ( row, index ) => el(
							'article',
							{ className: 'erankly-analysis-card erankly-analysis-sentence', key: `${ index }-${ row.text }` },
							el( 'blockquote', null, row.text || '' ),
							el( 'p', { className: 'description' }, row.placement || '' ),
							el(
								Button,
								{ onClick: () => onCopy( row.text || '' ), size: 'small', variant: 'secondary' },
								copiedSentence === row.text ? __( 'Copied', 'easyrankly' ) : __( 'Copy', 'easyrankly' )
							)
						) )
					),
					hasPillarDetails && analysisSection(
						__( 'Pillar readiness', 'easyrankly' ),
						el( 'span', { className: `erankly-analysis-status is-${ report.pillar.readiness }` }, analysisStatusLabel( report.pillar.readiness ) ),
						el( 'p', null, report.pillar.summary || '' ),
						Array.isArray( report.pillar.cluster_ideas ) && report.pillar.cluster_ideas.length > 0 && el( 'h4', null, __( 'Supporting ideas', 'easyrankly' ) ),
						analysisList( report.pillar.cluster_ideas ),
						Array.isArray( report.pillar.link_actions ) && report.pillar.link_actions.length > 0 && el( 'h4', null, __( 'Internal-link actions', 'easyrankly' ) ),
						analysisList( report.pillar.link_actions )
					),
					Array.isArray( signals.cannibalization ) && signals.cannibalization.length > 0 && analysisSection(
						__( 'Possible cannibalization', 'easyrankly' ),
						el(
							'ul',
							null,
							signals.cannibalization.map( ( row ) => {
								const label = `${ row.title || `#${ row.post_id }` } — ${ ( row.keywords || [] ).join( ', ' ) }`;

								return el(
									'li',
									{ key: row.post_id },
									row.edit_url ? el( 'a', { href: row.edit_url }, label ) : label
								);
							} )
						)
					),
					Array.isArray( report.warnings ) && report.warnings.length > 0 && analysisSection(
						__( 'Watch-outs', 'easyrankly' ),
						analysisList( report.warnings )
					),
					analysis.analyzed_at && el(
						'p',
						{ className: 'description erankly-analysis-timestamp' },
						`${ __( 'Analyzed', 'easyrankly' ) }: ${ new Date( analysis.analyzed_at ).toLocaleString() }`
					)
				)
			),
			! hasDetails && analysis.analyzed_at && el(
				'p',
				{ className: 'description erankly-analysis-timestamp' },
				`${ __( 'Analyzed', 'easyrankly' ) }: ${ new Date( analysis.analyzed_at ).toLocaleString() }`
			)
		);
	}

	function ContentAnalysisPanel() {
		const data = usePostData();
		const editorState = useSelect( ( select ) => {
			const editor = select( 'core/editor' );

			return {
				content: editor.getEditedPostAttribute( 'content' ) || '',
				postId: editor.getCurrentPostId(),
				savedContent: editor.getCurrentPostAttribute( 'content' ) || '',
				savedMeta: editor.getCurrentPostAttribute( 'meta' ) || {},
				savedTitle: editor.getCurrentPostAttribute( 'title' ) || '',
				title: editor.getEditedPostAttribute( 'title' ) || '',
			};
		}, [] );
		const keywords = normalizeAnalysisKeywords( data.get( 'focus_keywords' ) );
		const cornerstone = Boolean( data.get( 'cornerstone' ) );
		const currentPayload = {
			content: editorState.content,
			cornerstone,
			keywords,
			title: editorState.title,
		};
		const currentSnapshot = contentAnalysisSnapshot( currentPayload );
		const savedSnapshot = contentAnalysisSnapshot( {
			content: editorState.savedContent,
			cornerstone: Boolean( editorState.savedMeta[ META_MAP.cornerstone ] ),
			keywords: normalizeAnalysisKeywords( editorState.savedMeta[ META_MAP.focus_keywords ] ),
			title: editorState.savedTitle,
		} );
		const inputsDirty = currentSnapshot !== savedSnapshot;
		const [ analysis, setAnalysis ] = useState( null );
		const [ analyzedSnapshot, setAnalyzedSnapshot ] = useState( '' );
		const [ busy, setBusy ] = useState( false );
		const [ copiedSentence, setCopiedSentence ] = useState( '' );
		const [ deleteConfirm, setDeleteConfirm ] = useState( false );
		const [ error, setError ] = useState( '' );
		const [ keywordBusy, setKeywordBusy ] = useState( false );
		const [ loadingStored, setLoadingStored ] = useState( true );
		const [ modalOpen, setModalOpen ] = useState( false );
		const [ panelMessage, setPanelMessage ] = useState( '' );
		const [ serverStale, setServerStale ] = useState( false );
		const stale = Boolean(
			analysis && (
				serverStale ||
				( analyzedSnapshot ? analyzedSnapshot !== currentSnapshot : inputsDirty )
			)
		);

		useEffect( () => {
			let active = true;

			setLoadingStored( true );
			apiFetch( { path: config.contentAnalysisPath + editorState.postId } )
				.then( ( result ) => {
					if ( active ) {
						setAnalysis( result.analysis || null );
						setServerStale( Boolean( result.stale ) );
					}
				} )
				.catch( () => {
					if ( active ) {
						setPanelMessage( __( 'Could not load the saved analysis.', 'easyrankly' ) );
					}
				} )
				.finally( () => {
					if ( active ) {
						setLoadingStored( false );
					}
				} );

			return () => {
				active = false;
			};
		}, [ editorState.postId ] );

		function updateKeywords( values ) {
			const clean = normalizeAnalysisKeywords( values );

			if ( clean.length > 10 ) {
				setPanelMessage( __( 'Use up to 10 focus keywords.', 'easyrankly' ) );
			} else {
				setPanelMessage( '' );
			}

			data.set( 'focus_keywords', clean.slice( 0, 10 ) );
		}

		function makePrimary( keyword ) {
			data.set( 'focus_keywords', [ keyword, ...keywords.filter( ( value ) => value !== keyword ) ] );
		}

		function analyze() {
			const requestSnapshot = currentSnapshot;

			setModalOpen( true );
			setDeleteConfirm( false );
			setError( '' );

			if ( ! config.aiEnabled ) {
				setError( __( 'Enable AI and connect a provider.', 'easyrankly' ) );
				return;
			}
			if ( ! keywords.length ) {
				setError( __( 'Add a focus keyword first.', 'easyrankly' ) );
				return;
			}

			setBusy( true );
			apiFetch( {
				data: currentPayload,
				method: 'POST',
				path: config.contentAnalysisPath + editorState.postId,
			} )
				.then( ( result ) => {
					setAnalysis( result.analysis || null );
					setAnalyzedSnapshot( requestSnapshot );
					setServerStale( false );
					setPanelMessage( __( 'Analysis updated.', 'easyrankly' ) );
				} )
				.catch( ( requestError ) => {
					setError( ( requestError && requestError.message ) || __( 'Analysis failed. Try again.', 'easyrankly' ) );
				} )
				.finally( () => setBusy( false ) );
		}

		function suggestKeyword() {
			setPanelMessage( '' );

			if ( ! config.aiEnabled ) {
				setPanelMessage( __( 'Enable AI and connect a provider.', 'easyrankly' ) );
				return;
			}

			setKeywordBusy( true );
			apiFetch( {
				data: {
					content: editorState.content,
					title: editorState.title,
				},
				method: 'POST',
				path: config.contentAnalysisPath + editorState.postId + '/keyword-suggestion',
			} )
				.then( ( result ) => {
					const keyword = normalizeAnalysisKeywords( [ result.keyword ] )[ 0 ];

					if ( ! keyword ) {
						throw new Error( __( 'The AI did not return a valid keyword.', 'easyrankly' ) );
					}

					const nextKeywords = normalizeAnalysisKeywords( [ keyword, ...keywords ] );

					if ( nextKeywords.length > 10 ) {
						setPanelMessage( __( 'Remove a focus keyword before applying this suggestion.', 'easyrankly' ) );
						return;
					}

					updateKeywords( nextKeywords );
				} )
				.catch( ( requestError ) => {
					setPanelMessage( ( requestError && requestError.message ) || __( 'Keyword suggestion failed. Try again.', 'easyrankly' ) );
				} )
				.finally( () => setKeywordBusy( false ) );
		}

		function deleteAnalysis() {
			setBusy( true );
			setError( '' );
			apiFetch( {
				method: 'DELETE',
				path: config.contentAnalysisPath + editorState.postId,
			} )
				.then( () => {
					setAnalysis( null );
					setAnalyzedSnapshot( '' );
					setServerStale( false );
					setDeleteConfirm( false );
					setModalOpen( false );
					setPanelMessage( __( 'Analysis deleted.', 'easyrankly' ) );
				} )
				.catch( ( requestError ) => {
					setError( ( requestError && requestError.message ) || __( 'Could not delete the analysis.', 'easyrankly' ) );
				} )
				.finally( () => setBusy( false ) );
		}

		function copySentence( value ) {
			copyAnalysisText( value, () => {
				setCopiedSentence( value );
				window.setTimeout( () => setCopiedSentence( '' ), 1600 );
			} );
		}

		return el(
			PluginDocumentSettingPanel,
			{
				className: 'erankly-panel erankly-panel--analysis',
				name: 'erankly-content-analysis',
				title: __( 'Content analysis', 'easyrankly' ),
			},
			el(
				'div',
				{ className: 'erankly-analysis-targeting' },
				el(
					'div',
					{ className: 'erankly-analysis-targeting__keywords' },
					el( FormTokenField, {
						__next40pxDefaultSize: true,
						help: __( 'First tag is primary. Add up to 10 related keyphrases.', 'easyrankly' ),
						label: __( 'Focus keywords', 'easyrankly' ),
						maxLength: 10,
						onChange: updateKeywords,
						value: keywords,
					} ),
					keywords.length > 1 && el( SelectControl, {
						__next40pxDefaultSize: true,
						label: __( 'Primary keyword', 'easyrankly' ),
						onChange: makePrimary,
						options: keywords.map( ( keyword ) => ( { label: keyword, value: keyword } ) ),
						value: keywords[ 0 ],
					} )
				),
				el(
					'div',
					{ className: 'erankly-analysis-targeting__cornerstone' },
					el( ToggleControl, {
						checked: cornerstone,
						help: __( 'Uses stricter checks for depth, supporting content and internal links.', 'easyrankly' ),
						label: __( 'Cornerstone / pillar content', 'easyrankly' ),
						onChange: ( value ) => data.set( 'cornerstone', value ),
					} )
				)
			),
			el(
				'div',
				{ className: 'erankly-analysis-panel-actions' },
				el(
					Button,
					{
						disabled: loadingStored || busy || keywordBusy || ( ! analysis && ! config.aiEnabled ),
						isBusy: loadingStored || busy,
						onClick: () => {
							if ( analysis ) {
								setError( '' );
								setModalOpen( true );
							} else {
								analyze();
							}
						},
						variant: 'secondary',
					},
					analysis ? __( 'Open analysis', 'easyrankly' ) : __( 'Analyze', 'easyrankly' )
				),
				el(
					Button,
					{
						disabled: busy || keywordBusy || ! config.aiEnabled,
						isBusy: keywordBusy,
						onClick: suggestKeyword,
						variant: 'secondary',
					},
					keywordBusy ? __( 'Suggesting keyword…', 'easyrankly' ) : __( 'Suggest keyword', 'easyrankly' )
				),
				loadingStored && el( Spinner ),
				el(
					'p',
					{ className: 'erankly-analysis-panel-status' },
					stale ? __( 'Content changed since this analysis.', 'easyrankly' ) : panelMessage
				)
			),
			config.aiEnabled && el(
				'p',
				{ className: 'description erankly-ai-privacy' },
				__( 'Analyzing or suggesting a keyword shares the current editor content and measured signals with your configured WordPress AI provider. EasyRankly does not operate the AI service.', 'easyrankly' )
			),
			! config.aiEnabled && ! analysis && el(
				Notice,
				{ isDismissible: false, status: 'info' },
				__( 'Enable AI and connect a provider.', 'easyrankly' )
			),
			modalOpen && el(
				Modal,
				{
					className: 'erankly-content-analysis-modal-frame',
					onRequestClose: () => {
						if ( ! busy ) {
							setDeleteConfirm( false );
							setModalOpen( false );
						}
					},
					shouldCloseOnClickOutside: ! busy,
					title: __( 'Content analysis', 'easyrankly' ),
				},
				error && el( Notice, { isDismissible: false, status: 'error' }, error ),
				busy && ! analysis && el(
					'div',
					{ className: 'erankly-analysis-loading' },
					el( Spinner ),
					el( 'p', null, __( 'Analyzing content…', 'easyrankly' ) )
				),
				busy && analysis && el(
					Notice,
					{ className: 'erankly-analysis-progress', isDismissible: false, status: 'info' },
					el( Spinner ),
					__( 'Updating analysis…', 'easyrankly' )
				),
				analysis && el( ContentAnalysisReport, {
					analysis,
					copiedSentence,
					onCopy: copySentence,
					stale,
				} ),
				! busy && ! analysis && ! error && el(
					Notice,
					{ isDismissible: false, status: 'info' },
					__( 'No saved analysis.', 'easyrankly' )
				),
				el(
					'div',
					{ className: 'erankly-analysis-modal-controls' },
					el(
						'div',
						{ className: 'erankly-analysis-delete-controls' },
						analysis && ! deleteConfirm && el(
							Button,
							{ disabled: busy, isDestructive: true, onClick: () => setDeleteConfirm( true ), variant: 'link' },
							__( 'Delete analysis', 'easyrankly' )
						),
						analysis && deleteConfirm && el(
							Fragment,
							null,
							el( 'span', null, __( 'Delete this report?', 'easyrankly' ) ),
							el( Button, { disabled: busy, isDestructive: true, onClick: deleteAnalysis, size: 'small', variant: 'secondary' }, __( 'Delete', 'easyrankly' ) ),
							el( Button, { disabled: busy, onClick: () => setDeleteConfirm( false ), size: 'small', variant: 'link' }, __( 'Cancel', 'easyrankly' ) )
						)
					),
					el(
						'div',
						{ className: 'erankly-analysis-modal-actions' },
						analysis && el( Button, { disabled: busy || ! config.aiEnabled, isBusy: busy, onClick: analyze, variant: 'secondary' }, __( 'Analyze again', 'easyrankly' ) ),
						el( Button, { disabled: busy, onClick: () => setModalOpen( false ), variant: 'primary' }, __( 'Close', 'easyrankly' ) )
					)
				)
			)
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

	function InternalLinksPanel() {
		const postId = useSelect( ( select ) => select( 'core/editor' ).getCurrentPostId(), [] );
		const linksUi = window.eranklyLinkSuggestionsUi;

		if ( ! linksUi || ! config.internalLinksEnabled || ! config.aiEnabled ) {
			return null;
		}

		return linksUi.internalLinksPanel( PluginDocumentSettingPanel, postId );
	}

	function ERanklyDocumentSettings() {
		shared.usePanelsAfterDefaults();

		return el(
			Fragment,
			null,
			el( GeneralPanel ),
				config.contentAnalysisEnabled && el( ContentAnalysisPanel ),
			! config.simplifiedMode && el( SocialPanel ),
			! config.simplifiedMode && el( SchemaPanel ),
			el( VisibilityPanel ),
			config.internalLinksEnabled && config.aiEnabled && el( InternalLinksPanel ),
			el( SeoChecklistPanel )
		);
	}

	registerPlugin( 'erankly-document-settings', {
		render: ERanklyDocumentSettings,
	} );
}() );
