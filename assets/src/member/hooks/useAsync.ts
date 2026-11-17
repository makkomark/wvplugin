import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { errorMessage } from '../lib/api';

export interface AsyncState< T > {
	data: T | undefined;
	error: string | null;
	loading: boolean;
	reload: () => Promise< void >;
	setData: ( updater: T | ( ( prev: T | undefined ) => T ) ) => void;
}

/**
 * Load data on mount (and when deps change). `reload` refetches without clearing current data,
 * so screens can refresh in the background without flashing skeletons.
 */
export function useAsync< T >( fn: () => Promise< T >, deps: unknown[] = [] ): AsyncState< T > {
	const [ data, setDataState ] = useState< T | undefined >( undefined );
	const [ error, setError ] = useState< string | null >( null );
	const [ loading, setLoading ] = useState( true );
	const alive = useRef( true );
	const fnRef = useRef( fn );
	fnRef.current = fn;

	const run = useCallback( async () => {
		setLoading( true );
		try {
			const result = await fnRef.current();
			if ( alive.current ) {
				setDataState( result );
				setError( null );
			}
		} catch ( e ) {
			if ( alive.current ) {
				setError( errorMessage( e ) );
			}
		} finally {
			if ( alive.current ) {
				setLoading( false );
			}
		}
	}, [] );

	useEffect( () => {
		alive.current = true;
		run();
		return () => {
			alive.current = false;
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, deps );

	const setData = useCallback( ( updater: T | ( ( prev: T | undefined ) => T ) ) => {
		setDataState( ( prev ) => ( typeof updater === 'function' ? ( updater as ( p: T | undefined ) => T )( prev ) : updater ) );
	}, [] );

	return { data, error, loading, reload: run, setData };
}
