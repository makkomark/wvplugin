/* ?invite={token} — accept an organisation seat (logged in, log in first, or create an account). */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import type { FormEvent, ReactElement } from 'react';
import { api, errorMessage, fieldErrors, go } from '../../lib/api';
import { config, countryOptions } from '../../lib/config';
import { useAsync } from '../../hooks/useAsync';
import { LoginForm } from '../../components/Auth';
import { Brand } from '../../components/Brand';
import { Button } from '../../components/Button';
import { Banner, Skeleton, EmptyState } from '../../components/Feedback';
import { Input, Select, Toggle } from '../../components/Field';
import { Icon } from '../../components/Icon';
import { Card } from '../../components/Card';

interface InviteInfo {
	valid: boolean;
	org_name?: string;
	programme?: string;
	email?: string;
	role?: 'admin' | 'member';
	has_account?: boolean;
	joined?: boolean;
}

const AUTO_KEY = 'wvm_invite_autoaccept';

function dashboardWith( q: string ): string {
	const base = config.dashboardUrl;
	return base + ( base.includes( '?' ) ? '&' : '?' ) + q;
}

/** Full-page navigation to the programme tab (reloads even if only the hash changes). */
export function goToProgramme() {
	const target = ( config.dashboardUrl.includes( '?' ) ? config.dashboardUrl : config.dashboardUrl.replace( /\/?$/, '/' ) ) + '#/programme';
	const here = window.location.origin + window.location.pathname + window.location.search;
	if ( target.startsWith( here ) && window.location.search === '' ) {
		window.location.hash = '#/programme';
		window.location.reload();
	} else {
		window.location.href = target;
	}
}

interface Props {
	token: string;
	/** Logged-out full-page variant (vs. a card inside the logged-in dashboard). */
	standalone?: boolean;
	onDone?: () => void;
}

