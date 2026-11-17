/* Billing tab: manage plan, payment history (receipts / pay), payment methods. */
import { useState } from '@wordpress/element';
import type { Order, PaymentMethod } from '../../../../shared/types';
import { formatDate } from '../../../../shared/format';
import { api, errorMessage } from '../../../lib/api';
import { cx } from '../../../lib/util';
import { useAsync } from '../../../hooks/useAsync';
import { Card, SectionTitle, StatusChip, Chip } from '../../../components/Card';
import { Button } from '../../../components/Button';
import { EmptyState, ErrorState, Skeleton } from '../../../components/Feedback';
import { Icon } from '../../../components/Icon';
import { Confirm } from '../../../components/Confirm';
import { useToast } from '../../../components/Toast';
import { useDashboard } from '../context';
import { ManagePlan } from './ManagePlan';

export function BillingTab() {
	return (
		<div className="wvm-page">
			<div className="wvm-page__head">
				<div>
					<h1 className="wvm-h1">Billing</h1>
					<p className="wvm-muted">Your plan, payments and receipts.</p>
				</div>
			</div>
			<ManagePlan />
			<OrderHistory />
			<PaymentMethods />
		</div>
	);
}

const PER_PAGE = 8;

function OrderHistory() {
	const [ page, setPage ] = useState( 1 );
	const orders = useAsync( async () => api.get< Order[] >( 'me/orders', { page, per_page: PER_PAGE } ), [ page ] );
	const items = orders.data?.data;
	const pages = orders.data?.meta?.pages || 1;

	return (
		<section className="wvm-section">
			<SectionTitle title="Payment history" subtitle="Download receipts for anything you've paid." />
			<Card pad="none" className="wvm-orders">
				{ orders.error && ! items && <div className="wvm-pad"><ErrorState message={ orders.error } onRetry={ orders.reload } /></div> }
				{ ! items && ! orders.error && (
					<div className="wvm-pad wvm-stack">
						{ [ 0, 1, 2 ].map( ( i ) => <Skeleton key={ i } h={ 52 } r={ 12 } /> ) }
					</div>
				) }
				{ items && items.length === 0 && (
					<div className="wvm-pad">
						<EmptyState icon="card" title="No payments yet">When you pay for a plan, your receipts will appear here.</EmptyState>
					</div>
				) }
				{ items && items.length > 0 && (
					<>
						<div className="wvm-table" role="table" aria-label="Payment history">
							<div className="wvm-table__head" role="row">
								<span role="columnheader">Date</span>
								<span role="columnheader">Description</span>
								<span role="columnheader" className="wvm-num">Amount</span>
								<span role="columnheader">Status</span>
								<span role="columnheader" className="wvm-right"><span className="wvm-sr">Actions</span></span>
							</div>
							{ items.map( ( o ) => (
								<OrderRow key={ o.id } order={ o } />
							) ) }
						</div>
						{ pages > 1 && (
							<div className="wvm-pager">
								<Button size="sm" variant="ghost" icon="chevron-left" disabled={ page <= 1 || orders.loading } onClick={ () => setPage( ( p ) => p - 1 ) }>Newer</Button>
								<span className="wvm-small wvm-muted">Page { page } of { pages }</span>
								<Button size="sm" variant="ghost" iconRight="chevron-right" disabled={ page >= pages || orders.loading } onClick={ () => setPage( ( p ) => p + 1 ) }>Older</Button>
							</div>
						) }
					</>
				) }
			</Card>
		</section>
	);
}

function OrderRow( { order: o }: { order: Order } ) {
	const payable = ( o.status === 'pending' || o.status === 'failed' ) && ( o.checkout_url || o.pay_link );
	const label = o.description || [ o.plan_name, o.type_label ].filter( Boolean ).join( ' · ' );
	return (
		<div className={ cx( 'wvm-table__row', payable && 'is-payable' ) } role="row">
			<span role="cell" className="wvm-table__date">{ formatDate( o.paid_at || o.created_at ) }</span>
			<span role="cell" className="wvm-table__desc">
				<span className="wvm-table__title">{ o.plan_name || label }</span>
				<span className="wvm-table__sub">
					{ o.type_label }
					{ o.receipt_number && <> · { o.receipt_number }</> }
				</span>
			</span>
			<span role="cell" className="wvm-num wvm-table__amt">{ o.amount_display }</span>
			<span role="cell"><StatusChip status={ o.status } label={ o.status === 'needs_review' ? 'Processing' : undefined } /></span>
			<span role="cell" className="wvm-right wvm-table__act">
				{ o.status === 'paid' && (
					<Button size="sm" variant="ghost" href={ api.downloadUrl( `me/orders/${ o.id }/receipt.pdf` ) } target="_blank" rel="noreferrer" icon="download">Receipt</Button>
				) }
				{ payable && (
					<Button size="sm" href={ ( o.checkout_url || o.pay_link ) as string } iconRight="arrow-right">Pay</Button>
				) }
			</span>
		</div>
	);
}

