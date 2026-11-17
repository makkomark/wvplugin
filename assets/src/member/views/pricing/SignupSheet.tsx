/* Signup sheet: account details → POST /public/signup → redirect to checkout (or dashboard for Free). */
import { useMemo, useState } from '@wordpress/element';
import type { FormEvent } from 'react';
import type { PublicPlan } from '../../../shared/types';
import { intervalLabel } from '../../../shared/format';
import { api, errorMessage, fieldErrors, go, track } from '../../lib/api';
import { config, countryOptions } from '../../lib/config';
import { Modal } from '../../components/Modal';
import { Input, Select, Checkbox, Toggle, Honeypot } from '../../components/Field';
import { Button } from '../../components/Button';
import { Banner } from '../../components/Feedback';
import { Money } from '../../components/Brand';
import { Icon } from '../../components/Icon';

interface Props {
	plan: PublicPlan;
	currency: string;
	defaultCountry: string;
	onClose: () => void;
	onLogin: () => void;
}

interface SignupResult { redirect: string; checkout_url?: string }

export function SignupSheet( { plan, currency, defaultCountry, onClose, onLogin }: Props ) {
	const countries = useMemo( countryOptions, [] );
	const [ f, setF ] = useState( {
		name: '',
		email: '',
		phone: '',
		country: config.countries[ defaultCountry ] ? defaultCountry : countries[ 0 ]?.value || 'UG',
		password: '',
		terms: false,
		marketing_consent: true,
		website: '',
	} );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );
	const set = < K extends keyof typeof f >( k: K, v: ( typeof f )[ K ] ) => {
		setF( ( prev ) => ( { ...prev, [ k ]: v } ) );
		if ( errors[ k as string ] ) {
			setErrors( ( e ) => {
				const n = { ...e };
				delete n[ k as string ];
				return n;
			} );
		}
	};

	const validate = (): Record< string, string > => {
		const e: Record< string, string > = {};
		if ( ! f.name.trim() ) {
			e.name = 'Tell us your name.';
		}
		if ( ! /^\S+@\S+\.\S+$/.test( f.email.trim() ) ) {
			e.email = 'Enter a valid email address.';
		}
		if ( f.password.length < 8 || ! /[A-Za-z]/.test( f.password ) || ! /\d/.test( f.password ) ) {
			e.password = 'Use at least 8 characters with letters and numbers.';
		}
		if ( ! f.terms ) {
			e.terms = 'Please accept the terms to continue.';
		}
		return e;
	};

	const submit = async ( ev: FormEvent ) => {
		ev.preventDefault();
		setError( null );
		const local = validate();
		if ( Object.keys( local ).length ) {
			setErrors( local );
			return;
		}
		setBusy( true );
		try {
			const { data } = await api.post< SignupResult >( 'public/signup', {
				name: f.name.trim(),
				email: f.email.trim(),
				phone: f.phone.trim(),
				country: f.country,
				password: f.password,
				plan_id: plan.id,
				currency,
				terms: true,
				marketing_consent: f.marketing_consent,
				website: f.website,
				timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || undefined,
			} );
			track( 'wvm_signup_complete', { plan: plan.slug, currency, free: plan.is_free } );
			go( data.redirect || data.checkout_url || config.dashboardUrl );
		} catch ( e ) {
			const fe = fieldErrors( e );
			setErrors( fe );
			// Field-level problems are shown inline; show the summary only when nothing is attached to a field.
			const shown = [ 'name', 'email', 'phone', 'country', 'password', 'terms' ].some( ( k ) => fe[ k ] );
			setError( shown ? null : errorMessage( e ) );
			if ( fe.currency || fe.plan_id ) {
				setError( fe.currency || fe.plan_id );
			}
			setBusy( false );
		}
	};

	const interval = plan.is_free ? 'Free forever' : intervalLabel( plan.interval, plan.interval_count ).replace( /^\/\s*/, 'per ' );
	const exists = errors.email && /already exists|log in/i.test( errors.email );

	return (
		<Modal
			open
			onClose={ onClose }
			locked={ busy }
			title={ plan.is_free ? 'Create your free account' : `Join ${ plan.name }` }
			description={ plan.is_free ? 'No card needed. Upgrade any time.' : 'Create your account, then pay securely with Mobile Money or card.' }
			size="md"
		>
			<form className="wvm-stack" onSubmit={ submit } noValidate>
				<div className="wvm-signup-summary">
					<div>
						<p className="wvm-signup-summary__plan">{ plan.name }</p>
						<p className="wvm-signup-summary__meta">{ interval }</p>
					</div>
					{ ! plan.is_free && <Money display={ plan.amount_display } size="md" /> }
				</div>

				{ ! plan.is_free && (
					<ol className="wvm-steps" aria-label="Signup steps">
						<li className="is-current"><span>1</span> Account</li>
						<li><span>2</span> Pay</li>
						<li><span>3</span> Done</li>
					</ol>
				) }

				<Input label="Full name" autoComplete="name" value={ f.name } onValue={ ( v ) => set( 'name', v ) } error={ errors.name } required />
				<Input
					label="Email"
					type="email"
					autoComplete="email"
					inputMode="email"
					value={ f.email }
					onValue={ ( v ) => set( 'email', v ) }
					error={ errors.email }
					required
				/>
				{ exists && (
					<p className="wvm-small wvm-mt-n2">
						<button type="button" className="wvm-link" onClick={ onLogin }>Log in instead</button>
					</p>
				) }
				<div className="wvm-form-row">
					<Input label="Phone" type="tel" autoComplete="tel" inputMode="tel" placeholder="+256 7xx xxx xxx" value={ f.phone } onValue={ ( v ) => set( 'phone', v ) } error={ errors.phone } hint="For Mobile Money and reminders." optional />
					<Select label="Country" autoComplete="country" value={ f.country } onValue={ ( v ) => set( 'country', v ) } options={ countries } error={ errors.country } />
				</div>
				<Input label="Password" type="password" autoComplete="new-password" value={ f.password } onValue={ ( v ) => set( 'password', v ) } error={ errors.password } hint="At least 8 characters, with letters and numbers." required />

				<Checkbox checked={ f.terms } onChange={ ( v ) => set( 'terms', v ) } error={ errors.terms }>
					I agree to the{ ' ' }
					{ config.termsUrl ? (
						<a className="wvm-link" href={ config.termsUrl } target="_blank" rel="noreferrer">terms of membership</a>
					) : (
						'terms of membership'
					) }
					{ config.privacyUrl && (
						<>
							{ ' ' }and{ ' ' }
							<a className="wvm-link" href={ config.privacyUrl } target="_blank" rel="noreferrer">privacy policy</a>
						</>
					) }
					.
				</Checkbox>
				<Toggle checked={ f.marketing_consent } onChange={ ( v ) => set( 'marketing_consent', v ) } label="Send me money tips and news" description="A couple of emails a month. Unsubscribe any time." />
				<Honeypot value={ f.website } onValue={ ( v ) => set( 'website', v ) } />

				{ error && <Banner tone="danger">{ error }</Banner> }

				<Button type="submit" size="lg" block loading={ busy } iconRight="arrow-right">
					{ plan.is_free ? 'Create free account' : 'Continue to payment' }
				</Button>
				<p className="wvm-center wvm-small wvm-muted">
					<Icon name="lock" size={ 14 } /> Your details are encrypted and never shared.
				</p>
				<p className="wvm-center wvm-small">
					Already have an account?{ ' ' }
					<button type="button" className="wvm-link" onClick={ onLogin }>Log in</button>
				</p>
			</form>
		</Modal>
	);
}