export function InviteView( { token, standalone, onDone }: Props ) {
	const info = useAsync< InviteInfo >( async () => ( await api.get< InviteInfo >( `public/invites/${ encodeURIComponent( token ) }` ) ).data, [ token ] );
	const [ accepting, setAccepting ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const auto = useRef( false );

	const accept = async () => {
		setAccepting( true );
		setError( null );
		try {
			await api.post( 'me/organisation/accept', { token } );
			try {
				window.sessionStorage.removeItem( AUTO_KEY );
			} catch ( e ) {}
			if ( onDone ) {
				onDone();
			} else {
				goToProgramme();
			}
		} catch ( e ) {
			setError( errorMessage( e ) );
			setAccepting( false );
		}
	};

	// After "log in & join", the page reloads logged in: accept automatically.
	useEffect( () => {
		if ( ! config.isLoggedIn || auto.current || ! info.data?.valid || info.data.joined ) {
			return;
		}
		let flag: string | null = null;
		try {
			flag = window.sessionStorage.getItem( AUTO_KEY );
		} catch ( e ) {}
		if ( flag === token ) {
			auto.current = true;
			accept();
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ info.data ] );

	const wrap = ( body: ReactElement ) =>
		standalone ? (
			<div className="wvm-auth">
				<div className="wvm-auth__card wvm-auth__card--wide">
					<div className="wvm-auth__brand"><Brand /></div>
					{ body }
				</div>
			</div>
		) : (
			<Card className="wvm-invite-card" pad="lg">{ body }</Card>
		);

	if ( info.loading && ! info.data ) {
		return wrap(
			<div aria-busy="true">
				<Skeleton w="60%" h={ 24 } />
				<Skeleton w="85%" h={ 14 } className="wvm-mt-3" />
				<Skeleton h={ 48 } r={ 14 } className="wvm-mt-6" />
			</div>
		);
	}
	if ( info.error || ! info.data || ! info.data.valid ) {
		return wrap(
			<EmptyState icon="alert" title="This invitation is no longer valid" action={ <Button href={ config.isLoggedIn ? config.dashboardUrl : config.pricingUrl } variant="secondary">{ config.isLoggedIn ? 'Go to dashboard' : 'See membership plans' }</Button> }>
				It may have been used already or withdrawn. Ask your organisation admin to send a new invite.
			</EmptyState>
		);
	}
	const inv = info.data;
	const header = (
		<div className="wvm-invite__head">
			<span className="wvm-invite__badge" aria-hidden="true"><Icon name="building" size={ 24 } /></span>
			<p className="wvm-eyebrow">You're invited</p>
			<h2 className="wvm-h1">Join { inv.org_name }</h2>
			{ inv.programme && <p className="wvm-muted">Programme: <strong>{ inv.programme }</strong></p> }
		</div>
	);

	if ( inv.joined ) {
		return wrap(
			<>
				{ header }
				<Banner tone="success" title="You've already joined">Your seat is active.</Banner>
				<Button className="wvm-mt-4" block size="lg" onClick={ () => ( config.isLoggedIn ? goToProgramme() : go( config.dashboardUrl ) ) }>
					{ config.isLoggedIn ? 'Open programme' : 'Log in' }
				</Button>
			</>
		);
	}

	if ( config.isLoggedIn ) {
		const mismatch = inv.email && config.user?.email && inv.email.toLowerCase() !== config.user.email.toLowerCase();
		return wrap(
			<>
				{ header }
				<p className="wvm-muted wvm-mb-4">Accept to unlock your organisation's programme, private workshops and materials. It's covered by { inv.org_name } — there's nothing to pay.</p>
				{ mismatch && <Banner tone="warning" className="wvm-mb-4">This invite was sent to <strong>{ inv.email }</strong>, but you're logged in as { config.user?.email }.</Banner> }
				{ error && <Banner tone="danger" className="wvm-mb-4">{ error }</Banner> }
				<div className="wvm-row">
					<Button size="lg" onClick={ accept } loading={ accepting } icon="check">Accept invitation</Button>
					{ onDone && <Button size="lg" variant="ghost" onClick={ onDone }>Not now</Button> }
				</div>
			</>
		);
	}

	if ( inv.has_account ) {
		return wrap(
			<>
				{ header }
				<p className="wvm-muted wvm-mb-4">You already have an account. Log in to accept.</p>
				<LoginForm
					defaultEmail={ inv.email }
					submitLabel="Log in & join"
					onSuccess={ () => {
						try {
							window.sessionStorage.setItem( AUTO_KEY, token );
						} catch ( e ) {}
						go( dashboardWith( `invite=${ encodeURIComponent( token ) }` ) );
					} }
				/>
			</>
		);
	}

	return wrap(
		<>
			{ header }
			<CreateAccount token={ token } email={ inv.email || '' } />
		</>
	);
}

function CreateAccount( { token, email }: { token: string; email: string } ) {
	const countries = useMemo( countryOptions, [] );
	const [ f, setF ] = useState( { name: '', password: '', phone: '', country: config.countries.UG ? 'UG' : countries[ 0 ]?.value || '', marketing_consent: false } );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );

	const submit = async ( e: FormEvent ) => {
		e.preventDefault();
		const local: Record< string, string > = {};
		if ( ! f.name.trim() ) {
			local.name = 'Tell us your name.';
		}
		if ( f.password.length < 8 || ! /[A-Za-z]/.test( f.password ) || ! /\d/.test( f.password ) ) {
			local.password = 'Use at least 8 characters with letters and numbers.';
		}
		setErrors( local );
		if ( Object.keys( local ).length ) {
			return;
		}
		setBusy( true );
		setError( null );
		try {
			await api.post< { redirect: string } >( `public/invites/${ encodeURIComponent( token ) }/accept`, f );
			// The redirect is "/dashboard/#/programme" — from /dashboard/ that's only a hash change, so force a reload.
			goToProgramme();
		} catch ( err ) {
			const fe = fieldErrors( err );
			setErrors( fe );
			setError( Object.keys( fe ).length ? null : errorMessage( err ) );
			setBusy( false );
		}
	};

	return (
		<form className="wvm-stack" onSubmit={ submit } noValidate>
			<p className="wvm-muted">Create your account to accept. It's covered by your organisation — there's nothing to pay.</p>
			<Input label="Email" value={ email } readOnly hint="Invites are tied to this address." />
			<Input label="Full name" autoComplete="name" value={ f.name } onValue={ ( v ) => setF( { ...f, name: v } ) } error={ errors.name } required />
			<Input label="Create a password" type="password" autoComplete="new-password" value={ f.password } onValue={ ( v ) => setF( { ...f, password: v } ) } error={ errors.password } hint="At least 8 characters, with letters and numbers." required />
			<div className="wvm-form-row">
				<Input label="Phone" type="tel" autoComplete="tel" value={ f.phone } onValue={ ( v ) => setF( { ...f, phone: v } ) } error={ errors.phone } optional />
				<Select label="Country" value={ f.country } onValue={ ( v ) => setF( { ...f, country: v } ) } options={ countries } error={ errors.country } />
			</div>
			<Toggle checked={ f.marketing_consent } onChange={ ( v ) => setF( { ...f, marketing_consent: v } ) } label="Also send me WitsValley offers and news (optional)" description="Your programme emails come either way. Unsubscribe any time." />
			{ error && <Banner tone="danger">{ error }</Banner> }
			<Button type="submit" size="lg" block loading={ busy } iconRight="arrow-right">Create account &amp; join</Button>
		</form>
	);
}
