/* Order detail slide-over with review / refund / cancel / replay actions. */
import { useEffect, useState } from 'react';
import { useApi } from '../hooks/useApi';
import { api } from '../lib/api';
import { can, gatewayTitle } from '../lib/config';
import type { Order } from '../../shared/types';
import { formatDateTime, relativeTime, formatDate } from '../../shared/format';
import { EntityDrawer, ReasonModal } from '../components/overlays';
import { Btn, StatusChip, DescList, A, Avatar, CopyButton, Loading, ErrorState, Notice2, Mono, Chip, Muted, ResultBlock } from '../components/ui';
import { MoneyInput, Checkbox } from '../components/fields';
import { useToast } from '../components/Toasts';

type ActionKey = 'approve' | 'reject' | 'refund' | 'cancel' | null;

export function OrderDrawer( { id, onClose, onChanged }: { id: number | null; onClose: () => void; onChanged?: () => void } ) {
	const toast = useToast();
	const res = useApi< Order >( id ? `orders/${ id }` : null );
	const [ action, setAction ] = useState< ActionKey >( null );
	const [ refundAmount, setRefundAmount ] = useState< number | null >( null );
	const [ endMembership, setEndMembership ] = useState( false );
	const [ replaying, setReplaying ] = useState( false );
	const [ replayResult, setReplayResult ] = useState< unknown >( null );
	const o = res.data && res.data.id === id ? res.data : undefined;
	const manage = can( 'wvm_manage_payments' );

	useEffect( () => {
		setReplayResult( null );
		setAction( null );
	}, [ id ] );

	const done = ( msg: string, next?: Order ) => {
		toast.success( msg );
		setAction( null );
		if ( next && next.id ) {
			res.setData( () => ( { ...o, ...next } as Order ) );
		}
		res.reload();
		onChanged?.();
	};

	const footer = o && manage && (
		<>
			{ o.status === 'needs_review' && (
				<>
					<Btn variant="primary" icon="check" onClick={ () => setAction( 'approve' ) }>
						Approve
					</Btn>
					<Btn destructive icon="x" onClick={ () => setAction( 'reject' ) }>
						Reject
					</Btn>
				</>
			) }
			{ o.status === 'paid' && (
				<Btn
					destructive
					icon="restore"
					onClick={ () => {
						setRefundAmount( o.amount_minor );
						setEndMembership( false );
						setAction( 'refund' );
					} }
				>
					Refund
				</Btn>
			) }
			{ ( o.status === 'pending' || o.status === 'failed' ) && (
				<Btn destructive icon="x" onClick={ () => setAction( 'cancel' ) }>
					Cancel order
				</Btn>
			) }
			{ o.woo_order_id && (
				<Btn
					icon="refresh"
					busy={ replaying }
					onClick={ async () => {
						setReplaying( true );
						try {
							const r = await api.post< { result: unknown; order: Order } >( `orders/${ o.id }/replay` );
							setReplayResult( r.data.result );
							toast.info( `Replay result: ${ typeof r.data.result === 'string' ? r.data.result : 'done' }` );
							res.setData( () => r.data.order );
							onChanged?.();
						} catch ( e ) {
							toast.error( e );
						} finally {
							setReplaying( false );
						}
					} }
				>
					Replay hook
				</Btn>
			) }
		</>
	);

	return (
		<>
			<EntityDrawer
				open={ !! id }
				onClose={ onClose }
				title={ o ? `Order #${ o.id }` : 'Order' }
				badges={ o && <StatusChip status={ o.status } /> }
				subtitle={ o ? `${ o.type_label } · ${ formatDate( o.created_at ) }` : undefined }
				footer={ footer || undefined }
				width={ 600 }
			>
				{ ! o && res.loading && <Loading /> }
				{ res.error && <ErrorState error={ res.error } onRetry={ res.reload } /> }
				{ o && (
					<div className="wvm-a-stack">
						<div className="wvm-a-orderhero">
							<div>
								<span>Amount</span>
								<strong>{ o.amount_display }</strong>
								<em>{ o.description }</em>
							</div>
							<div className="wvm-a-orderhero__links">
								{ o.woo_order_url && (
									<Btn size="compact" icon="external" href={ o.woo_order_url } target="_blank">
										Woo order #{ o.woo_order_id }
									</Btn>
								) }
								{ o.status === 'paid' && (
									<Btn size="compact" icon="receipt" href={ api.downloadUrl( `me/orders/${ o.id }/receipt.pdf` ) } target="_blank">
										Receipt
									</Btn>
								) }
							</div>
						</div>

						{ o.status === 'needs_review' && (
							<Notice2 tone="warning" title="Held for review">
								{ o.review_reason || 'This payment needs a manual check before the membership is activated.' }
							</Notice2>
						) }
						{ replayResult !== null && (
							<Notice2 tone="info" title="Replay finished">
								<ResultBlock data={ replayResult } />
							</Notice2>
						) }

						{ o.member && (
							<A to={ `/members/${ o.member.id }` } className="wvm-a-membercard">
								<Avatar name={ o.member.name } size={ 38 } />
								<div>
									<strong>{ o.member.name }</strong>
									<span>
										{ o.member.public_id } · { o.member.email }
									</span>
								</div>
							</A>
						) }

						<DescList
							items={ [
								[ 'Plan', o.plan_name ],
								[ 'Type', o.type_label ],
								[ 'Gateway', o.gateway ? gatewayTitle( o.gateway ) : null ],
								[ 'Provider ref', o.provider_ref ? <Mono>{ o.provider_ref }</Mono> : null ],
								[ 'Created', formatDateTime( o.created_at ) ],
								[ 'Paid', o.paid_at ? formatDateTime( o.paid_at ) : null ],
								[ 'Receipt', o.receipt_number ? <Mono>{ o.receipt_number }</Mono> : null ],
								[ 'Period', o.period_start ? `${ formatDate( o.period_start ) } → ${ formatDate( o.period_end ) }` : null ],
								o.reviewed_at && [ 'Reviewed', formatDateTime( o.reviewed_at ) ],
							] }
						/>

						{ o.pay_link && ( o.status === 'pending' || o.status === 'failed' ) && (
							<div className="wvm-a-paylink">
								<div>
									<span>Pay link</span>
									<Mono>{ o.pay_link }</Mono>
								</div>
								<CopyButton text={ o.pay_link } label="Copy link" />
							</div>
						) }

						<div className="wvm-a-subsection">
							<h4>Payments</h4>
							{ o.payments && o.payments.length ? (
								<ul className="wvm-a-rowlist">
									{ o.payments.map( ( p ) => (
										<li key={ p.id }>
											<div>
												<strong>{ p.amount_display }</strong>
												<span>
													{ gatewayTitle( p.gateway ) } · <Mono>{ p.provider_ref }</Mono>
												</span>
											</div>
											<div className="wvm-a-rowlist__end">
												<StatusChip status={ p.status } />
												<Muted>{ formatDateTime( p.confirmed_at || p.created_at ) }</Muted>
											</div>
										</li>
									) ) }
								</ul>
							) : (
								<p className="wvm-a-muted">No gateway payments recorded.</p>
							) }
						</div>

						<div className="wvm-a-subsection">
							<h4>Woo hook log</h4>
							{ o.hook_log && o.hook_log.length ? (
								<ul className="wvm-a-hooklog">
									{ o.hook_log.map( ( h ) => (
										<li key={ h.id }>
											<code>{ h.event }</code>
											<Chip tone={ h.result === 'activated' || h.result === 'renewed' ? 'success' : h.result === 'duplicate' ? 'neutral' : h.result ? 'info' : 'warning' }>{ h.result || 'unprocessed' }</Chip>
											<span title={ formatDateTime( h.created_at ) }>{ relativeTime( h.created_at ) }</span>
										</li>
									) ) }
								</ul>
							) : (
								<p className="wvm-a-muted">No WooCommerce events received yet.</p>
							) }
						</div>
					</div>
				) }
			</EntityDrawer>

			{ o && (
				<>
					<ReasonModal
						open={ action === 'approve' }
						title="Approve payment"
						submitLabel="Approve & activate"
						description={ <>Approving runs the activation for <strong>{ o.amount_display }</strong> ({ o.type_label.toLowerCase() }). Review reason: <em>{ o.review_reason || '—' }</em></> }
						onClose={ () => setAction( null ) }
						onSubmit={ async ( reason ) => {
							const r = await api.post< Order >( `orders/${ o.id }/approve`, { reason } );
							done( 'Payment approved', r.data );
						} }
					/>
					<ReasonModal
						open={ action === 'reject' }
						title="Reject payment"
						destructive
						submitLabel="Reject payment"
						description="Rejecting refunds the payment through WooCommerce if it was captured. The membership is not activated."
						onClose={ () => setAction( null ) }
						onSubmit={ async ( reason ) => {
							const r = await api.post< Order >( `orders/${ o.id }/reject`, { reason } );
							done( 'Payment rejected', r.data );
						} }
					/>
					<ReasonModal
						open={ action === 'cancel' }
						title="Cancel order"
						destructive
						submitLabel="Cancel order"
						description="The pay link stops working and the Woo order is cancelled."
						onClose={ () => setAction( null ) }
						onSubmit={ async ( reason ) => {
							const r = await api.post< Order >( `orders/${ o.id }/cancel`, { reason } );
							done( 'Order cancelled', r.data );
						} }
					/>
					<ReasonModal
						open={ action === 'refund' }
						title="Refund payment"
						destructive
						submitLabel="Issue refund"
						canSubmit={ !! refundAmount && refundAmount > 0 && refundAmount <= o.amount_minor }
						description="The refund is created in WooCommerce and sent through the original gateway where supported."
						onClose={ () => setAction( null ) }
						onSubmit={ async ( reason ) => {
							const r = await api.post< Order >( `orders/${ o.id }/refund`, { amount_minor: refundAmount, reason, end_membership: endMembership } );
							done( 'Refund issued', r.data );
						} }
					>
						<MoneyInput label="Refund amount" currency={ o.currency } value={ refundAmount } onChange={ setRefundAmount } help={ `Up to ${ o.amount_display }` } />
						<Checkbox label="Also end the membership now" checked={ endMembership } onChange={ setEndMembership } help="Cancels the subscription immediately and removes access." />
					</ReasonModal>
				</>
			) }
		</>
	);
}
