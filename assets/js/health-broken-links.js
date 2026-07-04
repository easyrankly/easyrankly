/**
 * Drives the manual "Broken-Link Candidates" crawl on the Health tab.
 *
 * The crawl runs server-side in small batches; this script kicks it off and
 * then repeatedly advances it over REST, updating a progress readout. When the
 * run finishes the page is reloaded so the server can render the results table
 * (and its redirect / AI actions) with the freshly stored data.
 */
( function () {
	'use strict';

	var root = document.getElementById( 'erankly-bl' );

	if ( ! root ) {
		return;
	}

	var startBtn = document.getElementById( 'erankly-bl-start' );
	var cancelBtn = document.getElementById( 'erankly-bl-cancel' );
	var progress = document.getElementById( 'erankly-bl-progress' );

	var restBase = root.getAttribute( 'data-rest-base' ) || '';
	var nonce = root.getAttribute( 'data-nonce' ) || '';
	var initialStatus = root.getAttribute( 'data-status' ) || 'idle';

	var i18n = ( window.eranklyHealthBrokenLinks && window.eranklyHealthBrokenLinks.i18n ) || {};
	var running = false;
	var cancelled = false;

	function t( key, fallback ) {
		return Object.prototype.hasOwnProperty.call( i18n, key ) ? i18n[ key ] : fallback;
	}

	function post( path ) {
		return window
			.fetch( restBase + path, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': nonce }
			} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} );
	}

	function setProgress( text ) {
		progress.hidden = false;
		progress.textContent = text;
	}

	function describe( state ) {
		var s = state.stats || {};

		if ( state.status === 'discovering' ) {
			return ( t( 'crawling', 'Crawling pages:' ) + ' ' + state.pages_done +
				' (' + state.queued + ' ' + t( 'queued', 'queued' ) + ')' );
		}

		if ( state.status === 'checking' ) {
			return ( t( 'checking', 'Checking links:' ) + ' ' + state.checks_done + '/' +
				state.check_total + ' — ' + ( s.broken || 0 ) + ' ' + t( 'broken', 'broken' ) );
		}

		return t( 'starting', 'Starting…' );
	}

	function finish() {
		running = false;
		cancelBtn.hidden = true;
		startBtn.disabled = false;
	}

	function tick() {
		if ( cancelled ) {
			return;
		}

		post( 'tick' )
			.then( function ( state ) {
				if ( cancelled ) {
					return;
				}

				if ( state.status === 'done' ) {
					setProgress( t( 'complete', 'Scan complete. Reloading…' ) );
					window.location.reload();
					return;
				}

				if ( state.status === 'idle' ) {
					finish();
					setProgress( t( 'stopped', 'Scan stopped.' ) );
					return;
				}

				setProgress( describe( state ) );
				tick();
			} )
			.catch( function () {
				finish();
				setProgress( t( 'error', 'The scan failed. Please try again.' ) );
			} );
	}

	function enterRunningUi() {
		running = true;
		cancelled = false;
		startBtn.disabled = true;
		cancelBtn.hidden = false;
	}

	startBtn.addEventListener( 'click', function () {
		if ( running ) {
			return;
		}

		enterRunningUi();
		setProgress( t( 'starting', 'Starting…' ) );

		post( 'start' )
			.then( function ( state ) {
				setProgress( describe( state ) );
				tick();
			} )
			.catch( function () {
				finish();
				setProgress( t( 'error', 'The scan failed. Please try again.' ) );
			} );
	} );

	cancelBtn.addEventListener( 'click', function () {
		cancelled = true;
		finish();
		setProgress( t( 'stopping', 'Stopping…' ) );

		post( 'cancel' ).then( function () {
			setProgress( t( 'stopped', 'Scan stopped.' ) );
		} );
	} );

	// Resume a crawl that was still running when the page was (re)loaded.
	if ( initialStatus === 'discovering' || initialStatus === 'checking' ) {
		enterRunningUi();
		setProgress( t( 'starting', 'Starting…' ) );
		tick();
	}

	// Expand/collapse and the results filter are handled by the shared
	// bindExpandablePanel() in admin.js (data-erankly-* attributes).
} )();
