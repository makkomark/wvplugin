/* Profile tab: details form, email change, notification + consent toggles, sessions, privacy. */
import { useMemo, useState } from '@wordpress/element';
import type { FormEvent } from 'react';
import type { Member } from '../../../../shared/types';
import { formatDate, relativeTime } from '../../../../shared/format';
import { api, errorMessage, fieldErrors } from '../../../lib/api';
import { config, countryOptions } from '../../../lib/config';
import { describeUserAgent, isMobileUA } from '../../../lib/util';
import { useAsync } from '../../../hooks/useAsync';
import { Card, CardHeader, Chip } from '../../../components/Card';
import { Button } from '../../../components/Button';
import { Input, Select, Toggle } from '../../../components/Field';
import { Banner, Skeleton } from '../../../components/Feedback';
import { Avatar } from '../../../components/Avatar';
import { Icon } from '../../../components/Icon';
import { Confirm } from '../../../components/Confirm';
import { useToast } from '../../../components/Toast';
import { useDashboard } from '../context';

export function ProfileTab() {
	const { overview } = useDashboard();
	const m = overview.member;
	return (
		<div className="wvm-page">
			<div className="wvm-profile-head">
				<Avatar name={ m.name } url={ m.avatar_url } size={ 64 } />
				<div className="wvm-profile-head__text">
					<h1 className="wvm-h1">{ m.name || 'Your profile' }</h1>
					<p className="wvm-muted">{ m.email }</p>
				</div>
				<div className="wvm-profile-head__chips">
					<Chip tone="primary" icon="shield">{ m.public_id }</Chip>
					<Chip tone="neutral">Member since { formatDate( m.created_at, { month: 'short', year: 'numeric' } ) }</Chip>
				</div>
			</div>
			<div className="wvm-grid">
				<ProfileForm />
				<div className="wvm-stack wvm-stack--lg">
					<EmailCard />
					<NotificationsCard />
				</div>
				<SessionsCard />
				<PrivacyCard />
			</div>
		</div>
	);
}

function ProfileForm() {
	const { overview, reload } = useDashboard();
	const toast = useToast();
	const m = overview.member;
	const countries = useMemo( countryOptions, [] );
	const zones = useMemo( () => {
		const list = config.timezones.length ? config.timezones : [ m.timezone || 'Africa/Kampala' ];
		return list.map( ( z ) => ( { value: z, label: z.replace( /_/g, ' ' ) } ) );
	}, [ m.timezone ] );
	const [ f, setF ] = useState( { name: m.name, phone: m.phone || '', country: m.country || '', timezone: m.timezone || 'Africa/Kampala' } );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ busy, setBusy ] = useState( false );
	const dirty = f.name !== m.name || f.phone !== ( m.phone || '' ) || f.country !== ( m.country || '' ) || f.timezone !== ( m.timezone || 'Africa/Kampala' );

	const submit = async ( e: FormEvent ) => {
		e.preventDefault();
		if ( ! f.name.trim() ) {
			setErrors( { name: 'Your name is required.' } );
			return;
		}
		setBusy( true );
		setErrors( {} );
		try {
			await api.patch< Member >( 'me', f );
			await reload();
			toast.success( 'Profile saved.' );
		} catch ( err ) {
			setErrors( fieldErrors( err ) );
			toast.error( errorMessage( err ) );
		} finally {
			setBusy( false );
		}
	};

	const detected = Intl.DateTimeFormat().resolvedOptions().timeZone;

	return (
		<Card pad="lg">
			<CardHeader icon="user" title="Personal details" subtitle="Used on receipts and reminders." />
			<form className="wvm-stack" onSubmit={ submit } noValidate>
				<Input label="Full name" autoComplete="name" value={ f.name } onValue={ ( v ) => setF( { ...f, name: v } ) } error={ errors.name } />
				<Input label="Phone" type="tel" autoComplete="tel" value={ f.phone } onValue={ ( v ) => setF( { ...f, phone: v } ) } error={ errors.phone } hint="Used for Mobile Money payments." />
				<Select label="Country" value={ f.country } onValue={ ( v ) => setF( { ...f, country: v } ) } options={ countries } placeholder="Choose…" error={ errors.country } hint="Decides which payment methods you see at checkout." />
				<Select
					label="Time zone"
					value={ f.timezone }
					onValue={ ( v ) => setF( { ...f, timezone: v } ) }
					options={ zones }
					error={ errors.timezone }
					hint={ detected && detected !== f.timezone && zones.some( ( z ) => z.value === detected ) ? (
						<>Workshop times are shown in this zone. <button type="button" className="wvm-link" onClick={ () => setF( { ...f, timezone: detected } ) }>Use { detected.replace( /_/g, ' ' ) }</button></>
					) : 'Workshop times are shown in this zone.' }
				/>
				<div className="wvm-row">
					<Button type="submit" loading={ busy } disabled={ ! dirty }>Save changes</Button>
					{ dirty && ! busy && <Button variant="ghost" onClick={ () => setF( { name: m.name, phone: m.phone || '', country: m.country || '', timezone: m.timezone || 'Africa/Kampala' } ) }>Discard</Button> }
				</div>
			</form>
		</Card>
	);
}

