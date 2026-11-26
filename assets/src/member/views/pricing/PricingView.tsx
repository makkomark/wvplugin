/* [wvm_pricing] — plan cards with currency toggle, signup sheet and login. */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import type { PublicPlan, MeOverview } from '../../../shared/types';
import { intervalLabel } from '../../../shared/format';
import { api, errorMessage, go, track } from '../../lib/api';
import { config } from '../../lib/config';
import { cx, firstName } from '../../lib/util';
import { Button } from '../../components/Button';
import { Icon } from '../../components/Icon';
import { Chip } from '../../components/Card';
import { Segmented } from '../../components/Field';
import { Banner, Skeleton } from '../../components/Feedback';
import { Money } from '../../components/Brand';
import { Modal } from '../../components/Modal';
import { LoginForm } from '../../components/Auth';
import { useToast } from '../../components/Toast';
import { SignupSheet } from './SignupSheet';

interface Geo { country: string; currency: string }

export function PricingView( { preselect }: { preselect?: string } ) {
	const toast = useToast();
	const [ geo, setGeo ] = useState< Geo | null >( null );
	const [ currency, setCurrency ] = useState< string >( '' );
	const [ plans, setPlans ] = useState< PublicPlan[] | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ signupPlan, setSignupPlan ] = useState< PublicPlan | null >( null );
	const [ loginOpen, setLoginOpen ] = useState( false );
	const [ me, setMe ] = useState< MeOverview | null >( null );
	const [ busyPlan, setBusyPlan ] = useState< number | null >( null );
	const [ attempt, setAttempt ] = useState( 0 );
	const tracked = useRef( false );
	const autoOpened = useRef( false );

	// Geo → default currency (and country for the signup form).
	useEffect( () => {
		let alive = true;
		api.get< Geo >( 'public/geo' )
			.then( ( { data } ) => {
				if ( ! alive ) {
					return;
				}
				setGeo( data );
				setCurrency( config.currencies.includes( data.currency ) ? data.currency : config.currencies[ 0 ] );
			} )
			.catch( () => alive && setCurrency( config.currencies[ 0 ] ) );
		if ( config.isLoggedIn ) {
			api.get< MeOverview >( 'me' ).then( ( { data } ) => alive && setMe( data ) ).catch( () => {} );
		}
		return () => {
			alive = false;
		};
	}, [] );

	useEffect( () => {
		if ( ! currency ) {
			return;
		}
		let alive = true;
		setError( null );
		api.get< PublicPlan[] >( 'public/plans', { currency } )
			.then( ( { data } ) => {
				if ( ! alive ) {
					return;
				}
				setPlans( data );
				if ( ! tracked.current ) {
					tracked.current = true;
					track( 'wvm_pricing_view', { currency, plans: data.map( ( p ) => p.slug ) } );
				}
			} )
			.catch( ( e ) => alive && setError( errorMessage( e, 'We could not load plans right now.' ) ) );
		return () => {
			alive = false;
		};
	}, [ currency, attempt ] );

	// data-plan="premium" → open that plan's signup straight away (logged-out visitors only).
	useEffect( () => {
		if ( ! plans || ! preselect || autoOpened.current ) {
			return;
		}
		autoOpened.current = true;
		const p = plans.find( ( x ) => x.slug === preselect );
		if ( p && ! config.isLoggedIn && ! p.is_quoted && p.available ) {
			openSignup( p );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ plans, preselect ] );

	const sub = me?.subscription || null;
	const live = !! sub && ( sub.status === 'active' || sub.status === 'past_due' );
	const currentPlanId = sub && sub.status !== 'cancelled' && sub.status !== 'expired' ? sub.plan_id : null;

	const openSignup = ( p: PublicPlan ) => {
		track( 'wvm_signup_start', { plan: p.slug, currency } );
		setSignupPlan( p );
	};

	const join = async ( p: PublicPlan ) => {
		if ( ! config.isLoggedIn ) {
			openSignup( p );
			return;
		}
		// Members with a live paid plan change plans from the dashboard (preview, proration, downgrades).
		if ( live && sub && ! sub.plan.is_free ) {
			go( ( config.dashboardUrl.includes( '?' ) ? config.dashboardUrl : config.dashboardUrl.replace( /\/?$/, '/' ) ) + '#/billing' );
			return;
		}
		setBusyPlan( p.id );
		try {
			if ( p.is_free ) {
				await api.post( 'me/subscription/join-free' );
				go( config.dashboardUrl );
				return;
			}
			const { data } = await api.post< { redirect?: string; checkout_url?: string } >( 'me/subscription/upgrade', { plan_id: p.id, currency } );
			go( data.redirect || data.checkout_url || config.dashboardUrl );
		} catch ( e ) {
			toast.error( errorMessage( e ) );
			setBusyPlan( null );
		}
	};

	const preselected = preselect || '';

	return (
		<div className="wvm-pricing">
			<header className="wvm-pricing__head">
				<span className="wvm-pill-eyebrow">
					<Icon name="sparkles" size={ 14 } /> Membership
				</span>
				<h2 className="wvm-display">Begin your journey of personal transformation</h2>
				<p className="wvm-lead">Lessons, subconscious reprogramming systems and live sessions to reshape your thinking and grow. Start free, go deeper whenever you're ready, cancel anytime.</p>
				<div className="wvm-pricing__controls">
					{ config.currencies.length > 1 && currency && (
						<Segmented label="Currency" value={ currency } onChange={ setCurrency } options={ config.currencies.map( ( c ) => ( { value: c, label: c } ) ) } />
					) }
					{ config.isLoggedIn ? (
						<span className="wvm-muted wvm-small">
							Logged in as <strong>{ firstName( config.user?.name ) || 'member' }</strong> · <a className="wvm-link" href={ config.dashboardUrl }>Go to dashboard</a>
						</span>
					) : (
						<span className="wvm-muted wvm-small">
							Already a member?{ ' ' }
							<button type="button" className="wvm-link" onClick={ () => setLoginOpen( true ) }>Log in</button>
						</span>
					) }
				</div>
			</header>

			{ error && (
				<Banner tone="danger" actions={ <Button size="sm" variant="secondary" onClick={ () => setAttempt( ( n ) => n + 1 ) }>Retry</Button> }>
					{ error }
				</Banner>
			) }

			<div className={ cx( 'wvm-plans', plans && `wvm-plans--${ Math.min( plans.length, 4 ) }` ) }>
				{ ! plans &&
					! error &&
					[ 0, 1, 2, 3 ].map( ( i ) => (
						<div key={ i } className="wvm-plan wvm-plan--skeleton" aria-hidden="true">
							<Skeleton w="45%" h={ 16 } />
							<Skeleton w="80%" h={ 12 } className="wvm-mt-3" />
							<Skeleton w="60%" h={ 36 } className="wvm-mt-6" />
							{ [ 1, 2, 3 ].map( ( j ) => (
								<Skeleton key={ j } w={ `${ 85 - j * 10 }%` } h={ 12 } className="wvm-mt-4" />
							) ) }
							<Skeleton w="100%" h={ 48 } r={ 14 } className="wvm-mt-6" />
						</div>
					) ) }
				{ plans?.map( ( p ) => (
					<PlanTile
						key={ p.id }
						plan={ p }
						currency={ currency }
						current={ currentPlanId === p.id }
						loggedIn={ config.isLoggedIn }
						livePaid={ live && !! sub && ! sub.plan.is_free }
						busy={ busyPlan === p.id }
						focused={ preselected === p.slug }
						onJoin={ () => join( p ) }
					/>
				) ) }
			</div>

			<ul className="wvm-trust" aria-label="Why members trust us">
				<li><Icon name="phone" size={ 18 } /> Pay with Mobile Money or card</li>
				<li><Icon name="shield" size={ 18 } /> Secure checkout</li>
				<li><Icon name="refresh" size={ 18 } /> Cancel anytime from your dashboard</li>
			</ul>

			{ signupPlan && (
				<SignupSheet
					plan={ signupPlan }
					currency={ currency }
					defaultCountry={ geo?.country || 'UG' }
					onClose={ () => setSignupPlan( null ) }
					onLogin={ () => {
						setSignupPlan( null );
						setLoginOpen( true );
					} }
				/>
			) }

			<Modal open={ loginOpen } onClose={ () => setLoginOpen( false ) } title="Welcome back" description="Log in to manage your membership." size="sm">
				<LoginForm />
			</Modal>
		</div>
	);
}

