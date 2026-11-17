/* [wvm_enterprise_form] — marketing header + application form → POST /public/applications. */
import { useMemo, useState } from '@wordpress/element';
import type { FormEvent } from 'react';
import { api, errorMessage, fieldErrors, track } from '../../lib/api';
import { config, countryOptions } from '../../lib/config';
import { firstName } from '../../lib/util';
import { Input, Select, Textarea, Honeypot } from '../../components/Field';
import { Button } from '../../components/Button';
import { Banner } from '../../components/Feedback';
import { Icon, type IconName } from '../../components/Icon';
import { Card } from '../../components/Card';

const opts = ( list: string[] ) => list.map( ( v ) => ( { value: v, label: v } ) );

const ORG_SIZES = opts( [ '1–10 people', '11–50 people', '51–200 people', '201–1,000 people', '1,000+ people' ] );
const PROGRAMMES = opts( [ 'Financial literacy for staff', 'Leadership & management', 'School or student programme', 'Savings group / SACCO training', 'Custom programme', 'Not sure yet' ] );
const DURATIONS = opts( [ 'One-off session', '1 month', '3 months', '6 months', '12 months', 'Ongoing' ] );
const STARTS = opts( [ 'As soon as possible', 'Within 1 month', 'Within 3 months', 'Later this year', 'Just exploring' ] );
const BUDGETS = opts( [ 'Under UGX 5M', 'UGX 5M – 20M', 'UGX 20M – 50M', 'Over UGX 50M', 'Not sure yet' ] );

const FEATURES: { icon: IconName; title: string; text: string }[] = [
	{ icon: 'sparkles', title: 'Tailored to your team', text: 'We shape lessons around your people, goals and pay cycles.' },
	{ icon: 'video', title: 'Private live workshops', text: 'On-site or online sessions just for your organisation.' },
	{ icon: 'users', title: 'Seat management', text: 'Invite staff in minutes and see who has joined.' },
	{ icon: 'shield', title: 'A dedicated manager', text: 'One person who knows your programme end to end.' },
];

type Form = {
	org_name: string;
	contact_name: string;
	email: string;
	phone: string;
	country: string;
	org_size: string;
	programme_type: string;
	participants: string;
	duration: string;
	start_preference: string;
	budget_range: string;
	message: string;
	website: string;
};