function PaymentMethods() {
	const toast = useToast();
	const { reload } = useDashboard();
	const methods = useAsync< PaymentMethod[] >( async () => ( await api.get< PaymentMethod[] >( 'me/payment-methods' ) ).data, [] );
	const [ removing, setRemoving ] = useState< PaymentMethod | null >( null );
	const [ busy, setBusy ] = useState< number | null >( null );

	const makeDefault = async ( m: PaymentMethod ) => {
		setBusy( m.id );
		try {
			const { data } = await api.post< PaymentMethod[] >( `me/payment-methods/default/${ m.id }` );
			methods.setData( data );
			toast.success( `${ m.label } is now your default.` );
			reload();
		} catch ( e ) {
			toast.error( errorMessage( e ) );
		} finally {
			setBusy( null );
		}
	};

	return (
		<section className="wvm-section">
			<SectionTitle title="Payment methods" subtitle="Saved methods renew automatically. Without one, we email you a secure pay link." />
			<Card pad="lg">
				{ ! methods.data && ! methods.error && <Skeleton h={ 64 } r={ 14 } /> }
				{ methods.error && ! methods.data && <p className="wvm-muted">{ methods.error }</p> }
				{ methods.data && methods.data.length === 0 && (
					<EmptyState compact icon="mail" title="No saved payment methods">
						You renew with a pay link — we'll email it to you before each renewal, and you can pay with Mobile Money or card.
					</EmptyState>
				) }
				{ methods.data && methods.data.length > 0 && (
					<ul className="wvm-methods">
						{ methods.data.map( ( m ) => (
							<li key={ m.id } className={ cx( 'wvm-method', m.is_default && 'is-default' ) }>
								<span className="wvm-method__icon" aria-hidden="true"><Icon name={ /mobile|momo|mtn|airtel/i.test( m.gateway + m.label ) ? 'phone' : 'card' } size={ 20 } /></span>
								<div className="wvm-method__body">
									<p className="wvm-method__label">{ m.label }</p>
									<p className="wvm-method__meta">
										{ m.expires_at ? `Expires ${ formatDate( m.expires_at, { month: 'short', year: 'numeric' } ) }` : `Added ${ formatDate( m.created_at ) }` }
									</p>
								</div>
								{ m.is_default ? (
									<Chip tone="success" icon="check">Default</Chip>
								) : (
									<Button size="sm" variant="ghost" onClick={ () => makeDefault( m ) } loading={ busy === m.id }>Make default</Button>
								) }
								<button type="button" className="wvm-iconbtn wvm-iconbtn--sm" aria-label={ `Remove ${ m.label }` } title="Remove" onClick={ () => setRemoving( m ) }>
									<Icon name="trash" size={ 18 } />
								</button>
							</li>
						) ) }
					</ul>
				) }
			</Card>
			<Confirm
				open={ !! removing }
				onClose={ () => setRemoving( null ) }
				title="Remove payment method?"
				confirmLabel="Remove"
				tone="danger"
				onConfirm={ async () => {
					if ( ! removing ) {
						return;
					}
					const { data } = await api.del< PaymentMethod[] >( `me/payment-methods/${ removing.id }` );
					methods.setData( data );
					toast.success( 'Payment method removed.' );
					reload();
				} }
			>
				<p>
					<strong>{ removing?.label }</strong> won't be charged again. Future renewals will use { methods.data && methods.data.length > 1 ? 'your other saved method' : "a pay link we'll email you" }.
				</p>
			</Confirm>
		</section>
	);
}

