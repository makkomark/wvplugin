import { useEffect, useState } from 'react';
import { DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import type { Route } from '../router';
import { replaceQuery } from '../router';
import { useApi } from '../hooks/useApi';
import { usePlans, planOptions } from '../hooks/usePlans';
import { api, fieldError } from '../lib/api';
import { can, config, countryName, levelLabel } from '../lib/config';
import type { MemberDetailData, Note, NotificationRow } from '../lib/types';
import type { AuditRow, Order, Subscription } from '../../shared/types';
import { formatDate, formatDateTime, relativeTime, formatMoney, intervalLabel } from '../../shared/format';
import { PageHeader, Btn, Avatar, StatusChip, Card, Tabs, DescList, Loading, ErrorState, EmptyState, Chip, Mono, Muted, Notice2, ResultBlock, A, cx } from '../components/ui';
import { Icon } from '../components/Icon';
import { DataTable } from '../components/DataTable';
import { ReasonModal, FormModal, ConfirmModal } from '../components/overlays';
import { TextField, SelectField, Checkbox, Toggle, TextareaField, CountrySelect, FieldGrid, currencyOptions } from '../components/fields';
import { AuditTimeline } from '../components/AuditTimeline';
import { StatStrip } from '../components/widgets';
import { useToast } from '../components/Toasts';
import { OrderDrawer } from './OrderDrawer';

type Modal = 'extend' | 'change' | 'suspend' | 'reactivate' | 'cancel' | 'resume' | 'grant' | 'paylink' | 'welcome' | 'kit' | 'impersonate' | 'anonymise' | 'export' | 'edit' | null;

export function MemberDetail( { id, route }: { id: number; route: Route } ) {
	const toast = useToast();
	const res = useApi< MemberDetailData >( `members/${ id }` );
	const [ tab, setTab ] = useState( route.query.tab || 'subscriptions' );
	const [ modal, setModal ] = useState< Modal >( null );
	const [ kitResult, setKitResult ] = useState< unknown >( null );
	const [ refreshKey, setRefreshKey ] = useState( 0 );
	const edit = can( 'wvm_edit_members' );

	if ( res.error && ! res.data ) {
		return (
			<>
				<PageHeader title="Member" crumbs={ [ { label: 'Members', to: '/members' }, { label: `#${ id }` } ] } />
				<ErrorState error={ res.error } onRetry={ res.reload } />
			</>
		);
	}
	if ( ! res.data ) {
		return <Loading label="Loading member…" />;
	}
	const d = res.data;
	const m = d.member;
	const sub = d.subscription;
	const status = sub ? ( sub.status === 'active' && sub.cancel_at_period_end ? 'cancelling' : sub.status ) : 'none';
	const refresh = () => {
		res.reload();
		setRefreshKey( ( k ) => k + 1 );
	};
	const ok = ( msg: string ) => {
		toast.success( msg );
		setModal( null );
		refresh();
	};
	const live = sub && [ 'active', 'past_due', 'suspended' ].includes( sub.status );
	const canGrant = ! sub || [ 'cancelled', 'expired' ].includes( sub.status ) || sub.plan.is_free;

	const more: { title: string; icon: string; onClick: () => void; destructive?: boolean; hidden?: boolean }[] = [
		{ title: 'Send pay link', icon: 'link', onClick: () => setModal( 'paylink' ), hidden: ! edit },
		{ title: 'Resend welcome email', icon: 'mail', onClick: () => setModal( 'welcome' ), hidden: ! edit },
		{ title: 'Resync with Kit', icon: 'refresh', onClick: () => setModal( 'kit' ) },
		{ title: 'Edit profile', icon: 'edit', onClick: () => setModal( 'edit' ), hidden: ! edit },
		{ title: 'Impersonate member', icon: 'login', onClick: () => setModal( 'impersonate' ), hidden: ! can( 'wvm_impersonate' ) },
		{ title: 'Request data export', icon: 'download', onClick: () => setModal( 'export' ), hidden: ! edit },
		{ title: 'View WordPress user', icon: 'external', onClick: () => ( window.location.href = d.wp_user_url ) },
	];

	return (
		<div className="wvm-a-member">
			<PageHeader title="" crumbs={ [ { label: 'Members', to: '/members' }, { label: m.name } ] } />

			<section className="wvm-a-profile">
				<div className="wvm-a-profile__id">
					<Avatar name={ m.name } size={ 64 } />
					<div>
						<div className="wvm-a-profile__name">
							<h1>{ m.name }</h1>
							<StatusChip status={ status } />
							{ m.anonymised_at && <Chip tone="neutral">Anonymised</Chip> }
						</div>
						<div className="wvm-a-profile__meta">
							<Mono>{ m.public_id }</Mono>
							<span>
								<Icon name="mail" size={ 13 } /> { m.email }
							</span>
							{ m.phone && (
								<span>
									<Icon name="message" size={ 13 } /> { m.phone }
								</span>
							) }
							<span>
								<Icon name="globe" size={ 13 } /> { countryName( m.country ) }
							</span>
							<span className="wvm-a-profile__plan">
								<Icon name="layers" size={ 13 } /> { sub ? sub.plan.name : 'No plan' }
							</span>
						</div>
					</div>
				</div>
				<div className="wvm-a-profile__actions">
					{ edit && sub && sub.status !== 'cancelled' && sub.status !== 'pending' && ! sub.plan.is_free && (
						<Btn icon="plus" onClick={ () => setModal( 'extend' ) }>
							Extend
						</Btn>
					) }
					{ edit && sub && live && (
						<Btn icon="layers" onClick={ () => setModal( 'change' ) }>
							Change plan
						</Btn>
					) }
					{ edit && canGrant && (
						<Btn variant="primary" icon="star" onClick={ () => setModal( 'grant' ) }>
							Grant plan
						</Btn>
					) }
					{ edit && sub?.cancel_at_period_end && (
						<Btn variant="primary" icon="play" onClick={ () => setModal( 'resume' ) }>
							Resume
						</Btn>
					) }
					<DropdownMenu
						icon={ <Icon name="more" size={ 18 } /> }
						label="More actions"
						toggleProps={ { className: 'wvm-a-btn is-icon wvm-a-morebtn', __next40pxDefaultSize: true } as never }
						popoverProps={ { placement: 'bottom-end', className: 'wvm-a-popover' } as never }
					>
						{ ( { onClose }: { onClose: () => void } ) => (
							<>
								<MenuGroup>
									{ more
										.filter( ( x ) => ! x.hidden )
										.map( ( x ) => (
											<MenuItem
												key={ x.title }
												icon={ <Icon name={ x.icon } size={ 16 } /> }
												onClick={ () => {
													onClose();
													x.onClick();
												} }
											>
												{ x.title }
											</MenuItem>
										) ) }
								</MenuGroup>
								{ edit && sub && (
									<MenuGroup label="Membership">
										{ ( sub.status === 'active' || sub.status === 'past_due' ) && (
											<MenuItem icon={ <Icon name="pause" size={ 16 } /> } onClick={ () => ( onClose(), setModal( 'suspend' ) ) }>
												Suspend
											</MenuItem>
										) }
										{ [ 'suspended', 'expired', 'past_due' ].includes( sub.status ) && (
											<MenuItem icon={ <Icon name="play" size={ 16 } /> } onClick={ () => ( onClose(), setModal( 'reactivate' ) ) }>
												Reactivate
											</MenuItem>
										) }
										{ [ 'active', 'past_due', 'suspended' ].includes( sub.status ) && ! sub.cancel_at_period_end && (
											<MenuItem icon={ <Icon name="x" size={ 16 } /> } isDestructive onClick={ () => ( onClose(), setModal( 'cancel' ) ) }>
												Cancel membership
											</MenuItem>
										) }
									</MenuGroup>
								) }
								{ can( 'wvm_manage_settings' ) && ! m.anonymised_at && (
									<MenuGroup label="Danger zone">
										<MenuItem icon={ <Icon name="shield" size={ 16 } /> } isDestructive onClick={ () => ( onClose(), setModal( 'anonymise' ) ) }>
											Anonymise (GDPR)
										</MenuItem>
									</MenuGroup>
								) }
							</>
						) }
					</DropdownMenu>
				</div>
			</section>

			{ d.pending_order && (
				<Notice2
					tone="warning"
					icon="clock"
					title={ `Pending ${ d.pending_order.type_label.toLowerCase() } order — ${ d.pending_order.amount_display }` }
					actions={ d.pending_order.pay_link ? <Btn size="compact" icon="copy" onClick={ () => navigator.clipboard?.writeText( d.pending_order!.pay_link! ).then( () => toast.success( 'Pay link copied' ) ) }>Copy pay link</Btn> : undefined }
				>
					Created { relativeTime( d.pending_order.created_at ) }. The member can pay from their dashboard or the pay link.
				</Notice2>
			) }

			<StatStrip
				items={ [
					{ label: 'Lifetime value', value: d.stats.lifetime_value.length ? d.stats.lifetime_value.map( ( l ) => l.display ).join( ' · ' ) : '—', icon: 'receipt' },
					{ label: sub?.cancel_at_period_end ? 'Ends' : 'Renews', value: sub?.current_period_end ? `${ formatDate( sub.current_period_end ) }${ sub.days_left !== null && sub.days_left >= 0 ? ` · ${ sub.days_left }d` : '' }` : '—', icon: 'calendar' },
					{ label: 'Price', value: sub && sub.amount_minor ? `${ sub.amount_display } ${ intervalLabel( sub.plan.interval ) }` : sub ? 'Free' : '—', icon: 'card' },
					{ label: 'Access level', value: `${ d.entitlements.level } · ${ levelLabel( d.entitlements.level ) }`, icon: 'lock' },
					{ label: 'Member since', value: formatDate( d.stats.member_since ), icon: 'clock' },
				] }
			/>

			<Tabs
				variant="line"
				value={ tab }
				onChange={ ( t ) => {
					setTab( t );
					replaceQuery( { tab: t } );
				} }
				tabs={ [
					{ key: 'subscriptions', label: 'Subscriptions', count: d.stats.subscriptions },
					{ key: 'orders', label: 'Orders & payments' },
					{ key: 'access', label: 'Access' },
					{ key: 'notes', label: 'Notes', count: d.notes.length },
					{ key: 'audit', label: 'Audit' },
					{ key: 'emails', label: 'Emails' },
				] }
			/>

			<div className="wvm-a-tabpanel" key={ refreshKey }>
				{ tab === 'subscriptions' && <SubscriptionsTab memberId={ id } /> }
				{ tab === 'orders' && <OrdersTab memberId={ id } onChanged={ refresh } /> }
				{ tab === 'access' && <AccessTab d={ d } onResync={ () => setModal( 'kit' ) } /> }
				{ tab === 'notes' && <NotesTab memberId={ id } initial={ d.notes } onChanged={ res.reload } /> }
				{ tab === 'audit' && <MemberAudit memberId={ id } /> }
				{ tab === 'emails' && <EmailsTab memberId={ id } /> }
			</div>

			{ /* ---- action modals ---- */ }
			{ sub && (
				<>
					<ExtendModal open={ modal === 'extend' } sub={ sub } onClose={ () => setModal( null ) } onDone={ () => ok( 'Membership extended' ) } />
					<ChangePlanModal open={ modal === 'change' } sub={ sub } onClose={ () => setModal( null ) } onDone={ () => ok( 'Plan change saved' ) } />
					<ReasonModal
						open={ modal === 'suspend' }
						title="Suspend membership"
						destructive
						submitLabel="Suspend"
						description="Access is removed immediately. The member can be reactivated later."
						onClose={ () => setModal( null ) }
						onSubmit={ async ( reason ) => {
							await api.post( `subscriptions/${ sub.id }/suspend`, { reason } );
							ok( 'Membership suspended' );
						} }
					/>
					<ReasonModal
						open={ modal === 'reactivate' }
						title="Reactivate membership"
						submitLabel="Reactivate"
						description="Restores access without taking a payment."
						onClose={ () => setModal( null ) }
						onSubmit={ async ( reason ) => {
							await api.post( `subscriptions/${ sub.id }/reactivate`, { reason } );
							ok( 'Membership reactivated' );
						} }
					/>
					<CancelModal open={ modal === 'cancel' } sub={ sub } onClose={ () => setModal( null ) } onDone={ ( imm ) => ok( imm ? 'Membership cancelled' : 'Cancellation scheduled for period end' ) } />
					<ConfirmModal
						open={ modal === 'resume' }
						title="Resume membership"
						confirmLabel="Resume"
						onClose={ () => setModal( null ) }
						onConfirm={ async () => {
							await api.post( `subscriptions/${ sub.id }/resume` );
							ok( 'Scheduled cancellation removed' );
						} }
					>
						<p>The membership will renew as normal on { formatDate( sub.current_period_end ) }.</p>
					</ConfirmModal>
				</>
			) }
			<GrantModal open={ modal === 'grant' } memberId={ id } onClose={ () => setModal( null ) } onDone={ () => ok( 'Plan granted' ) } />
			<PayLinkModal open={ modal === 'paylink' } memberId={ id } sub={ sub } onClose={ () => setModal( null ) } onDone={ refresh } />
			<EditMemberModal open={ modal === 'edit' } d={ d } onClose={ () => setModal( null ) } onDone={ () => ok( 'Profile updated' ) } />
			<ConfirmModal
				open={ modal === 'welcome' }
				title="Resend welcome email"
				confirmLabel="Send email"
				onClose={ () => setModal( null ) }
				onConfirm={ async () => {
					await api.post( `members/${ id }/resend-welcome` );
					ok( `Welcome email queued to ${ m.email }` );
				} }
			>
				<p>
					Sends the <strong>welcome</strong> template to { m.email } again.
				</p>
			</ConfirmModal>
			<FormModal
				open={ modal === 'kit' }
				title="Resync with Kit"
				submitLabel={ kitResult ? 'Run again' : 'Resync now' }
				onClose={ () => {
					setModal( null );
					setKitResult( null );
				} }
				onSubmit={ async () => {
					const r = await api.post< Record< string, unknown > >( `members/${ id }/kit-resync` );
					setKitResult( r.data );
					toast.info( `Kit sync: ${ String( r.data.status ) }` );
					res.reload();
				} }
				description="Applies the expected Kit tags for this member’s current state. Non WV- tags are never touched."
			>
				{ kitResult ? (
					<>
						{ ( kitResult as { status?: string } ).status === 'not_configured' && (
							<Notice2 tone="warning" title="Kit isn’t connected">
								Add your Kit API key in <A to="/settings/kit">Settings → Kit</A> first.
							</Notice2>
						) }
						<ResultBlock data={ kitResult } />
					</>
				) : (
					<DescList items={ [ [ 'Expected state', d.kit.expected.state ], [ 'Plan tag', d.kit.expected.plan_tag ], [ 'Kit subscriber', d.kit.subscriber_id ], [ 'Last sync', d.kit.last_synced ? relativeTime( d.kit.last_synced ) : 'Never' ] ] } />
				) }
			</FormModal>
			<ConfirmModal
				open={ modal === 'impersonate' }
				title="Impersonate member"
				confirmLabel="Log in as member"
				onClose={ () => setModal( null ) }
				onConfirm={ async () => {
					const r = await api.post< { url: string } >( `members/${ id }/impersonate` );
					window.location.href = r.data.url;
				} }
			>
				<p>
					You’ll see the site exactly as <strong>{ m.name }</strong> does. A banner lets you return to your account. This is recorded in the audit log.
				</p>
			</ConfirmModal>
			<ConfirmModal
				open={ modal === 'export' }
				title="Request data export"
				confirmLabel="Create export request"
				onClose={ () => setModal( null ) }
				onConfirm={ async () => {
					const r = await api.post< { request_id: number; url: string } >( `members/${ id }/data-export` );
					setModal( null );
					toast.success( `Export request #${ r.data.request_id } created`, { label: 'Open Tools → Export', onClick: () => ( window.location.href = r.data.url ) } );
				} }
			>
				<p>Creates a WordPress personal-data export request for { m.email }. You can send or download it from Tools → Export Personal Data.</p>
			</ConfirmModal>
			<ReasonModal
				open={ modal === 'anonymise' }
				title="Anonymise member"
				destructive
				submitLabel="Anonymise permanently"
				confirmText={ m.public_id }
				description={
					<Notice2 tone="danger" title="This can’t be undone">
						Personal data (name, email, phone) is pseudonymised. Financial records are kept for accounting. The member loses access.
					</Notice2>
				}
				onClose={ () => setModal( null ) }
				onSubmit={ async ( reason ) => {
					await api.post( `members/${ id }/anonymise`, { reason } );
					ok( 'Member anonymised' );
				} }
			/>
		</div>
	);
}

/* ---------------- tabs ---------------- */

function SubscriptionsTab( { memberId }: { memberId: number } ) {
	const res = useApi< Subscription[] >( `members/${ memberId }/subscriptions` );
	if ( res.error ) {
		return <ErrorState error={ res.error } onRetry={ res.reload } />;
	}
	if ( ! res.data ) {
		return <Loading />;
	}
	if ( ! res.data.length ) {
		return <EmptyState icon="layers" title="No subscriptions yet" />;
	}
	return (
		<div className="wvm-a-stack">
			{ res.data.map( ( s ) => (
				<Card key={ s.id } className="wvm-a-subcard">
					<div className="wvm-a-subcard__head">
						<div>
							<h3>
								{ s.plan.name } <Muted>#{ s.id }</Muted>
							</h3>
							<p>
								{ s.amount_minor ? `${ s.amount_display } ${ intervalLabel( s.plan.interval ) }` : 'Free' }
								{ s.organisation_id && (
									<>
										{ ' · ' }
										<A to={ `/enterprise/organisations/${ s.organisation_id }` }>Organisation seat</A>
									</>
								) }
							</p>
						</div>
						<StatusChip status={ s.status === 'active' && s.cancel_at_period_end ? 'cancelling' : s.status } />
					</div>
					<DescList
						cols={ 3 }
						items={ [
							[ 'Started', formatDate( s.started_at ) ],
							[ 'Current period', s.current_period_start ? `${ formatDate( s.current_period_start ) } → ${ formatDate( s.current_period_end ) }` : null ],
							[ 'Days left', s.days_left ],
							s.grace_ends_at && [ 'Grace ends', formatDate( s.grace_ends_at ) ],
							s.expires_at && [ 'Expires', formatDate( s.expires_at ) ],
							s.cancelled_at && [ 'Cancelled', formatDate( s.cancelled_at ) ],
							s.cancel_reason && [ 'Cancel reason', s.cancel_reason ],
							s.pending_plan && [ 'Switching to', s.pending_plan.name + ' at renewal' ],
							s.autocharge_attempts > 0 && [ 'Auto-charge attempts', s.autocharge_attempts ],
						] }
					/>
					{ s.events && s.events.length > 0 && (
						<div className="wvm-a-subcard__events">
							<h4>History</h4>
							<ol className="wvm-a-events">
								{ s.events.map( ( e ) => (
									<li key={ e.id }>
										<span className={ cx( 'wvm-a-events__dot', `is-${ e.to_status }` ) } />
										<div>
											<div className="wvm-a-events__line">
												{ e.from_status ? <StatusChip status={ e.from_status } /> : <Muted>Created</Muted> }
												<Icon name="arrowRight" size={ 12 } />
												<StatusChip status={ e.to_status } />
												<span className="wvm-a-events__reason">{ e.reason }</span>
											</div>
											<div className="wvm-a-events__meta">
												{ e.actor_name } · <span title={ formatDateTime( e.created_at ) }>{ relativeTime( e.created_at ) }</span>
											</div>
										</div>
									</li>
								) ) }
							</ol>
						</div>
					) }
				</Card>
			) ) }
		</div>
	);
}

function OrdersTab( { memberId, onChanged }: { memberId: number; onChanged: () => void } ) {
	const res = useApi< Order[] >( `members/${ memberId }/orders` );
	const [ open, setOpen ] = useState< number | null >( null );
	return (
		<Card pad={ false }>
			<DataTable
				columns={ [
					{ key: 'id', header: 'Order', render: ( o ) => <Mono>#{ o.id }</Mono> },
					{ key: 'date', header: 'Date', render: ( o ) => formatDate( o.created_at ) },
					{ key: 'type', header: 'Type', render: ( o ) => o.type_label },
					{ key: 'plan', header: 'Plan', render: ( o ) => o.plan_name },
					{ key: 'status', header: 'Status', render: ( o ) => <StatusChip status={ o.status } /> },
					{ key: 'gw', header: 'Gateway', render: ( o ) => o.gateway || <Muted>—</Muted> },
					{ key: 'amount', header: 'Amount', align: 'right', render: ( o ) => <span className="wvm-a-num">{ o.amount_display }</span> },
				] }
				rows={ res.data }
				rowKey={ ( o ) => o.id }
				loading={ res.loading }
				error={ res.error }
				onRetry={ res.reload }
				onRowClick={ ( o ) => setOpen( o.id ) }
				empty={ <EmptyState icon="receipt" title="No orders yet" /> }
			/>
			<OrderDrawer
				id={ open }
				onClose={ () => setOpen( null ) }
				onChanged={ () => {
					res.reload();
					onChanged();
				} }
			/>
		</Card>
	);
}

function AccessTab( { d, onResync }: { d: MemberDetailData; onResync: () => void } ) {
	const e = d.entitlements;
	return (
		<div className="wvm-a-grid2">
			<Card title="Entitlements" icon="lock">
				<div className="wvm-a-levelbadge">
					<span>{ e.level }</span>
					<div>
						<strong>{ levelLabel( e.level ) }</strong>
						<em>Highest access level across active memberships and seats</em>
					</div>
				</div>
				<DescList
					items={ [
						[ 'Plans', e.plan_slugs.length ? e.plan_slugs.map( ( s ) => <Chip key={ s } tone="primary">{ s }</Chip> ) : null ],
						[ 'Organisations', d.organisation ? <A to={ `/enterprise/organisations/${ d.organisation.id }` }>{ d.organisation.name }</A> : null ],
						[ 'Grants', e.grants.length ? e.grants.join( ', ' ) : null ],
						[ 'Payment methods', d.payment_methods.length ? d.payment_methods.map( ( p ) => p.label ).join( ', ' ) : 'None saved (manual renewals)' ],
					] }
					cols={ 1 }
				/>
			</Card>
			<Card title="Kit (email content)" icon="tag" actions={ <Btn size="compact" icon="refresh" onClick={ onResync }>Resync</Btn> }>
				{ d.kit.error && (
					<Notice2 tone="danger" title="Last sync failed">
						{ d.kit.error }
					</Notice2>
				) }
				<DescList
					cols={ 1 }
					items={ [
						[ 'Expected state', <Chip key="s" tone="info">{ d.kit.expected.state }</Chip> ],
						[ 'Plan tag', d.kit.expected.plan_tag ? <Mono>{ d.kit.expected.plan_tag }</Mono> : null ],
						[ 'Extra tags', d.kit.expected.extra.length ? d.kit.expected.extra.join( ', ' ) : null ],
						[ 'Kit subscriber ID', d.kit.subscriber_id ? <Mono>{ d.kit.subscriber_id }</Mono> : 'Not linked yet' ],
						[ 'Last synced', d.kit.last_synced ? <span title={ formatDateTime( d.kit.last_synced ) }>{ relativeTime( d.kit.last_synced ) }</span> : 'Never' ],
						[ 'Marketing consent', d.member.marketing_consent ? `Yes · ${ formatDate( d.member.marketing_consent_at ) }` : 'No' ],
					] }
				/>
			</Card>
		</div>
	);
}

function NotesTab( { memberId, initial, onChanged }: { memberId: number; initial: Note[]; onChanged: () => void } ) {
	const toast = useToast();
	const [ notes, setNotes ] = useState< Note[] >( initial );
	const [ body, setBody ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ del, setDel ] = useState< Note | null >( null );
	const edit = can( 'wvm_edit_members' );
	useEffect( () => setNotes( initial ), [ initial ] );
	return (
		<div className="wvm-a-notes">
			{ edit && (
				<Card>
					<form
						className="wvm-a-notes__form"
						onSubmit={ async ( e ) => {
							e.preventDefault();
							if ( ! body.trim() ) {
								return;
							}
							setBusy( true );
							try {
								const r = await api.post< Note >( `members/${ memberId }/notes`, { body } );
								setNotes( [ r.data, ...notes ] );
								setBody( '' );
								toast.success( 'Note added' );
								onChanged();
							} catch ( err ) {
								toast.error( err );
							} finally {
								setBusy( false );
							}
						} }
					>
						<Avatar name={ config.user.name } size={ 32 } />
						<textarea value={ body } onChange={ ( e ) => setBody( e.target.value ) } placeholder="Add an internal note — only staff can see notes." rows={ 2 } />
						<Btn variant="primary" type="submit" busy={ busy } disabled={ ! body.trim() }>
							Add note
						</Btn>
					</form>
				</Card>
			) }
			{ notes.length === 0 ? (
				<EmptyState icon="message" title="No notes yet">
					Notes help the team keep context on calls, refunds and special arrangements.
				</EmptyState>
			) : (
				<ul className="wvm-a-notes__list">
					{ notes.map( ( n ) => (
						<li key={ n.id }>
							<Avatar name={ n.author_name } size={ 32 } />
							<div>
								<div className="wvm-a-notes__meta">
									<strong>{ n.author_name }</strong>
									<span title={ formatDateTime( n.created_at ) }>{ relativeTime( n.created_at ) }</span>
								</div>
								<p>{ n.body }</p>
							</div>
							{ edit && <Btn size="small" variant="ghost" icon="trash" title="Delete note" onClick={ () => setDel( n ) } /> }
						</li>
					) ) }
				</ul>
			) }
			<ConfirmModal
				open={ !! del }
				title="Delete note"
				destructive
				confirmLabel="Delete"
				onClose={ () => setDel( null ) }
				onConfirm={ async () => {
					await api.del( `members/${ memberId }/notes/${ del!.id }` );
					setNotes( notes.filter( ( x ) => x.id !== del!.id ) );
					setDel( null );
					toast.success( 'Note deleted' );
					onChanged();
				} }
			>
				<p>This note will be removed. The deletion is recorded in the audit log.</p>
			</ConfirmModal>
		</div>
	);
}

function MemberAudit( { memberId }: { memberId: number } ) {
	const [ page, setPage ] = useState( 1 );
	const res = useApi< AuditRow[] >( `members/${ memberId }/audit`, { page, per_page: 25 } );
	if ( res.error ) {
		return <ErrorState error={ res.error } onRetry={ res.reload } />;
	}
	if ( ! res.data ) {
		return <Loading />;
	}
	return (
		<Card>
			{ res.data.length ? <AuditTimeline rows={ res.data } /> : <EmptyState icon="list" title="No audit entries" /> }
			{ res.meta && Number( res.meta.pages ) > 1 && (
				<div className="wvm-a-loadmore">
					<Btn size="compact" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
						Newer
					</Btn>
					<Muted>
						Page { page } of { res.meta.pages }
					</Muted>
					<Btn size="compact" disabled={ page >= Number( res.meta.pages ) } onClick={ () => setPage( page + 1 ) }>
						Older
					</Btn>
				</div>
			) }
		</Card>
	);
}

function EmailsTab( { memberId }: { memberId: number } ) {
	const toast = useToast();
	const res = useApi< NotificationRow[] >( `members/${ memberId }/notifications` );
	const [ busy, setBusy ] = useState< number | null >( null );
	return (
		<Card pad={ false }>
			<DataTable
				columns={ [
					{ key: 'subject', header: 'Email', render: ( n ) => <div className="wvm-a-person is-plain"><div><strong>{ n.subject }</strong><span><Mono>{ n.template_key }</Mono></span></div></div> },
					{ key: 'to', header: 'To', render: ( n ) => n.to_email },
					{ key: 'status', header: 'Status', render: ( n ) => <span title={ n.error || '' }><StatusChip status={ n.status } /></span> },
					{ key: 'attempts', header: 'Attempts', align: 'center', render: ( n ) => n.attempts },
					{ key: 'when', header: 'Sent', render: ( n ) => <Muted>{ formatDateTime( n.sent_at || n.created_at ) }</Muted> },
					{
						key: 'act',
						header: '',
						align: 'right',
						render: ( n ) =>
							n.status === 'failed' && can( 'wvm_edit_members' ) ? (
								<Btn
									size="compact"
									icon="refresh"
									busy={ busy === n.id }
									onClick={ async () => {
										setBusy( n.id );
										try {
											await api.post( `notifications/${ n.id }/retry` );
											toast.success( 'Email re-queued' );
											res.reload();
										} catch ( e ) {
											toast.error( e );
										} finally {
											setBusy( null );
										}
									} }
								>
									Retry
								</Btn>
							) : null,
					},
				] }
				rows={ res.data }
				rowKey={ ( n ) => n.id }
				loading={ res.loading }
				error={ res.error }
				onRetry={ res.reload }
				empty={ <EmptyState icon="mail" title="No emails sent to this member yet" /> }
			/>
		</Card>
	);
}

/* ---------------- action modals ---------------- */

function ExtendModal( { open, sub, onClose, onDone }: { open: boolean; sub: Subscription; onClose: () => void; onDone: () => void } ) {
	const [ days, setDays ] = useState( '30' );
	const [ err, setErr ] = useState< unknown >( null );
	useEffect( () => {
		if ( open ) {
			setDays( '30' );
			setErr( null );
		}
	}, [ open ] );
	const n = Number( days );
	const newEnd = sub.current_period_end ? new Date( new Date( sub.current_period_end ).getTime() + n * 86400000 ).toISOString() : null;
	return (
		<ReasonModal
			open={ open }
			title="Extend membership"
			submitLabel={ `Extend by ${ n || 0 } days` }
			canSubmit={ n >= 1 && n <= 3650 }
			description={ `Adds free days to ${ sub.plan.name }. A zero-amount adjustment order is recorded and the member is emailed.` }
			onClose={ onClose }
			onSubmit={ async ( reason ) => {
				try {
					await api.post( `subscriptions/${ sub.id }/extend`, { days: n, reason } );
					onDone();
				} catch ( e ) {
					setErr( e );
					throw e;
				}
			} }
		>
			<div className="wvm-a-quickpick">
				{ [ 7, 14, 30, 90 ].map( ( x ) => (
					<button key={ x } type="button" className={ cx( Number( days ) === x && 'is-active' ) } onClick={ () => setDays( String( x ) ) }>
						+{ x } days
					</button>
				) ) }
			</div>
			<TextField label="Days" type="number" min={ 1 } max={ 3650 } value={ days } onChange={ setDays } error={ fieldError( err, 'days' ) } help={ newEnd ? `New period end ≈ ${ formatDate( newEnd ) }` : undefined } />
		</ReasonModal>
	);
}

function ChangePlanModal( { open, sub, onClose, onDone }: { open: boolean; sub: Subscription; onClose: () => void; onDone: () => void } ) {
	const { plans } = usePlans();
	const [ planId, setPlanId ] = useState( '' );
	const [ mode, setMode ] = useState< 'immediate' | 'at_renewal' >( 'immediate' );
	const [ charge, setCharge ] = useState( false );
	useEffect( () => {
		if ( open ) {
			setPlanId( '' );
			setMode( 'immediate' );
			setCharge( false );
		}
	}, [ open ] );
	const target = plans.find( ( p ) => String( p.id ) === planId );
	const targetPrice = target?.prices[ sub.currency ];
	return (
		<ReasonModal
			open={ open }
			title="Change plan"
			submitLabel="Change plan"
			canSubmit={ !! planId }
			description={ <>Currently on <strong>{ sub.plan.name }</strong> at { sub.amount_display || 'no charge' }.</> }
			onClose={ onClose }
			onSubmit={ async ( reason ) => {
				await api.post( `subscriptions/${ sub.id }/change-plan`, { plan_id: Number( planId ), mode, charge: mode === 'immediate' && charge, reason } );
				onDone();
			} }
		>
			<SelectField
				label="New plan"
				value={ planId }
				onChange={ setPlanId }
				options={ [ { label: 'Choose a plan…', value: '' }, ...planOptions( plans ).filter( ( o ) => o.value !== String( sub.plan_id ) ) ] }
				help={ target ? ( targetPrice !== undefined ? `${ formatMoney( targetPrice, sub.currency ) } ${ sub.currency } price` : target.is_free ? 'Free plan' : `No ${ sub.currency } price on this plan` ) : undefined }
			/>
			<div className="wvm-a-radiocards">
				<label className={ cx( mode === 'immediate' && 'is-active' ) }>
					<input type="radio" checked={ mode === 'immediate' } onChange={ () => setMode( 'immediate' ) } />
					<strong>Immediately</strong>
					<span>Switch now and keep the current period end.</span>
				</label>
				<label className={ cx( mode === 'at_renewal' && 'is-active' ) }>
					<input type="radio" checked={ mode === 'at_renewal' } onChange={ () => setMode( 'at_renewal' ) } />
					<strong>At renewal</strong>
					<span>Switch on { formatDate( sub.current_period_end ) }.</span>
				</label>
			</div>
			{ mode === 'immediate' && <Checkbox label="Charge the prorated difference" checked={ charge } onChange={ setCharge } help="Creates an upgrade order and sends a pay link. Unticked = switch for free." /> }
		</ReasonModal>
	);
}

function CancelModal( { open, sub, onClose, onDone }: { open: boolean; sub: Subscription; onClose: () => void; onDone: ( immediate: boolean ) => void } ) {
	const [ immediate, setImmediate ] = useState( false );
	useEffect( () => {
		if ( open ) {
			setImmediate( false );
		}
	}, [ open ] );
	return (
		<ReasonModal
			open={ open }
			title="Cancel membership"
			destructive
			submitLabel={ immediate ? 'Cancel now' : 'Cancel at period end' }
			description={ immediate ? 'Access ends immediately.' : `Access continues until ${ formatDate( sub.current_period_end ) }, then the membership ends. You can resume before then.` }
			onClose={ onClose }
			onSubmit={ async ( reason ) => {
				await api.post( `subscriptions/${ sub.id }/cancel`, { reason, immediate } );
				onDone( immediate );
			} }
		>
			<Toggle label="End access immediately" checked={ immediate } onChange={ setImmediate } />
		</ReasonModal>
	);
}

function GrantModal( { open, memberId, onClose, onDone }: { open: boolean; memberId: number; onClose: () => void; onDone: () => void } ) {
	const { plans } = usePlans();
	const [ planId, setPlanId ] = useState( '' );
	const [ days, setDays ] = useState( '' );
	const [ startNow, setStartNow ] = useState( true );
	useEffect( () => {
		if ( open ) {
			setPlanId( '' );
			setDays( '' );
			setStartNow( true );
		}
	}, [ open ] );
	return (
		<ReasonModal
			open={ open }
			title="Grant a plan"
			submitLabel="Grant plan"
			canSubmit={ !! planId }
			description="Gives the member a plan without payment — scholarships, partners, comps."
			reasonPlaceholder="e.g. Scholarship — Makerere 2026 cohort"
			onClose={ onClose }
			onSubmit={ async ( reason ) => {
				await api.post( 'subscriptions', { member_id: memberId, plan_id: Number( planId ), reason, start_now: startNow, days: days ? Number( days ) : undefined } );
				onDone();
			} }
		>
			<FieldGrid>
				<SelectField label="Plan" value={ planId } onChange={ setPlanId } options={ [ { label: 'Choose a plan…', value: '' }, ...planOptions( plans ) ] } />
				<TextField label="Length (days)" type="number" min={ 1 } value={ days } onChange={ setDays } help="Empty = one billing period" />
			</FieldGrid>
			<Toggle label="Start immediately" checked={ startNow } onChange={ setStartNow } help="Off = create as pending until the member’s first login." />
		</ReasonModal>
	);
}

function PayLinkModal( { open, memberId, sub, onClose, onDone }: { open: boolean; memberId: number; sub: Subscription | null; onClose: () => void; onDone: () => void } ) {
	const toast = useToast();
	const { plans } = usePlans();
	const [ planId, setPlanId ] = useState( '' );
	const [ currency, setCurrency ] = useState( sub?.currency || config.currencies[ 0 ] );
	const [ result, setResult ] = useState< Order | null >( null );
	useEffect( () => {
		if ( open ) {
			setPlanId( '' );
			setResult( null );
		}
	}, [ open ] );
	const renews = sub && [ 'active', 'past_due', 'suspended', 'expired' ].includes( sub.status ) && ! sub.plan.is_free;
	return (
		<FormModal
			open={ open }
			title="Send pay link"
			submitLabel={ result ? 'Done' : 'Send pay link' }
			canSubmit={ !! result || !! renews || !! planId }
			onClose={ onClose }
			onSubmit={ async () => {
				if ( result ) {
					onClose();
					return;
				}
				const r = await api.post< Order >( `members/${ memberId }/send-pay-link`, planId ? { plan_id: Number( planId ), currency } : {} );
				setResult( r.data );
				toast.success( 'Pay link emailed' );
				onDone();
			} }
			description={ renews && ! planId ? `Leave the plan empty to send a renewal link for ${ sub!.plan.name } (${ sub!.amount_display }).` : 'Creates a pending order and emails the member a one-tap payment link.' }
		>
			{ result ? (
				<div className="wvm-a-paylink">
					<div>
						<span>
							{ result.plan_name } · { result.amount_display }
						</span>
						<Mono>{ result.pay_link }</Mono>
					</div>
				</div>
			) : (
				<FieldGrid>
					<SelectField label="Plan" value={ planId } onChange={ setPlanId } options={ [ { label: renews ? 'Renew current plan' : 'Choose a plan…', value: '' }, ...planOptions( plans, { paidOnly: true } ) ] } />
					<SelectField label="Currency" value={ currency } onChange={ setCurrency } options={ currencyOptions() } disabled={ ! planId } />
				</FieldGrid>
			) }
		</FormModal>
	);
}

function EditMemberModal( { open, d, onClose, onDone }: { open: boolean; d: MemberDetailData; onClose: () => void; onDone: () => void } ) {
	const m = d.member;
	const [ f, setF ] = useState( { name: m.name, phone: m.phone || '', country: m.country || '', timezone: m.timezone || 'Africa/Kampala', marketing_consent: m.marketing_consent, reason: '' } );
	const [ err, setErr ] = useState< unknown >( null );
	useEffect( () => {
		if ( open ) {
			setF( { name: m.name, phone: m.phone || '', country: m.country || '', timezone: m.timezone || 'Africa/Kampala', marketing_consent: m.marketing_consent, reason: '' } );
			setErr( null );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ open ] );
	return (
		<FormModal
			open={ open }
			title="Edit profile"
			submitLabel="Save changes"
			onClose={ onClose }
			onSubmit={ async () => {
				try {
					await api.patch( `members/${ m.id }`, { ...f, reason: f.reason || undefined } );
					onDone();
				} catch ( e ) {
					setErr( e );
					throw e;
				}
			} }
		>
			<FieldGrid>
				<TextField label="Full name" value={ f.name } onChange={ ( name ) => setF( { ...f, name } ) } error={ fieldError( err, 'name' ) } />
				<TextField label="Phone" value={ f.phone } onChange={ ( phone ) => setF( { ...f, phone } ) } error={ fieldError( err, 'phone' ) } />
				<CountrySelect value={ f.country } onChange={ ( country ) => setF( { ...f, country } ) } error={ fieldError( err, 'country' ) } />
				<SelectField label="Timezone" value={ f.timezone } onChange={ ( timezone ) => setF( { ...f, timezone } ) } options={ config.timezones.map( ( t ) => ( { label: t, value: t } ) ) } error={ fieldError( err, 'timezone' ) } />
			</FieldGrid>
			<Toggle label="Marketing consent" checked={ f.marketing_consent } onChange={ ( marketing_consent ) => setF( { ...f, marketing_consent } ) } help="Changing consent re-syncs the member with Kit." />
			<TextareaField label="Reason (optional)" value={ f.reason } onChange={ ( reason ) => setF( { ...f, reason } ) } rows={ 2 } />
			<p className="wvm-a-help">Email changes require the member to verify — they can do this from their dashboard.</p>
		</FormModal>
	);
}

