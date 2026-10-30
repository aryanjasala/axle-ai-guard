/**
 * Tools > AI Guard: the overview cards, kill switch toggles, the calls table and its detail dialog.
 *
 * JS hooks (through wp.hooks, read on each use so later scripts can add them):
 *
 * - filter axleAiGuard.overviewCards ( cards ): the cards on the Overview tab. Each is
 *   { id, label, value, kind } where kind is number or cost.
 * - filter axleAiGuard.callColumns ( columns ): the columns of the Calls table. Each is
 *   { key, label, render( call ) } and render returns text or a DOM node.
 * - filter axleAiGuard.formatCost ( text, micros, currency ): how a cost is shown.
 * - action axleAiGuard.killSwitchToggled ( result ): a kill switch changed; result is
 *   { scope, subject, on, changed }.
 */
( function () {
	'use strict';

	const data = window.axleAiGuardData || {};
	const __ = window.wp && window.wp.i18n ? window.wp.i18n.__ : ( text ) => text;

	function hooks() {
		return window.wp && window.wp.hooks ? window.wp.hooks : null;
	}

	function filter( name, value, ...args ) {
		const h = hooks();
		return h ? h.applyFilters( name, value, ...args ) : value;
	}

	function action( name, ...args ) {
		const h = hooks();
		if ( h ) {
			h.doAction( name, ...args );
		}
	}

	function formatCost( micros, currency ) {
		const amount = micros / 1000000;
		const decimals = 0 !== micros && Math.abs( amount ) < 0.01 ? 4 : 2;
		const text = currency + ' ' + amount.toLocaleString( undefined, { minimumFractionDigits: decimals, maximumFractionDigits: decimals } );
		return filter( 'axleAiGuard.formatCost', text, micros, currency );
	}

	function el( tag, attrs, children ) {
		const node = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ key, value ] ) => {
			if ( 'text' === key ) {
				node.textContent = value;
			} else {
				node.setAttribute( key, value );
			}
		} );
		( children || [] ).forEach( ( child ) => node.append( child instanceof Node ? child : document.createTextNode( String( child ) ) ) );
		return node;
	}

	function renderCards() {
		const list = document.querySelector( '[data-aag-cards]' );
		if ( ! list || ! data.cards ) {
			return;
		}
		const cards = filter( 'axleAiGuard.overviewCards', data.cards.slice() );
		list.textContent = '';
		cards.forEach( ( card ) => {
			const value = 'cost' === card.kind ? formatCost( Number( card.value ) || 0, data.currency ) : Number( card.value || 0 ).toLocaleString();
			list.append(
				el( 'li', { class: 'aag-card', 'data-card': card.id }, [ el( 'span', { class: 'aag-card-value', text: value } ), el( 'span', { class: 'aag-card-label', text: card.label } ) ] )
			);
		} );
	}

	function announce( message ) {
		let region = document.querySelector( '.aag-live' );
		if ( ! region ) {
			region = el( 'div', { class: 'aag-live screen-reader-text', role: 'status', 'aria-live': 'polite' } );
			document.body.append( region );
		}
		region.textContent = message;
	}

	document.addEventListener( 'submit', ( event ) => {
		const form = event.target.closest( '.aag-kill-form' );
		const button = form && form.querySelector( '.aag-kill-toggle' );
		if ( ! button || ! window.wp || ! window.wp.apiFetch ) {
			return;
		}
		event.preventDefault();
		button.disabled = true;

		window.wp
			.apiFetch( {
				path: '/axle-ai-guard/v1/kill',
				method: 'POST',
				data: { scope: button.dataset.scope, subject: button.dataset.subject, on: '1' === button.dataset.on },
			} )
			.then( ( result ) => {
				action( 'axleAiGuard.killSwitchToggled', result );
				announce( result.on ? __( 'Switched off.', 'axle-ai-guard' ) : __( 'Switched back on.', 'axle-ai-guard' ) );
				// The status, the notice and the admin bar all change: load the screen again.
				window.location.reload();
			} )
			.catch( ( error ) => {
				button.disabled = false;
				announce( ( error && error.message ) || __( 'That did not work.', 'axle-ai-guard' ) );
			} );
	} );

	function defaultColumns() {
		return [
			{ key: 'started_at', label: __( 'When', 'axle-ai-guard' ), render: ( call ) => new Date( call.started_at ).toLocaleString() },
			{ key: 'caller', label: __( 'Caller', 'axle-ai-guard' ), render: ( call ) => el( 'code', { text: call.caller } ) },
			{ key: 'status', label: __( 'Status', 'axle-ai-guard' ), render: ( call ) => el( 'span', { class: 'aag-pill aag-pill-' + call.status, text: call.status + ( call.block_rule ? ': ' + call.block_rule : '' ) } ) },
			{ key: 'model', label: __( 'Provider and model', 'axle-ai-guard' ), render: ( call ) => [ call.provider, call.model ].filter( Boolean ).join( ' / ' ) || '-' },
			{ key: 'tokens', label: __( 'Tokens', 'axle-ai-guard' ), render: ( call ) => call.total_tokens.toLocaleString() + ( call.estimated ? ' ' + __( '(estimated)', 'axle-ai-guard' ) : '' ) },
			{ key: 'cost', label: __( 'Cost', 'axle-ai-guard' ), render: ( call ) => formatCost( call.cost_micros, call.currency || data.currency ) },
			{ key: 'context', label: __( 'Context', 'axle-ai-guard' ), render: ( call ) => call.context },
			{
				key: 'details',
				label: __( 'Details', 'axle-ai-guard' ),
				render: ( call ) =>
					el( 'button', { type: 'button', class: 'button button-small aag-detail-button', 'data-id': String( call.id ), 'aria-haspopup': 'dialog' }, [
						__( 'Details', 'axle-ai-guard' ),
						el( 'span', { class: 'screen-reader-text', text: ' #' + call.id } ),
					] ),
			},
		];
	}

	function renderCalls() {
		const holder = document.querySelector( '[data-aag-calls]' );
		if ( ! holder || ! data.calls ) {
			return;
		}
		const columns = filter( 'axleAiGuard.callColumns', defaultColumns() );
		const table = el( 'table', { class: 'widefat striped aag-table aag-calls-table' } );
		const head = el( 'tr' );
		columns.forEach( ( column ) => head.append( el( 'th', { scope: 'col', 'data-column': column.key, text: column.label } ) ) );
		table.append( el( 'thead', {}, [ head ] ) );

		const body = el( 'tbody' );
		if ( ! data.calls.length ) {
			body.append( el( 'tr', {}, [ el( 'td', { colspan: String( columns.length ), class: 'aag-empty', text: __( 'No calls match.', 'axle-ai-guard' ) } ) ] ) );
		}
		data.calls.forEach( ( call ) => {
			const row = el( 'tr', { 'data-id': String( call.id ) } );
			columns.forEach( ( column ) => {
				const value = column.render ? column.render( call ) : call[ column.key ];
				row.append( el( 'td', { 'data-column': column.key }, [ value instanceof Node ? value : String( value ?? '' ) ] ) );
			} );
			body.append( row );
		} );
		table.append( body );
		holder.textContent = '';
		holder.append( table );
	}

	function detailList( call ) {
		const profile = call.profile || {};
		const findings = Object.entries( profile.findings || {} )
			.map( ( [ id, count ] ) => id + ': ' + count + ' (' + ( ( profile.actions || {} )[ id ] || '' ) + ')' )
			.join( ', ' );
		const redacted = Object.entries( profile.redacted || {} )
			.map( ( [ id, count ] ) => id + ': ' + count )
			.join( ', ' );
		const rows = [
			[ __( 'Status', 'axle-ai-guard' ), call.status + ( call.block_rule ? ': ' + call.block_rule : '' ) + ( call.error_code ? ' (' + call.error_code + ')' : '' ) ],
			[ __( 'Caller', 'axle-ai-guard' ), call.caller + ' (' + call.caller_type + ')' ],
			[ __( 'Attributed from', 'axle-ai-guard' ), call.caller_file || '-' ],
			[ __( 'Context and user', 'axle-ai-guard' ), call.context + ( call.user_id ? ', #' + call.user_id : '' ) ],
			[ __( 'Provider and model', 'axle-ai-guard' ), [ call.provider, call.model ].filter( Boolean ).join( ' / ' ) || '-' ],
			[ __( 'Tokens', 'axle-ai-guard' ), call.prompt_tokens + ' in, ' + call.completion_tokens + ' out, ' + call.thought_tokens + ' thought' ],
			[ __( 'Cost', 'axle-ai-guard' ), formatCost( call.cost_micros, call.currency || data.currency ) ],
			[ __( 'Timing and HTTP', 'axle-ai-guard' ), call.duration_ms + ' ms, HTTP ' + ( call.http_status || '-' ) + ', ' + call.request_bytes + ' B sent, ' + call.response_bytes + ' B received' ],
			[ __( 'Messages', 'axle-ai-guard' ), String( profile.messages ?? '-' ) + ( profile.chars ? ' (' + Object.entries( profile.chars ).map( ( [ role, n ] ) => role + ' ' + n + ' chars' ).join( ', ' ) + ')' : '' ) ],
			[ __( 'Files', 'axle-ai-guard' ), ( profile.files || [] ).map( ( file ) => file.mime + ' ' + file.bytes + ' B' ).join( ', ' ) || '-' ],
			[ __( 'Findings', 'axle-ai-guard' ), findings || '-' ],
			[ __( 'Redacted', 'axle-ai-guard' ), redacted || '-' ],
			[ __( 'Preview', 'axle-ai-guard' ), null === call.preview || undefined === call.preview ? __( 'Not stored', 'axle-ai-guard' ) : call.preview ],
		];
		const list = el( 'dl', { class: 'aag-detail' } );
		rows.forEach( ( [ term, value ] ) => list.append( el( 'dt', { text: term } ), el( 'dd', { text: value } ) ) );
		return list;
	}

	let opener = null;

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '.aag-detail-button' );
		const dialog = document.querySelector( '.aag-modal' );
		if ( button && dialog && window.wp && window.wp.apiFetch ) {
			opener = button;
			const body = dialog.querySelector( '[data-aag-detail]' );
			body.textContent = __( 'Loading...', 'axle-ai-guard' );
			dialog.showModal();
			window.wp.apiFetch( { path: '/axle-ai-guard/v1/calls/' + button.dataset.id } ).then( ( call ) => {
				body.textContent = '';
				body.append( detailList( call ) );
			} );
			return;
		}
		if ( event.target.closest( '.aag-modal-close' ) && dialog ) {
			dialog.close();
		}
	} );

	document.addEventListener(
		'close',
		( event ) => {
			if ( event.target.classList && event.target.classList.contains( 'aag-modal' ) && opener ) {
				opener.focus();
			}
		},
		true
	);

	function start() {
		renderCards();
		renderCalls();

		// A filter added later, by a script that loads after this one, redraws what it changes.
		const h = hooks();
		if ( h ) {
			h.addAction( 'hookAdded', 'axleAiGuard/redraw', ( name ) => {
				if ( 'string' === typeof name && 0 === name.indexOf( 'axleAiGuard.' ) ) {
					renderCards();
					renderCalls();
				}
			} );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
