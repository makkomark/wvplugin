import { useEffect, useRef, useState } from '@wordpress/element';

export function useMediaQuery( query: string ): boolean {
	const get = () => ( typeof window.matchMedia === 'function' ? window.matchMedia( query ).matches : false );
	const [ matches, setMatches ] = useState< boolean >( get );
	useEffect( () => {
		if ( typeof window.matchMedia !== 'function' ) {
			return;
		}
		const mql = window.matchMedia( query );
		const onChange = () => setMatches( mql.matches );
		onChange();
		mql.addEventListener( 'change', onChange );
		return () => mql.removeEventListener( 'change', onChange );
	}, [ query ] );
	return matches;
}

/** Current time, re-rendering every `intervalMs`. */
export function useNow( intervalMs = 1000 ): number {
	const [ now, setNow ] = useState( () => Date.now() );
	useEffect( () => {
		const t = window.setInterval( () => setNow( Date.now() ), intervalMs );
		return () => window.clearInterval( t );
	}, [ intervalMs ] );
	return now;
}

/** Close a popover when clicking outside or pressing Escape. */
export function useDismiss< T extends HTMLElement >( open: boolean, onClose: () => void ) {
	const ref = useRef< T >( null );
	useEffect( () => {
		if ( ! open ) {
			return;
		}
		const onDown = ( e: MouseEvent | TouchEvent ) => {
			if ( ref.current && ! ref.current.contains( e.target as Node ) ) {
				onClose();
			}
		};
		const onKey = ( e: KeyboardEvent ) => {
			if ( e.key === 'Escape' ) {
				onClose();
			}
		};
		document.addEventListener( 'mousedown', onDown );
		document.addEventListener( 'touchstart', onDown );
		document.addEventListener( 'keydown', onKey );
		return () => {
			document.removeEventListener( 'mousedown', onDown );
			document.removeEventListener( 'touchstart', onDown );
			document.removeEventListener( 'keydown', onKey );
		};
	}, [ open, onClose ] );
	return ref;
}
