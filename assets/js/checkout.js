/* WitsValley Members — order-pay helper: polls the order status so mobile-money style payments
   (approved on the phone while this page is open) land the member in the dashboard automatically. */
( function () {
	'use strict';
	var cfg = window.wvmCheckout;
	if ( ! cfg || ! cfg.statusUrl ) {
		return;
	}
	var waiting = document.querySelector( '.wvm-pay__waiting' );
	var form = document.getElementById( 'order_review' );
	var started = false;
	var tries = 0;

	function track( name, data ) {
		if ( ! cfg.analytics ) {
			return;
		}
		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push( Object.assign( { event: name }, data || {} ) );
	}

	function poll() {
		tries++;
		fetch( cfg.statusUrl, { credentials: 'same-origin', headers: { Accept: 'application/json' } } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( body ) {
				var status = body && body.data ? body.data.status : null;
				if ( status === 'paid' ) {
					track( 'wvm_purchase', { value: cfg.amount, currency: cfg.currency } );
					window.location.href = cfg.dashboardUrl + ( cfg.dashboardUrl.indexOf( '?' ) > -1 ? '&' : '?' ) + 'welcome=1';
					return;
				}
				if ( tries < 120 ) {
					setTimeout( poll, tries < 12 ? 5000 : 10000 );
				}
			} )
			.catch( function () {
				if ( tries < 120 ) {
					setTimeout( poll, 10000 );
				}
			} );
	}

	function start() {
		if ( started ) {
			return;
		}
		started = true;
		if ( waiting ) {
			waiting.hidden = false;
		}
		setTimeout( poll, 4000 );
	}

	track( 'wvm_checkout_view', { value: cfg.amount, currency: cfg.currency } );
	if ( form ) {
		form.addEventListener( 'submit', function () {
			track( 'wvm_checkout_submit', { value: cfg.amount, currency: cfg.currency } );
			start();
		} );
	}
	// If the member returns to this tab after approving on their phone, check right away.
	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'visible' && started ) {
			poll();
		}
	} );
} )();
