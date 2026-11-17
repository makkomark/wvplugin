import { useEffect, useMemo, useState } from 'react';
import { useApi } from '../hooks/useApi';
import { api, fieldError } from '../lib/api';
import { config, decodeEntities } from '../lib/config';
import type { Organisation, Seat, Order } from '../../shared/types';
import type { AccessPost } from '../lib/types';
import { formatDate, relativeTime, formatDateTime } from '../../shared/format';
import { PageHeader, Btn, StatusChip, EmptyState, Progress, Muted, Mono, Card, Loading, ErrorState, Chip, Avatar, A, DescList, Notice2, CopyButton, Tabs, cx } from '../components/ui';
import { Icon } from '../components/Icon';
import { DataTable } from '../components/DataTable';
import { FormModal, ReasonModal } from '../components/overlays';
import { TextField, TextareaField, LevelSelect, FieldGrid, MoneyInput, SelectField } from '../components/fields';
import { useToast } from '../components/Toasts';
import { OrderDrawer } from './OrderDrawer';

type Modal = 'edit' | 'renew' | 'suspend' | 'reactivate' | 'invoice' | null;

export function OrganisationDetail( { id }: { id: number } ) {
	const toast = useToast();
	const res = useApi< Organisation >( `organisations/${ id }` );
	const [ modal, setModal ] = useState< Modal >( null );
	const [ tab, setTab ] = useState( 'seats' );
	if ( res.error && ! res.data ) {
		return (
			<>
				<PageHeader title="Organisation" crumbs={ [ { label: 'Enterprise', to: '/enterprise/organisations' }, { label: `#${ id }` } ] } />
				<ErrorState error={ res.error } onRetry={ res.reload } />
			</>
		);
	}
	if ( ! res.data ) {
		return <Loading label="Loading organisation…" />;
	}
	const o = res.data;
	const taken = o.seats_used + o.seats_pending;
	const ok = ( msg: string ) => {
		toast.success( msg );
		setModal( null );
		res.reload();
	};
	return (
		<div>
			<PageHeader
				crumbs={ [ { label: 'Enterprise', to: '/enterprise/applications' }, { label: 'Organisations', to: '/enterprise/organisations' }, { label: o.name } ] }
				title={
					<span className="wvm-a-inline">
						{ o.name } <StatusChip status={ o.status } />
					</span>
				}
				subtitle={ o.programme_title || 'Enterprise programme' }
				actions={
					<>
						<Btn icon="edit" onClick={ () => setModal( 'edit' ) }>
							Edit details
						</Btn>
						<Btn icon="calendar" onClick={ () => setModal( 'renew' ) }>
							Renew term
						</Btn>
						<Btn icon="receipt" onClick={ () => setModal( 'invoice' ) }>
							Invoice order
						</Btn>
						{ o.status === 'active' ? (
							<Btn destructive variant="ghost" icon="pause" onClick={ () => setModal( 'suspend' ) }>
								Suspend
							</Btn>
						) : (
							<Btn variant="primary" icon="play" onClick={ () => setModal( 'reactivate' ) }>
								Reactivate
							</Btn>
						) }
					</>
				}
			/>

			<div className="wvm-a-orgtop">
				<Card className="wvm-a-seatcard">
					<div className="wvm-a-seatcard__nums">
						<div>
							<span>Seats used</span>
							<strong>
								{ taken }
								<em> / { o.seats }</em>
							</strong>
						</div>
						<div className="wvm-a-seatcard__legend">
							<span>
								<i className="is-joined" /> { o.seats_used } joined
							</span>
							<span>
								<i className="is-invited" /> { o.seats_pending } invited
							</span>
							<span>
								<i /> { o.seats_available } available
							</span>
						</div>
					</div>
					<div className="wvm-a-seatbar">
						<span className="is-joined" style={ { width: `${ ( o.seats_used / Math.max( 1, o.seats ) ) * 100 }%` } } />
						<span className="is-invited" style={ { width: `${ ( o.seats_pending / Math.max( 1, o.seats ) ) * 100 }%` } } />
					</div>
				</Card>
				<Card className="wvm-a-termcard">
					<DescList
						cols={ 2 }
						items={ [
							[ 'Term', `${ formatDate( o.term_start ) } → ${ formatDate( o.term_end ) }` ],
							[ 'Access level', <Chip key="l" tone="primary">{ o.level_label }</Chip> ],
							[ 'Contract', o.contract_ref ? <Mono>{ o.contract_ref }</Mono> : null ],
							[ 'Contact', o.contact_member_id ? <A to={ `/members/${ o.contact_member_id }` }>{ o.contact_name || o.contact_email }</A> : <Muted>Admin hasn’t joined yet</Muted> ],
						] }
					/>
				</Card>
			</div>
			{ o.status !== 'active' && (
				<Notice2 tone="warning" title={ `Organisation ${ o.status }` }>
					Seat holders don’t have enterprise access while the organisation is { o.status }. New invites are blocked.
				</Notice2>
			) }

			<Tabs
				variant="line"
				value={ tab }
				onChange={ setTab }
				tabs={ [
					{ key: 'seats', label: 'Seats', count: ( o.seats_list || [] ).filter( ( s ) => s.status !== 'revoked' ).length },
					{ key: 'content', label: 'Content', count: ( o.content || [] ).length },
					{ key: 'orders', label: 'Orders', count: ( o.orders || [] ).length },
					{ key: 'notes', label: 'Programme & notes' },
				] }
			/>
			<div className="wvm-a-tabpanel">
				{ tab === 'seats' && <SeatsPanel org={ o } onChanged={ res.reload } /> }
				{ tab === 'content' && <ContentPanel org={ o } onChanged={ res.reload } /> }
				{ tab === 'orders' && <OrdersPanel orders={ o.orders || [] } onChanged={ res.reload } /> }
				{ tab === 'notes' && (
					<Card>
						<DescList cols={ 1 } items={ [ [ 'Programme title', o.programme_title ], [ 'Programme description', o.programme_description ], [ 'Internal notes', o.notes ] ] } />
						<Btn icon="edit" size="compact" onClick={ () => setModal( 'edit' ) }>
							Edit
						</Btn>
					</Card>
				) }
			</div>

			<EditOrgModal open={ modal === 'edit' } org={ o } onClose={ () => setModal( null ) } onDone={ () => ok( 'Organisation updated' ) } />
			<RenewModal open={ modal === 'renew' } org={ o } onClose={ () => setModal( null ) } onDone={ () => ok( 'Term renewed — seat periods updated' ) } />
			<InvoiceModal open={ modal === 'invoice' } org={ o } onClose={ () => setModal( null ) } onDone={ res.reload } />
			<ReasonModal
				open={ modal === 'suspend' }
				title="Suspend organisation"
				destructive
				submitLabel="Suspend"
				description={ `All ${ o.seats_used } seat holders lose enterprise access until you reactivate.` }
				onClose={ () => setModal( null ) }
				onSubmit={ async ( reason ) => {
					await api.post( `organisations/${ o.id }/suspend`, { reason } );
					ok( 'Organisation suspended' );
				} }
			/>
			<ReasonModal
				open={ modal === 'reactivate' }
				title="Reactivate organisation"
				submitLabel="Reactivate"
				reasonOptional
				onClose={ () => setModal( null ) }
				onSubmit={ async ( reason ) => {
					await api.post( `organisations/${ o.id }/reactivate`, { reason } );
					ok( 'Organisation reactivated' );
				} }
			/>
		</div>
	);
}

