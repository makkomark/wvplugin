/* Manage plan: current plan summary, upgrade (prorated preview), downgrade at renewal, cancel with reason, resume. */
import { useEffect, useState } from '@wordpress/element';
import type { PublicPlan, UpgradePreview } from '../../../../shared/types';
import { intervalLabel } from '../../../../shared/format';
import { api, errorMessage, go } from '../../../lib/api';
import { config } from '../../../lib/config';
import { cx } from '../../../lib/util';
import { Card, Chip, SectionTitle, StatusChip } from '../../../components/Card';
import { Button } from '../../../components/Button';
import { Modal } from '../../../components/Modal';
import { Banner, Skeleton } from '../../../components/Feedback';
import { Select, Textarea } from '../../../components/Field';
import { Icon } from '../../../components/Icon';
import { Money } from '../../../components/Brand';
import { useToast } from '../../../components/Toast';
import { useDashboard, isLivePaid, isOrgCovered } from '../context';
import { useResume, usePayNow } from '../actions';

const REASONS = [ 'Too expensive', 'Not using it', 'Found an alternative', 'Technical problems', 'Other' ];

export function ManagePlan() {
	const { overview, reload } = useDashboard();
	const toast = useToast();
	const { resume, busy: resuming } = useResume();
	const { pay, busy: paying } = usePayNow();
	const [ upgrade, setUpgrade ] = useState< PublicPlan | null >( null );
	const [ downgrade, setDowngrade ] = useState< PublicPlan | null >( null );
	const [ cancelOpen, setCancelOpen ] = useState( false );
	const [ joiningFree, setJoiningFree ] = useState( false );

	const sub = overview.subscription;
	const livePaid = isLivePaid( overview );
	const hasAccess = !! sub && [ 'active', 'past_due' ].includes( sub.status );
	const currentPlanId = hasAccess ? sub.plan_id : null;
	const sponsored = !! sub?.organisation_id;
	const currency = livePaid && sub ? sub.currency : overview.currency;

	const joinFree = async () => {
		setJoiningFree( true );
		try {
			await api.post( 'me/subscription/join-free' );
			await reload();
			toast.success( "You're on the Free plan." );
		} catch ( e ) {
			toast.error( errorMessage( e ) );
		} finally {
			setJoiningFree( false );
		}
	};

	const plans = overview.plans;

	return (
		<section className="wvm-section" id="manage-plan">
			<SectionTitle title={ hasAccess ? 'Your plan' : 'Choose a plan' } subtitle={ hasAccess ? undefined : 'Pick the plan that fits you. You can change or cancel any time.' } />

			{ isOrgCovered( overview ) && overview.organisation && (
				<Banner tone="primary" icon="building" title={ `Covered by ${ overview.organisation.name }` }>
					Your { overview.level_label } access comes through your organisation's programme, so you don't need a personal plan.
				</Banner>
			) }

			{ sub && (
				<Card pad="lg" className="wvm-current">
					<div className="wvm-current__main">
						<span className="wvm-current__icon" aria-hidden="true"><Icon name="star" size={ 22 } /></span>
						<div>
							<div className="wvm-row wvm-row--tight">
								<p className="wvm-h3">{ sub.plan.name }</p>
								<StatusChip status={ overview.state === 'ended' ? 'expired' : overview.state } />
							</div>
							<p className="wvm-muted wvm-small">
								{ sub.plan.is_free
									? 'Free forever'
									: sponsored
									? `Provided by ${ overview.organisation?.name || 'your organisation' }`
									: (
										<>
											<span className="wvm-num">{ sub.amount_display }</span> { intervalLabel( sub.plan.interval ) }
											{ hasAccess && sub.period_end_display && <> · { overview.state === 'cancelling' ? 'ends' : sub.status === 'past_due' ? 'was due' : 'renews' } { sub.period_end_display }</> }
										</>
									) }
							</p>
						</div>
					</div>
					<div className="wvm-current__actions">
						{ overview.state === 'cancelling' && <Button size="sm" onClick={ resume } loading={ resuming } icon="refresh">Resume</Button> }
						{ ( overview.state === 'past_due' || overview.state === 'suspended' ) && (
							<Button size="sm" variant={ overview.state === 'suspended' ? 'danger' : 'primary' } onClick={ pay } loading={ paying }>
								{ overview.state === 'suspended' ? 'Reactivate' : 'Pay now' }
							</Button>
						) }
						{ ( overview.state === 'expired' || overview.state === 'ended' || overview.state === 'cancelled' ) && ! sub.plan.is_free && (
							<Button size="sm" onClick={ pay } loading={ paying }>Rejoin { sub.plan.name }</Button>
						) }
						{ livePaid && overview.state !== 'cancelling' && ! sponsored && (
							<Button size="sm" variant="ghost" onClick={ () => setCancelOpen( true ) }>Cancel membership</Button>
						) }
					</div>
					{ sub.pending_plan && (
						<Banner tone="info" className="wvm-mt-4" icon="zap">
							You'll switch to <strong>{ sub.pending_plan.name }</strong> on { sub.period_end_display }.
						</Banner>
					) }
				</Card>
			) }

			{ ! sponsored && (
				<div className="wvm-planpick">
					{ plans.map( ( p ) => {
						const isCurrent = p.id === currentPlanId;
						const isFreeCurrent = p.is_free && overview.state === 'free';
						let action;
						if ( p.is_quoted ) {
							action = <Button size="sm" variant="secondary" href={ config.enterpriseUrl } iconRight="arrow-right">Talk to us</Button>;
						} else if ( isCurrent || isFreeCurrent ) {
							action = <Chip tone="success" icon="check">Current plan</Chip>;
						} else if ( sub?.pending_plan?.id === p.id ) {
							action = <Chip tone="info" icon="clock">From { sub.period_end_display }</Chip>;
						} else if ( ! p.available ) {
							action = <span className="wvm-small wvm-muted">Not available in { currency }</span>;
						} else if ( livePaid && sub ) {
							const up = p.level > sub.plan.level || ( p.level === sub.plan.level && ( p.amount_minor || 0 ) > sub.amount_minor );
							action = up ? (
								<Button size="sm" onClick={ () => setUpgrade( p ) } iconRight="arrow-right">Upgrade</Button>
							) : (
								<Button size="sm" variant="secondary" onClick={ () => setDowngrade( p ) }>{ p.is_free ? 'Switch to Free' : 'Downgrade' }</Button>
							);
						} else if ( p.is_free ) {
							action = <Button size="sm" variant="secondary" onClick={ joinFree } loading={ joiningFree }>Join free</Button>;
						} else {
							action = <Button size="sm" onClick={ () => setUpgrade( p ) } iconRight="arrow-right">{ sub && sub.plan_id === p.id ? 'Rejoin' : 'Choose' }</Button>;
						}
						return (
							<div key={ p.id } className={ cx( 'wvm-planopt', ( isCurrent || isFreeCurrent ) && 'is-current', p.highlight && 'is-highlight' ) }>
								<div className="wvm-planopt__head">
									<p className="wvm-planopt__name">{ p.name }</p>
									{ p.highlight && ! isCurrent && <Chip tone="primary">Popular</Chip> }
								</div>
								<p className="wvm-planopt__price">
									{ p.is_quoted ? <span className="wvm-planopt__custom">Custom pricing</span> : p.is_free ? <span className="wvm-planopt__custom">No cost, ever</span> : p.available ? <><span className="wvm-num">{ p.amount_display }</span> <span className="wvm-muted wvm-small">{ intervalLabel( p.interval, p.interval_count ) }</span></> : <span className="wvm-muted">—</span> }
								</p>
								<ul className="wvm-planopt__benefits">
									{ p.benefits.slice( 0, 4 ).map( ( b ) => (
										<li key={ b }><Icon name="check" size={ 14 } strokeWidth={ 3 } /> { b }</li>
									) ) }
								</ul>
								<div className="wvm-planopt__action">{ action }</div>
							</div>
						);
					} ) }
				</div>
			) }

			{ upgrade && <UpgradeModal plan={ upgrade } currency={ currency } onClose={ () => setUpgrade( null ) } /> }
			{ downgrade && <DowngradeModal plan={ downgrade } onClose={ () => setDowngrade( null ) } /> }
			{ cancelOpen && <CancelModal onClose={ () => setCancelOpen( false ) } /> }
		</section>
	);
}