export function EnterpriseView() {
	const countries = useMemo( countryOptions, [] );
	const [ f, setF ] = useState< Form >( {
		org_name: '',
		contact_name: config.user?.name || '',
		email: config.user?.email || '',
		phone: '',
		country: config.countries.UG ? 'UG' : countries[ 0 ]?.value || '',
		org_size: '',
		programme_type: '',
		participants: '',
		duration: '',
		start_preference: '',
		budget_range: '',
		message: '',
		website: '',
	} );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ done, setDone ] = useState( false );

	const set = ( k: keyof Form ) => ( v: string ) => {
		setF( ( p ) => ( { ...p, [ k ]: v } ) );
		setErrors( ( e ) => {
			if ( ! e[ k ] ) {
				return e;
			}
			const n = { ...e };
			delete n[ k ];
			return n;
		} );
	};

	const submit = async ( ev: FormEvent ) => {
		ev.preventDefault();
		setError( null );
		const e: Record< string, string > = {};
		if ( ! f.org_name.trim() ) {
			e.org_name = 'Enter your organisation name.';
		}
		if ( ! f.contact_name.trim() ) {
			e.contact_name = 'Enter your name.';
		}
		if ( ! /^\S+@\S+\.\S+$/.test( f.email.trim() ) ) {
			e.email = 'Enter a valid work email.';
		}
		if ( f.participants && ( ! /^\d+$/.test( f.participants ) || Number( f.participants ) < 1 ) ) {
			e.participants = 'Enter a number of people.';
		}
		if ( Object.keys( e ).length ) {
			setErrors( e );
			return;
		}
		setBusy( true );
		try {
			await api.post( 'public/applications', {
				...f,
				participants: f.participants ? Number( f.participants ) : null,
				budget_range: f.budget_range || null,
			} );
			track( 'wvm_enterprise_application', { org_size: f.org_size, programme_type: f.programme_type } );
			setDone( true );
		} catch ( err ) {
			const fe = fieldErrors( err );
			setErrors( fe );
			setError( Object.keys( fe ).length ? 'Please check the highlighted fields.' : errorMessage( err ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<div className="wvm-ent">
			<section className="wvm-ent__intro">
				<span className="wvm-pill-eyebrow">
					<Icon name="building" size={ 14 } /> Enterprise &amp; private programmes
				</span>
				<h2 className="wvm-display">Money skills for your whole team</h2>
				<p className="wvm-lead">From staff wellbeing to school programmes, we design practical financial education that people actually use. Tell us a little about your organisation and we'll send a tailored proposal.</p>
				<ul className="wvm-ent__features">
					{ FEATURES.map( ( x ) => (
						<li key={ x.title }>
							<span className="wvm-ent__ficon" aria-hidden="true"><Icon name={ x.icon } size={ 20 } /></span>
							<div>
								<p className="wvm-ent__ftitle">{ x.title }</p>
								<p className="wvm-ent__ftext">{ x.text }</p>
							</div>
						</li>
					) ) }
				</ul>
				<div className="wvm-ent__quote">
					<Icon name="clock" size={ 18 } />
					<span>We reply within <strong>2 working days</strong>.{ config.supportEmail && <> Questions? <a className="wvm-link" href={ `mailto:${ config.supportEmail }` }>{ config.supportEmail }</a></> }</span>
				</div>
			</section>

			<Card className="wvm-ent__form" pad="lg">
				{ done ? (
					<div className="wvm-success" role="status">
						<span className="wvm-success__icon" aria-hidden="true"><Icon name="check" size={ 30 } strokeWidth={ 2.6 } /></span>
						<h3 className="wvm-h2">Thank you{ f.contact_name ? `, ${ firstName( f.contact_name ) }` : '' }!</h3>
						<p className="wvm-muted">
							We've received your application for <strong>{ f.org_name }</strong>. Our team will review it and reply to <strong>{ f.email }</strong> within 2 working days.
						</p>
						<div className="wvm-row wvm-row--center wvm-mt-4">
							<Button href={ config.pricingUrl } variant="secondary" iconRight="arrow-right">See individual plans</Button>
						</div>
					</div>
				) : (
					<form className="wvm-stack" onSubmit={ submit } noValidate>
						<div>
							<h3 className="wvm-h3">Apply for a programme</h3>
							<p className="wvm-muted wvm-small">Takes about 2 minutes. No commitment.</p>
						</div>
						<p className="wvm-form-section">About you</p>
						<div className="wvm-form-row">
							<Input label="Your name" autoComplete="name" value={ f.contact_name } onValue={ set( 'contact_name' ) } error={ errors.contact_name } required />
							<Input label="Work email" type="email" autoComplete="email" value={ f.email } onValue={ set( 'email' ) } error={ errors.email } required />
						</div>
						<div className="wvm-form-row">
							<Input label="Phone" type="tel" autoComplete="tel" value={ f.phone } onValue={ set( 'phone' ) } error={ errors.phone } optional />
							<Select label="Country" value={ f.country } onValue={ set( 'country' ) } options={ countries } error={ errors.country } />
						</div>
						<p className="wvm-form-section">Your organisation</p>
						<Input label="Organisation name" autoComplete="organization" value={ f.org_name } onValue={ set( 'org_name' ) } error={ errors.org_name } required />
						<div className="wvm-form-row">
							<Select label="Organisation size" value={ f.org_size } onValue={ set( 'org_size' ) } options={ ORG_SIZES } placeholder="Choose…" error={ errors.org_size } />
							<Input label="Participants" type="number" inputMode="numeric" min={ 1 } placeholder="e.g. 40" value={ f.participants } onValue={ set( 'participants' ) } error={ errors.participants } />
						</div>
						<p className="wvm-form-section">Programme</p>
						<Select label="What are you looking for?" value={ f.programme_type } onValue={ set( 'programme_type' ) } options={ PROGRAMMES } placeholder="Choose…" error={ errors.programme_type } />
						<div className="wvm-form-row">
							<Select label="Duration" value={ f.duration } onValue={ set( 'duration' ) } options={ DURATIONS } placeholder="Choose…" error={ errors.duration } />
							<Select label="Ideal start" value={ f.start_preference } onValue={ set( 'start_preference' ) } options={ STARTS } placeholder="Choose…" error={ errors.start_preference } />
						</div>
						<Select label="Budget range" value={ f.budget_range } onValue={ set( 'budget_range' ) } options={ BUDGETS } placeholder="Prefer not to say" error={ errors.budget_range } optional />
						<Textarea label="Anything else we should know?" rows={ 4 } value={ f.message } onValue={ set( 'message' ) } error={ errors.message } placeholder="Goals, audience, dates, languages…" optional />
						<Honeypot value={ f.website } onValue={ set( 'website' ) } />
						{ error && <Banner tone="danger">{ error }</Banner> }
						<Button type="submit" size="lg" block loading={ busy } iconRight="arrow-right">Send application</Button>
						<p className="wvm-center wvm-small wvm-muted">
							<Icon name="lock" size={ 14 } /> We only use these details to prepare your proposal.
						</p>
					</form>
				) }
			</Card>
		</div>
	);
}
