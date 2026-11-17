import { useEffect, useMemo, useState } from 'react';
import type { Route } from '../router';
import { replaceQuery } from '../router';
import { useApi } from '../hooks/useApi';
import type { ApiState } from '../hooks/useApi';
import { usePlans, planOptions } from '../hooks/usePlans';
import { api, fieldError, todayYmd } from '../lib/api';
import { can, config, gatewayTitle } from '../lib/config';
import type { MemberRow, Order } from '../../shared/types';
import { formatDate, formatDateTime, relativeTime, STATUS_LABELS } from '../../shared/format';
import { PageHeader, Btn, Avatar, StatusChip, EmptyState, Mono, Muted, Tabs, Card, Notice2, CopyButton, Skeleton, ErrorState, A } from '../components/ui';
import { Icon } from '../components/Icon';
import { DataTable } from '../components/DataTable';
import type { Column } from '../components/DataTable';
import { FilterBar, SearchInput, FilterSelect, FilterDate } from '../components/FilterBar';
import { FormModal, ReasonModal } from '../components/overlays';
import { SelectField, TextField, MoneyInput, Checkbox, FieldGrid, currencyOptions } from '../components/fields';
import { MemberPicker } from '../components/editors';
import { useToast } from '../components/Toasts';
import { OrderDrawer } from './OrderDrawer';

interface OrderFilters { q: string; status: string; gateway: string; type: string; currency: string; from: string; to: string }
const EMPTY: OrderFilters = { q: '', status: '', gateway: '', type: '', currency: '', from: '', to: '' };
const ORDER_STATUSES = [ 'pending', 'paid', 'failed', 'cancelled', 'refunded', 'needs_review' ];
const ORDER_TYPES: Record< string, string > = { new: 'New membership', renewal: 'Renewal', upgrade: 'Upgrade', downgrade: 'Downgrade', reactivation: 'Reactivation', enterprise: 'Enterprise', manual_adjust: 'Manual adjustment' };

function fromQuery( q: Record< string, string > ): OrderFilters {
	const f = { ...EMPTY };
	( Object.keys( EMPTY ) as ( keyof OrderFilters )[] ).forEach( ( k ) => {
		if ( q[ k ] ) {
			f[ k ] = q[ k ];
		}
	} );
	return f;
}

export function Payments( { route }: { route: Route } ) {
	const review = route.name === 'review';
	const queue = useApi< Order[] >( 'orders/review-queue' );
	const [ manual, setManual ] = useState( route.query.manual === '1' );
	const [ linkOpen, setLinkOpen ] = useState( false );
	const [ tick, setTick ] = useState( 0 );
	const manage = can( 'wvm_manage_payments' );
	useEffect( () => {
		if ( route.query.manual === '1' ) {
			setManual( true );
		}
	}, [ route.query.manual ] );
	const changed = () => {
		queue.reload();
		setTick( ( t ) => t + 1 );
	};
	return (
		<div>
			<PageHeader
				title="Payments"
				subtitle="Orders, gateway payments and the review queue"
				actions={
					<>
						{ ( manage || can( 'wvm_edit_members' ) ) && (
							<Btn icon="link" onClick={ () => setLinkOpen( true ) }>
								Create payment link
							</Btn>
						) }
						{ manage && (
							<Btn variant="primary" icon="plus" onClick={ () => setManual( true ) }>
								Record manual payment
							</Btn>
						) }
					</>
				}
				tabs={
					<Tabs
						value={ review ? 'review' : 'all' }
						tabs={ [
							{ key: 'all', label: 'All payments', to: '/payments', icon: 'card' },
							{ key: 'review', label: 'Needs review', to: '/payments/review', icon: 'shield', count: queue.meta ? Number( queue.meta.total ) : null },
						] }
					/>
				}
			/>
			{ review ? <ReviewQueue res={ queue } onChanged={ changed } /> : <OrdersList key={ tick } route={ route } onChanged={ changed } /> }
			<ManualPaymentModal
				open={ manual }
				onClose={ () => {
					setManual( false );
					if ( route.query.manual ) {
						replaceQuery( {} );
					}
				} }
				onDone={ changed }
			/>
			<PayLinkCreateModal open={ linkOpen } onClose={ () => setLinkOpen( false ) } onDone={ changed } />
		</div>
	);
}

