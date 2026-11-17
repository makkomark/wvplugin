/* Small structured editors: ordered list, token list, key→value map, member picker. */
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Icon } from './Icon';
import { Avatar, Btn, cx } from './ui';
import { api } from '../lib/api';
import type { MemberRow } from '../../shared/types';
import { StatusChip } from './ui';

/** Ordered list of strings (plan benefits). */
export function ListEditor( { value, onChange, placeholder = 'Add an item…', disabled }: { value: string[]; onChange: ( v: string[] ) => void; placeholder?: string; disabled?: boolean } ) {
	const [ draft, setDraft ] = useState( '' );
	const [ drag, setDrag ] = useState< number | null >( null );
	const move = ( from: number, to: number ) => {
		if ( to < 0 || to >= value.length ) {
			return;
		}
		const next = value.slice();
		const [ it ] = next.splice( from, 1 );
		next.splice( to, 0, it );
		onChange( next );
	};
	const add = () => {
		const t = draft.trim();
		if ( t ) {
			onChange( [ ...value, t ] );
			setDraft( '' );
		}
	};
	return (
		<div className={ cx( 'wvm-a-listedit', disabled && 'is-disabled' ) }>
			<ul>
				{ value.map( ( item, i ) => (
					<li
						key={ i }
						draggable={ ! disabled }
						onDragStart={ () => setDrag( i ) }
						onDragOver={ ( e ) => e.preventDefault() }
						onDrop={ () => {
							if ( drag !== null ) {
								move( drag, i );
							}
							setDrag( null );
						} }
						className={ drag === i ? 'is-dragging' : '' }
					>
						<span className="wvm-a-listedit__grip">
							<Icon name="grip" size={ 14 } />
						</span>
						<span className="wvm-a-listedit__num">{ i + 1 }</span>
						<input
							value={ item }
							disabled={ disabled }
							onChange={ ( e ) => {
								const next = value.slice();
								next[ i ] = e.target.value;
								onChange( next );
							} }
						/>
						<div className="wvm-a-listedit__btns">
							<button type="button" disabled={ disabled || i === 0 } onClick={ () => move( i, i - 1 ) } aria-label="Move up">
								<Icon name="chevronDown" size={ 13 } className="is-flipped" />
							</button>
							<button type="button" disabled={ disabled || i === value.length - 1 } onClick={ () => move( i, i + 1 ) } aria-label="Move down">
								<Icon name="chevronDown" size={ 13 } />
							</button>
							<button type="button" disabled={ disabled } onClick={ () => onChange( value.filter( ( _, j ) => j !== i ) ) } aria-label="Remove" className="is-danger">
								<Icon name="trash" size={ 13 } />
							</button>
						</div>
					</li>
				) ) }
			</ul>
			{ ! disabled && (
				<div className="wvm-a-listedit__add">
					<input
						value={ draft }
						placeholder={ placeholder }
						onChange={ ( e ) => setDraft( e.target.value ) }
						onKeyDown={ ( e ) => {
							if ( e.key === 'Enter' ) {
								e.preventDefault();
								add();
							}
						} }
					/>
					<Btn size="compact" icon="plus" onClick={ add } disabled={ ! draft.trim() }>
						Add
					</Btn>
				</div>
			) }
		</div>
	);
}

/** Token input (array of strings or numbers) — e.g. reminder days, gateway ids, tags. */
export function TokenInput( { value, onChange, numeric, placeholder = 'Type and press Enter', suggestions, label, help }: { value: ( string | number )[]; onChange: ( v: ( string | number )[] ) => void; numeric?: boolean; placeholder?: string; suggestions?: { value: string; label: string }[]; label?: ReactNode; help?: ReactNode } ) {
	const [ draft, setDraft ] = useState( '' );
	const listId = suggestions ? 'wvm-tok-' + Math.random().toString( 36 ).slice( 2, 8 ) : undefined;
	const add = ( raw: string ) => {
		const parts = raw.split( /[,\s]+/ ).map( ( s ) => s.trim() ).filter( Boolean );
		if ( ! parts.length ) {
			return;
		}
		const items = parts.map( ( s ) => ( numeric ? Number( s ) : s ) ).filter( ( v ) => ! numeric || ( Number.isFinite( v as number ) && ( v as number ) >= 0 ) );
		const next = [ ...value ];
		items.forEach( ( it ) => {
			if ( ! next.includes( it ) ) {
				next.push( it );
			}
		} );
		onChange( numeric ? ( next as number[] ).sort( ( a, b ) => b - a ) : next );
		setDraft( '' );
	};
	const labelFor = ( v: string | number ) => suggestions?.find( ( s ) => s.value === String( v ) )?.label;
	return (
		<div className="wvm-a-field wvm-a-tokens-field">
			{ label && <span className="wvm-a-label">{ label }</span> }
			<div className="wvm-a-tokens">
				{ value.map( ( v ) => (
					<span key={ String( v ) } className="wvm-a-token" title={ labelFor( v ) }>
						{ String( v ) }
						{ labelFor( v ) && labelFor( v ) !== String( v ) && <em>{ labelFor( v ) }</em> }
						<button type="button" aria-label={ `Remove ${ v }` } onClick={ () => onChange( value.filter( ( x ) => x !== v ) ) }>
							<Icon name="x" size={ 11 } strokeWidth={ 2.4 } />
						</button>
					</span>
				) ) }
				<input
					value={ draft }
					list={ listId }
					inputMode={ numeric ? 'numeric' : undefined }
					placeholder={ value.length ? '' : placeholder }
					onChange={ ( e ) => setDraft( e.target.value ) }
					onKeyDown={ ( e ) => {
						if ( e.key === 'Enter' || e.key === ',' ) {
							e.preventDefault();
							add( draft );
						} else if ( e.key === 'Backspace' && ! draft && value.length ) {
							onChange( value.slice( 0, -1 ) );
						}
					} }
					onBlur={ () => draft && add( draft ) }
				/>
				{ suggestions && (
					<datalist id={ listId }>
						{ suggestions.map( ( s ) => (
							<option key={ s.value } value={ s.value }>
								{ s.label }
							</option>
						) ) }
					</datalist>
				) }
			</div>
			{ help && <p className="wvm-a-help">{ help }</p> }
		</div>
	);
}

