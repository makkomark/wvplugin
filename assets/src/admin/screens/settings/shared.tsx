/* Settings group loader/saver + sticky save bar. Only changed keys are PATCHed (masked secrets stay untouched). */
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { useApi } from '../../hooks/useApi';
import { api } from '../../lib/api';
import { Btn, Loading, ErrorState } from '../../components/ui';
import { useToast } from '../../components/Toasts';

export type SettingsValues = Record< string, unknown >;

export function useSettingsGroup< T extends SettingsValues >( group: string ) {
	const toast = useToast();
	const res = useApi< T >( `settings/${ group }` );
	const [ values, setValues ] = useState< T | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< unknown >( null );
	useEffect( () => {
		if ( res.data ) {
			setValues( res.data );
		}
	}, [ res.data ] );
	const changed = (): Partial< T > => {
		if ( ! values || ! res.data ) {
			return {};
		}
		const out: Partial< T > = {};
		( Object.keys( values ) as ( keyof T )[] ).forEach( ( k ) => {
			if ( JSON.stringify( values[ k ] ) !== JSON.stringify( res.data![ k ] ) ) {
				out[ k ] = values[ k ];
			}
		} );
		return out;
	};
	const dirty = Object.keys( changed() ).length > 0;
	const save = async ( extra?: Partial< T > ) => {
		const patch = { ...changed(), ...( extra || {} ) };
		if ( ! Object.keys( patch ).length ) {
			return;
		}
		setBusy( true );
		setError( null );
		try {
			const r = await api.patch< T >( `settings/${ group }`, patch );
			res.setData( () => r.data );
			setValues( r.data );
			toast.success( 'Settings saved' );
		} catch ( e ) {
			setError( e );
			toast.error( e );
		} finally {
			setBusy( false );
		}
	};
	const set = ( patch: Partial< T > ) => setValues( ( v ) => ( v ? { ...v, ...patch } : v ) );
	const reset = () => res.data && setValues( res.data );
	return { res, values, set, save, dirty, busy, error, reset };
}

export interface FrameState {
	res: { error: { message: string; status?: number } | null; reload: () => void };
	values: unknown;
	dirty: boolean;
	busy: boolean;
	save: () => unknown;
	reset: () => unknown;
}

export function SettingsFrame( { title, description, children, state, actions }: { title: string; description?: ReactNode; children: ReactNode; state?: FrameState; actions?: ReactNode } ) {
	return (
		<div className="wvm-a-settings__panel">
			<div className="wvm-a-settings__head">
				<div>
					<h2>{ title }</h2>
					{ description && <p>{ description }</p> }
				</div>
				{ actions && <div className="wvm-a-settings__headactions">{ actions }</div> }
			</div>
			{ state && state.res.error && ! state.values ? <ErrorState error={ state.res.error } onRetry={ state.res.reload } /> : state && ! state.values ? <Loading /> : children }
			{ state && !! state.values && (
				<div className={ 'wvm-a-savebar' + ( state.dirty ? ' is-dirty' : '' ) }>
					<span>{ state.dirty ? 'You have unsaved changes' : 'All changes saved' }</span>
					{ state.dirty && (
						<Btn variant="ghost" onClick={ state.reset }>
							Discard
						</Btn>
					) }
					<Btn variant="primary" busy={ state.busy } disabled={ ! state.dirty } onClick={ () => state.save() }>
						Save changes
					</Btn>
				</div>
			) }
		</div>
	);
}

export function Section( { title, description, children }: { title: string; description?: ReactNode; children: ReactNode } ) {
	return (
		<section className="wvm-a-setsec">
			<div className="wvm-a-setsec__intro">
				<h3>{ title }</h3>
				{ description && <p>{ description }</p> }
			</div>
			<div className="wvm-a-setsec__fields">{ children }</div>
		</section>
	);
}
