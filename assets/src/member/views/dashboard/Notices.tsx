/* One-off notices driven by query params read on load: welcome, pending poller, invite, pay link, email confirm. */
import { useEffect, useRef, useState } from '@wordpress/element';
import { api, errorMessage, go } from '../../lib/api';
import { config } from '../../lib/config';
import { firstName } from '../../lib/util';
import { Banner } from '../../components/Feedback';
import { Button } from '../../components/Button';
import { Icon } from '../../components/Icon';
import { useDashboard } from './context';
import { InviteView } from './InviteView';

export interface BootParams {
	welcome: boolean;
	pending: string | null;
	invite: string | null;
	pay: string | null;
	emailToken: string | null;
}

export function Notices( { params }: { params: BootParams } ) {
	const { overview, reload, navigate } = useDashboard();
	const [ welcome, setWelcome ] = useState( params.welcome );
	const [ pending, setPending ] = useState( params.pending );
	const [ invite, setInvite ] = useState( params.invite );
	const [ pay, setPay ] = useState( params.pay );
	const [ email, setEmail ] = useState< { tone: 'success' | 'danger'; text: string } | null >( null );
	const emailRan = useRef( false );

	useEffect( () => {
		if ( ! params.emailToken || emailRan.current ) {
			return;
		}
		emailRan.current = true;
		api.post( 'me/email-change/confirm', { token: params.emailToken } )
			.then( () => {
				setEmail( { tone: 'success', text: 'Your email address has been updated.' } );
				reload();
			} )
			.catch( ( e ) => setEmail( { tone: 'danger', text: errorMessage( e, 'This verification link is invalid or has expired.' ) } ) );
	}, [ params.emailToken, reload ] );

	const plan = overview.subscription?.plan.name;

	return (
		<>
			{ invite && (
				<div className="wvm-mb-6">
					<InviteView
						token={ invite }
						onDone={ () => {
							setInvite( null );
							reload().then( () => navigate( 'programme' ) );
						} }
					/>
				</div>
			) }
			{ pending && (
				<PendingPaymentPoller
					token={ pending }
					onPaid={ () => {
						setPending( null );
						setWelcome( true );
						reload();
					} }
					onDismiss={ () => setPending( null ) }
				/>
			) }
			{ welcome && (
				<div className="wvm-welcome" role="status">
					<span className="wvm-welcome__icon" aria-hidden="true"><Icon name="check" size={ 22 } strokeWidth={ 2.8 } /></span>
					<div className="wvm-welcome__body">
						<p className="wvm-welcome__title">You're in{ firstName( overview.member.name ) ? `, ${ firstName( overview.member.name ) }` : '' }!</p>
						<p className="wvm-welcome__text">
							{ plan ? <>Welcome to <strong>{ plan }</strong>. </> : null }Your membership is active — check your inbox for your receipt and first lesson.
						</p>
					</div>
					<button type="button" className="wvm-banner__close" onClick={ () => setWelcome( false ) } aria-label="Dismiss">
						<Icon name="x" size={ 16 } />
					</button>
				</div>
			) }
			{ pay && (
				<Banner
					tone="info"
					className="wvm-mb-6"
					onDismiss={ () => setPay( null ) }
					title={ pay === 'closed' ? 'That payment link is closed' : 'That payment link is no longer valid' }
					actions={ overview.pending_order?.checkout_url ? <Button size="sm" href={ overview.pending_order.checkout_url }>Pay now</Button> : undefined }
				>
					{ pay === 'closed' ? 'The order was already completed or cancelled. Your current membership is shown below.' : 'It may have expired. You can pay securely from your dashboard instead.' }
				</Banner>
			) }
			{ email && (
				<Banner tone={ email.tone } className="wvm-mb-6" onDismiss={ () => setEmail( null ) }>
					{ email.text }
				</Banner>
			) }
		</>
	);
}

const FAST_MS = 5000;
const SLOW_MS = 10000;
const SLOW_AFTER_MS = 2 * 60 * 1000;
const GIVE_UP_MS = 10 * 60 * 1000;

