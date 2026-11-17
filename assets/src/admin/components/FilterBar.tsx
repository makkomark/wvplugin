/* Filter toolbar: search box + compact pill selects/dates + clear. */
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { Icon } from './Icon';
import { cx } from './ui';

export function FilterBar( { children, onClear, active, right }: { children: ReactNode; onClear?: () => void; active?: boolean; right?: ReactNode } ) {
	return (
		<div className="wvm-a-filterbar">
			<div className="wvm-a-filterbar__main">
				{ children }
				{ active && onClear && (
					<button type="button" className="wvm-a-filterbar__clear" onClick={ onClear }>
						<Icon name="x" size={ 13 } /> Clear
					</button>
				) }
			</div>
			{ right && <div className="wvm-a-filterbar__right">{ right }</div> }
		</div>
	);
}

/** Search input that reports changes after a short debounce. */
export function SearchInput( { value, onChange, placeholder = 'Search…', autoFocus, delay = 300, className }: { value: string; onChange: ( v: string ) => void; placeholder?: string; autoFocus?: boolean; delay?: number; className?: string } ) {
	const [ text, setText ] = useState( value );
	useEffect( () => setText( value ), [ value ] );
	useEffect( () => {
		if ( text === value ) {
			return;
		}
		const t = setTimeout( () => onChange( text ), delay );
		return () => clearTimeout( t );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ text ] );
	return (
		<label className={ cx( 'wvm-a-search', className ) }>
			<Icon name="search" size={ 16 } />
			<input type="search" value={ text } placeholder={ placeholder } autoFocus={ autoFocus } onChange={ ( e ) => setText( e.target.value ) } onKeyDown={ ( e ) => e.key === 'Enter' && onChange( text ) } />
			{ text && (
				<button
					type="button"
					aria-label="Clear search"
					onClick={ () => {
						setText( '' );
						onChange( '' );
					} }
				>
					<Icon name="x" size={ 13 } />
				</button>
			) }
		</label>
	);
}

export function FilterSelect( { label, value, onChange, options, className }: { label: string; value: string; onChange: ( v: string ) => void; options: { label: string; value: string }[]; className?: string } ) {
	const current = options.find( ( o ) => o.value === value );
	return (
		<label className={ cx( 'wvm-a-fsel', value && 'is-active', className ) }>
			<span className="wvm-a-fsel__label">{ label }</span>
			<span className="wvm-a-fsel__value">{ value ? current?.label || value : 'Any' }</span>
			<Icon name="chevronDown" size={ 13 } />
			<select value={ value } onChange={ ( e ) => onChange( e.target.value ) } aria-label={ label }>
				<option value="">Any</option>
				{ options.map( ( o ) => (
					<option key={ o.value } value={ o.value }>
						{ o.label }
					</option>
				) ) }
			</select>
		</label>
	);
}

export function FilterDate( { label, value, onChange }: { label: string; value: string; onChange: ( v: string ) => void } ) {
	return (
		<label className={ cx( 'wvm-a-fsel is-date', value && 'is-active' ) }>
			<span className="wvm-a-fsel__label">{ label }</span>
			<input type="date" value={ value } onChange={ ( e ) => onChange( e.target.value ) } aria-label={ label } />
		</label>
	);
}
