/* Membership tab (#/): greeting, status banner, plan / renewal / benefits / next-workshop cards. */
import { useState } from '@wordpress/element';
import { intervalLabel, STATUS_LABELS } from '../../../../shared/format';
import { formatDate } from '../../../../shared/format';
import { firstName, formatWhen, formatTime, zoneLabel, cx } from '../../../lib/util';
import { Card, CardHeader, Chip } from '../../../components/Card';
import { Button } from '../../../components/Button';
import { Icon } from '../../../components/Icon';
import { ProgressRing, EmptyState } from '../../../components/Feedback';
import { Money } from '../../../components/Brand';
import { Countdown } from '../../../components/Countdown';
import { useToast } from '../../../components/Toast';
import { useDashboard, useTz, nextPlanUp, isOrgCovered, orgStatus, orgTermEnd } from '../context';
import { StatusBanner } from './StatusBanner';
import { usePayNow } from '../actions';

function greeting(): string {
	const h = new Date().getHours();
	return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
}

export function MembershipTab() {
	const { overview } = useDashboard();
	return (
		<div className="wvm-page">
			<div className="wvm-page__head">
				<div>
					<h1 className="wvm-h1">{ greeting() }, { firstName( overview.member.name ) || 'there' }</h1>
					<p className="wvm-muted">Here's everything about your membership in one place.</p>
				</div>
			</div>
			<StatusBanner />
			<div className="wvm-grid">
				<PlanCard />
				<RenewalCard />
				<BenefitsCard />
				<NextWorkshopCard />
			</div>
		</div>
	);
}

function PlanCard() {
	const { overview, navigate } = useDashboard();
	const toast = useToast();
	const [ copied, setCopied ] = useState( false );
	const sub = overview.subscription;
	const live = sub && [ 'active', 'past_due', 'pending' ].includes( sub.status );
	const org = isOrgCovered( overview ) ? overview.organisation : null;
	const stateLabel = org ? STATUS_LABELS[ orgStatus( overview ) ] || 'Active' : overview.state === 'ended' ? 'Ended' : STATUS_LABELS[ overview.state ] || overview.state;

	const copy = async () => {
		try {
			await navigator.clipboard.writeText( overview.member.public_id );
			setCopied( true );
			window.setTimeout( () => setCopied( false ), 1600 );
		} catch ( e ) {
			toast.info( `Member ID: ${ overview.member.public_id }` );
		}
	};

	return (
		<Card variant="hero" pad="lg" className="wvm-plancard">
			<div className="wvm-plancard__glow" aria-hidden="true" />
			<div className="wvm-plancard__top">
				<span className="wvm-plancard__eyebrow">Your plan</span>
				<span className={ cx( 'wvm-plancard__state', `is-${ org ? orgStatus( overview ) : overview.state }` ) }>
					<span className="wvm-plancard__dot" aria-hidden="true" />
					{ stateLabel }
				</span>
			</div>
			<h2 className="wvm-plancard__name">{ org ? org.programme_title || overview.level_label : sub ? sub.plan.name : 'No plan yet' }</h2>
			{ org ? (
				<p className="wvm-plancard__price">
					<span className="wvm-plancard__free">Covered by { org.name }</span>
				</p>
			) : sub && ! sub.plan.is_free ? (
				<p className="wvm-plancard__price">
					<Money display={ sub.amount_display } size="lg" />
					<span className="wvm-plancard__interval">{ sub.organisation_id ? 'covered by your organisation' : intervalLabel( sub.plan.interval ) }</span>
				</p>
			) : (
				<p className="wvm-plancard__price">
					<span className="wvm-plancard__free">{ sub ? 'Free forever' : 'Pick a plan to unlock everything' }</span>
				</p>
			) }
			<div className="wvm-plancard__meta">
				<button type="button" className="wvm-plancard__id" onClick={ copy } aria-label={ `Member ID ${ overview.member.public_id }. Copy` }>
					<span className="wvm-plancard__metalabel">Member ID</span>
					<span className="wvm-plancard__metavalue wvm-mono">
						{ overview.member.public_id }
						<Icon name={ copied ? 'check' : 'copy' } size={ 14 } />
					</span>
				</button>
				<div>
					<span className="wvm-plancard__metalabel">Access</span>
					<span className="wvm-plancard__metavalue">{ overview.level_label }</span>
				</div>
			</div>
			<div className="wvm-plancard__actions">
				{ org ? (
					<Button variant="light" size="sm" onClick={ () => navigate( 'programme' ) } iconRight="arrow-right">Open programme</Button>
				) : live && ! sub?.plan.is_free ? (
					<Button variant="light" size="sm" onClick={ () => navigate( 'billing' ) } iconRight="arrow-right">Manage plan</Button>
				) : (
					<Button variant="light" size="sm" onClick={ () => navigate( 'billing' ) } iconRight="arrow-right">{ sub && ! sub.plan.is_free ? 'Rejoin or change plan' : 'Choose a plan' }</Button>
				) }
			</div>
		</Card>
	);
}

