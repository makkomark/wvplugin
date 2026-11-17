/* WitsValley admin app entry. Mounts into every WitsValley submenu page. */
import { createRoot } from '@wordpress/element';
import '../shared/tokens.css';
import './styles/admin.css';
import { App } from './App';
import { setFallbackRoute } from './router';

function mount() {
	const el = document.getElementById( 'wvm-admin-root' );
	if ( ! el ) {
		return;
	}
	setFallbackRoute( el.getAttribute( 'data-route' ) || '/' );
	document.body.classList.add( 'wvm-a-ready' );
	createRoot( el ).render( <App /> );
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
