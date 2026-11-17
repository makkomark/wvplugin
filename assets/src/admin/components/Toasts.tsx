import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { Icon } from './Icon';
import { errorMessage } from '../lib/api';

type Tone = 'success' | 'error' | 'info';
interface Toast { id: number; tone: Tone; message: ReactNode; action?: { label: string; onClick: () => void } }

interface ToastApi {
	success: ( m: ReactNode, action?: Toast[ 'action' ] ) => void;
	error: ( e: unknown ) => void;
	info: ( m: ReactNode, action?: Toast[ 'action' ] ) => void;
}

const Ctx = createContext< ToastApi >( { success: () => {}, error: () => {}, info: () => {} } );

export function ToastProvider( { children }: { children: ReactNode } ) {
	const [ items, setItems ] = useState< Toast[] >( [] );
	const seq = useRef( 0 );
	const remove = useCallback( ( id: number ) => setItems( ( l ) => l.filter( ( t ) => t.id !== id ) ), [] );
	const push = useCallback(
		( tone: Tone, message: ReactNode, action?: Toast[ 'action' ] ) => {
			const id = ++seq.current;
			setItems( ( l ) => [ ...l.slice( -3 ), { id, tone, message, action } ] );
			setTimeout( () => remove( id ), tone === 'error' ? 7000 : 4500 );
		},
		[ remove ]
	);
	const value = useMemo< ToastApi >(
		() => ( {
			success: ( m, a ) => push( 'success', m, a ),
			info: ( m, a ) => push( 'info', m, a ),
			error: ( e ) => push( 'error', typeof e === 'string' ? e : errorMessage( e ) ),
		} ),
		[ push ]
	);
	return (
		<Ctx.Provider value={ value }>
			{ children }
			{ createPortal(
				<div className="wvm-a-toasts" role="status" aria-live="polite">
					{ items.map( ( t ) => (
						<div key={ t.id } className={ `wvm-a-toast is-${ t.tone }` }>
							<span className="wvm-a-toast__icon">
								<Icon name={ t.tone === 'success' ? 'check' : t.tone === 'error' ? 'alert' : 'info' } size={ 16 } strokeWidth={ 2.2 } />
							</span>
							<div className="wvm-a-toast__msg">{ t.message }</div>
							{ t.action && (
								<button
									type="button"
									className="wvm-a-toast__action"
									onClick={ () => {
										t.action?.onClick();
										remove( t.id );
									} }
								>
									{ t.action.label }
								</button>
							) }
							<button type="button" className="wvm-a-toast__close" aria-label="Dismiss" onClick={ () => remove( t.id ) }>
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

export function useToast(): ToastApi {
	return useContext( Ctx );
}
