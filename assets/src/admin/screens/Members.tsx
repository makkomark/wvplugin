import { useEffect, useMemo, useState } from 'react';
import { DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import type { Route } from '../router';
import { navigate, replaceQuery } from '../router';
import { useApi } from '../hooks/useApi';
import { usePlans, planOptions } from '../hooks/usePlans';
import { useSavedViews } from '../hooks/useSavedViews';
import { api, fieldError } from '../lib/api';
import { can, config, countryName } from '../lib/config';
import type { MemberRow } from '../../shared/types';
import type { EmailTemplateRow } from '../lib/types';
import { formatDate, formatMoney, STATUS_LABELS } from '../../shared/format';
import { PageHeader, Btn, Avatar, StatusChip, EmptyState, Mono, Muted, Chip } from '../components/ui';
import { Icon } from '../components/Icon';
import { DataTable } from '../components/DataTable';
import type { Column } from '../components/DataTable';
import { FilterBar, SearchInput, FilterSelect, FilterDate } from '../components/FilterBar';
import { FormModal } from '../components/overlays';
import { TextField, SelectField, CountrySelect, Checkbox, FieldGrid, TextareaField, countryOptions } from '../components/fields';
import { useToast } from '../components/Toasts';

export interface MemberFilters {
	q: string;
	plan: string;
	status: string;
	country: string;
	method: string;
	joined_from: string;
	joined_to: string;
	renewal_within_days: string;
	sort: string;
}

const EMPTY: MemberFilters = { q: '', plan: '', status: '', country: '', method: '', joined_from: '', joined_to: '', renewal_within_days: '', sort: '' };
const STATUSES = [ 'active', 'past_due', 'suspended', 'expired', 'cancelled', 'cancelling', 'none' ];

function fromQuery( q: Record< string, string > ): MemberFilters {
	const f = { ...EMPTY };
	( Object.keys( EMPTY ) as ( keyof MemberFilters )[] ).forEach( ( k ) => {
		if ( q[ k ] ) {
			f[ k ] = q[ k ];
		}
	} );
	return f;
}

export function Members( { route }: { route: Route } ) {
	const toast = useToast();
	const [ filters, setFilters ] = useState< MemberFilters >( () => fromQuery( route.query ) );
	const [ page, setPage ] = useState( Number( route.query.page ) || 1 );
	const [ selected, setSelected ] = useState< Set< number | string > >( new Set() );
	const [ adding, setAdding ] = useState( route.query.new === '1' );
	const [ savingView, setSavingView ] = useState( false );
	const { plans } = usePlans();
	const views = useSavedViews< MemberFilters >( 'members' );

	// Home links (#/members?status=past_due) re-enter this screen with a new query.
	useEffect( () => {
		setFilters( fromQuery( route.query ) );
		setPage( Number( route.query.page ) || 1 );
		if ( route.query.new === '1' ) {
			setAdding( true );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ JSON.stringify( route.query ) ] );

	const query = useMemo( () => ( { ...filters, page, per_page: 20 } ), [ filters, page ] );
	const list = useApi< MemberRow[] >( 'members', query );

	const update = ( patch: Partial< MemberFilters > ) => {
		const next = { ...filters, ...patch };
		setFilters( next );
		setPage( 1 );
		replaceQuery( next as unknown as Record< string, unknown > );
	};
	const active = Object.entries( filters ).some( ( [ k, v ] ) => k !== 'sort' && v );
	const planOpts = plans.map( ( p ) => ( { label: p.name, value: p.slug } ) );

	const columns: Column< MemberRow >[] = [
		{
			key: 'name',
			header: 'Member',
			sort: 'name',
			render: ( m ) => (
				<div className="wvm-a-person">
					<Avatar name={ m.name } size={ 34 } />
					<div>
						<strong>{ m.name }</strong>
						<span>{ m.email }</span>
					</div>
				</div>
			),
		},
		{ key: 'public_id', header: 'ID', render: ( m ) => <Mono>{ m.public_id }</Mono> },
		{
			key: 'plan',
			header: 'Plan',
			render: ( m ) => ( m.plan_name ? <span className="wvm-a-strong">{ m.plan_name }</span> : <Muted>—</Muted> ),
		},
		{
			key: 'status',
			header: 'Status',
			render: ( m ) => (
				<span className="wvm-a-inline">
					<StatusChip status={ m.status === 'active' && m.cancel_at_period_end ? 'cancelling' : m.status } />
					{ m.kit_sync_error && (
						<span className="wvm-a-warnicon" title={ 'Kit: ' + m.kit_sync_error }>
							<Icon name="tag" size={ 13 } />
						</span>
					) }
				</span>
			),
		},
		{ key: 'renewal', header: 'Renews / ends', sort: 'renewal', render: ( m ) => ( m.period_end ? formatDate( m.period_end ) : <Muted>—</Muted> ) },
		{ key: 'amount', header: 'Price', align: 'right', render: ( m ) => ( m.amount_minor && m.currency ? <span className="wvm-a-num">{ formatMoney( m.amount_minor, m.currency ) }</span> : <Muted>Free</Muted> ) },
		{ key: 'country', header: 'Country', render: ( m ) => ( m.country ? <span title={ countryName( m.country ) }>{ m.country }</span> : <Muted>—</Muted> ) },
		{ key: 'joined', header: 'Joined', sort: 'joined', render: ( m ) => <Muted>{ formatDate( m.created_at ) }</Muted> },
	];

	const exportUrl = api.downloadUrl( 'members/export.csv', filters as unknown as Record< string, string > );

	return (
		<div>
			<PageHeader
				title="Members"
				subtitle={ list.meta ? `${ Number( list.meta.total ).toLocaleString() } ${ active ? 'matching' : 'total' } members` : 'Everyone with a WitsValley account' }
				actions={
					<>
						<Btn icon="download" href={ exportUrl }>
							Export CSV
						</Btn>
						{ can( 'wvm_edit_members' ) && (
							<Btn variant="primary" icon="plus" onClick={ () => setAdding( true ) }>
								Add member
							</Btn>
						) }
					</>
				}
			/>

			<div className="wvm-a-card is-flush">
				<FilterBar
					active={ active }
					onClear={ () => update( { ...EMPTY, sort: filters.sort } ) }
					right={
						<DropdownMenu
							icon={ <Icon name="bookmark" size={ 16 } /> }
							label="Saved views"
							text={ 'Saved views' + ( views.views.length ? ` (${ views.views.length })` : '' ) }
							toggleProps={ { className: 'wvm-a-btn wvm-a-viewsbtn', __next40pxDefaultSize: true } as never }
							popoverProps={ { placement: 'bottom-end', className: 'wvm-a-popover' } as never }
						>
							{ ( { onClose }: { onClose: () => void } ) => (
								<>
									<MenuGroup label="Saved views">
										{ views.views.length === 0 && <div className="wvm-a-menuempty">No saved views yet. Filter the list, then save it here.</div> }
										{ views.views.map( ( v ) => (
											<div key={ v.name } className="wvm-a-viewrow">
												<MenuItem
													onClick={ () => {
														update( v.filters );
														onClose();
													} }
												>
													{ v.name }
												</MenuItem>
												<button type="button" className="wvm-a-iconbtn is-danger" aria-label={ `Delete view ${ v.name }` } onClick={ () => views.remove( v.name ) }>
													<Icon name="trash" size={ 13 } />
												</button>
											</div>
										) ) }
									</MenuGroup>
									<MenuGroup>
										<MenuItem
											icon={ <Icon name="plus" size={ 16 } /> }
											disabled={ ! active }
											onClick={ () => {
												setSavingView( true );
												onClose();
											} }
										>
											Save current filters…
										</MenuItem>
									</MenuGroup>
								</>
							) }
						</DropdownMenu>
					}
				>
					<SearchInput value={ filters.q } onChange={ ( q ) => update( { q } ) } placeholder="Name, email, phone or WV-ID" />
					<FilterSelect label="Status" value={ filters.status } onChange={ ( status ) => update( { status } ) } options={ STATUSES.map( ( s ) => ( { value: s, label: STATUS_LABELS[ s ] || s } ) ) } />
					<FilterSelect label="Plan" value={ filters.plan } onChange={ ( plan ) => update( { plan } ) } options={ planOpts } />
					<FilterSelect label="Country" value={ filters.country } onChange={ ( country ) => update( { country } ) } options={ countryOptions() } />
					<FilterSelect label="Paid via" value={ filters.method } onChange={ ( method ) => update( { method } ) } options={ config.gateways.map( ( g ) => ( { value: g.id, label: g.title } ) ) } />
					<FilterSelect
						label="Renews within"
						value={ filters.renewal_within_days }
						onChange={ ( v ) => update( { renewal_within_days: v } ) }
						options={ [ 1, 3, 7, 14, 30 ].map( ( n ) => ( { value: String( n ), label: n === 1 ? '24 hours' : `${ n } days` } ) ) }
					/>
					<FilterDate label="Joined from" value={ filters.joined_from } onChange={ ( v ) => update( { joined_from: v } ) } />
					<FilterDate label="to" value={ filters.joined_to } onChange={ ( v ) => update( { joined_to: v } ) } />
				</FilterBar>

				{ selected.size > 0 && <BulkBar ids={ Array.from( selected ).map( Number ) } onDone={ () => setSelected( new Set() ) } /> }

				<DataTable
					columns={ columns }
					rows={ list.data }
					rowKey={ ( m ) => m.id }
					loading={ list.loading }
					error={ list.error }
					onRetry={ list.reload }
					meta={ list.meta }
					onPage={ ( p ) => {
						setPage( p );
						replaceQuery( { ...filters, page: p } as unknown as Record< string, unknown > );
					} }
					sort={ filters.sort }
					onSort={ ( sort ) => update( { sort } ) }
					onRowClick={ ( m ) => navigate( `/members/${ m.id }` ) }
					selectable={ can( 'wvm_edit_members' ) }
					selected={ selected }
					onSelect={ setSelected }
					empty={
						<EmptyState icon="users" title={ active ? 'No members match these filters' : 'No members yet' } action={ active ? <Btn onClick={ () => update( EMPTY ) }>Clear filters</Btn> : undefined }>
							{ active ? 'Try widening the search or removing a filter.' : 'Members appear here when they sign up or you add them.' }
						</EmptyState>
					}
				/>
			</div>

			<AddMemberModal
				open={ adding }
				onClose={ () => {
					setAdding( false );
					if ( route.query.new ) {
						replaceQuery( filters as unknown as Record< string, unknown > );
					}
				} }
				plans={ planOptions( plans, { paidOnly: false } ) }
				onCreated={ ( id ) => {
					toast.success( 'Member created' );
					navigate( `/members/${ id }` );
				} }
			/>
			<SaveViewModal
				open={ savingView }
				onClose={ () => setSavingView( false ) }
				onSave={ ( name ) => {
					views.save( name, filters );
					setSavingView( false );
					toast.success( `Saved view “${ name }”` );
				} }
			/>
		</div>
	);
}

function BulkBar( { ids, onDone }: { ids: number[]; onDone: () => void } ) {
	const toast = useToast();
	const [ tag, setTag ] = useState( 'WV-' );
	const [ template, setTemplate ] = useState( '' );
	const [ busy, setBusy ] = useState( '' );
	const templates = useApi< EmailTemplateRow[] >( 'email-templates' );
	const run = async ( action: 'kit_tag' | 'email_template', value: string ) => {
		setBusy( action );
		try {
			const r = await api.post< { queued: number } >( 'members/bulk', { ids, action, value } );
			toast.success( action === 'kit_tag' ? `Kit tag “${ value }” queued for ${ r.data.queued } member(s)` : `Email queued for ${ r.data.queued } member(s)` );
			onDone();
		} catch ( e ) {
			toast.error( e );
		} finally {
			setBusy( '' );
		}
	};
	return (
		<div className="wvm-a-bulkbar">
			<span className="wvm-a-bulkbar__count">
				<strong>{ ids.length }</strong> selected
			</span>
			<div className="wvm-a-bulkbar__group">
				<Icon name="tag" size={ 15 } />
				<input value={ tag } onChange={ ( e ) => setTag( e.target.value.toUpperCase() ) } placeholder="WV-TAG" aria-label="Kit tag" />
				<Btn size="compact" busy={ busy === 'kit_tag' } disabled={ tag.trim().length < 4 } onClick={ () => run( 'kit_tag', tag.trim() ) }>
					Add Kit tag
				</Btn>
			</div>
			<div className="wvm-a-bulkbar__group">
				<Icon name="mail" size={ 15 } />
				<select value={ template } onChange={ ( e ) => setTemplate( e.target.value ) } aria-label="Email template">
					<option value="">Choose email…</option>
					{ ( templates.data || [] ).map( ( t ) => (
						<option key={ t.key } value={ t.key } disabled={ ! t.enabled }>
							{ t.name }
						</option>
					) ) }
				</select>
				<Btn size="compact" busy={ busy === 'email_template' } disabled={ ! template } onClick={ () => run( 'email_template', template ) }>
					Send email
				</Btn>
			</div>
			<button type="button" className="wvm-a-bulkbar__clear" onClick={ onDone }>
				Clear selection
			</button>
		</div>
	);
}

function AddMemberModal( { open, onClose, plans, onCreated }: { open: boolean; onClose: () => void; plans: { label: string; value: string }[]; onCreated: ( id: number ) => void } ) {
	const blank = { name: '', email: '', phone: '', country: 'UG', plan_id: '', days: '', reason: '', send_welcome: true };
	const [ f, setF ] = useState( blank );
	const [ err, setErr ] = useState< unknown >( null );
	useEffect( () => {
		if ( open ) {
			setF( blank );
			setErr( null );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ open ] );
	const set = ( patch: Partial< typeof blank > ) => setF( { ...f, ...patch } );
	return (
		<FormModal
			open={ open }
			title="Add member"
			onClose={ onClose }
			submitLabel="Create member"
			description="Creates a WordPress account and a WitsValley member. Optionally grant a plan without payment (e.g. scholarship)."
			canSubmit={ !! f.name.trim() && !! f.email.trim() && ( ! f.plan_id || !! f.reason.trim() ) }
			onSubmit={ async () => {
				try {
					const r = await api.post< { member: { id: number } } >( 'members', {
						name: f.name.trim(),
						email: f.email.trim(),
						phone: f.phone.trim() || undefined,
						country: f.country || undefined,
						plan_id: f.plan_id ? Number( f.plan_id ) : undefined,
						days: f.days ? Number( f.days ) : undefined,
						reason: f.reason.trim() || undefined,
						send_welcome: f.send_welcome,
					} );
					onCreated( r.data.member.id );
				} catch ( e ) {
					setErr( e );
					throw e;
				}
			} }
		>
			<FieldGrid>
				<TextField label="Full name" value={ f.name } onChange={ ( name ) => set( { name } ) } error={ fieldError( err, 'name' ) } autoFocus />
				<TextField label="Email" type="email" value={ f.email } onChange={ ( email ) => set( { email } ) } error={ fieldError( err, 'email' ) } />
				<TextField label="Phone" value={ f.phone } onChange={ ( phone ) => set( { phone } ) } placeholder="+256…" error={ fieldError( err, 'phone' ) } />
				<CountrySelect value={ f.country } onChange={ ( country ) => set( { country } ) } error={ fieldError( err, 'country' ) } />
			</FieldGrid>
			<div className="wvm-a-subsection">
				<h4>Initial plan</h4>
				<FieldGrid>
					<SelectField label="Plan" value={ f.plan_id } onChange={ ( plan_id ) => set( { plan_id } ) } options={ [ { label: 'Free plan (default)', value: '' }, ...plans ] } error={ fieldError( err, 'plan_id' ) } />
					<TextField label="Length (days)" type="number" min={ 1 } value={ f.days } onChange={ ( days ) => set( { days } ) } help="Leave empty for one billing period" disabled={ ! f.plan_id } />
				</FieldGrid>
				{ f.plan_id && <TextareaField label="Reason" value={ f.reason } onChange={ ( reason ) => set( { reason } ) } rows={ 2 } placeholder="e.g. Scholarship — 2026 cohort" error={ fieldError( err, 'reason' ) } /> }
			</div>
			<Checkbox label="Email the member a link to set their password" checked={ f.send_welcome } onChange={ ( send_welcome ) => set( { send_welcome } ) } />
			{ f.plan_id && <Chip tone="info" icon="info">Granting a paid plan without payment is recorded in the audit log.</Chip> }
		</FormModal>
	);
}

function SaveViewModal( { open, onClose, onSave }: { open: boolean; onClose: () => void; onSave: ( name: string ) => void } ) {
	const [ name, setName ] = useState( '' );
	useEffect( () => {
		if ( open ) {
			setName( '' );
		}
	}, [ open ] );
	return (
		<FormModal open={ open } title="Save view" size="small" onClose={ onClose } submitLabel="Save view" canSubmit={ !! name.trim() } onSubmit={ () => onSave( name.trim() ) } description="Saved views are stored in this browser for your user.">
			<TextField label="View name" value={ name } onChange={ setName } placeholder="e.g. Past due in Uganda" autoFocus />
		</FormModal>
	);
}