function UpgradeModal( { plan, currency, onClose }: { plan: PublicPlan; currency: string; onClose: () => void } ) {
	const { reload, overview } = useDashboard();
	const toast = useToast();
	const [ preview, setPreview ] = useState< UpgradePreview | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		let alive = true;
		api.get< UpgradePreview >( 'me/subscription/upgrade-preview', { plan_id: plan.id, currency } )
			.then( ( { data } ) => alive && setPreview( data ) )
			.catch( ( e ) => alive && setError( errorMessage( e ) ) );
		return () => {
			alive = false;
		};
	}, [ plan.id, currency ] );

	const confirm = async () => {
		setBusy( true );
		setError( null );
		try {
			const { data } = await api.post< { checkout_url?: string; redirect?: string } >( 'me/subscription/upgrade', { plan_id: plan.id, currency } );
			if ( data.checkout_url ) {
				go( data.checkout_url );
				return;
			}
			await reload();
			toast.success( `You're now on ${ plan.name }.` );
			onClose();
		} catch ( e ) {
			setError( errorMessage( e ) );
			setBusy( false );
		}
	};

	const prorated = preview?.mode === 'prorated';
	const isNew = ! overview.subscription || ! isLivePaid( overview );

	return (
		<Modal
			open
			onClose={ onClose }
			locked={ busy }
			title={ isNew ? `Join ${ plan.name }` : `Upgrade to ${ plan.name }` }
			description={ isNew ? 'Review and continue to secure payment.' : 'Get the new benefits straight away.' }
			icon={ <Icon name="zap" size={ 22 } /> }
			footer={
				<>
					<Button variant="ghost" onClick={ onClose } disabled={ busy }>Not now</Button>
					<Button onClick={ confirm } loading={ busy } disabled={ ! preview } iconRight="arrow-right">
						{ preview && preview.amount_minor > 0 ? `Pay ${ preview.amount_display }` : 'Confirm' }
					</Button>
				</>
			}
		>
			{ ! preview && ! error && (
				<div className="wvm-stack" aria-busy="true">
					<Skeleton h={ 88 } r={ 16 } />
					<Skeleton w="70%" h={ 12 } />
				</div>
			) }
			{ preview && (
				<div className="wvm-stack">
					<div className="wvm-quote">
						<div>
							<p className="wvm-quote__label">{ prorated ? 'Due today (prorated)' : 'Due today' }</p>
							<Money display={ preview.amount_display } size="lg" />
						</div>
						<span className="wvm-quote__icon" aria-hidden="true"><Icon name="card" size={ 22 } /></span>
					</div>
					{ prorated && (
						<dl className="wvm-dl">
							<div><dt>Days left in this period</dt><dd className="wvm-num">{ preview.days_remaining } of { preview.days_in_period }</dd></div>
							<div><dt>{ plan.name } price after that</dt><dd className="wvm-num">{ plan.amount_display } { intervalLabel( plan.interval, plan.interval_count ) }</dd></div>
						</dl>
					) }
					{ preview.note && <p className="wvm-muted wvm-small">{ preview.note }</p> }
					{ plan.benefits.length > 0 && (
						<ul className="wvm-checklist wvm-checklist--compact">
							{ plan.benefits.slice( 0, 5 ).map( ( b ) => (
								<li key={ b }><span className="wvm-checklist__tick" aria-hidden="true"><Icon name="check" size={ 12 } strokeWidth={ 3 } /></span>{ b }</li>
							) ) }
						</ul>
					) }
				</div>
			) }
			{ error && <Banner tone="danger" className="wvm-mt-4">{ error }</Banner> }
		</Modal>
	);
}

