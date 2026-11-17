import { useCallback, useEffect, useState } from '@wordpress/element';

function read(): string {
	const h = window.location.hash.replace( /^#\/?/, '' ).replace( /\/+$/, '' );
	return h.split( '?' )[ 0 ];
}

/** Hash router: "#/billing" → "billing", "#/" → "". */
export function useHashRoute(): [ string, ( route: string ) => void ] {
	const [ route, setRoute ] = useState< string >( read );

	useEffect( () => {
		const onChange = () => setRoute( read() );
		window.addEventListener( 'hashchange', onChange );
		return () => window.removeEventListener( 'hashchange', onChange );
	}, [] );

	const navigate = useCallback( ( next: string ) => {
		const target = '#/' + next.replace( /^#?\/?/, '' );
		if ( window.location.hash !== target ) {
			window.location.hash = target;
		} else {
			setRoute( read() );
		}
	}, [] );

	return [ route, navigate ];
}