function EmailCard() {
	const { overview } = useDashboard();
	const [ open, setOpen ] = useState( false );
	const [ email, setEmail ] = useState( '' );
	const [ error, setError ] = useState< string | undefined >();
	const [ sent, setSent ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );

	const submit = async ( e: FormEvent ) => {
		e.preventDefault();
		setError( undefined );
		if ( ! /^\S+@\S+\.\S+$/.test( email.trim() ) ) {
			setError( 'Enter a valid email address.' );
			return;
		}
		setBusy( true );
		try {
			await api.post( 'me/email-change', { email: email.trim() } );
			setSent( email.trim() );
			setOpen( false );
			setEmail( '' );
		} catch ( err ) {
			setError( fieldErrors( err ).email || errorMessage( err ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Card pad="lg">
			<CardHeader icon="mail" title="Email address" subtitle="Where we send receipts and reminders." />
			<div className="wvm-row wvm-row--between">
				<p className="wvm-strong wvm-break">{ overview.member.email }</p>
				{ ! open && <Button size="sm" variant="secondary" onClick={ () => setOpen( true ) }>Change</Button> }
			</div>
			{ sent && (
				<Banner tone="success" className="wvm-mt-4" title="Check your inbox" onDismiss={ () => setSent( null ) }>
					We sent a confirmation link to <strong>{ sent }</strong>. Your email changes once you click it.
				</Banner>
			) }
			{ open && (
				<form className="wvm-stack wvm-mt-4" onSubmit={ submit } noValidate>
					<Input label="New email" type="email" autoComplete="email" value={ email } onValue={ setEmail } error={ error } data-autofocus />
					<div className="wvm-row">
						<Button type="submit" size="sm" loading={ busy }>Send confirmation</Button>
						<Button size="sm" variant="ghost" onClick={ () => setOpen( false ) }>Cancel</Button>
					</div>
				</form>
			) }
		</Card>
	);
}

type PrefKey = keyof Member[ 'notification_prefs' ];
const PREFS: { key: PrefKey; label: string; desc: string }[] = [
	{ key: 'renewal_reminders', label: 'Renewal reminders', desc: 'A heads-up before your plan renews.' },
	{ key: 'workshop_reminders', label: 'Workshop reminders', desc: 'The day before and an hour before you start.' },
	{ key: 'product_updates', label: 'Product updates', desc: 'New features and programmes.' },
	{ key: 'in_app', label: 'In-app notifications', desc: 'Updates in the bell at the top of your dashboard.' },
];

function NotificationsCard() {
	const { overview, reload } = useDashboard();
	const toast = useToast();
	const m = overview.member;
	const [ prefs, setPrefs ] = useState( m.notification_prefs || { renewal_reminders: true, workshop_reminders: true, product_updates: true, in_app: true } );
	const [ consent, setConsent ] = useState( m.marketing_consent );
	const [ saving, setSaving ] = useState< string | null >( null );

	const save = async ( key: string, body: Record< string, unknown >, rollback: () => void ) => {
		setSaving( key );
		try {
			await api.patch( 'me', body );
			reload();
		} catch ( e ) {
			rollback();
			toast.error( errorMessage( e ) );
		} finally {
			setSaving( null );
		}
	};

	const togglePref = ( key: PrefKey, v: boolean ) => {
		const prev = prefs;
		const next = { ...prefs, [ key ]: v };
		setPrefs( next );
		save( key, { notification_prefs: next }, () => setPrefs( prev ) );
	};

	return (
		<Card pad="lg">
			<CardHeader icon="bell" title="Notifications" subtitle="Payment receipts and security alerts are always sent." />
			<div className="wvm-toggles">
				{ PREFS.map( ( p ) => (
					<Toggle key={ p.key } label={ p.label } description={ p.desc } checked={ !! prefs[ p.key ] } busy={ saving === p.key } onChange={ ( v ) => togglePref( p.key, v ) } />
				) ) }
				<Toggle
					label="Marketing emails"
					description="Insights, offers and news from WitsValley."
					checked={ consent }
					busy={ saving === 'consent' }
					onChange={ ( v ) => {
						setConsent( v );
						save( 'consent', { marketing_consent: v }, () => setConsent( ! v ) );
					} }
				/>
			</div>
		</Card>
	);
}

interface SessionRow { id: string; ip: string; user_agent: string; login_at: string | null; expires_at: string | null; current: boolean }

function SessionsCard() {
	const toast = useToast();
	const sessions = useAsync< SessionRow[] >( async () => ( await api.get< SessionRow[] >( 'me/sessions' ) ).data, [] );
	const [ confirm, setConfirm ] = useState( false );
	const [ showAll, setShowAll ] = useState( false );
	const others = ( sessions.data || [] ).filter( ( s ) => ! s.current ).length;

	return (
		<Card pad="lg">
			<CardHeader
				icon="monitor"
				title="Where you're logged in"
				subtitle="Don't recognise a device? Sign it out."
				action={ others > 0 ? <Button size="sm" variant="secondary" onClick={ () => setConfirm( true ) }>Sign out others</Button> : undefined }
			/>
			{ ! sessions.data && ! sessions.error && (
				<div className="wvm-stack">
					<Skeleton h={ 52 } r={ 12 } />
					<Skeleton h={ 52 } r={ 12 } />
				</div>
			) }
			{ sessions.error && ! sessions.data && <p className="wvm-muted">{ sessions.error }</p> }
			{ sessions.data && (
				<ul className="wvm-list">
					{ [ ...sessions.data ]
						.sort( ( a, b ) => Number( b.current ) - Number( a.current ) || String( b.login_at ).localeCompare( String( a.login_at ) ) )
						.slice( 0, showAll ? undefined : 4 )
						.map( ( s ) => (
							<li key={ s.id } className="wvm-list__item wvm-session">
								<span className="wvm-session__icon" aria-hidden="true"><Icon name={ isMobileUA( s.user_agent ) ? 'phone' : 'monitor' } size={ 18 } /></span>
								<div className="wvm-session__main">
									<p className="wvm-list__title">{ describeUserAgent( s.user_agent ) }</p>
									<p className="wvm-list__meta">
										{ s.ip || 'Unknown location' }
										{ s.login_at && <> · signed in { relativeTime( s.login_at ) }</> }
									</p>
								</div>
								{ s.current && <Chip tone="success" dot>This device</Chip> }
							</li>
						) ) }
				</ul>
			) }
			{ sessions.data && sessions.data.length > 4 && (
				<button type="button" className="wvm-link wvm-small wvm-mt-3" onClick={ () => setShowAll( ( v ) => ! v ) }>
					{ showAll ? 'Show fewer' : `Show all ${ sessions.data.length } sessions` }
				</button>
			) }
			<Confirm
				open={ confirm }
				onClose={ () => setConfirm( false ) }
				title="Sign out other devices?"
				confirmLabel="Sign out others"
				onConfirm={ async () => {
					const { data } = await api.del< SessionRow[] >( 'me/sessions/others' );
					sessions.setData( data );
					toast.success( 'Other devices have been signed out.' );
				} }
			>
				<p>You'll stay logged in here. Everywhere else, you'll need to log in again.</p>
			</Confirm>
		</Card>
	);
}

function PrivacyCard() {
	const toast = useToast();
	const [ exportBusy, setExportBusy ] = useState( false );
	const [ exported, setExported ] = useState( false );
	const [ deleteOpen, setDeleteOpen ] = useState( false );
	const [ deleted, setDeleted ] = useState( false );

	const requestExport = async () => {
		setExportBusy( true );
		try {
			await api.post( 'me/data-export' );
			setExported( true );
		} catch ( e ) {
			toast.error( errorMessage( e ) );
		} finally {
			setExportBusy( false );
		}
	};

	return (
		<Card pad="lg">
			<CardHeader icon="shield" title="Privacy & data" subtitle="You're in control of your information." />
			<div className="wvm-privacy">
				<div className="wvm-privacy__row">
					<div>
						<p className="wvm-strong">Download my data</p>
						<p className="wvm-muted wvm-small">Our team prepares a copy of your account data and emails it to you — usually within a few days.</p>
					</div>
					{ exported ? <Chip tone="success" icon="check">Requested</Chip> : <Button size="sm" variant="secondary" onClick={ requestExport } loading={ exportBusy } icon="download">Request</Button> }
				</div>
				<div className="wvm-privacy__row">
					<div>
						<p className="wvm-strong">Delete my account</p>
						<p className="wvm-muted wvm-small">We'll confirm by email, then remove your personal details. Payment records are kept as required by law.</p>
					</div>
					{ deleted ? <Chip tone="warning">Requested</Chip> : <Button size="sm" variant="danger" onClick={ () => setDeleteOpen( true ) } icon="trash">Delete</Button> }
				</div>
				{ ( exported || deleted ) && (
					<Banner tone="info">
						{ deleted ? 'Check your email to confirm the deletion request.' : "We've received your request — we'll email you when your data is ready." }
					</Banner>
				) }
				{ config.privacyUrl && (
					<a className="wvm-link wvm-small" href={ config.privacyUrl } target="_blank" rel="noreferrer">
						Read our privacy policy <Icon name="external" size={ 12 } />
					</a>
				) }
			</div>
			<Confirm
				open={ deleteOpen }
				onClose={ () => setDeleteOpen( false ) }
				title="Delete your account?"
				confirmLabel="Request deletion"
				tone="danger"
				onConfirm={ async () => {
					await api.post( 'me/delete-request' );
					setDeleted( true );
				} }
			>
				<p>We'll email you a link to confirm. Once you confirm, our team permanently removes your personal details and ends your access. This can't be undone.</p>
			</Confirm>
		</Card>
	);
}
