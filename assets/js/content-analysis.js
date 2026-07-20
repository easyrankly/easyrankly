/* global eranklyContentAnalysis, tinymce */
/**
 * Classic-editor keyword tokens and persistent content-analysis modal.
 */
( function () {
	'use strict';

	if ( 'undefined' === typeof eranklyContentAnalysis ) {
		return;
	}

	const config = eranklyContentAnalysis;
	const i18n = config.i18n || {};

	function create( tag, className, text ) {
		const node = document.createElement( tag );

		if ( className ) {
			node.className = className;
		}
		if ( undefined !== text ) {
			node.textContent = text;
		}

		return node;
	}

	function normalizedKey( value ) {
		return value.trim().replace( /\s+/g, ' ' ).toLocaleLowerCase();
	}

	function uniqueKeywords( values ) {
		const seen = new Set();
		const output = [];

		values.forEach( ( value ) => {
			const clean = String( value || '' ).trim().replace( /\s+/g, ' ' ).slice( 0, 120 );
			const key = normalizedKey( clean );

			if ( clean && ! seen.has( key ) ) {
				seen.add( key );
				output.push( clean );
			}
		} );

		return output;
	}

	function bindKeywordField() {
		const field = document.querySelector( '[data-erankly-keyword-field]' );

		if ( ! field ) {
			return { getKeywords: () => [], setSuggestedKeyword: () => {} };
		}

		const source = field.querySelector( '[data-erankly-keyword-source]' );
		const ui = field.querySelector( '[data-erankly-keyword-ui]' );
		const tags = field.querySelector( '[data-erankly-keyword-tags]' );
		const entry = field.querySelector( '[data-erankly-keyword-entry]' );
		const addButton = field.querySelector( '[data-erankly-keyword-add]' );
		const primary = field.querySelector( '[data-erankly-keyword-primary]' );
		const primarySelect = field.querySelector( '[data-erankly-keyword-primary-select]' );
		const error = field.querySelector( '[data-erankly-keyword-error]' );
		let keywords = uniqueKeywords( ( source.value || '' ).split( /[\r\n,]+/ ) );

		function announceChange() {
			source.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}

		function render( changed = false ) {
			tags.replaceChildren();
			primarySelect.replaceChildren();
			source.value = keywords.join( ', ' );

			keywords.forEach( ( keyword, index ) => {
				const item = create( 'span', 'erankly-keyword-tag' + ( 0 === index ? ' is-primary' : '' ) );
				item.setAttribute( 'role', 'listitem' );
				if ( 0 === index ) {
					item.appendChild( create( 'span', 'erankly-keyword-tag__primary', i18n.primary ) );
				}
				item.appendChild( create( 'span', 'erankly-keyword-tag__text', keyword ) );

				const remove = create( 'button', 'erankly-keyword-tag__remove', '×' );
				remove.type = 'button';
				remove.setAttribute( 'aria-label', ( i18n.removeKeyword || 'Remove keyword' ) + ': ' + keyword );
				remove.addEventListener( 'click', () => {
					keywords.splice( index, 1 );
					render( true );
					entry.focus();
				} );
				item.appendChild( remove );
				tags.appendChild( item );

				const option = document.createElement( 'option' );
				option.value = String( index );
				option.textContent = keyword;
				primarySelect.appendChild( option );
			} );

			primary.hidden = keywords.length < 2;
			primarySelect.value = '0';
			error.textContent = '';

			if ( changed ) {
				announceChange();
			}
		}

		function add( raw ) {
			const additions = uniqueKeywords( String( raw || '' ).split( /[\r\n,]+/ ) );
			const next = uniqueKeywords( keywords.concat( additions ) );

			if ( next.length > 10 ) {
				error.textContent = i18n.keywordLimit;
				return;
			}

			keywords = next;
			entry.value = '';
			render( additions.length > 0 );
		}

		primarySelect.addEventListener( 'change', () => {
			const index = Number( primarySelect.value );
			if ( index > 0 && index < keywords.length ) {
				keywords.unshift( keywords.splice( index, 1 )[ 0 ] );
				render( true );
			}
		} );

		addButton.addEventListener( 'click', () => add( entry.value ) );
		entry.addEventListener( 'keydown', ( event ) => {
			if ( 'Enter' === event.key || ',' === event.key ) {
				event.preventDefault();
				add( entry.value );
			} else if ( 'Backspace' === event.key && ! entry.value && keywords.length ) {
				keywords.pop();
				render( true );
			}
		} );
		entry.addEventListener( 'input', () => {
			if ( /[\r\n,]/.test( entry.value ) ) {
				add( entry.value );
			}
		} );

		source.type = 'hidden';
		ui.hidden = false;
		render();

		return {
			getKeywords: () => keywords.slice(),
			setSuggestedKeyword: ( keyword ) => {
				const nextKeywords = uniqueKeywords( [ keyword ].concat( keywords ) );

				if ( nextKeywords.length > 10 ) {
					return false;
				}

				keywords = nextKeywords;
				render( true );
				return true;
			},
		};
	}

	function currentEditorContent() {
		if ( 'undefined' !== typeof tinymce ) {
			const editor = tinymce.get( 'content' );
			if ( editor && ! editor.isHidden() ) {
				return editor.getContent();
			}
		}

		const textarea = document.getElementById( 'content' );
		return textarea ? textarea.value : '';
	}

	function request( method, data, url = config.restUrl ) {
		const options = {
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			method,
		};

		if ( data ) {
			options.body = JSON.stringify( data );
		}

		return window.fetch( url, options ).then( async ( response ) => {
			const payload = await response.json().catch( () => ( {} ) );

			if ( ! response.ok ) {
				throw new Error( payload.message || i18n.error );
			}

			return payload;
		} );
	}

	function appendList( parent, values, className = '' ) {
		if ( ! Array.isArray( values ) || ! values.length ) {
			return;
		}

		const list = create( 'ul', className );
		values.forEach( ( value ) => {
			list.appendChild( create( 'li', '', value ) );
		} );
		parent.appendChild( list );
	}

	function addSection( parent, title ) {
		const section = create( 'section', 'erankly-analysis-section' );
		section.appendChild( create( 'h3', '', title ) );
		parent.appendChild( section );

		return section;
	}

	function copyText( text, button ) {
		const done = () => {
			button.textContent = i18n.copied;
			window.setTimeout( () => {
				button.textContent = i18n.copy;
			}, 1600 );
		};

		const fallback = () => {
			const textarea = document.createElement( 'textarea' );
			textarea.value = text;
			textarea.style.position = 'fixed';
			textarea.style.opacity = '0';
			document.body.appendChild( textarea );
			textarea.select();
			document.execCommand( 'copy' );
			textarea.remove();
			done();
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done ).catch( fallback );
			return;
		}

		fallback();
	}

	function renderReport( body, analysis, stale ) {
		body.replaceChildren();
		const report = analysis.report || {};
		const signals = report.signals || {};
		const source = signals.source || {};
		const verdictLabels = {
			in_focus: i18n.inFocus,
			partially_in_focus: i18n.partiallyInFocus,
			out_of_focus: i18n.outOfFocus,
		};
		const statusLabels = {
			high: i18n.highPriority,
			low: i18n.lowPriority,
			medium: i18n.mediumPriority,
			missing: i18n.missing,
			not_applicable: i18n.notApplicable,
			overused: i18n.overused,
			partial: i18n.partial,
			strong: i18n.strong,
			weak: i18n.weak,
		};
		const priorityActions = Array.isArray( report.priority_actions ) ? report.priority_actions : [];
		const visiblePriorityActions = priorityActions.filter( ( row ) => 'high' === row.priority );
		const detailPriorityActions = priorityActions.filter( ( row ) => 'high' !== row.priority );
		const hasPillarDetails = report.pillar && ( report.pillar.summary || 'not_applicable' !== report.pillar.readiness );
		const hasDetails = Boolean(
			detailPriorityActions.length ||
			report.search_intent ||
			( report.strengths && report.strengths.length ) ||
			( report.keyword_results && report.keyword_results.length ) ||
			( report.missing_topics && report.missing_topics.length ) ||
			( report.suggested_headings && report.suggested_headings.length ) ||
			( report.suggested_sentences && report.suggested_sentences.length ) ||
			hasPillarDetails ||
			( signals.cannibalization && signals.cannibalization.length ) ||
			( report.warnings && report.warnings.length )
		);
		const hero = create( 'div', 'erankly-analysis-hero is-' + ( report.verdict || 'out_of_focus' ) );
		const heroText = create( 'div', 'erankly-analysis-hero__text' );
		heroText.appendChild( create( 'span', 'erankly-analysis-verdict', verdictLabels[ report.verdict ] || i18n.outOfFocus ) );
		heroText.appendChild( create( 'p', '', report.summary || '' ) );
		hero.appendChild( heroText );

		const score = create( 'div', 'erankly-analysis-score' );
		const scoreValue = create( 'strong', 'erankly-analysis-score__value', String( report.score || 0 ) );
		scoreValue.appendChild( create( 'span', 'erankly-analysis-score__max', '/100' ) );
		score.appendChild( scoreValue );
		score.appendChild( create( 'span', 'erankly-analysis-score__label', i18n.focusScore ) );
		hero.appendChild( score );
		body.appendChild( hero );

		if ( stale ) {
			body.appendChild( create( 'div', 'notice notice-warning inline erankly-analysis-stale', i18n.analysisStale ) );
		}

		const measured = addSection( body, i18n.measuredSignals );
		const metrics = create( 'dl', 'erankly-analysis-metrics' );
		[
			[ i18n.words, source.word_count || 0 ],
			[ i18n.coverage, String( source.coverage_percent || 0 ) + '%' ],
			[ i18n.headingsCount, source.heading_count || 0 ],
		].forEach( ( metric ) => {
			const item = create( 'div', 'erankly-analysis-metric' );
			item.appendChild( create( 'dt', '', metric[ 0 ] ) );
			item.appendChild( create( 'dd', '', String( metric[ 1 ] ) ) );
			metrics.appendChild( item );
		} );
		if ( signals.links && signals.links.available ) {
			[
				[ i18n.inboundLinks, signals.links.inbound_count || 0 ],
				[ i18n.outboundLinks, signals.links.outbound_count || 0 ],
			].forEach( ( metric ) => {
				const item = create( 'div', 'erankly-analysis-metric' );
				item.appendChild( create( 'dt', '', metric[ 0 ] ) );
				item.appendChild( create( 'dd', '', String( metric[ 1 ] ) ) );
				metrics.appendChild( item );
			} );
		}
		measured.appendChild( metrics );

		if ( visiblePriorityActions.length ) {
			const priorities = addSection( body, i18n.priorities );
			visiblePriorityActions.forEach( ( row ) => {
				const card = create( 'article', 'erankly-analysis-card erankly-analysis-priority is-' + row.priority );
				const heading = create( 'h4', '' );
				heading.appendChild( create( 'span', 'erankly-analysis-status is-' + row.priority, statusLabels[ row.priority ] || row.priority ) );
				heading.appendChild( document.createTextNode( row.title || '' ) );
				card.appendChild( heading );
				card.appendChild( create( 'p', '', row.reason || '' ) );
				card.appendChild( create( 'p', 'erankly-analysis-action', row.action || '' ) );
				priorities.appendChild( card );
			} );
		}

		const details = create( 'div', 'erankly-analysis-details' );
		if ( hasDetails ) {
			const detailsId = 'erankly-content-analysis-details-classic';
			const toggle = create( 'button', 'erankly-analysis-details-toggle', i18n.showDetails );
			toggle.type = 'button';
			toggle.setAttribute( 'aria-controls', detailsId );
			toggle.setAttribute( 'aria-expanded', 'false' );
			details.id = detailsId;
			details.hidden = true;
			toggle.addEventListener( 'click', () => {
				const open = 'true' !== toggle.getAttribute( 'aria-expanded' );
				toggle.setAttribute( 'aria-expanded', String( open ) );
				toggle.textContent = open ? i18n.hideDetails : i18n.showDetails;
				details.hidden = ! open;
			} );
			body.appendChild( toggle );
			body.appendChild( details );
		}

		if ( detailPriorityActions.length ) {
			const morePriorities = addSection( details, i18n.morePriorities );
			detailPriorityActions.forEach( ( row ) => {
				const card = create( 'article', 'erankly-analysis-card erankly-analysis-priority is-' + row.priority );
				const heading = create( 'h4', '' );
				heading.appendChild( create( 'span', 'erankly-analysis-status is-' + row.priority, statusLabels[ row.priority ] || row.priority ) );
				heading.appendChild( document.createTextNode( row.title || '' ) );
				card.appendChild( heading );
				card.appendChild( create( 'p', '', row.reason || '' ) );
				card.appendChild( create( 'p', 'erankly-analysis-action', row.action || '' ) );
				morePriorities.appendChild( card );
			} );
		}

		if ( report.search_intent ) {
			const intent = addSection( details, i18n.searchIntent );
			intent.appendChild( create( 'p', '', report.search_intent ) );
		}

		if ( report.strengths && report.strengths.length ) {
			appendList( addSection( details, i18n.strengths ), report.strengths );
		}

		if ( report.keyword_results && report.keyword_results.length ) {
			const keywordSection = addSection( details, i18n.keywordReview );
			report.keyword_results.forEach( ( row, index ) => {
				const check = ( signals.keyword_checks || [] ).find( ( item ) => item.keyword === row.keyword );
				const card = create( 'article', 'erankly-analysis-card erankly-analysis-keyword' );
				const heading = create( 'h4', '' );
				heading.appendChild( create( 'span', 'erankly-analysis-status is-' + row.status, statusLabels[ row.status ] || row.status ) );
				heading.appendChild( document.createTextNode( row.keyword || '' ) );
				if ( 0 === index ) {
					heading.appendChild( create( 'span', 'erankly-analysis-primary', i18n.primary ) );
				}
				card.appendChild( heading );
				if ( check ) {
					const checks = create( 'div', 'erankly-analysis-keyword-signals' );
					checks.appendChild( create( 'span', '', i18n.exactMentions + ': ' + String( check.occurrences || 0 ) ) );
					[
						[ i18n.title, check.in_title ],
						[ i18n.opening, check.in_intro ],
						[ i18n.headingsCount, check.in_headings ],
					].forEach( ( signal ) => {
						checks.appendChild( create( 'span', signal[ 1 ] ? 'is-present' : '', signal[ 0 ] + ': ' + ( signal[ 1 ] ? i18n.yes : i18n.no ) ) );
					} );
					card.appendChild( checks );
				}
				card.appendChild( create( 'p', '', row.assessment || '' ) );
				appendList( card, row.evidence, 'erankly-analysis-evidence' );
				appendList( card, row.recommendations );
				keywordSection.appendChild( card );
			} );
		}

		if ( report.missing_topics && report.missing_topics.length ) {
			appendList( addSection( details, i18n.missingTopics ), report.missing_topics );
		}

		if ( report.suggested_headings && report.suggested_headings.length ) {
			const headings = addSection( details, i18n.headings );
			report.suggested_headings.forEach( ( row ) => {
				const card = create( 'article', 'erankly-analysis-card' );
				card.appendChild( create( 'h4', '', String( row.level || 'h2' ).toUpperCase() + ': ' + ( row.text || '' ) ) );
				card.appendChild( create( 'p', '', row.reason || '' ) );
				headings.appendChild( card );
			} );
		}

		if ( report.suggested_sentences && report.suggested_sentences.length ) {
			const sentences = addSection( details, i18n.sentences );
			report.suggested_sentences.forEach( ( row ) => {
				const card = create( 'article', 'erankly-analysis-card erankly-analysis-sentence' );
				card.appendChild( create( 'blockquote', '', row.text || '' ) );
				card.appendChild( create( 'p', 'description', row.placement || '' ) );
				const button = create( 'button', 'button button-small', i18n.copy );
				button.type = 'button';
				button.addEventListener( 'click', () => copyText( row.text || '', button ) );
				card.appendChild( button );
				sentences.appendChild( card );
			} );
		}

		if ( hasPillarDetails ) {
			const pillar = addSection( details, i18n.pillar );
			pillar.appendChild( create( 'span', 'erankly-analysis-status is-' + report.pillar.readiness, statusLabels[ report.pillar.readiness ] || report.pillar.readiness ) );
			pillar.appendChild( create( 'p', '', report.pillar.summary || '' ) );
			if ( report.pillar.cluster_ideas && report.pillar.cluster_ideas.length ) {
				pillar.appendChild( create( 'h4', '', i18n.clusterIdeas ) );
				appendList( pillar, report.pillar.cluster_ideas );
			}
			if ( report.pillar.link_actions && report.pillar.link_actions.length ) {
				pillar.appendChild( create( 'h4', '', i18n.linkActions ) );
				appendList( pillar, report.pillar.link_actions );
			}
		}

		if ( signals.cannibalization && signals.cannibalization.length ) {
			const conflicts = addSection( details, i18n.conflicts );
			const list = create( 'ul', '' );
			signals.cannibalization.forEach( ( row ) => {
				const item = document.createElement( 'li' );
				const label = ( row.title || '#' + row.post_id ) + ' — ' + ( row.keywords || [] ).join( ', ' );
				if ( row.edit_url ) {
					const link = create( 'a', '', label );
					link.href = row.edit_url;
					item.appendChild( link );
				} else {
					item.textContent = label;
				}
				list.appendChild( item );
			} );
			conflicts.appendChild( list );
		}

		if ( report.warnings && report.warnings.length ) {
			appendList( addSection( details, i18n.warnings ), report.warnings );
		}

		if ( analysis.analyzed_at ) {
			( hasDetails ? details : body ).appendChild( create( 'p', 'description erankly-analysis-timestamp', i18n.analyzedAt + ': ' + new Date( analysis.analyzed_at ).toLocaleString() ) );
		}
	}

	function bindAnalysis( keywordController ) {
		const root = document.querySelector( '[data-erankly-content-analysis-root]' );

		if ( ! root ) {
			return;
		}

		const openButton = root.querySelector( '[data-erankly-content-analysis-open]' );
		const suggestButton = root.querySelector( '[data-erankly-keyword-suggestion]' );
		const summary = root.querySelector( '[data-erankly-content-analysis-summary]' );
		const overlay = root.querySelector( '[data-erankly-content-analysis-modal]' );
		const modal = overlay.querySelector( '.erankly-content-analysis-modal' );
		const body = root.querySelector( '[data-erankly-content-analysis-body]' );
		const rerun = root.querySelector( '[data-erankly-content-analysis-rerun]' );
		const deleteButton = root.querySelector( '[data-erankly-content-analysis-delete]' );
		const deleteConfirm = root.querySelector( '[data-erankly-content-analysis-delete-confirm]' );
		const deleteYes = root.querySelector( '[data-erankly-content-analysis-delete-yes]' );
		const deleteNo = root.querySelector( '[data-erankly-content-analysis-delete-no]' );
		const closeButtons = root.querySelectorAll( '[data-erankly-content-analysis-close]' );
		let analysis = null;
		let stale = false;
		let busy = false;
		let busyAction = '';
		let editorChanged = false;
		let keywordSummary = '';
		let lastFocused = null;

		function updateControls() {
			const hasAnalysis = !! analysis;
			openButton.textContent = hasAnalysis ? i18n.openAnalysis : i18n.analyze;
			openButton.disabled = busy || ( ! hasAnalysis && ! config.aiEnabled );
			suggestButton.textContent = 'keyword' === busyAction ? i18n.suggesting : i18n.suggestKeyword;
			suggestButton.disabled = busy || ! config.aiEnabled;
			rerun.hidden = ! hasAnalysis;
			rerun.disabled = busy || ! config.aiEnabled;
			deleteButton.hidden = ! hasAnalysis;
			deleteConfirm.hidden = true;

			if ( 'keyword' === busyAction ) {
				summary.textContent = i18n.suggesting;
			} else if ( keywordSummary ) {
				summary.textContent = keywordSummary;
			} else if ( stale && hasAnalysis ) {
				summary.textContent = i18n.analysisStale;
			} else if ( hasAnalysis ) {
				summary.textContent = i18n.analysisUpdated;
			} else if ( config.aiEnabled ) {
				summary.textContent = '';
			}
		}

		function openModal() {
			lastFocused = document.activeElement;
			overlay.hidden = false;
			document.body.classList.add( 'erankly-analysis-modal-open' );
			modal.focus();
		}

		function closeModal() {
			overlay.hidden = true;
			document.body.classList.remove( 'erankly-analysis-modal-open' );
			deleteConfirm.hidden = true;
			deleteButton.hidden = ! analysis;
			if ( lastFocused && lastFocused.focus ) {
				lastFocused.focus();
			}
		}

		function setLoading() {
			body.replaceChildren();
			const loading = create( 'div', 'erankly-analysis-loading' );
			loading.setAttribute( 'aria-busy', 'true' );
			loading.appendChild( create( 'span', 'spinner is-active' ) );
			loading.appendChild( create( 'p', '', i18n.analyzing ) );
			body.appendChild( loading );
		}

		function setError( message ) {
			const notice = create( 'div', 'notice notice-error inline', message || i18n.error );

			if ( analysis ) {
				renderReport( body, analysis, stale );
				body.prepend( notice );
			} else {
				body.replaceChildren( notice );
			}
		}

		function payload() {
			const title = document.getElementById( 'title' );
			const cornerstone = document.querySelector( '[data-erankly-cornerstone]' );

			return {
				content: currentEditorContent(),
				cornerstone: !! ( cornerstone && cornerstone.checked ),
				keywords: keywordController.getKeywords(),
				title: title ? title.value : '',
			};
		}

		function analyze() {
			const data = payload();

			openModal();
			if ( ! data.keywords.length ) {
				setError( i18n.keywordRequired );
				return;
			}
			if ( data.keywords.length > 10 ) {
				setError( i18n.keywordLimit );
				return;
			}

			busy = true;
			busyAction = 'analysis';
			keywordSummary = '';
			editorChanged = false;
			setLoading();
			updateControls();
			request( 'POST', data ).then( ( result ) => {
				analysis = result.analysis || null;
				stale = editorChanged;
				renderReport( body, analysis, stale );
			} ).catch( ( error ) => {
				setError( error.message );
			} ).finally( () => {
				busy = false;
				busyAction = '';
				updateControls();
			} );
		}

		function suggestKeyword() {
			const data = payload();

			busy = true;
			busyAction = 'keyword';
			keywordSummary = '';
			updateControls();
			request(
				'POST',
				{ content: data.content, title: data.title },
				config.suggestUrl
			).then( ( result ) => {
				const keyword = uniqueKeywords( [ result.keyword ] )[ 0 ];

				if ( ! keyword ) {
					throw new Error( i18n.suggestInvalid );
				}

				const applied = keywordController.setSuggestedKeyword( keyword );
				keywordSummary = applied ? '' : i18n.suggestLimit;
				if ( applied && analysis ) {
					stale = true;
				}
			} ).catch( ( error ) => {
				keywordSummary = error.message || i18n.suggestError;
			} ).finally( () => {
				busy = false;
				busyAction = '';
				updateControls();
			} );
		}

		openButton.addEventListener( 'click', () => {
			if ( analysis ) {
				renderReport( body, analysis, stale );
				openModal();
			} else {
				analyze();
			}
		} );
		suggestButton.addEventListener( 'click', suggestKeyword );
		rerun.addEventListener( 'click', analyze );
		closeButtons.forEach( ( button ) => button.addEventListener( 'click', closeModal ) );
		overlay.addEventListener( 'mousedown', ( event ) => {
			if ( event.target === overlay ) {
				closeModal();
			}
		} );

		deleteButton.addEventListener( 'click', () => {
			deleteButton.hidden = true;
			deleteConfirm.hidden = false;
			deleteYes.focus();
		} );
		deleteNo.addEventListener( 'click', () => {
			deleteConfirm.hidden = true;
			deleteButton.hidden = false;
		} );
		deleteYes.addEventListener( 'click', () => {
			busy = true;
			busyAction = 'delete';
			updateControls();
			request( 'DELETE' ).then( () => {
				analysis = null;
				stale = false;
				summary.textContent = i18n.analysisDeleted;
				closeModal();
			} ).catch( ( error ) => {
				setError( error.message );
			} ).finally( () => {
				busy = false;
				busyAction = '';
				updateControls();
			} );
		} );

		document.addEventListener( 'keydown', ( event ) => {
			if ( overlay.hidden ) {
				return;
			}
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				closeModal();
				return;
			}
			if ( 'Tab' === event.key ) {
				const focusable = Array.from( modal.querySelectorAll( 'a[href], button:not([disabled]):not([hidden]), [tabindex]:not([tabindex="-1"])' ) ).filter( ( node ) => ! node.closest( '[hidden]' ) );
				if ( ! focusable.length ) {
					return;
				}
				const first = focusable[ 0 ];
				const last = focusable[ focusable.length - 1 ];
				if ( event.shiftKey && document.activeElement === first ) {
					event.preventDefault();
					last.focus();
				} else if ( ! event.shiftKey && document.activeElement === last ) {
					event.preventDefault();
					first.focus();
				}
			}
		} );

		function markStale() {
			editorChanged = true;
			if ( analysis && ! busy ) {
				stale = true;
				updateControls();
			}
		}

		[ '#title', '#content', '[data-erankly-keyword-source]', '[data-erankly-cornerstone]' ].forEach( ( selector ) => {
			const field = document.querySelector( selector );
			if ( field ) {
				field.addEventListener( 'input', markStale );
				field.addEventListener( 'change', markStale );
			}
		} );
		if ( 'undefined' !== typeof tinymce ) {
			const attachEditor = ( editor ) => {
				if ( editor && 'content' === editor.id ) {
					editor.on( 'input change undo redo', markStale );
				}
			};
			attachEditor( tinymce.get( 'content' ) );
			tinymce.on( 'AddEditor', ( event ) => attachEditor( event.editor ) );
		}

		request( 'GET' ).then( ( result ) => {
			analysis = result.analysis || null;
			stale = !! result.stale || editorChanged;
			updateControls();
		} ).catch( () => {
			updateControls();
		} );
	}

	document.addEventListener( 'DOMContentLoaded', () => {
		bindAnalysis( bindKeywordField() );
	} );
}() );