/** Polls GET /public/orders/{token}/status until paid (5s → 10s back-off, stops after ~10 min). */
export function PendingPaymentPoller( { token, onPaid, onDismiss }: { token: string; onPaid: () => void; onDismiss: () => void } ) {
	const { overview } = useDashboard();
	const [ phase, setPhase ] = useState< 'waiting' | 'slow' | 'failed' | 'timeout' >( 'waiting' );
	const [ checks, setChecks ] = useState( 0 );
	const started = useRef( Date.now() );
	const timer = useRef< number | undefined >();
	const paidRef = useRef( onPaid );
	paidRef.current = onPaid;

	const poll = async () => {
		const elapsed = Date.now() - started.current;
		try {
			const { data } = await api.get< { status: string; paid: boolean } >( `public/orders/${ encodeURIComponent( token ) }/status` );
			setChecks( ( c ) => c + 1 );
			if ( data.paid ) {
				paidRef.current();
				return;
			}
			if ( data.status === 'failed' || data.status === 'cancelled' ) {
				setPhase( 'failed' );
				return;
			}
		} catch ( e ) {
			// Network blip or unknown token: keep trying until the time limit.
		}
		if ( elapsed >= GIVE_UP_MS ) {
			setPhase( 'timeout' );
			return;
		}
		if ( elapsed >= SLOW_AFTER_MS ) {
			setPhase( 'slow' );
		}
		timer.current = window.setTimeout( poll, elapsed >= SLOW_AFTER_MS ? SLOW_MS : FAST_MS );
	};

	useEffect( () => {
		poll();
		return () => window.clearTimeout( timer.current );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ token ] );

	const restart = () => {
		started.current = Date.now();
		setPhase( 'waiting' );
		window.clearTimeout( timer.current );
		poll();
	};

	const checkout = overview.pending_order?.checkout_url;

	if ( phase === 'failed' ) {
		return (
			<Banner
				tone="danger"
				className="wvm-mb-6"
				title="Your payment didn't go through"
				onDismiss={ onDismiss }
				actions={ checkout ? <Button size="sm" href={ checkout }>Try again</Button> : <Button size="sm" onClick={ () => go( `#/billing` ) }>Go to billing</Button> }
			>
				No money was taken. You can try again with Mobile Money or a card.
			</Banner>
		);
	}

	if ( phase === 'timeout' ) {
		return (
			<Banner
				tone="warning"
				className="wvm-mb-6"
				title="We're still waiting for your payment provider"
				onDismiss={ onDismiss }
				actions={ <Button size="sm" variant="secondary" icon="refresh" onClick={ restart }>Check again</Button> }
			>
				If you approved the payment, it can take a little longer to confirm — we'll email you as soon as it does.{ config.supportEmail && <> Need help? <a className="wvm-link" href={ `mailto:${ config.supportEmail }` }>{ config.supportEmail }</a></> }
			</Banner>
		);
	}

	return (
		<div className="wvm-poller" role="status" aria-live="polite">
			<div className="wvm-poller__pulse" aria-hidden="true">
				<span /><span /><span />
				<Icon name="phone" size={ 22 } />
			</div>
			<div className="wvm-poller__body">
				<p className="wvm-poller__title">Confirming your payment…</p>
				<p className="wvm-poller__text">
					{ phase === 'slow'
						? 'This is taking a little longer than usual. If you paid with Mobile Money, make sure you approved the prompt on your phone.'
						: 'Approve the prompt on your phone if asked. Keep this page open — it updates automatically.' }
				</p>
				<p className="wvm-poller__meta">
					<span className="wvm-poller__dot" /> Checking{ checks > 0 ? ` · ${ checks } ${ checks === 1 ? 'check' : 'checks' }` : '' }
				</p>
			</div>
			{ checkout && (
				<Button size="sm" variant="ghost" href={ checkout }>Back to payment</Button>
			) }
		</div>
	);
}
