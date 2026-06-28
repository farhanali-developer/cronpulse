/* global cronpulseData, jQuery */
( function ( $ ) {
	'use strict';

	const PAGE_SIZE = 25;
	let currentPage = 1;
	let sortKey     = null; // 'next-run' | 'duration' | null
	let sortDir     = 'asc';

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
	// Jobs table: search + status filter + sort + pagination
	//
	// All four behaviors share one row set, so they're combined into a single
	// renderTable() rather than independent handlers stepping on each other
	// (e.g. a filter hiding rows pagination already sliced to a page).
	// -------------------------------------------------------------------------
	function getAllRows() {
		return $( '#cp-jobs .cp-table tbody tr.cp-row[data-hook]' );
	}

	function renderTable() {
		const $tbody = $( '#cp-jobs .cp-table tbody' );
		if ( ! $tbody.length ) {
			return;
		}

		const q      = $( '#cp-search' ).val().toLowerCase().trim();
		const status = $( '#cp-status-filter' ).val();

		let rows = getAllRows().get();

		if ( sortKey ) {
			rows.sort( function ( a, b ) {
				const av = parseFloat( $( a ).data( sortKey ) );
				const bv = parseFloat( $( b ).data( sortKey ) );
				return sortDir === 'asc' ? av - bv : bv - av;
			} );
			$.each( rows, function ( i, row ) {
				$tbody.append( row );
			} );
		}

		const $rows = $( rows );
		const $matching = $rows.filter( function () {
			const $row    = $( this );
			const hook    = $row.data( 'hook' ).toLowerCase();
			const rowStat = $row.data( 'status' );

			const matchesText   = ! q || hook.includes( q );
			const matchesStatus = ! status || rowStat === status;

			return matchesText && matchesStatus;
		} );

		$rows.hide();

		const totalPages = Math.max( 1, Math.ceil( $matching.length / PAGE_SIZE ) );
		if ( currentPage > totalPages ) {
			currentPage = totalPages;
		}

		const start = ( currentPage - 1 ) * PAGE_SIZE;
		$matching.slice( start, start + PAGE_SIZE ).show();

		$( '#cp-pagination' ).toggle( totalPages > 1 );
		$( '#cp-page-info' ).text(
			'Page ' + currentPage + ' of ' + totalPages + ' (' + $matching.length + ' jobs)'
		);
		$( '#cp-prev-page' ).prop( 'disabled', currentPage <= 1 );
		$( '#cp-next-page' ).prop( 'disabled', currentPage >= totalPages );
	}

	$( '#cp-search' ).on( 'input', function () {
		currentPage = 1;
		renderTable();
	} );
	$( '#cp-status-filter' ).on( 'change', function () {
		currentPage = 1;
		renderTable();
	} );
	$( '#cp-prev-page' ).on( 'click', function () {
		currentPage--;
		renderTable();
	} );
	$( '#cp-next-page' ).on( 'click', function () {
		currentPage++;
		renderTable();
	} );
	$( '.cp-sortable' ).on( 'click', function () {
		const key = $( this ).data( 'sort' );

		if ( sortKey === key ) {
			sortDir = sortDir === 'asc' ? 'desc' : 'asc';
		} else {
			sortKey = key;
			sortDir = 'asc';
		}

		$( '.cp-sortable' ).removeClass( 'cp-sort-asc cp-sort-desc' );
		$( this ).addClass( sortDir === 'asc' ? 'cp-sort-asc' : 'cp-sort-desc' );

		currentPage = 1;
		renderTable();
	} );

	renderTable();

	// -------------------------------------------------------------------------
	// Run Now
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-run-now', function () {
		const $btn  = $( this );
		const hook  = $btn.data( 'hook' );
		const args  = $btn.data( 'args' );

		$btn.addClass( 'is-loading' ).text( cronpulseData.i18n.running );

		$.post( cronpulseData.ajaxUrl, {
			action : 'cronpulse_run_now',
			nonce  : cronpulseData.nonce,
			hook   : hook,
			args   : JSON.stringify( args ),
		} )
		.done( function ( res ) {
			if ( res.success ) {
				flash( res.data.message, 'success' );

				const $row = $btn.closest( 'tr' );

				// Update duration cell in the same row
				if ( res.data.duration !== undefined ) {
					$row.find( '.cp-duration-text' ).text( res.data.duration + ' ms' );
					$row.attr( 'data-duration', res.data.duration );
				}

				// Update last run cell
				const $lastRun = $row.find( 'td' ).eq( 4 );
				$lastRun.text( 'Just now' );

				// Flip status if it was overdue/pending/failing → healthy
				$row.removeClass( 'cp-status-overdue cp-status-pending cp-status-failing' )
				    .addClass( 'cp-status-healthy' )
				    .attr( 'data-status', 'healthy' );
				$row.find( '.cp-dot' )
				    .removeClass( 'cp-dot-overdue cp-dot-pending cp-dot-failing' )
				    .addClass( 'cp-dot-healthy' );
				$row.find( '.cp-status-text' ).text( 'Healthy' );
				$row.find( '.cp-snooze' ).remove();

				renderTable();

			} else {
				flash( res.data.message || cronpulseData.i18n.error, 'error' );
			}
		} )
		.fail( function () {
			flash( cronpulseData.i18n.error, 'error' );
		} )
		.always( function () {
			$btn.removeClass( 'is-loading' ).text( 'Run Now' );
		} );
	} );

	// -------------------------------------------------------------------------
	// Clear log
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-clear-log', function () {
		if ( ! window.confirm( cronpulseData.i18n.confirmClear ) ) {
			return;
		}

		$.post( cronpulseData.ajaxUrl, {
			action : 'cronpulse_clear_log',
			nonce  : cronpulseData.nonce,
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
			flash( cronpulseData.i18n.error, 'error' );
		} );
	} );

	// -------------------------------------------------------------------------
	// Unschedule
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-unschedule', function () {
		const $btn = $( this );
		const hook = $btn.data( 'hook' );

		const confirmMsg = cronpulseData.i18n.confirmUnschedule.replace( '%s', hook );
		if ( ! window.confirm( confirmMsg ) ) {
			return;
		}

		$btn.prop( 'disabled', true );

		$.post( cronpulseData.ajaxUrl, {
			action    : 'cronpulse_unschedule',
			nonce     : cronpulseData.nonce,
			hook      : hook,
			timestamp : $btn.data( 'timestamp' ),
			sig       : $btn.data( 'sig' ),
		} )
		.done( function ( res ) {
			if ( res.success ) {
				flash( res.data.message, 'success' );
				$btn.closest( 'tr' ).remove();
				renderTable();
			} else {
				flash( res.data.message || cronpulseData.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} )
		.fail( function () {
			flash( cronpulseData.i18n.error, 'error' );
			$btn.prop( 'disabled', false );
		} );
	} );

	// -------------------------------------------------------------------------
	// Snooze alert
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-snooze', function () {
		const $btn = $( this );
		const hook = $btn.data( 'hook' );

		$btn.prop( 'disabled', true );

		$.post( cronpulseData.ajaxUrl, {
			action : 'cronpulse_snooze',
			nonce  : cronpulseData.nonce,
			hook   : hook,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				flash( res.data.message, 'success' );
				$btn.remove();
			} else {
				flash( res.data.message || cronpulseData.i18n.error, 'error' );
				$btn.prop( 'disabled', false );
			}
		} )
		.fail( function () {
			flash( cronpulseData.i18n.error, 'error' );
			$btn.prop( 'disabled', false );
		} );
	} );

	// -------------------------------------------------------------------------
	// Send test email
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-test-email', function () {
		const $btn = $( this );

		$btn.prop( 'disabled', true );

		$.post( cronpulseData.ajaxUrl, {
			action : 'cronpulse_test_email',
			nonce  : cronpulseData.nonce,
		} )
		.done( function ( res ) {
			flash( res.data.message || cronpulseData.i18n.error, res.success ? 'success' : 'error' );
		} )
		.fail( function () {
			flash( cronpulseData.i18n.error, 'error' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// -------------------------------------------------------------------------
	// Send test webhook
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-test-webhook', function () {
		const $btn = $( this );

		$btn.prop( 'disabled', true );

		$.post( cronpulseData.ajaxUrl, {
			action : 'cronpulse_test_webhook',
			nonce  : cronpulseData.nonce,
		} )
		.done( function ( res ) {
			flash( res.data.message || cronpulseData.i18n.error, res.success ? 'success' : 'error' );
		} )
		.fail( function () {
			flash( cronpulseData.i18n.error, 'error' );
		} )
		.always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// -------------------------------------------------------------------------
	// Clear email log
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-clear-email-log', function () {
		if ( ! window.confirm( cronpulseData.i18n.confirmClear ) ) {
			return;
		}

		$.post( cronpulseData.ajaxUrl, {
			action : 'cronpulse_clear_email_log',
			nonce  : cronpulseData.nonce,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				const $section = $( '.cp-email-log-section' );
				$section.find( '.cp-table' ).remove();
				$section.find( '.cp-log-toolbar' ).remove();
				$section.append(
					'<p class="cp-empty">' + 'No emails sent yet. Alert emails (and test emails) will show up here.' + '</p>'
				);
				$( '.cp-tab[data-tab="email-log"] .cp-badge' ).remove();
				flash( res.data.message, 'success' );
			}
		} )
		.fail( function () {
			flash( cronpulseData.i18n.error, 'error' );
		} );
	} );

	// -------------------------------------------------------------------------
	// Clear debug log
	// -------------------------------------------------------------------------
	$( document ).on( 'click', '.cp-clear-debug-log', function () {
		if ( ! window.confirm( cronpulseData.i18n.confirmClear ) ) {
			return;
		}

		$.post( cronpulseData.ajaxUrl, {
			action : 'cronpulse_clear_debug_log',
			nonce  : cronpulseData.nonce,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				const $section = $( '.cp-debug-log-section' );
				$section.find( '.cp-debug-log' ).remove();
				$section.find( '.cp-log-toolbar' ).remove();
				$section.append(
					'<p class="cp-empty">' + 'No debug output yet. Use "Send Test Email" on the Settings tab to generate some.' + '</p>'
				);
				flash( res.data.message, 'success' );
			}
		} )
		.fail( function () {
			flash( cronpulseData.i18n.error, 'error' );
		} );
	} );

} )( jQuery );
