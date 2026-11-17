/*
 * WitsValley member app entry. Mounts every `.wvm-root[data-wvm-view]` rendered by the
 * [wvm_dashboard], [wvm_pricing] and [wvm_enterprise_form] shortcodes.
 */
import { createRoot } from '@wordpress/element';
import '../shared/tokens.css';
import './styles/base.css';
import './styles/components.css';
import './styles/pricing.css';
import './styles/enterprise.css';
import './styles/dashboard.css';
import { App, type View } from './App';

const VIEWS: View[] = [ 'dashboard', 'pricing', 'enterprise' ];

function mount( el: HTMLElement ) {
	if ( el.dataset.wvmMounted ) {
		return;
	}
	el.dataset.wvmMounted = '1';
	const requested = ( el.dataset.wvmView || 'dashboard' ) as View;
	const view: View = VIEWS.includes( requested ) ? requested : 'dashboard';
	el.classList.add( 'wvm-theme', 'wvm-theme--auto', `wvm-root--${ view }` );
	el.innerHTML = '';
	createRoot( el ).render( <App view={ view } plan={ el.dataset.plan || undefined } /> );
}

function boot() {
	document.querySelectorAll< HTMLElement >( '.wvm-root[data-wvm-view]' ).forEach( mount );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