function DowngradeModal( { plan, onClose }: { plan: PublicPlan; onClose: () => void } ) {
	const { overview, reload } = useDashboard();
	const toast = useToast();
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const sub = overview.subscription;

	const confirm = async () => {
		setBusy( true );
		setError( null );
		try {
			await api.post( 'me/subscription/downgrade', { plan_id: plan.id } );
			await reload();
			toast.success( plan.is_free ? `Your paid plan ends on ${ sub?.period_end_display }.` : `You'll move to ${ plan.name } on ${ sub?.period_end_display }.` );
			onClose();
		} catch ( e ) {
			setError( errorMessage( e ) );
			setBusy( false );
		}
	};

	return (
		<Modal
			open
			onClose={ onClose }
			locked={ busy }
			size="sm"
			title={ plan.is_free ? 'Switch to Free?' : `Switch to ${ plan.name }?` }
			footer={
				<>
					<Button variant="ghost" onClick={ onClose } disabled={ busy }>Keep { sub?.plan.name }</Button>
					<Button variant="secondary" onClick={ confirm } loading={ busy }>Confirm switch</Button>
				</>
			}
		>
			<div className="wvm-prose">
				{ plan.is_free ? (
					<p>Your { sub?.plan.name } membership won't renew. You'll keep all its benefits until <strong>{ sub?.period_end_display }</strong>, then you'll lose access to paid content.</p>
				) : (
					<p>
						The change takes effect at your next renewal on <strong>{ sub?.period_end_display }</strong>. You'll keep your { sub?.plan.name } benefits until then, and pay <strong>{ plan.amount_display }</strong> { intervalLabel( plan.interval, plan.interval_count ) } after that.
					</p>
				) }
			</div>
			{ error && <Banner tone="danger" className="wvm-mt-4">{ error }</Banner> }
		</Modal>
	);
}