function SeatsPanel( { org, onChanged }: { org: Organisation; onChanged: () => void } ) {
	const toast = useToast();
	const [ emails, setEmails ] = useState( '' );
	const [ role, setRole ] = useState< 'member' | 'admin' >( 'member' );
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState< { invited: string[]; skipped: Record< string, string > } | null >( null );
	const [ revoke, setRevoke ] = useState< Seat | null >( null );
	const [ showRevoked, setShowRevoked ] = useState( false );
	const [ resending, setResending ] = useState< number | null >( null );
	const seats = ( org.seats_list || [] ).filter( ( s ) => showRevoked || s.status !== 'revoked' );
	const parsed = useMemo( () => emails.split( /[\s,;]+/ ).map( ( e ) => e.trim() ).filter( ( e ) => e.includes( '@' ) ), [ emails ] );
	const invite = async () => {
		setBusy( true );
		setResult( null );
		try {
			const r = await api.post< { invited: string[]; skipped: Record< string, string > } >( `organisations/${ org.id }/invites`, { emails: parsed, role } );
			setResult( r.data );
			if ( r.data.invited.length ) {
				toast.success( `${ r.data.invited.length } invite(s) sent` );
				setEmails( '' );
			}
			onChanged();
		} catch ( e ) {
			toast.error( e );
		} finally {
			setBusy( false );
		}
	};
	return (
		<div className="wvm-a-grid-side">
			<Card pad={ false } title="People" actions={ <label className="wvm-a-inline wvm-a-muted"><input type="checkbox" checked={ showRevoked } onChange={ ( e ) => setShowRevoked( e.target.checked ) } /> Show revoked</label> }>
				<DataTable
					columns={ [
						{
							key: 'who',
							header: 'Person',
							render: ( s ) => (
								<div className="wvm-a-person">
									<Avatar name={ s.name || s.email } size={ 30 } />
									<div>
										<strong>{ s.member_id ? <A to={ `/members/${ s.member_id }` }>{ s.name || s.email }</A> : s.email }</strong>
										<span>{ s.member_id ? s.email : 'Invite pending' }</span>
									</div>
								</div>
							),
						},
						{ key: 'role', header: 'Role', render: ( s ) => <Chip tone={ s.role === 'admin' ? 'primary' : 'neutral' }>{ s.role === 'admin' ? 'Org admin' : 'Member' }</Chip> },
						{ key: 'status', header: 'Status', render: ( s ) => <StatusChip status={ s.status } /> },
						{ key: 'when', header: 'Since', render: ( s ) => <span className="wvm-a-nowrap wvm-a-muted">{ relativeTime( s.joined_at || s.invited_at ) }</span> },
						{
							key: 'act',
							header: '',
							align: 'right',
							render: ( s ) =>
								s.status === 'revoked' ? null : (
									<span className="wvm-a-rowactions">
										{ s.status === 'invited' && (
											<Btn
												size="compact"
												variant="ghost"
												icon="send"
												busy={ resending === s.id }
												onClick={ async () => {
													setResending( s.id );
													try {
														await api.post( `organisations/${ org.id }/invites/${ s.id }/resend` );
														toast.success( `Invite resent to ${ s.email }` );
														onChanged();
													} catch ( e ) {
														toast.error( e );
													} finally {
														setResending( null );
													}
												} }
											>
												Resend
											</Btn>
										) }
										<Btn size="compact" variant="ghost" destructive onClick={ () => setRevoke( s ) }>
											Revoke
										</Btn>
									</span>
								),
						},
					] }
					rows={ seats }
					rowKey={ ( s ) => s.id }
					empty={ <EmptyState icon="users" title="No seats yet">Invite people with the form on the right.</EmptyState> }
				/>
			</Card>
			<Card title="Invite people" icon="send" subtitle={ `${ org.seats_available } seat(s) available` }>
				<TextareaField label="Emails" value={ emails } onChange={ setEmails } rows={ 5 } placeholder={ 'one@acme.com, two@acme.com\nor paste a CSV column' } help={ parsed.length ? `${ parsed.length } email(s) detected` : 'Separate with commas, spaces or new lines.' } />
				<label className="wvm-a-filebtn">
					<Icon name="upload" size={ 14 } /> Import CSV
					<input
						type="file"
						accept=".csv,text/csv,text/plain"
						onChange={ async ( e ) => {
							const file = e.target.files?.[ 0 ];
							if ( file ) {
								const text = await file.text();
								const found = text.match( /[^\s,;"']+@[^\s,;"']+/g ) || [];
								setEmails( ( emails ? emails + '\n' : '' ) + found.join( '\n' ) );
								toast.info( `${ found.length } email(s) read from ${ file.name }` );
							}
							e.target.value = '';
						} }
					/>
				</label>
				<SelectField label="Role" value={ role } onChange={ ( v ) => setRole( v as 'member' | 'admin' ) } options={ [ { value: 'member', label: 'Member — access the programme' }, { value: 'admin', label: 'Org admin — can manage seats' } ] } />
				<Btn variant="primary" icon="send" busy={ busy } disabled={ ! parsed.length || org.status !== 'active' } onClick={ invite }>
					Send { parsed.length || '' } invite{ parsed.length === 1 ? '' : 's' }
				</Btn>
				{ result && Object.keys( result.skipped ).length > 0 && (
					<Notice2 tone="warning" title={ `${ Object.keys( result.skipped ).length } skipped` }>
						<ul className="wvm-a-plainlist">
							{ Object.entries( result.skipped ).map( ( [ e, why ] ) => (
								<li key={ e }>
									<strong>{ e }</strong> — { why }
								</li>
							) ) }
						</ul>
					</Notice2>
				) }
			</Card>
			<ReasonModal
				open={ !! revoke }
				title="Revoke seat"
				destructive
				reasonOptional
				submitLabel="Revoke seat"
				description={ revoke ? ( revoke.member_id ? `${ revoke.name || revoke.email } loses enterprise access immediately.` : `The pending invite for ${ revoke.email } stops working.` ) : null }
				onClose={ () => setRevoke( null ) }
				onSubmit={ async ( reason ) => {
					if ( ! revoke ) {
						return;
					}
					// Joined seats are revoked by member id; pending invites are withdrawn by seat id.
					if ( revoke.member_id ) {
						await api.del( `organisations/${ org.id }/seats/${ revoke.member_id }${ reason ? `?reason=${ encodeURIComponent( reason ) }` : '' }` );
					} else {
						await api.del( `organisations/${ org.id }/invites/${ revoke.id }` );
					}
					toast.success( 'Seat revoked' );
					setRevoke( null );
					onChanged();
				} }
			/>
		</div>
	);
}

function ContentPanel( { org, onChanged }: { org: Organisation; onChanged: () => void } ) {
	const toast = useToast();
	const [ ids, setIds ] = useState< number[] >( ( org.content || [] ).map( ( c ) => c.post_id ) );
	const [ q, setQ ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ known, setKnown ] = useState< Record< number, { title: string; post_type: string } > >( () => Object.fromEntries( ( org.content || [] ).map( ( c ) => [ c.post_id, { title: c.title, post_type: c.post_type } ] ) ) );
	const search = useApi< AccessPost[] >( 'access/posts', { q } );
	useEffect( () => setIds( ( org.content || [] ).map( ( c ) => c.post_id ) ), [ org.content ] );
	const original = ( org.content || [] ).map( ( c ) => c.post_id ).sort().join( ',' );
	const dirty = ids.slice().sort().join( ',' ) !== original;
	return (
		<div className="wvm-a-grid2">
			<Card
				title="Assigned content"
				icon="lock"
				subtitle="Only seat holders of this organisation can see these posts."
				actions={
					<Btn
						variant="primary"
						size="compact"
						busy={ busy }
						disabled={ ! dirty }
						onClick={ async () => {
							setBusy( true );
							try {
								await api.put( `organisations/${ org.id }/content`, { post_ids: ids } );
								toast.success( 'Content assignment saved' );
								onChanged();
							} catch ( e ) {
								toast.error( e );
							} finally {
								setBusy( false );
							}
						} }
					>
						Save changes
					</Btn>
				}
			>
				{ ids.length === 0 ? (
					<EmptyState icon="lock" title="Nothing assigned">Search on the right and add posts or pages.</EmptyState>
				) : (
					<ul className="wvm-a-rowlist">
						{ ids.map( ( pid ) => {
							const c = ( org.content || [] ).find( ( x ) => x.post_id === pid );
							return (
								<li key={ pid }>
									<div>
										<strong>{ decodeEntities( c?.title || known[ pid ]?.title || '' ) || `Post #${ pid }` }</strong>
										<span>
											{ c?.post_type || known[ pid ]?.post_type } { c?.url && <a href={ c.url } target="_blank" rel="noopener noreferrer">View</a> }
										</span>
									</div>
									<Btn size="small" variant="ghost" icon="x" title="Remove" onClick={ () => setIds( ids.filter( ( x ) => x !== pid ) ) } />
								</li>
							);
						} ) }
					</ul>
				) }
			</Card>
			<Card title="Add content" icon="search">
				<div className="wvm-a-searchwrap">
					<input className="wvm-a-input" value={ q } onChange={ ( e ) => setQ( e.target.value ) } placeholder="Search posts and pages…" />
				</div>
				<ul className="wvm-a-rowlist">
					{ ( search.data || [] ).map( ( p ) => {
						const on = ids.includes( p.id );
						return (
							<li key={ p.id } className={ cx( on && 'is-on' ) }>
								<div>
									<strong>{ decodeEntities( p.title ) }</strong>
									<span>
										{ p.post_type } · { p.level_label }
									</span>
								</div>
								<Btn
									size="compact"
									icon={ on ? 'check' : 'plus' }
									variant={ on ? 'secondary' : 'ghost' }
									onClick={ () => {
										setKnown( { ...known, [ p.id ]: { title: decodeEntities( p.title ), post_type: p.post_type } } );
										setIds( on ? ids.filter( ( x ) => x !== p.id ) : [ ...ids, p.id ] );
									} }
								>
									{ on ? 'Added' : 'Add' }
								</Btn>
							</li>
						);
					} ) }
					{ search.data && search.data.length === 0 && <li className="wvm-a-muted">No posts found.</li> }
				</ul>
			</Card>
		</div>
	);
}

function OrdersPanel( { orders, onChanged }: { orders: Order[]; onChanged: () => void } ) {
	const [ open, setOpen ] = useState< number | null >( null );
	return (
		<Card pad={ false }>
			<DataTable
				columns={ [
					{ key: 'id', header: 'Order', render: ( o ) => <Mono>#{ o.id }</Mono> },
					{ key: 'desc', header: 'Description', render: ( o ) => o.description },
					{ key: 'status', header: 'Status', render: ( o ) => <StatusChip status={ o.status } /> },
					{ key: 'date', header: 'Created', render: ( o ) => formatDate( o.created_at ) },
					{ key: 'amount', header: 'Amount', align: 'right', render: ( o ) => <span className="wvm-a-num">{ o.amount_display }</span> },
				] }
				rows={ orders }
				rowKey={ ( o ) => o.id }
				onRowClick={ ( o ) => setOpen( o.id ) }
				empty={ <EmptyState icon="receipt" title="No invoices yet">Use “Invoice order” to bill this organisation.</EmptyState> }
			/>
			<OrderDrawer id={ open } onClose={ () => setOpen( null ) } onChanged={ onChanged } />
		</Card>
	);
}

function EditOrgModal( { open, org, onClose, onDone }: { open: boolean; org: Organisation; onClose: () => void; onDone: () => void } ) {
	const init = () => ( { name: org.name, seats: String( org.seats ), level: org.level, term_start: org.term_start || '', term_end: org.term_end || '', contract_ref: org.contract_ref || '', programme_title: org.programme_title || '', programme_description: org.programme_description || '', notes: org.notes || '' } );
	const [ f, setF ] = useState( init );
	const [ err, setErr ] = useState< unknown >( null );
	useEffect( () => {
		if ( open ) {
			setF( init() );
			setErr( null );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ open ] );
	const set = ( p: Partial< ReturnType< typeof init > > ) => setF( { ...f, ...p } );
	return (
		<FormModal
			open={ open }
			title="Edit organisation"
			onClose={ onClose }
			submitLabel="Save"
			onSubmit={ async () => {
				try {
					await api.patch( `organisations/${ org.id }`, { ...f, seats: Number( f.seats ) } );
					onDone();
				} catch ( e ) {
					setErr( e );
					throw e;
				}
			} }
		>
			<FieldGrid>
				<TextField label="Name" value={ f.name } onChange={ ( name ) => set( { name } ) } error={ fieldError( err, 'name' ) } />
				<TextField label="Seats" type="number" min={ 1 } value={ f.seats } onChange={ ( seats ) => set( { seats } ) } error={ fieldError( err, 'seats' ) } help={ `${ org.seats_used } in use` } />
				<TextField label="Term start" type="date" value={ f.term_start } onChange={ ( term_start ) => set( { term_start } ) } error={ fieldError( err, 'term_start' ) } />
				<TextField label="Term end" type="date" value={ f.term_end } onChange={ ( term_end ) => set( { term_end } ) } error={ fieldError( err, 'term_end' ) } />
				<TextField label="Contract reference" value={ f.contract_ref } onChange={ ( contract_ref ) => set( { contract_ref } ) } />
				<LevelSelect value={ f.level } onChange={ ( level ) => set( { level } ) } />
			</FieldGrid>
			<TextField label="Programme title" value={ f.programme_title } onChange={ ( programme_title ) => set( { programme_title } ) } />
			<TextareaField label="Programme description" value={ f.programme_description } onChange={ ( programme_description ) => set( { programme_description } ) } rows={ 3 } />
			<TextareaField label="Internal notes" value={ f.notes } onChange={ ( notes ) => set( { notes } ) } rows={ 2 } />
		</FormModal>
	);
}

function RenewModal( { open, org, onClose, onDone }: { open: boolean; org: Organisation; onClose: () => void; onDone: () => void } ) {
	const [ end, setEnd ] = useState( '' );
	useEffect( () => {
		if ( open ) {
			const d = org.term_end ? new Date( org.term_end + 'T00:00:00' ) : new Date();
			d.setFullYear( d.getFullYear() + 1 );
			setEnd( d.toISOString().slice( 0, 10 ) );
		}
	}, [ open, org.term_end ] );
	return (
		<ReasonModal
			open={ open }
			title="Renew term"
			submitLabel="Renew term"
			reasonOptional
			canSubmit={ !! end }
			description={ `Current term ends ${ formatDate( org.term_end ) }. All active seats are extended to the new end date.` }
			onClose={ onClose }
			onSubmit={ async ( reason ) => {
				await api.post( `organisations/${ org.id }/renew`, { term_end: end, reason } );
				onDone();
			} }
		>
			<TextField label="New term end" type="date" value={ end } onChange={ setEnd } />
		</ReasonModal>
	);
}

function InvoiceModal( { open, org, onClose, onDone }: { open: boolean; org: Organisation; onClose: () => void; onDone: () => void } ) {
	const [ amount, setAmount ] = useState< number | null >( null );
	const [ currency, setCurrency ] = useState( config.currencies[ 0 ] );
	const [ desc, setDesc ] = useState( '' );
	const [ result, setResult ] = useState< Order | null >( null );
	const [ err, setErr ] = useState< unknown >( null );
	useEffect( () => {
		if ( open ) {
			setAmount( null );
			setDesc( `WitsValley Enterprise programme — ${ org.name }` );
			setResult( null );
			setErr( null );
		}
	}, [ open, org.name ] );
	return (
		<FormModal
			open={ open }
			title="Invoice order"
			submitLabel={ result ? 'Done' : 'Create invoice order' }
			canSubmit={ !! result || ( !! org.contact_member_id && !! amount && amount > 0 ) }
			description="Creates a WooCommerce order and emails a pay link to the organisation contact."
			onClose={ onClose }
			onSubmit={ async () => {
				if ( result ) {
					onClose();
					return;
				}
				try {
					const r = await api.post< Order >( `organisations/${ org.id }/invoice-order`, { amount_minor: amount, currency, description: desc } );
					setResult( r.data );
					onDone();
				} catch ( e ) {
					setErr( e );
					throw e;
				}
			} }
		>
			{ ! org.contact_member_id && ! result && (
				<Notice2 tone="warning" title="No contact yet">
					The organisation admin needs to accept their invite before you can invoice. Invite an admin from the Seats tab.
				</Notice2>
			) }
			{ result ? (
				<>
					<Notice2 tone="success" title={ `Order #${ result.id } — ${ result.amount_display }` }>
						The pay link has been emailed to { org.contact_email || 'the contact' }. Created { formatDateTime( result.created_at ) }.
					</Notice2>
					{ result.pay_link && (
						<div className="wvm-a-paylink">
							<div>
								<span>Pay link</span>
								<Mono>{ result.pay_link }</Mono>
							</div>
							<CopyButton text={ result.pay_link } />
						</div>
					) }
				</>
			) : (
				<>
					<MoneyInput label="Amount" currency={ currency } currencies={ config.currencies } onCurrencyChange={ setCurrency } value={ amount } onChange={ setAmount } error={ fieldError( err, 'amount_minor' ) } />
					<TextField label="Description" value={ desc } onChange={ setDesc } />
				</>
			) }
		</FormModal>
	);
}

