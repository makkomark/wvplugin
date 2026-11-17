/* "Saved views": named filter sets kept in localStorage per WP user. */
import { useCallback, useState } from 'react';
import { config } from '../lib/config';

export interface SavedView< F > { name: string; filters: F; created: string }

export function useSavedViews< F >( scope: string ) {
	const key = `wvm_admin_views_${ scope }_${ config.user.id }`;
	const read = (): SavedView< F >[] => {
		try {
			return JSON.parse( window.localStorage.getItem( key ) || '[]' ) as SavedView< F >[];
		} catch ( e ) {
			return [];
		}
	};
	const [ views, setViews ] = useState< SavedView< F >[] >( read );
	const write = useCallback(
		( next: SavedView< F >[] ) => {
			setViews( next );
			try {
				window.localStorage.setItem( key, JSON.stringify( next ) );
			} catch ( e ) {
				// Storage blocked — keep in memory for this session.
			}
		},
		[ key ]
	);
	const save = ( name: string, filters: F ) => write( [ ...views.filter( ( v ) => v.name !== name ), { name, filters, created: new Date().toISOString() } ] );
	const remove = ( name: string ) => write( views.filter( ( v ) => v.name !== name ) );
	return { views, save, remove };
}
