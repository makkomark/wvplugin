import { useCallback, useEffect, useRef, useState } from 'react';
import { api, ApiError } from '../lib/api';
import type { Query } from '../lib/api';
import type { PageMeta } from '../../shared/types';

export type Meta = PageMeta & Record< string, unknown >;

export interface ApiState< T > {
	data: T | undefined;
	meta: Meta | undefined;
	loading: boolean;
	error: ApiError | null;
	reload: () => void;
	setData: ( fn: ( prev: T | undefined ) => T | undefined ) => void;
}

/**
 * GET a REST path; re-fetches when path/query change. Keeps the previous data while
 * reloading so tables don't flash. Pass null to skip.
 */
export function useApi< T >( path: string | null, query?: Query ): ApiState< T > {
	const key = path ? path + '|' + JSON.stringify( query || {} ) : '';
	const [ data, setDataState ] = useState< T | undefined >( undefined );
	const [ meta, setMeta ] = useState< Meta | undefined >( undefined );
	const [ loading, setLoading ] = useState< boolean >( !! path );
	const [ error, setError ] = useState< ApiError | null >( null );
	const [ tick, setTick ] = useState( 0 );
	const queryRef = useRef( query );
	queryRef.current = query;

	useEffect( () => {
		if ( ! path ) {
			setLoading( false );
			return;
		}
		let alive = true;
		setLoading( true );
		setError( null );
		api.get< T >( path, queryRef.current )
			.then( ( res ) => {
				if ( alive ) {
					setDataState( res.data );
					setMeta( res.meta as Meta | undefined );
					setLoading( false );
				}
			} )
			.catch( ( e: unknown ) => {
				if ( alive ) {
					setError( e instanceof ApiError ? e : new ApiError( { message: String( e ) }, 0 ) );
					setLoading( false );
				}
			} );
		return () => {
			alive = false;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ key, tick ] );

	const reload = useCallback( () => setTick( ( t ) => t + 1 ), [] );
	const setData = useCallback( ( fn: ( prev: T | undefined ) => T | undefined ) => setDataState( fn ), [] );

	return { data, meta, loading, error, reload, setData };
}

/** Debounce a fast-changing value (search boxes). */
export function useDebounced< T >( value: T, ms = 300 ): T {
	const [ v, setV ] = useState( value );
	useEffect( () => {
		const t = setTimeout( () => setV( value ), ms );
		return () => clearTimeout( t );
	}, [ value, ms ] );
	return v;
}
