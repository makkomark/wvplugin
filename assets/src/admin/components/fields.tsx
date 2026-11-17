/*
 * Thin wrappers over @wordpress/components form controls: consistent sizing flags,
 * inline field errors, and a few controls WP doesn't ship (MoneyInput, CountrySelect, LevelSelect).
 */
import { useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { TextControl, SelectControl, TextareaControl, ToggleControl, CheckboxControl, BaseControl } from '@wordpress/components';
import { config, currencyDecimals, decodeEntities, levelEntries } from '../lib/config';
import { majorStringToMinor, minorToMajorString } from '../lib/api';

type Opt = { label: string; value: string; disabled?: boolean };

function errClass( error?: string, extra?: string ) {
	return [ 'wvm-a-field', error ? 'has-error' : '', extra || '' ].filter( Boolean ).join( ' ' );
}

export function TextField( props: {
	label?: ReactNode;
	value: string | number | null | undefined;
	onChange: ( v: string ) => void;
	type?: string;
	placeholder?: string;
	help?: ReactNode;
	error?: string;
	disabled?: boolean;
	className?: string;
	min?: number;
	max?: number;
	autoFocus?: boolean;
	required?: boolean;
} ) {
	const { label, value, onChange, type = 'text', placeholder, help, error, disabled, className, min, max, autoFocus, required } = props;
	return (
		<div className={ errClass( error, className ) }>
			<TextControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label as string }
				value={ value === null || value === undefined ? '' : String( value ) }
				onChange={ ( v: string ) => onChange( v ) }
				type={ type as 'text' }
				placeholder={ placeholder }
				help={ error || help }
				disabled={ disabled }
				min={ min }
				max={ max }
				autoFocus={ autoFocus }
				required={ required }
			/>
		</div>
	);
}

export function SelectField( props: { label?: ReactNode; value: string | number | null | undefined; onChange: ( v: string ) => void; options: Opt[]; help?: ReactNode; error?: string; disabled?: boolean; className?: string } ) {
	const { label, value, onChange, options, help, error, disabled, className } = props;
	return (
		<div className={ errClass( error, className ) }>
			<SelectControl
				__next40pxDefaultSize
				__nextHasNoMarginBottom
				label={ label as string }
				value={ value === null || value === undefined ? '' : String( value ) }
				onChange={ ( v: string ) => onChange( v ) }
				options={ options }
				help={ error || help }
				disabled={ disabled }
			/>
		</div>
	);
}

export function TextareaField( props: { label?: ReactNode; value: string | null | undefined; onChange: ( v: string ) => void; rows?: number; placeholder?: string; help?: ReactNode; error?: string; disabled?: boolean; className?: string } ) {
	const { label, value, onChange, rows = 4, placeholder, help, error, disabled, className } = props;
	return (
		<div className={ errClass( error, className ) }>
			<TextareaControl __nextHasNoMarginBottom label={ label as string } value={ value || '' } onChange={ onChange } rows={ rows } placeholder={ placeholder } help={ error || help } disabled={ disabled } />
		</div>
	);
}

export function Toggle( props: { label: ReactNode; checked: boolean; onChange: ( v: boolean ) => void; help?: ReactNode; disabled?: boolean } ) {
	return (
		<div className="wvm-a-field wvm-a-toggle">
			<ToggleControl __nextHasNoMarginBottom label={ props.label as string } checked={ !! props.checked } onChange={ props.onChange } help={ props.help as string } disabled={ props.disabled } />
		</div>
	);
}

export function Checkbox( props: { label: ReactNode; checked: boolean; onChange: ( v: boolean ) => void; help?: ReactNode; disabled?: boolean } ) {
	return (
		<div className="wvm-a-field wvm-a-check">
			<CheckboxControl __nextHasNoMarginBottom label={ props.label as string } checked={ !! props.checked } onChange={ props.onChange } help={ props.help as string } disabled={ props.disabled } />
		</div>
	);
}

