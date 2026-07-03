/* global eranklyAI */
/**
 * "Generate with AI" buttons for the classic post editor meta box and the
 * taxonomy term forms. The block editor handles its own button inside editor.js.
 */
( function () {
	'use strict';

	if ( 'undefined' === typeof eranklyAI ) {
		return;
	}

	const config = eranklyAI;

	function setField( selector, value ) {
		if ( ! selector || ! value ) {
			return;
		}

		const field = document.querySelector( selector );
		if ( ! field ) {
			return;
		}

		field.value = value;
		// Let the character counter (and any other listeners) react.
		field.dispatchEvent( new Event( 'input', { bubbles: true } ) );
	}

	function getTargetSelectors( button, attr, legacyAttr ) {
		const raw = button.getAttribute( attr ) || button.getAttribute( legacyAttr ) || '';

		return raw.split( ',' ).map( function ( selector ) {
			return selector.trim();
		} ).filter( Boolean );
	}

	function setFields( selectors, value ) {
		selectors.forEach( function ( selector ) {
			setField( selector, value );
		} );
	}

	function getFieldValue( selectors ) {
		let field;

		for ( const selector of selectors ) {
			field = document.querySelector( selector );

			if ( field ) {
				return field.value || '';
			}
		}

		return '';
	}

	function setStatus( button, message, isError ) {
		const status = button.parentNode
			? button.parentNode.querySelector( '[data-erankly-ai-status]' )
			: null;

		if ( ! status ) {
			return;
		}

		status.textContent = message || '';
		status.classList.toggle( 'erankly-ai-error', !! isError );
	}

	function setButtonsBusy( button, isBusy ) {
		const panel = button.parentNode
			? button.parentNode.querySelector( '[data-erankly-ai-improve]' )
			: null;
		const improveButton = panel ? panel.querySelector( '.erankly-ai-improve-button' ) : null;
		const textarea = panel ? panel.querySelector( 'textarea' ) : null;

		button.disabled = isBusy;

		if ( improveButton ) {
			improveButton.disabled = isBusy || ! ( textarea && textarea.value.trim() );
		}
	}

	function getImprovePanel( button ) {
		const field = button.parentNode;
		let panel;
		let textarea;
		let improveButton;
		let label;
		let textareaId;
		let status;

		if ( ! field || config.simplifiedMode ) {
			return null;
		}

		panel = field.querySelector( '[data-erankly-ai-improve]' );

		if ( panel ) {
			return panel;
		}

		textareaId = 'erankly-ai-improve-' + (
			button.getAttribute( 'data-erankly-ai-object-type' ) || 'post'
		) + '-' + (
			button.getAttribute( 'data-erankly-ai-object-id' ) || '0'
		) + '-' + (
			button.getAttribute( 'data-erankly-ai-target' ) || 'seo'
		);

		textareaId = textareaId.replace( /[^a-z0-9_-]/gi, '-' );

		panel = document.createElement( 'div' );
		panel.className = 'erankly-ai-improve';
		panel.hidden = true;
		panel.setAttribute( 'data-erankly-ai-improve', '' );

		label = document.createElement( 'label' );
		label.setAttribute( 'for', textareaId );
		label.textContent = config.i18n.improveLabel;

		textarea = document.createElement( 'textarea' );
		textarea.id = textareaId;
		textarea.className = 'widefat';
		textarea.rows = 3;
		textarea.placeholder = config.i18n.improvePlaceholder;

		improveButton = document.createElement( 'button' );
		improveButton.type = 'button';
		improveButton.className = 'button erankly-ai-improve-button';
		improveButton.disabled = true;
		improveButton.textContent = config.i18n.improveButton;

		textarea.addEventListener( 'input', function () {
			improveButton.disabled = button.disabled || ! textarea.value.trim();
		} );

		panel.appendChild( label );
		panel.appendChild( textarea );
		panel.appendChild( improveButton );

		status = field.querySelector( '[data-erankly-ai-status]' );
		field.insertBefore( panel, status || null );

		return panel;
	}

	function onClick( button, isImprovement ) {
		const objectId = parseInt( button.getAttribute( 'data-erankly-ai-object-id' ), 10 );
		const objectType = button.getAttribute( 'data-erankly-ai-object-type' ) || 'post';
		const generationTarget = button.getAttribute( 'data-erankly-ai-target' ) || 'seo';
		const titleTargets = getTargetSelectors( button, 'data-erankly-ai-title-targets', 'data-erankly-ai-title-target' );
		const descTargets = getTargetSelectors( button, 'data-erankly-ai-desc-targets', 'data-erankly-ai-desc-target' );
		const panel = isImprovement ? getImprovePanel( button ) : null;
		const textarea = panel ? panel.querySelector( 'textarea' ) : null;
		const instructions = textarea ? textarea.value.trim() : '';
		const requestData = { object_id: objectId, object_type: objectType, target: generationTarget };

		if ( ! objectId ) {
			setStatus( button, config.i18n.saveFirst, true );
			return;
		}

		if ( isImprovement ) {
			if ( ! instructions ) {
				setStatus( button, config.i18n.instructionsRequired, true );
				return;
			}

			requestData.instructions = instructions;
			requestData.current_title = getFieldValue( titleTargets );
			requestData.current_description = getFieldValue( descTargets );
		}

		setButtonsBusy( button, true );
		setStatus( button, isImprovement ? config.i18n.improving : config.i18n.generating, false );

		window.fetch( config.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce,
			},
			body: JSON.stringify( requestData ),
		} )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					if ( ! response.ok ) {
						throw new Error( ( data && data.message ) || config.i18n.error );
					}
					return data;
				} );
			} )
			.then( function ( data ) {
				setFields( titleTargets, data.title );
				setFields( descTargets, data.description );
				setStatus( button, isImprovement ? config.i18n.improved : config.i18n.done, false );

				if ( ! config.simplifiedMode ) {
					const improvePanel = getImprovePanel( button );

					if ( improvePanel ) {
						improvePanel.hidden = false;
					}
				}
			} )
			.catch( function ( err ) {
				setStatus( button, err.message || config.i18n.error, true );
			} )
			.finally( function () {
				setButtonsBusy( button, false );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		const improveButton = event.target.closest( '.erankly-ai-improve-button' );
		if ( improveButton ) {
			const field = improveButton.closest( '.erankly-ai-field' );
			const button = field ? field.querySelector( '.erankly-ai-generate' ) : null;

			if ( button ) {
				event.preventDefault();
				onClick( button, true );
			}

			return;
		}

		const button = event.target.closest( '.erankly-ai-generate' );
		if ( button ) {
			event.preventDefault();
			onClick( button, false );
		}
	} );
}() );
