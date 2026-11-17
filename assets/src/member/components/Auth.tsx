/* Login + password-reset forms (shared by pricing modal, dashboard login card and invite flow). */
import { useState } from '@wordpress/element';
import type { FormEvent, ReactNode } from 'react';
import { api, errorMessage, fieldErrors } from '../lib/api';
import { config } from '../lib/config';
import { Input, Checkbox } from './Field';
import { Button } from './Button';
import { Banner } from './Feedback';

interface LoginResult { user: { id: number; name: string }; nonce: string; redirect: string }

interface LoginFormProps {
	/** Called after a successful login. Defaults to reloading the page. */
	onSuccess?: ( r: LoginResult ) => void;
	defaultEmail?: string;
	lockEmail?: boolean;
	submitLabel?: string;
	footer?: ReactNode;
}

export function LoginForm( { onSuccess, defaultEmail = '', lockEmail, submitLabel = 'Log in', footer }: LoginFormProps ) {
	const [ mode, setMode ] = useState< 'login' | 'reset' | 'reset-sent' >( 'login' );
	const [ email, setEmail ] = useState( defaultEmail );
	const [ password, setPassword ] = useState( '' );
	const [ remember, setRemember ] = useState( true );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );

	const submit = async ( e: FormEvent ) => {
		e.preventDefault();
		setError( null );
		setErrors( {} );
		if ( ! email.trim() || ! password ) {
			setErrors( { ...( ! email.trim() ? { email: 'Enter your email.' } : {} ), ...( ! password ? { password: 'Enter your password.' } : {} ) } );
			return;
		}
		setBusy( true );
		try {
			const { data } = await api.post< LoginResult >( 'public/login', { email: email.trim(), password, remember } );
			if ( onSuccess ) {
				onSuccess( data );
			} else {
				window.location.reload();
			}
		} catch ( err ) {
			setError( errorMessage( err ) );
			setErrors( fieldErrors( err ) );
			setBusy( false );
		}
	};

	const reset = async ( e: FormEvent ) => {
		e.preventDefault();
		setError( null );
		if ( ! /\S+@\S+\.\S+/.test( email ) ) {
			setErrors( { email: 'Enter the email you signed up with.' } );
			return;
		}
		setBusy( true );
		try {
			await api.post( 'public/password-reset', { email: email.trim() } );
			setMode( 'reset-sent' );
		} catch ( err ) {
			setError( errorMessage( err ) );
		} finally {
			setBusy( false );
		}
	};

	if ( mode === 'reset-sent' ) {
		return (
			<div className="wvm-stack">
				<Banner tone="success" title="Check your inbox">
					If an account exists for <strong>{ email }</strong>, we've sent a link to set a new password. It can take a minute to arrive.
				</Banner>
				<Button variant="secondary" block onClick={ () => setMode( 'login' ) }>Back to log in</Button>
			</div>
		);
	}

	if ( mode === 'reset' ) {
		return (
			<form className="wvm-stack" onSubmit={ reset } noValidate>
				<p className="wvm-muted">Enter your email and we'll send you a link to reset your password.</p>
				<Input label="Email" type="email" autoComplete="email" value={ email } onValue={ setEmail } error={ errors.email } required />
				{ error && <Banner tone="danger">{ error }</Banner> }
				<Button type="submit" block size="lg" loading={ busy }>Send reset link</Button>
				<div className="wvm-row wvm-row--between wvm-small">
					<button type="button" className="wvm-link" onClick={ () => setMode( 'login' ) }>Back to log in</button>
					{ config.lostPasswordUrl && <a className="wvm-link" href={ config.lostPasswordUrl }>Use the classic form</a> }
				</div>
			</form>
		);
	}

	return (
		<form className="wvm-stack" onSubmit={ submit } noValidate>
			<Input label="Email" type="email" autoComplete="email" value={ email } onValue={ setEmail } error={ errors.email } readOnly={ lockEmail } required />
			<Input label="Password" type="password" autoComplete="current-password" value={ password } onValue={ setPassword } error={ errors.password } required />
			<div className="wvm-row wvm-row--between">
				<Checkbox checked={ remember } onChange={ setRemember }>Keep me logged in</Checkbox>
				<button type="button" className="wvm-link wvm-small" onClick={ () => setMode( 'reset' ) }>Forgot password?</button>
			</div>
			{ error && <Banner tone="danger">{ error }</Banner> }
			<Button type="submit" block size="lg" loading={ busy } iconRight="arrow-right">{ submitLabel }</Button>
			{ footer }
		</form>
	);
}
