/* Form controls with labels, hints and inline errors. */
import { useId, useState } from '@wordpress/element';
import type { ReactNode, InputHTMLAttributes, SelectHTMLAttributes, TextareaHTMLAttributes } from 'react';
import { cx } from '../lib/util';
import { Icon } from './Icon';

interface FieldShellProps {
	id: string;
	label?: ReactNode;
	hint?: ReactNode;
	error?: string;
	optional?: boolean;
	children: ReactNode;
	className?: string;
}

export function FieldShell( { id, label, hint, error, optional, children, className }: FieldShellProps ) {
	return (
		<div className={ cx( 'wvm-field', error && 'has-error', className ) }>
			{ label && (
				<label className="wvm-field__label" htmlFor={ id }>
					{ label }
					{ optional && <span className="wvm-field__optional">Optional</span> }
				</label>
			) }
			{ children }
			{ error ? (
				<p className="wvm-field__error" id={ `${ id }-err` } role="alert">
					<Icon name="alert" size={ 14 } /> { error }
				</p>
			) : hint ? (
				<p className="wvm-field__hint" id={ `${ id }-hint` }>{ hint }</p>
			) : null }
		</div>
	);
}

type InputProps = Omit< InputHTMLAttributes< HTMLInputElement >, 'onChange' > & {
	label?: ReactNode;
	hint?: ReactNode;
	error?: string;
	optional?: boolean;
	onValue?: ( v: string ) => void;
	onChange?: InputHTMLAttributes< HTMLInputElement >[ 'onChange' ];
	prefix?: ReactNode;
	fieldClassName?: string;
};

export function Input( { label, hint, error, optional, onValue, onChange, prefix, fieldClassName, id, type, ...rest }: InputProps ) {
	const auto = useId();
	const fid = id || auto;
	const [ reveal, setReveal ] = useState( false );
	const isPassword = type === 'password';
	return (
		<FieldShell id={ fid } label={ label } hint={ hint } error={ error } optional={ optional } className={ fieldClassName }>
			<div className={ cx( 'wvm-input', prefix && 'has-prefix', isPassword && 'has-suffix' ) }>
				{ prefix && <span className="wvm-input__prefix">{ prefix }</span> }
				<input
					{ ...rest }
					id={ fid }
					type={ isPassword && reveal ? 'text' : type || 'text' }
					aria-invalid={ error ? true : undefined }
					aria-describedby={ error ? `${ fid }-err` : hint ? `${ fid }-hint` : undefined }
					onChange={ ( e ) => {
						onChange?.( e );
						onValue?.( e.target.value );
					} }
				/>
				{ isPassword && (
					<button type="button" className="wvm-input__suffix" onClick={ () => setReveal( ( r ) => ! r ) } aria-label={ reveal ? 'Hide password' : 'Show password' }>
						<Icon name={ reveal ? 'eye-off' : 'eye' } size={ 18 } />
					</button>
				) }
			</div>
		</FieldShell>
	);
}

type SelectProps = Omit< SelectHTMLAttributes< HTMLSelectElement >, 'onChange' > & {
	label?: ReactNode;
	hint?: ReactNode;
	error?: string;
	optional?: boolean;
	options: { value: string; label: string }[];
	placeholder?: string;
	onValue?: ( v: string ) => void;
	fieldClassName?: string;
};

export function Select( { label, hint, error, optional, options, placeholder, onValue, id, fieldClassName, ...rest }: SelectProps ) {
	const auto = useId();
	const fid = id || auto;
	return (
		<FieldShell id={ fid } label={ label } hint={ hint } error={ error } optional={ optional } className={ fieldClassName }>
			<div className="wvm-select">
				<select
					{ ...rest }
					id={ fid }
					aria-invalid={ error ? true : undefined }
					aria-describedby={ error ? `${ fid }-err` : hint ? `${ fid }-hint` : undefined }
					onChange={ ( e ) => onValue?.( e.target.value ) }
				>
					{ placeholder !== undefined && <option value="">{ placeholder }</option> }
					{ options.map( ( o ) => (
						<option key={ o.value } value={ o.value }>{ o.label }</option>
					) ) }
				</select>
				<Icon name="chevron-down" size={ 18 } className="wvm-select__chev" />
			</div>
		</FieldShell>
	);
}

