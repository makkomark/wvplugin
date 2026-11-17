/* State-driven banner + primary action for the Membership tab. */
import type { ReactNode } from 'react';
import { Banner } from '../../../components/Feedback';
import { Button } from '../../../components/Button';
import { useDashboard, isOrgCovered } from '../context';
import { usePayNow, useResume } from '../actions';
import type { Tone } from '../../../../shared/format';
import type { IconName } from '../../../components/Icon';

export function StatusBanner() {
	const { overview, navigate } = useDashboard();
	const { pay, busy: paying } = usePayNow();
	const { resume, busy: resuming } = useResume();
	const sub = overview.subscription;
	const order = overview.pending_order;
	const planName = sub?.plan.name || order?.plan_name || 'your plan';

	let tone: Tone = 'info';
	let title: ReactNode = null;
	let text: ReactNode = null;
	let icon: IconName | undefined;
	let action: ReactNode = null;

	if ( isOrgCovered( overview ) && overview.organisation && [ 'none', 'free', 'expired', 'ended', 'cancelled' ].includes( overview.state ) ) {
		return (
			<Banner
				tone="primary"
				icon="building"
				title={ `Your access is provided by ${ overview.organisation.name }` }
				actions={ <Button size="sm" onClick={ () => navigate( 'programme' ) } iconRight="arrow-right">Open programme</Button> }
				className="wvm-status-banner"
			>
				You have { overview.level_label } access through { overview.organisation.programme_title || 'your organisation' } — nothing to pay.
			</Banner>
		);
	}

	switch ( overview.state ) {
		case 'none':
			if ( order?.checkout_url ) {
				tone = 'warning';
				title = `Finish joining ${ order.plan_name }`;
				text = <>Your order for <strong>{ order.amount_display }</strong> is waiting for payment.</>;
				action = <Button size="sm" href={ order.checkout_url } iconRight="arrow-right">Finish payment</Button>;
			} else {
				tone = 'primary';
				icon = 'sparkles';
				title = 'Choose a plan to get started';
				text = 'Unlock lessons, live workshops and member-only resources.';
				action = <Button size="sm" onClick={ () => navigate( 'billing' ) } iconRight="arrow-right">Choose a plan</Button>;
			}
			break;
		case 'free':
			tone = 'primary';
			icon = 'sparkles';
			title = "You're on the Free plan";
			text = 'Upgrade to join live workshops, watch recordings and get every lesson.';
			action = <Button size="sm" onClick={ () => navigate( 'billing' ) } iconRight="arrow-right">Choose a plan</Button>;
			if ( order?.checkout_url ) {
				tone = 'warning';
				icon = undefined;
				title = `Finish upgrading to ${ order.plan_name }`;
				text = <>Your order for <strong>{ order.amount_display }</strong> is waiting for payment.</>;
				action = <Button size="sm" href={ order.checkout_url } iconRight="arrow-right">Finish payment</Button>;
			}
			break;
		case 'pending':
			tone = 'warning';
			title = `Finish payment to activate ${ planName }`;
			text = order ? <>We're holding your order for <strong>{ order.amount_display }</strong>.</> : 'Your membership starts as soon as payment is confirmed.';
			action = order?.checkout_url ? <Button size="sm" href={ order.checkout_url } iconRight="arrow-right">Finish payment</Button> : <Button size="sm" onClick={ pay } loading={ paying }>Pay now</Button>;
			break;
		case 'cancelling':
			tone = 'warning';
			icon = 'clock';
			title = `Your membership ends on ${ sub?.period_end_display }`;
			text = "You'll keep full access until then. Changed your mind?";
			action = <Button size="sm" variant="secondary" onClick={ resume } loading={ resuming } icon="refresh">Resume membership</Button>;
			break;
		case 'past_due':
			tone = 'warning';
			title = 'Your renewal payment is due';
			text = <>Pay <strong>{ sub?.amount_display }</strong> by <strong>{ sub?.grace_ends_display }</strong> to keep your access.</>;
			action = <Button size="sm" onClick={ pay } loading={ paying } iconRight="arrow-right">Pay now</Button>;
			break;
		case 'suspended':
			tone = 'danger';
			title = 'Your membership is paused';
			text = <>We couldn't collect your renewal, so access to { planName } is on hold. Reactivate in a minute with Mobile Money or card.</>;
			action = <Button size="sm" variant="danger" onClick={ pay } loading={ paying } iconRight="arrow-right">Reactivate</Button>;
			break;
		case 'expired':
			tone = 'neutral';
			icon = 'clock';
			title = 'Your membership has expired';
			text = sub?.can_rejoin ? <>Rejoin { planName } and pick up where you left off.</> : 'Choose a plan to come back.';
			action = sub?.can_rejoin ? <Button size="sm" onClick={ pay } loading={ paying }>Rejoin</Button> : <Button size="sm" onClick={ () => navigate( 'billing' ) }>Choose a plan</Button>;
			break;
		case 'ended':
		case 'cancelled':
			tone = 'neutral';
			icon = 'clock';
			title = 'Your membership has ended';
			text = <>We'd love to have you back. Rejoin { planName } or pick another plan.</>;
			action = (
				<>
					<Button size="sm" onClick={ pay } loading={ paying }>Rejoin { planName }</Button>
					<Button size="sm" variant="ghost" onClick={ () => navigate( 'billing' ) }>Other plans</Button>
				</>
			);
			break;
		case 'active':
		default:
			if ( sub?.pending_plan ) {
				tone = 'info';
				title = `You'll move to ${ sub.pending_plan.name } on ${ sub.period_end_display }`;
				text = 'Your current plan and benefits continue until then.';
			} else if ( order && order.checkout_url && ( order.type === 'upgrade' || order.type === 'new' ) && order.plan_id !== sub?.plan_id ) {
				tone = 'info';
				icon = 'zap';
				title = `Your upgrade to ${ order.plan_name } is waiting for payment`;
				text = <>Pay <strong>{ order.amount_display }</strong> to switch now.</>;
				action = <Button size="sm" variant="secondary" href={ order.checkout_url }>Complete upgrade</Button>;
			} else {
				return null;
			}
	}

	return (
		<Banner tone={ tone } icon={ icon } title={ title } actions={ action } className="wvm-status-banner">
			{ text }
		</Banner>
	);
}
