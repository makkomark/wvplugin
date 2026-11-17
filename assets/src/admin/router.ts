/*
 * Hash router: #/members/12?tab=notes. In-app navigation only changes the hash;
 * on first load an empty hash falls back to the submenu page's data-route.
 */
import { useEffect, useState } from 'react';

export type RouteName =
	| 'home'
	| 'members'
	| 'member'
	| 'payments'
	| 'review'
	| 'plans'
	| 'workshops'
	| 'consultations'
	| 'applications'
	| 'organisations'
	| 'organisation'
	| 'access'
	| 'insights'
	| 'audit'
	| 'settings'
	| 'notfound';

export interface Route {
	name: RouteName;
	path: string;
	params: Record< string, string >;
	query: Record< string, string >;
}

const TABLE: [ string, RouteName ][] = [
	[ '/', 'home' ],
	[ '/members', 'members' ],
	[ '/members/:id', 'member' ],
	[ '/payments', 'payments' ],
	[ '/payments/review', 'review' ],
	[ '/plans', 'plans' ],
	[ '/workshops', 'workshops' ],
	[ '/consultations', 'consultations' ],
	[ '/enterprise', 'applications' ],
	[ '/enterprise/applications', 'applications' ],
	[ '/enterprise/organisations', 'organisations' ],
	[ '/enterprise/organisations/:id', 'organisation' ],
	[ '/access', 'access' ],
	[ '/insights', 'insights' ],
	[ '/audit', 'audit' ],
	[ '/settings', 'settings' ],
	[ '/settings/:group', 'settings' ],
];

let fallback = '/';

export function setFallbackRoute( r: string ): void {
	fallback = r || '/';
}

export function parse( hash: string ): Route {
	let h = ( hash || '' ).replace( /^#!?/, '' );
	if ( ! h ) {
		h = fallback;
	}
	if ( ! h.startsWith( '/' ) ) {
		h = '/' + h;
	}
	const [ rawPath, rawQuery = '' ] = h.split( '?' );
	const path = rawPath.replace( /\/+$/, '' ) || '/';
	const query: Record< string, string > = {};
	new URLSearchParams( rawQuery ).forEach( ( v, k ) => {
		query[ k ] = v;
	} );
	const parts = path.split( '/' ).filter( Boolean );
	for ( const [ pattern, name ] of TABLE ) {
		const pp = pattern.split( '/' ).filter( Boolean );
		if ( pp.length !== parts.length ) {
			continue;
		}
		const params: Record< string, string > = {};
		let ok = true;
		pp.forEach( ( seg, i ) => {
			if ( seg.startsWith( ':' ) ) {
				params[ seg.slice( 1 ) ] = decodeURIComponent( parts[ i ] );
			} else if ( seg !== parts[ i ] ) {
				ok = false;
			}
		} );
		if ( ok ) {
			return { name, path, params, query };
		}
	}
	return { name: 'notfound', path, params: {}, query };
}

export function current(): Route {
	return parse( window.location.hash );
}

export function href( to: string ): string {
	return '#' + ( to.startsWith( '/' ) ? to : '/' + to );
}

export function navigate( to: string ): void {
	const next = href( to );
	if ( window.location.hash === next ) {
		window.dispatchEvent( new HashChangeEvent( 'hashchange' ) );
		return;
	}
	window.location.hash = next;
}

/** Build a path with a query string (empty values dropped). */
export function withQuery( path: string, query: Record< string, unknown > ): string {
	const p = new URLSearchParams();
	Object.entries( query ).forEach( ( [ k, v ] ) => {
		if ( v === undefined || v === null || v === '' || v === false ) {
			return;
		}
		p.set( k, String( v ) );
	} );
	const s = p.toString();
	return s ? `${ path }?${ s }` : path;
}

/** Update the query string of the current route without adding history or re-rendering. */
export function replaceQuery( query: Record< string, unknown > ): void {
	const r = current();
	const next = href( withQuery( r.path, query ) );
	if ( window.location.hash !== next ) {
		window.history.replaceState( null, '', next );
	}
}

export function useRoute(): Route {
	const [ route, setRoute ] = useState< Route >( () => current() );
	useEffect( () => {
		const on = () => setRoute( current() );
		window.addEventListener( 'hashchange', on );
		return () => window.removeEventListener( 'hashchange', on );
	}, [] );
	return route;
}

/** Top-level section of a route (for nav highlight). */
export function sectionOf( name: RouteName ): string {
	switch ( name ) {
		case 'member':
			return 'members';
		case 'review':
			return 'payments';
		case 'consultations':
			return 'workshops';
		case 'organisations':
		case 'organisation':
			return 'enterprise';
		default:
			return name;
	}
}