function CancelModal( { onClose }: { onClose: () => void } ) {
	const { overview, reload } = useDashboard();
	const toast = useToast();
	const [ reason, setReason ] = useState( '' );
	const [ other, setOther ] = useState( '' );
	const [ errors, setErrors ] = useState< Record< string, string > >( {} );
	const [ error, setError ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );
	const sub = overview.subscription;

	const confirm = async () => {
		const e: Record< string, string > = {};
		if ( ! reason ) {
			e.reason = 'Please choose a reason.';
		} else if ( reason === 'Other' && ! other.trim() ) {
			e.other = 'Tell us a little more.';
		}
		setErrors( e );
		if ( Object.keys( e ).length ) {
			return;
		}
		setBusy( true );
		setError( null );
		try {
			const text = reason === 'Other' ? `Other: ${ other.trim() }` : reason;
			await api.post( 'me/subscription/cancel', { reason: text.slice( 0, 100 ) } );
			await reload();
			toast.success( `Cancelled. You'll keep access until ${ sub?.period_end_display }.` );
			onClose();
		} catch ( err ) {
			setError( errorMessage( err ) );
			setBusy( false );
		}
	};

	return (
		<Modal
			open
			onClose={ onClose }
			locked={ busy }
			title="Cancel your membership?"
			description={ <>You'll keep full access until <strong>{ sub?.period_end_display }</strong>. You can resume any time before then.</> }
			footer={
				<>
					<Button variant="ghost" onClick={ onClose } disabled={ busy }>Keep membership</Button>
					<Button variant="danger" onClick={ confirm } loading={ busy }>Cancel membership</Button>
				</>
			}
		>
			<div className="wvm-stack">
				{ sub && sub.plan.benefits.length > 0 && (
					<div className="wvm-lose">
						<p className="wvm-lose__title">After { sub.period_end_display } you'll lose:</p>
						<ul>
							{ sub.plan.benefits.slice( 0, 4 ).map( ( b ) => (
								<li key={ b }><Icon name="x" size={ 14 } strokeWidth={ 2.6 } /> { b }</li>
							) ) }
						</ul>
					</div>
				) }
				<Select
					label="Why are you leaving?"
					value={ reason }
					onValue={ ( v ) => {
						setReason( v );
						setErrors( {} );
					} }
					placeholder="Choose a reason…"
					options={ REASONS.map( ( r ) => ( { value: r, label: r } ) ) }
					error={ errors.reason }
					required
				/>
				{ reason === 'Other' && <Textarea label="Tell us more" rows={ 3 } value={ other } onValue={ ( v ) => { setOther( v ); setErrors( {} ); } } error={ errors.other } maxLength={ 90 } /> }
				{ error && <Banner tone="danger">{ error }</Banner> }
			</div>
		</Modal>
	);
}