/**
 * Map editor: rows of key → value. Value is rendered by `renderValue` (token list, select, …).
 */
export function MapEditor< V >( { value, onChange, keyLabel = 'Key', valueLabel = 'Value', renderValue, newValue, keyPlaceholder = 'e.g. UG', keyHint }: { value: Record< string, V >; onChange: ( v: Record< string, V > ) => void; keyLabel?: string; valueLabel?: string; renderValue: ( v: V, set: ( v: V ) => void, key: string ) => ReactNode; newValue: () => V; keyPlaceholder?: string; keyHint?: ReactNode } ) {
	const [ newKey, setNewKey ] = useState( '' );
	const entries = Object.entries( value || {} );
	return (
		<div className="wvm-a-mapedit">
			<div className="wvm-a-mapedit__head">
				<span>{ keyLabel }</span>
				<span>{ valueLabel }</span>
				<span />
			</div>
			{ entries.map( ( [ k, v ] ) => (
				<div key={ k } className="wvm-a-mapedit__row">
					<span className="wvm-a-mapedit__key">
						<code>{ k }</code>
					</span>
					<div className="wvm-a-mapedit__val">{ renderValue( v, ( nv ) => onChange( { ...value, [ k ]: nv } ), k ) }</div>
					<button
						type="button"
						className="wvm-a-iconbtn is-danger"
						aria-label={ `Remove ${ k }` }
						onClick={ () => {
							const next = { ...value };
							delete next[ k ];
							onChange( next );
						} }
					>
						<Icon name="trash" size={ 14 } />
					</button>
				</div>
			) ) }
			<div className="wvm-a-mapedit__add">
				<input
					value={ newKey }
					placeholder={ keyPlaceholder }
					onChange={ ( e ) => setNewKey( e.target.value ) }
					onKeyDown={ ( e ) => {
						if ( e.key === 'Enter' ) {
							e.preventDefault();
						}
					} }
				/>
				<Btn
					size="compact"
					icon="plus"
					disabled={ ! newKey.trim() || Object.prototype.hasOwnProperty.call( value || {}, newKey.trim() ) }
					onClick={ () => {
						onChange( { ...value, [ newKey.trim() ]: newValue() } );
						setNewKey( '' );
					} }
				>
					Add row
				</Btn>
				{ keyHint && <span className="wvm-a-help">{ keyHint }</span> }
			</div>
		</div>
	);
}

/** Search members by name/email/ID and pick one. */
export function MemberPicker( { value, onChange, label = 'Member' }: { value: MemberRow | null; onChange: ( m: MemberRow | null ) => void; label?: string } ) {
	const [ q, setQ ] = useState( '' );
	const [ results, setResults ] = useState< MemberRow[] >( [] );
	const [ loading, setLoading ] = useState( false );
	useEffect( () => {
		if ( value || q.trim().length < 2 ) {
			setResults( [] );
			return;
		}
		let alive = true;
		setLoading( true );
		const t = setTimeout( () => {
			api.get< MemberRow[] >( 'members', { q: q.trim(), per_page: 8 } )
				.then( ( r ) => alive && setResults( r.data || [] ) )
				.catch( () => alive && setResults( [] ) )
				.finally( () => alive && setLoading( false ) );
		}, 250 );
		return () => {
			alive = false;
			clearTimeout( t );
		};
	}, [ q, value ] );
	return (
		<div className="wvm-a-field wvm-a-picker">
			<span className="wvm-a-label">{ label }</span>
			{ value ? (
				<div className="wvm-a-picker__chosen">
					<Avatar name={ value.name } size={ 32 } />
					<div>
						<strong>{ value.name }</strong>
						<span>
							{ value.public_id } · { value.email }
						</span>
					</div>
					<StatusChip status={ value.status } />
					<button type="button" className="wvm-a-iconbtn" aria-label="Change member" onClick={ () => onChange( null ) }>
						<Icon name="x" size={ 14 } />
					</button>
				</div>
			) : (
				<div className="wvm-a-picker__search">
					<Icon name="search" size={ 15 } />
					<input autoFocus value={ q } onChange={ ( e ) => setQ( e.target.value ) } placeholder="Search by name, email, phone or WV-ID…" />
					{ ( results.length > 0 || loading ) && (
						<ul className="wvm-a-picker__results">
							{ loading && results.length === 0 && <li className="is-muted">Searching…</li> }
							{ results.map( ( m ) => (
								<li key={ m.id }>
									<button type="button" onClick={ () => onChange( m ) }>
										<Avatar name={ m.name } size={ 28 } />
										<span>
											<strong>{ m.name }</strong>
											<em>
												{ m.public_id } · { m.email }
											</em>
										</span>
										<StatusChip status={ m.status } />
									</button>
								</li>
							) ) }
						</ul>
					) }
				</div>
			) }
		</div>
	);
}