function RenewalCard() {
	const { overview, navigate } = useDashboard();
	const { pay, busy } = usePayNow();
	const sub = overview.subscription;
	const method = overview.payment_methods.find( ( m ) => m.is_default ) || overview.payment_methods[ 0 ];

	if ( isOrgCovered( overview ) && overview.organisation ) {
		const termEnd = orgTermEnd( overview );
		const end = termEnd ? new Date( termEnd ).getTime() : 0;
		const days = end ? Math.max( 0, Math.ceil( ( end - Date.now() ) / 86400000 ) ) : 0;
		return (
			<Card pad="lg" className="wvm-renewal">
				<CardHeader icon="refresh" title="Programme term" subtitle={ `Sponsored by ${ overview.organisation.name }` } />
				<div className="wvm-renewal__row">
					<ProgressRing value={ Math.min( 1, days / 365 ) } size={ 104 } label={ `${ days } days left` }>
						<span className="wvm-ring__num">{ days }</span>
						<span className="wvm-ring__lbl">{ days === 1 ? 'day left' : 'days left' }</span>
					</ProgressRing>
					<div className="wvm-renewal__info">
						<p className="wvm-statlabel">Term ends</p>
						<p className="wvm-stat">{ formatDate( termEnd ) }</p>
						{ orgStatus( overview ) !== 'active' && <Chip tone="warning">Access { ( STATUS_LABELS[ orgStatus( overview ) ] || orgStatus( overview ) ).toLowerCase() }</Chip> }
					</div>
				</div>
				<div className="wvm-renewal__method">
					<Icon name="building" size={ 18 } />
					<span>Covered by your organisation — nothing to pay.</span>
				</div>
			</Card>
		);
	}

	if ( ! sub || sub.plan.is_free ) {
		return (
			<Card pad="lg">
				<CardHeader icon="refresh" title="Renewal" subtitle={ sub ? 'The Free plan never expires.' : 'Nothing to renew yet.' } />
				<EmptyState compact icon="card" title="No payments due" action={ <Button size="sm" variant="secondary" onClick={ () => navigate( 'billing' ) }>See plans</Button> }>
					When you join a paid plan, your renewal date and payment method will show here.
				</EmptyState>
			</Card>
		);
	}

	const start = sub.current_period_start ? new Date( sub.current_period_start ).getTime() : 0;
	const end = sub.current_period_end ? new Date( sub.current_period_end ).getTime() : 0;
	const periodDays = start && end ? Math.max( 1, Math.round( ( end - start ) / 86400000 ) ) : 30;
	const daysLeft = Math.max( 0, sub.days_left ?? 0 );
	const graceDays = sub.grace_ends_at ? Math.max( 0, Math.ceil( ( new Date( sub.grace_ends_at ).getTime() - Date.now() ) / 86400000 ) ) : 0;

	let title = 'Renews on';
	let date = sub.period_end_display;
	let ringValue = daysLeft / periodDays;
	let ringTone: 'primary' | 'warning' | 'danger' = 'primary';
	let ringNum = daysLeft;
	let ringLabel = daysLeft === 1 ? 'day left' : 'days left';
	let foot = null;

	switch ( overview.state ) {
		case 'cancelling':
			title = 'Access ends on';
			ringTone = 'warning';
			break;
		case 'past_due':
			title = 'Grace period ends';
			date = sub.grace_ends_display || sub.period_end_display;
			ringTone = 'warning';
			ringNum = graceDays;
			ringValue = graceDays / 7;
			ringLabel = graceDays === 1 ? 'day to pay' : 'days to pay';
			foot = <Button size="sm" onClick={ pay } loading={ busy } block>Pay { sub.amount_display } now</Button>;
			break;
		case 'suspended':
			title = 'Paused since';
			date = formatDate( sub.suspended_at );
			ringTone = 'danger';
			ringNum = 0;
			ringValue = 0;
			ringLabel = 'access paused';
			foot = <Button size="sm" variant="danger" onClick={ pay } loading={ busy } block>Reactivate</Button>;
			break;
		case 'expired':
		case 'ended':
		case 'cancelled':
			title = 'Ended on';
			date = formatDate( sub.expires_at || sub.cancelled_at || sub.current_period_end );
			ringNum = 0;
			ringValue = 0;
			ringLabel = 'no access';
			break;
		case 'pending':
			title = 'Starts when paid';
			date = '—';
			ringNum = 0;
			ringValue = 0;
			ringLabel = 'pending';
			break;
	}
	if ( sub.organisation_id && overview.organisation?.term_end ) {
		title = 'Programme term ends';
		date = formatDate( overview.organisation.term_end );
	}

	return (
		<Card pad="lg" className="wvm-renewal">
			<CardHeader icon="refresh" title="Renewal" subtitle={ sub.organisation_id ? `Sponsored by ${ overview.organisation?.name || 'your organisation' }` : undefined } />
			<div className="wvm-renewal__row">
				<ProgressRing value={ ringValue } tone={ ringTone } size={ 104 } label={ `${ ringNum } ${ ringLabel }` }>
					<span className="wvm-ring__num">{ ringNum }</span>
					<span className="wvm-ring__lbl">{ ringLabel }</span>
				</ProgressRing>
				<div className="wvm-renewal__info">
					<p className="wvm-statlabel">{ title }</p>
					<p className="wvm-stat">{ date }</p>
					{ overview.state === 'active' && sub.pending_plan && (
						<Chip tone="info" icon="zap">Switching to { sub.pending_plan.name }</Chip>
					) }
				</div>
			</div>
			{ [ 'active', 'past_due', 'cancelling', 'suspended' ].includes( overview.state ) && (
				<div className="wvm-renewal__method">
					{ sub.organisation_id ? (
						<>
							<Icon name="building" size={ 18 } />
							<span>Covered by your organisation — nothing to pay.</span>
						</>
					) : overview.state === 'cancelling' ? (
						<>
							<Icon name="info" size={ 18 } />
							<span>Your plan won't renew. Resume any time before it ends to keep your benefits.</span>
						</>
					) : overview.state === 'suspended' ? (
						<>
							<Icon name="lock" size={ 18 } />
							<span>Reactivate to restore access straight away — your next period starts from your last renewal date.</span>
						</>
					) : method ? (
						<>
							<Icon name="card" size={ 18 } />
							<span>
								Auto-renews with <strong>{ method.label }</strong>
							</span>
						</>
					) : (
						<>
							<Icon name="mail" size={ 18 } />
							<span>Manual renewal — we'll email you a pay link before your renewal date.</span>
						</>
					) }
				</div>
			) }
			{ foot && <div className="wvm-mt-4">{ foot }</div> }
		</Card>
	);
}