type TextareaProps = Omit< TextareaHTMLAttributes< HTMLTextAreaElement >, 'onChange' > & {
	label?: ReactNode;
	hint?: ReactNode;
	error?: string;
	optional?: boolean;
	onValue?: ( v: string ) => void;
	fieldClassName?: string;
};

export function Textarea( { label, hint, error, optional, onValue, id, fieldClassName, ...rest }: TextareaProps ) {
	const auto = useId();
	const fid = id || auto;
	return (
		<FieldShell id={ fid } label={ label } hint={ hint } error={ error } optional={ optional } className={ fieldClassName }>
			<textarea
				{ ...rest }
				id={ fid }
				className="wvm-textarea"
				aria-invalid={ error ? true : undefined }
				aria-describedby={ error ? `${ fid }-err` : hint ? `${ fid }-hint` : undefined }
				onChange={ ( e ) => onValue?.( e.target.value ) }
			/>
		</FieldShell>
	);
}

interface ToggleProps {
	checked: boolean;
	onChange: ( v: boolean ) => void;
	label: ReactNode;
	description?: ReactNode;
	disabled?: boolean;
	busy?: boolean;
}

export function Toggle( { checked, onChange, label, description, disabled, busy }: ToggleProps ) {
	const id = useId();
	return (
		<div className={ cx( 'wvm-toggle-row', disabled && 'is-disabled' ) }>
			<div className="wvm-toggle-row__text">
				<label htmlFor={ id } className="wvm-toggle-row__label">{ label }</label>
				{ description && <p className="wvm-toggle-row__desc">{ description }</p> }
			</div>
			<button
				id={ id }
				type="button"
				role="switch"
				aria-checked={ checked }
				className={ cx( 'wvm-switch', checked && 'is-on', busy && 'is-busy' ) }
				onClick={ () => ! disabled && onChange( ! checked ) }
				disabled={ disabled }
			>
				<span className="wvm-switch__thumb" />
			</button>
		</div>
	);
}

interface CheckboxProps {
	checked: boolean;
	onChange: ( v: boolean ) => void;
	children: ReactNode;
	error?: string;
	name?: string;
}

export function Checkbox( { checked, onChange, children, error, name }: CheckboxProps ) {
	const id = useId();
	return (
		<div className={ cx( 'wvm-field', error && 'has-error' ) }>
			<label className="wvm-check" htmlFor={ id }>
				<input id={ id } name={ name } type="checkbox" checked={ checked } onChange={ ( e ) => onChange( e.target.checked ) } aria-invalid={ error ? true : undefined } />
				<span className="wvm-check__box" aria-hidden="true">
					<Icon name="check" size={ 14 } strokeWidth={ 3 } />
				</span>
				<span className="wvm-check__label">{ children }</span>
			</label>
			{ error && (
				<p className="wvm-field__error" role="alert">
					<Icon name="alert" size={ 14 } /> { error }
				</p>
			) }
		</div>
	);
}

/** Visually hidden honeypot field — bots fill it, humans never see it. */
export function Honeypot( { value, onValue }: { value: string; onValue: ( v: string ) => void } ) {
	return (
		<div className="wvm-hp" aria-hidden="true">
			<label>
				Website
				<input type="text" name="website" tabIndex={ -1 } autoComplete="off" value={ value } onChange={ ( e ) => onValue( e.target.value ) } />
			</label>
		</div>
	);
}

/** Segmented control (currency toggle, past/upcoming etc). */
export function Segmented< T extends string >( { value, options, onChange, label }: { value: T; options: { value: T; label: ReactNode }[]; onChange: ( v: T ) => void; label: string } ) {
	return (
		<div className="wvm-seg" role="radiogroup" aria-label={ label }>
			{ options.map( ( o ) => (
				<button
					key={ o.value }
					type="button"
					role="radio"
					aria-checked={ value === o.value }
					className={ cx( 'wvm-seg__opt', value === o.value && 'is-active' ) }
					onClick={ () => onChange( o.value ) }
				>
					{ o.label }
				</button>
			) ) }
		</div>
	);
}