interface TileProps {
	plan: PublicPlan;
	currency: string;
	current: boolean;
	loggedIn: boolean;
	livePaid: boolean;
	busy: boolean;
	focused: boolean;
	onJoin: () => void;
}

function PlanTile( { plan, currency, current, loggedIn, livePaid, busy, focused, onJoin }: TileProps ) {
	const hero = plan.highlight;
	const interval = useMemo( () => ( plan.is_free ? 'forever' : intervalLabel( plan.interval, plan.interval_count ).replace( /^\/\s*/, 'per ' ) ), [ plan ] );
	let cta;
	if ( plan.is_quoted ) {
		cta = (
			<Button href={ config.enterpriseUrl } variant={ hero ? 'light' : 'secondary' } block size="lg" iconRight="arrow-right">
				Talk to us
			</Button>
		);
	} else if ( current ) {
		cta = (
			<Button href={ config.dashboardUrl } variant={ hero ? 'light' : 'secondary' } block size="lg" icon="check">
				Your current plan
			</Button>
		);
	} else if ( ! plan.available ) {
		cta = (
			<Button variant="secondary" block size="lg" disabled>
				Not available in { currency }
			</Button>
		);
	} else {
		const label = plan.is_free ? 'Join free' : loggedIn && livePaid ? 'Switch plan' : `Join ${ plan.name }`;
		cta = (
			<Button variant={ hero ? 'light' : plan.is_free ? 'secondary' : 'primary' } block size="lg" onClick={ onJoin } loading={ busy } iconRight="arrow-right">
				{ label }
			</Button>
		);
	}

	return (
		<article className={ cx( 'wvm-plan', hero && 'wvm-plan--hero', focused && 'is-focused', ! plan.available && ! plan.is_quoted && 'is-unavailable' ) } aria-label={ `${ plan.name } plan` }>
			<div className="wvm-plan__top">
				<h3 className="wvm-plan__name">
					{ plan.name }
					{ ! plan.is_quoted && <span className="wvm-plan__name-suffix"> Membership</span> }
				</h3>
				{ hero && <Chip tone="primary" className="wvm-plan__badge" icon="star">Most popular</Chip> }
				{ current && ! hero && <Chip tone="success">Current</Chip> }
			</div>
			<p className="wvm-plan__tagline" aria-hidden={ plan.tagline ? undefined : true }>{ plan.tagline }</p>
			{ plan.description && <p className="wvm-plan__desc">{ plan.description }</p> }
			<div className="wvm-plan__price">
				{ plan.is_quoted ? (
					<span className="wvm-money wvm-money--lg"><span className="wvm-money__amt wvm-money__amt--word">Custom</span></span>
				) : plan.available ? (
					<Money display={ plan.amount_display } size="xl" />
				) : (
					<span className="wvm-plan__na">Not available in { currency }</span>
				) }
				<span className="wvm-plan__interval">{ plan.is_quoted ? 'tailored quote' : interval }</span>
			</div>
			{ cta }
			{ plan.benefits.length > 0 && <p className="wvm-plan__included">What's included:</p> }
			{ plan.benefits.length > 0 && (
				<ul className="wvm-plan__benefits">
					{ plan.benefits.map( ( b ) => (
						<li key={ b }>
							<span className="wvm-plan__tick" aria-hidden="true"><Icon name="check" size={ 13 } strokeWidth={ 3 } /></span>
							{ b }
						</li>
					) ) }
				</ul>
			) }
			{ plan.trial_days > 0 && ! plan.is_free && <p className="wvm-plan__note">{ plan.trial_days }-day free trial</p> }
		</article>
	);
}