function BenefitsCard() {
	const { overview, navigate } = useDashboard();
	const sub = overview.subscription;
	const live = sub && [ 'active', 'past_due' ].includes( sub.status );
	const orgPlan = isOrgCovered( overview ) ? overview.plans.find( ( p ) => p.level === overview.entitlements.level ) : undefined;
	const current = orgPlan ? orgPlan.benefits : live ? sub.plan.benefits : overview.plans.find( ( p ) => p.is_free )?.benefits || [];
	const next = nextPlanUp( overview );
	const extra = next ? next.benefits.filter( ( b ) => ! current.includes( b ) && ! /^everything in/i.test( b ) ).slice( 0, 3 ) : [];

	return (
		<Card pad="lg" className="wvm-benefits">
			<CardHeader icon="sparkles" title="Your benefits" subtitle={ orgPlan ? `Included with ${ orgPlan.name }` : live ? `Included with ${ sub.plan.name }` : sub?.plan.is_free ? 'Included with Free' : 'What you get with Free' } />
			{ current.length > 0 ? (
				<ul className="wvm-checklist">
					{ current.map( ( b ) => (
						<li key={ b }>
							<span className="wvm-checklist__tick" aria-hidden="true"><Icon name="check" size={ 13 } strokeWidth={ 3 } /></span>
							{ b }
						</li>
					) ) }
				</ul>
			) : (
				<p className="wvm-muted">Choose a plan to see your benefits.</p>
			) }
			{ next && (
				<div className="wvm-upsell">
					<div className="wvm-upsell__head">
						<span className="wvm-upsell__icon" aria-hidden="true"><Icon name="zap" size={ 16 } /></span>
						<div>
							<p className="wvm-upsell__title">Get more with { next.name }</p>
							<p className="wvm-upsell__price">{ next.amount_display } { intervalLabel( next.interval, next.interval_count ) }</p>
						</div>
					</div>
					{ extra.length > 0 && (
						<ul className="wvm-upsell__list">
							{ extra.map( ( b ) => (
								<li key={ b }><Icon name="plus" size={ 14 } /> { b }</li>
							) ) }
						</ul>
					) }
					<Button size="sm" variant="primary" onClick={ () => navigate( 'billing' ) } iconRight="arrow-right">
						Upgrade to { next.name }
					</Button>
				</div>
			) }
		</Card>
	);
}