/** Major-unit input that reads/writes integer minor units using config.decimals. */
export function MoneyInput( props: {
	label?: ReactNode;
	currency: string;
	value: number | null | undefined;
	onChange: ( minor: number | null ) => void;
	currencies?: string[];
	onCurrencyChange?: ( c: string ) => void;
	help?: ReactNode;
	error?: string;
	disabled?: boolean;
	placeholder?: string;
	id?: string;
} ) {
	const { label, currency, value, onChange, currencies, onCurrencyChange, help, error, disabled, placeholder } = props;
	const [ text, setText ] = useState( () => minorToMajorString( value ?? null, currency ) );
	const d = currencyDecimals( currency );
	useEffect( () => {
		// Re-sync when the value or currency changes from outside.
		if ( majorStringToMinor( text, currency ) !== ( value ?? null ) ) {
			setText( minorToMajorString( value ?? null, currency ) );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ value, currency ] );
	const id = useMemo( () => props.id || 'wvm-money-' + Math.random().toString( 36 ).slice( 2, 8 ), [ props.id ] );
	return (
		<div className={ errClass( error, 'wvm-a-money' ) }>
			<BaseControl __nextHasNoMarginBottom id={ id } label={ label as string } help={ error || help }>
				<div className={ 'wvm-a-money__box' + ( disabled ? ' is-disabled' : '' ) }>
					{ currencies && onCurrencyChange ? (
						<select className="wvm-a-money__cur" value={ currency } onChange={ ( e ) => onCurrencyChange( e.target.value ) } disabled={ disabled } aria-label="Currency">
							{ currencies.map( ( c ) => (
								<option key={ c } value={ c }>
									{ c }
								</option>
							) ) }
						</select>
					) : (
						<span className="wvm-a-money__cur is-static">{ currency }</span>
					) }
					<input
						id={ id }
						className="wvm-a-money__input"
						inputMode="decimal"
						value={ text }
						disabled={ disabled }
						placeholder={ placeholder ?? ( d ? '0.' + '0'.repeat( d ) : '0' ) }
						onChange={ ( e ) => {
							setText( e.target.value );
							onChange( majorStringToMinor( e.target.value, currency ) );
						} }
						onBlur={ () => {
							const m = majorStringToMinor( text, currency );
							setText( m === null ? '' : minorToMajorString( m, currency ) );
						} }
					/>
				</div>
			</BaseControl>
		</div>
	);
}

let countryOpts: Opt[] | null = null;
export function countryOptions(): Opt[] {
	if ( ! countryOpts ) {
		countryOpts = Object.entries( config.countries )
			.map( ( [ code, name ] ) => ( { value: code, label: decodeEntities( name ) } ) )
			.sort( ( a, b ) => a.label.localeCompare( b.label ) );
	}
	return countryOpts;
}

export function CountrySelect( props: { label?: ReactNode; value: string | null | undefined; onChange: ( v: string ) => void; anyLabel?: string; error?: string; help?: ReactNode; className?: string } ) {
	const opts = countryOptions();
	return <SelectField className={ props.className } label={ props.label ?? 'Country' } value={ props.value || '' } onChange={ props.onChange } options={ [ { value: '', label: props.anyLabel ?? '— Select country —' }, ...opts ] } error={ props.error } help={ props.help } />;
}

export function LevelSelect( props: { label?: ReactNode; value: number | string | null | undefined; onChange: ( v: number ) => void; error?: string; help?: ReactNode; disabled?: boolean; allowAny?: string; className?: string } ) {
	const opts = levelEntries().map( ( [ n, l ] ) => ( { value: String( n ), label: `${ n } · ${ l }` } ) );
	return (
		<SelectField
			className={ props.className }
			label={ props.label ?? 'Access level' }
			value={ props.value === null || props.value === undefined ? '' : String( props.value ) }
			onChange={ ( v ) => props.onChange( v === '' ? -1 : Number( v ) ) }
			options={ props.allowAny !== undefined ? [ { value: '', label: props.allowAny }, ...opts ] : opts }
			error={ props.error }
			help={ props.help }
			disabled={ props.disabled }
		/>
	);
}

export function currencyOptions( list = config.currencies ): Opt[] {
	return list.map( ( c ) => ( { value: c, label: c } ) );
}

/** Wrap fields in a responsive grid. */
export function FieldGrid( { children, cols = 2 }: { children: ReactNode; cols?: 1 | 2 | 3 } ) {
	return <div className={ `wvm-a-fieldgrid cols-${ cols }` }>{ children }</div>;
}
