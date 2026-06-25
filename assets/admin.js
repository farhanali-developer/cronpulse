/* global cpData, jQuery */
( function ( $ ) {
	'use strict';

	// -------------------------------------------------------------------------
	// Flash message
	// -------------------------------------------------------------------------
	const $flash = $( '<div id="cp-flash"></div>' ).appendTo( 'body' );
	let flashTimer;

	function flash( msg, type ) {
		clearTimeout( flashTimer );
		$flash
			.text( msg )
			.removeClass( 'cp-flash-success cp-flash-error' )
			.addClass( 'cp-flash-' + type )
			.fadeIn( 200 );
		flashTimer = setTimeout( function () {
			$flash.fadeOut( 400 );
		}, 3500 );
	}

	// -------------------------------------------------------------------------
	// Tabs
	// -------------------------------------------------------------------------
	function activateTab( tab ) {
		const $tab = $( '.cp-tab[data-tab="' + tab + '"]' );
		if ( ! $tab.length ) {
			return;
		}
		$( '.cp-tab' ).removeClass( 'cp-tab-active' );
		$tab.addClass( 'cp-tab-active' );
		$( '.cp-tab-panel' ).hide();
		$( '#cp-' + tab ).show();
	}

	$( '.cp-tab' ).on( 'click', function ( e ) {
		e.preventDefault();
		activateTab( $( this ).data( 'tab' ) );
	} );

	// Land on the right tab after a redirect, e.g. saving Alert settings.
	const initialTab = window.location.hash.replace( '#cp-', '' );
	if ( initialTab ) {
		activateTab( initialTab );
	}

	// -------------------------------------------------------------------------
	// Hook search / filter
	// -------------------------------------------------------------------------
	$( '#cp-search' ).on( 'input', function () {
		const q = $( this ).val().toLowerCase().trim();
		$( '.cp-row[data-hook]' ).each( function () {
			const hook = $( this ).data( 'hook' ).toLowerCase();
			$( this ).toggle( ! q || hook.includes( q ) );
		} );
	} );

	// -------------------------------------------------------------------------
	// Run Now
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-run-now', function () {
		const $btn  = $( this );
		const hook  = $btn.data( 'hook' );
		const args  = $btn.data( 'args' );

		$btn.addClass( 'is-loading' ).text( cpData.i18n.running );

		$.post( cpData.ajaxUrl, {
			action : 'cp_run_now',
			nonce  : cpData.nonce,
			hook   : hook,
			args   : JSON.stringify( args ),
		} )
		.done( function ( res ) {
			if ( res.success ) {
				flash( res.data.message, 'success' );

				// Update duration cell in the same row
				const $row = $btn.closest( 'tr' );
				const $dur = $row.find( 'td' ).eq( 5 );
				if ( res.data.duration !== undefined ) {
					$dur.text( res.data.duration + ' ms' );
				}

				// Update last run cell
				const $lastRun = $row.find( 'td' ).eq( 4 );
				$lastRun.text( 'Just now' );

				// Flip status if it was overdue/pending/failing → healthy
				$row.removeClass( 'cp-status-overdue cp-status-pending cp-status-failing' )
				    .addClass( 'cp-status-healthy' );
				$row.find( '.cp-dot' )
				    .removeClass( 'cp-dot-overdue cp-dot-pending cp-dot-failing' )
				    .addClass( 'cp-dot-healthy' );
				$row.find( '.cp-status-text' ).text( 'Healthy' );

			} else {
				flash( res.data.message || cpData.i18n.error, 'error' );
			}
		} )
		.fail( function () {
			flash( cpData.i18n.error, 'error' );
		} )
		.always( function () {
			$btn.removeClass( 'is-loading' ).text( 'Run Now' );
		} );
	} );

	// -------------------------------------------------------------------------
	// Clear log
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-clear-log', function () {
		if ( ! window.confirm( cpData.i18n.confirmClear ) ) {
			return;
		}

		$.post( cpData.ajaxUrl, {
			action : 'cp_clear_log',
			nonce  : cpData.nonce,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				$( '#cp-log .cp-table' ).remove();
				$( '.cp-log-toolbar' ).remove();
				$( '#cp-log' ).append(
					'<p class="cp-empty">' + 'Log cleared.' + '</p>'
				);
				$( '.cp-badge' ).remove();
				flash( res.data.message, 'success' );
			}
		} )
		.fail( function () {
			flash( cpData.i18n.error, 'error' );
		} );
	} );

} )( jQuery );