function NextWorkshopCard() {
	const { overview, navigate } = useDashboard();
	const tz = useTz();
	const w = overview.next_workshop;
	if ( ! w ) {
		const paid = overview.entitlements.level > 0;
		return (
			<Card pad="lg">
				<CardHeader icon="calendar" title="Next workshop" />
				<EmptyState
					compact
					icon="calendar"
					title={ paid ? 'No workshops scheduled yet' : 'Live workshops are for members' }
					action={ <Button size="sm" variant="secondary" onClick={ () => navigate( paid ? 'workshops' : 'billing' ) }>{ paid ? 'See past recordings' : 'See plans' }</Button> }
				>
					{ paid ? "We'll notify you as soon as the next session is announced." : 'Join Basic or Premium to take part in monthly live sessions.' }
				</EmptyState>
			</Card>
		);
	}
	return (
		<Card pad="lg" className="wvm-nextws">
			<CardHeader icon="calendar" title="Next workshop" action={ <Chip tone={ w.registered ? 'success' : 'primary' }>{ w.registered ? 'Registered' : w.required_level_label }</Chip> } />
			<h4 className="wvm-nextws__title">{ w.title }</h4>
			<p className="wvm-nextws__when">
				<Icon name="clock" size={ 16 } /> { formatWhen( w.starts_at, tz ) } – { formatTime( w.ends_at, tz ) } <span className="wvm-subtle">{ zoneLabel( tz ) }</span>
			</p>
			{ w.host && <p className="wvm-nextws__host">with { w.host }</p> }
			<div className="wvm-mt-4">
				<Countdown to={ w.starts_at } endsAt={ w.ends_at } />
			</div>
			<div className="wvm-mt-4">
				<Button size="sm" variant="secondary" onClick={ () => navigate( 'workshops' ) } iconRight="arrow-right">
					{ w.registered ? 'View details' : 'Register' }
				</Button>
			</div>
		</Card>
	);
}