function OrdersList( { route, onChanged }: { route: Route; onChanged: () => void } ) {
	const [ filters, setFilters ] = useState< OrderFilters >( () => fromQuery( route.query ) );
	const [ page, setPage ] = useState( 1 );
	const [ open, setOpen ] = useState< number | null >( route.query.order ? Number( route.query.order ) : null );
	useEffect( () => {
		setFilters( fromQuery( route.query ) );
		if ( route.query.order ) {
			setOpen( Number( route.query.order ) );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ JSON.stringify( route.query ) ] );
	const query = useMemo( () => ( { ...filters, page, per_page: 20 } ), [ filters, page ] );
	const list = useApi< Order[] >( 'orders', query );
	const update = ( patch: Partial< OrderFilters > ) => {
		const next = { ...filters, ...patch };
		setFilters( next );
		setPage( 1 );
		replaceQuery( next as unknown as Record< string, unknown > );
	};
	const active = Object.values( filters ).some( Boolean );
	const columns: Column< Order >[] = [
		{ key: 'id', header: 'Order', render: ( o ) => <div className="wvm-a-ordercell"><Mono>#{ o.id }</Mono><Muted>{ o.receipt_number || ( o.woo_order_id ? `Woo #${ o.woo_order_id }` : '' ) }</Muted></div> },
		{
			key: 'member',
			header: 'Member',
			render: ( o ) =>
				o.member ? (
					<div className="wvm-a-person">
						<Avatar name={ o.member.name } size={ 30 } />
						<div>
							<strong>{ o.member.name }</strong>
							<span>{ o.member.public_id }</span>
						</div>
					</div>
				) : (
					<Muted>—</Muted>
				),
		},
		{ key: 'plan', header: 'Plan · type', render: ( o ) => <div className="wvm-a-ordercell"><span className="wvm-a-strong">{ o.plan_name || o.description }</span><Muted>{ o.type_label }</Muted></div> },
		{ key: 'status', header: 'Status', render: ( o ) => <StatusChip status={ o.status } /> },
		{ key: 'gateway', header: 'Gateway', render: ( o ) => ( o.gateway ? gatewayTitle( o.gateway ) : <Muted>—</Muted> ) },
		{ key: 'date', header: 'Date', render: ( o ) => <span title={ formatDateTime( o.paid_at || o.created_at ) }>{ formatDate( o.paid_at || o.created_at ) }</span> },
		{ key: 'amount', header: 'Amount', align: 'right', render: ( o ) => <span className="wvm-a-num is-strong">{ o.amount_display }</span> },
	];
	return (
		<div className="wvm-a-card is-flush">
			<FilterBar active={ active } onClear={ () => update( EMPTY ) } right={ <Btn icon="download" size="compact" href={ api.downloadUrl( 'orders/export.csv', filters as unknown as Record< string, string > ) }>Export CSV</Btn> }>
				<SearchInput value={ filters.q } onChange={ ( q ) => update( { q } ) } placeholder="Member, receipt, provider ref, Woo #" />
				<FilterSelect label="Status" value={ filters.status } onChange={ ( status ) => update( { status } ) } options={ ORDER_STATUSES.map( ( s ) => ( { value: s, label: STATUS_LABELS[ s ] || s } ) ) } />
				<FilterSelect label="Gateway" value={ filters.gateway } onChange={ ( gateway ) => update( { gateway } ) } options={ config.gateways.map( ( g ) => ( { value: g.id, label: g.title } ) ) } />
				<FilterSelect label="Type" value={ filters.type } onChange={ ( type ) => update( { type } ) } options={ Object.entries( ORDER_TYPES ).map( ( [ value, label ] ) => ( { value, label } ) ) } />
				<FilterSelect label="Currency" value={ filters.currency } onChange={ ( currency ) => update( { currency } ) } options={ currencyOptions( config.allCurrencies ) } />
				<FilterDate label="From" value={ filters.from } onChange={ ( from ) => update( { from } ) } />
				<FilterDate label="To" value={ filters.to } onChange={ ( to ) => update( { to } ) } />
			</FilterBar>
			<DataTable
				columns={ columns }
				rows={ list.data }
				rowKey={ ( o ) => o.id }
				loading={ list.loading }
				error={ list.error }
				onRetry={ list.reload }
				meta={ list.meta }
				onPage={ setPage }
				onRowClick={ ( o ) => setOpen( o.id ) }
				rowClassName={ ( o ) => ( o.status === 'needs_review' ? 'is-attention' : undefined ) }
				empty={ <EmptyState icon="receipt" title={ active ? 'No orders match these filters' : 'No orders yet' } /> }
			/>
			<OrderDrawer
				id={ open }
				onClose={ () => {
					setOpen( null );
					if ( route.query.order ) {
						replaceQuery( filters as unknown as Record< string, unknown > );
					}
				} }
				onChanged={ () => {
					list.reload();
					onChanged();
				} }
			/>
		</div>
	);
}

function ReviewQueue( { res, onChanged }: { res: ApiState< Order[] >; onChanged: () => void } ) {
	const toast = useToast();
	const [ act, setAct ] = useState< { order: Order; kind: 'approve' | 'reject' } | null >( null );
	const [ open, setOpen ] = useState< number | null >( null );
	const manage = can( 'wvm_manage_payments' );
	if ( res.error ) {
		return <ErrorState error={ res.error } onRetry={ res.reload } />;
	}
	if ( ! res.data ) {
		return (
			<div className="wvm-a-reviewgrid">
				<Skeleton h={ 200 } r={ 18 } />
				<Skeleton h={ 200 } r={ 18 } />
			</div>
		);
	}
	if ( ! res.data.length ) {
		return (
			<Card>
				<EmptyState icon="check" title="The review queue is empty">
					Payments land here when the amount or currency doesn’t match, or activation fails. Nothing needs checking right now.
				</EmptyState>
			</Card>
		);
	}
	return (
		<>
			<div className="wvm-a-reviewgrid">
				{ res.data.map( ( o ) => {
					const [ code, ...rest ] = ( o.review_reason || 'needs_review' ).split( ':' );
					return (
						<article key={ o.id } className="wvm-a-reviewcard">
							<header>
								<span className="wvm-a-reviewcard__icon">
									<Icon name="shield" size={ 18 } />
								</span>
								<div>
									<strong>{ code.replace( /_/g, ' ' ) }</strong>
									<span>{ rest.join( ':' ).trim() || 'Held for a manual check' }</span>
								</div>
								<span className="wvm-a-reviewcard__amount">{ o.amount_display }</span>
							</header>
							<div className="wvm-a-reviewcard__body">
								{ o.member && (
									<A to={ `/members/${ o.member.id }` } className="wvm-a-membercard is-compact">
										<Avatar name={ o.member.name } size={ 32 } />
										<div>
											<strong>{ o.member.name }</strong>
											<span>
												{ o.member.public_id } · { o.member.email }
											</span>
										</div>
									</A>
								) }
								<div className="wvm-a-reviewcard__meta">
									<span>
										<Icon name="layers" size={ 13 } /> { o.plan_name } · { o.type_label }
									</span>
									<span>
										<Icon name="clock" size={ 13 } /> { relativeTime( o.created_at ) }
									</span>
									{ o.woo_order_url && (
										<a href={ o.woo_order_url } target="_blank" rel="noopener noreferrer">
											<Icon name="external" size={ 13 } /> Woo #{ o.woo_order_id }
										</a>
									) }
								</div>
							</div>
							<footer>
								<Btn variant="ghost" size="compact" onClick={ () => setOpen( o.id ) }>
									Details
								</Btn>
								{ manage && (
									<>
										<Btn destructive size="compact" icon="x" onClick={ () => setAct( { order: o, kind: 'reject' } ) }>
											Reject
										</Btn>
										<Btn variant="primary" size="compact" icon="check" onClick={ () => setAct( { order: o, kind: 'approve' } ) }>
											Approve
										</Btn>
									</>
								) }
							</footer>
						</article>
					);
				} ) }
			</div>
			<ReasonModal
				open={ !! act }
				title={ act?.kind === 'approve' ? 'Approve payment' : 'Reject payment' }
				destructive={ act?.kind === 'reject' }
				submitLabel={ act?.kind === 'approve' ? 'Approve & activate' : 'Reject payment' }
				description={ act ? <>Order #{ act.order.id } · <strong>{ act.order.amount_display }</strong> · { act.order.review_reason }</> : null }
				onClose={ () => setAct( null ) }
				onSubmit={ async ( reason ) => {
					if ( ! act ) {
						return;
					}
					await api.post( `orders/${ act.order.id }/${ act.kind }`, { reason } );
					toast.success( act.kind === 'approve' ? 'Payment approved — membership activated' : 'Payment rejected' );
					setAct( null );
					onChanged();
				} }
			/>
			<OrderDrawer id={ open } onClose={ () => setOpen( null ) } onChanged={ onChanged } />
		</>
	);
}

const METHODS = [ 'Bank transfer', 'Cash', 'Mobile money', 'Other' ];

function ManualPaymentModal( { open, onClose, onDone }: { open: boolean; onClose: () => void; onDone: () => void } ) {
	const toast = useToast();
	const { plans } = usePlans();
	const [ member, setMember ] = useState< MemberRow | null >( null );
	const [ planId, setPlanId ] = useState( '' );
	const [ currency, setCurrency ] = useState( config.currencies[ 0 ] );
	const [ amount, setAmount ] = useState< number | null >( null );
	const [ method, setMethod ] = useState( METHODS[ 0 ] );
	const [ reference, setReference ] = useState( '' );
	const [ paidAt, setPaidAt ] = useState( todayYmd() );
	const [ err, setErr ] = useState< unknown >( null );
	useEffect( () => {
		if ( open ) {
			setMember( null );
			setPlanId( '' );
			setAmount( null );
			setReference( '' );
			setPaidAt( todayYmd() );
			setErr( null );
		}
	}, [ open ] );
	const plan = plans.find( ( p ) => String( p.id ) === planId );
	// Default the amount to the plan price in the chosen currency.
	useEffect( () => {
		if ( plan && plan.prices[ currency ] !== undefined ) {
			setAmount( plan.prices[ currency ] );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ planId, currency ] );
	return (
		<FormModal
			open={ open }
			title="Record manual payment"
			submitLabel="Record payment"
			size="medium"
			canSubmit={ !! member && !! planId && !! amount && amount > 0 && !! reference.trim() }
			description="For bank transfers, cash or mobile money received outside the checkout. The membership is activated or renewed immediately and a receipt is emailed."
			onClose={ onClose }
			onSubmit={ async () => {
				try {
					const r = await api.post< Order >( 'orders/manual', { member_id: member!.id, plan_id: Number( planId ), amount_minor: amount, currency, method, reference: reference.trim(), paid_at: paidAt || undefined } );
					toast.success( `Payment recorded — ${ r.data.amount_display }${ r.data.receipt_number ? ` · receipt ${ r.data.receipt_number }` : '' }` );
					onClose();
					onDone();
				} catch ( e ) {
					setErr( e );
					throw e;
				}
			} }
		>
			<MemberPicker value={ member } onChange={ setMember } />
			<FieldGrid>
				<SelectField label="Plan" value={ planId } onChange={ setPlanId } options={ [ { label: 'Choose a plan…', value: '' }, ...planOptions( plans, { paidOnly: true } ) ] } error={ fieldError( err, 'plan_id' ) } />
				<MoneyInput label="Amount received" currency={ currency } currencies={ config.currencies } onCurrencyChange={ setCurrency } value={ amount } onChange={ setAmount } error={ fieldError( err, 'amount_minor' ) } help={ plan && plan.prices[ currency ] === undefined ? `${ plan.name } has no ${ currency } price` : undefined } />
				<SelectField label="Method" value={ method } onChange={ setMethod } options={ METHODS.map( ( m ) => ( { label: m, value: m } ) ) } />
				<TextField label="Reference" value={ reference } onChange={ setReference } placeholder="Bank ref, MoMo TxID…" error={ fieldError( err, 'reference' ) } />
				<TextField label="Paid on" type="date" value={ paidAt } onChange={ setPaidAt } error={ fieldError( err, 'paid_at' ) } />
			</FieldGrid>
		</FormModal>
	);
}

function PayLinkCreateModal( { open, onClose, onDone }: { open: boolean; onClose: () => void; onDone: () => void } ) {
	const { plans } = usePlans();
	const [ member, setMember ] = useState< MemberRow | null >( null );
	const [ planId, setPlanId ] = useState( '' );
	const [ type, setType ] = useState( 'new' );
	const [ currency, setCurrency ] = useState( config.currencies[ 0 ] );
	const [ sendEmail, setSendEmail ] = useState( true );
	const [ result, setResult ] = useState< Order | null >( null );
	useEffect( () => {
		if ( open ) {
			setMember( null );
			setPlanId( '' );
			setType( 'new' );
			setResult( null );
			setSendEmail( true );
		}
	}, [ open ] );
	return (
		<FormModal
			open={ open }
			title="Create payment link"
			submitLabel={ result ? 'Done' : 'Create link' }
			canSubmit={ !! result || ( !! member && ( type !== 'new' || !! planId ) ) }
			onClose={ onClose }
			onSubmit={ async () => {
				if ( result ) {
					onClose();
					return;
				}
				const r = await api.post< Order >( 'orders', { member_id: member!.id, plan_id: planId ? Number( planId ) : undefined, type, currency, send_email: sendEmail } );
				setResult( r.data );
				onDone();
			} }
		>
			{ result ? (
				<>
					<Notice2 tone="success" title={ `Order #${ result.id } created — ${ result.amount_display }` }>
						{ sendEmail ? 'The pay link was emailed to the member.' : 'Copy the link below and share it with the member.' }
					</Notice2>
					<div className="wvm-a-paylink">
						<div>
							<span>Pay link</span>
							<Mono>{ result.pay_link }</Mono>
						</div>
						{ result.pay_link && <CopyButton text={ result.pay_link } label="Copy" /> }
					</div>
				</>
			) : (
				<>
					<MemberPicker value={ member } onChange={ setMember } />
					<div className="wvm-a-radiocards is-3">
						{ [
							[ 'new', 'New plan', 'Any plan, any currency' ],
							[ 'renewal', 'Renewal', 'Renew their current plan' ],
							[ 'reactivation', 'Reactivation', 'Bring a lapsed member back' ],
						].map( ( [ k, t, s ] ) => (
							<label key={ k } className={ type === k ? 'is-active' : '' }>
								<input type="radio" checked={ type === k } onChange={ () => setType( k ) } />
								<strong>{ t }</strong>
								<span>{ s }</span>
							</label>
						) ) }
					</div>
					{ type === 'new' && (
						<FieldGrid>
							<SelectField label="Plan" value={ planId } onChange={ setPlanId } options={ [ { label: 'Choose a plan…', value: '' }, ...planOptions( plans, { paidOnly: true } ) ] } />
							<SelectField label="Currency" value={ currency } onChange={ setCurrency } options={ currencyOptions() } />
						</FieldGrid>
					) }
					<Checkbox label="Email the pay link to the member" checked={ sendEmail } onChange={ setSendEmail } />
				</>
			) }
		</FormModal>
	);
}
