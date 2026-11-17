/* Toast notifications (portal, auto-dismiss). */
import { createContext, createPortal, useCallback, useContext, useMemo, useRef, useState } from '@wordpress/element';
import type { ReactNode } from 'react';
import { Icon } from './Icon';
import { cx } from '../lib/util';

type ToastTone = 'success' | 'danger' | 'info';
interface ToastItem { id: number; tone: ToastTone; message: string }
interface ToastApi {
	success: ( m: string ) => void;
	error: ( m: string ) => void;
	info: ( m: string ) => void;
}

const Ctx = createContext< ToastApi >( { success: () => {}, error: () => {}, info: () => {} } );

export function useToast(): ToastApi {
	return useContext( Ctx );
}

export function ToastProvider( { children }: { children: ReactNode } ) {
	const [ items, setItems ] = useState< ToastItem[] >( [] );
	const seq = useRef( 0 );

	const remove = useCallback( ( id: number ) => setItems( ( list ) => list.filter( ( t ) => t.id !== id ) ), [] );
	const push = useCallback(
		( tone: ToastTone, message: string ) => {
			const id = ++seq.current;
			setItems( ( list ) => [ ...list.slice( -2 ), { id, tone, message } ] );
			window.setTimeout( () => remove( id ), tone === 'danger' ? 6000 : 4000 );
		},
		[ remove ]
	);
	const api = useMemo< ToastApi >( () => ( { success: ( m ) => push( 'success', m ), error: ( m ) => push( 'danger', m ), info: ( m ) => push( 'info', m ) } ), [ push ] );

	return (
		<Ctx.Provider value={ api }>
			{ children }
			{ createPortal(
				<div className="wvm-root wvm-theme wvm-theme--auto wvm-toasts" aria-live="polite" aria-atomic="false">
					{ items.map( ( t ) => (
						<div key={ t.id } className={ cx( 'wvm-toast', `wvm-toast--${ t.tone }` ) } role={ t.tone === 'danger' ? 'alert' : 'status' }>
							<span className="wvm-toast__icon" aria-hidden="true">
								<Icon name={ t.tone === 'success' ? 'check' : t.tone === 'danger' ? 'alert' : 'info' } size={ 16 } strokeWidth={ 2.6 } />
							</span>
							<span className="wvm-toast__msg">{ t.message }</span>
							<button type="button" className="wvm-toast__close" onClick={ () => remove( t.id ) } aria-label="Dismiss">
								<Icon name="x" size={ 14 } />
							</button>
						</div>
					) ) }
				</div>,
				document.body
			) }
		</Ctx.Provider>
	);
}
