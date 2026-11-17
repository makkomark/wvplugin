import { useEffect, useMemo, useState } from 'react';
import { DropdownMenu, MenuGroup, MenuItem } from '@wordpress/components';
import type { Route } from '../router';
import { replaceQuery } from '../router';
import { useApi } from '../hooks/useApi';
import { api, fieldError, isoToLocalInput, localInputToIso, ymd } from '../lib/api';
import { can, levelLabel } from '../lib/config';
import type { Workshop, Organisation } from '../../shared/types';
import type { EmailTemplateRow } from '../lib/types';
import { formatDate, formatDateTime, STATUS_LABELS } from '../../shared/format';
import { PageHeader, Btn, StatusChip, EmptyState, Tabs, Muted, Chip, Avatar, Notice2, Progress, Mono, cx } from '../components/ui';
import { Icon } from '../components/Icon';
import { DataTable } from '../components/DataTable';
import { FilterBar, FilterSelect } from '../components/FilterBar';
import { EntityDrawer, FormModal, ConfirmModal, ReasonModal } from '../components/overlays';
import { TextField, TextareaField, LevelSelect, SelectField, Checkbox, FieldGrid } from '../components/fields';
import { useToast } from '../components/Toasts';

const STATUSES = [ 'draft', 'scheduled', 'live', 'ended', 'cancelled' ];

function timeRange( w: Workshop ): string {
	const s = new Date( w.starts_at );
	const e = new Date( w.ends_at );
	const t = ( d: Date ) => d.toLocaleTimeString( undefined, { hour: '2-digit', minute: '2-digit' } );
	return `${ t( s ) } – ${ t( e ) }`;
}

export function Workshops( { route }: { route: Route } ) {
	const [ view, setView ] = useState< 'list' | 'calendar' >( route.query.view === 'calendar' ? 'calendar' : 'list' );
	const [ when, setWhen ] = useState< 'upcoming' | 'past' | 'all' >( 'upcoming' );
	const [ status, setStatus ] = useState( '' );
	const [ month, setMonth ] = useState( () => {
		const d = new Date();
		return new Date( d.getFullYear(), d.getMonth(), 1 );
	} );
	const [ editing, setEditing ] = useState< number | 'new' | null >( route.query.new === '1' ? 'new' : route.query.workshop ? Number( route.query.workshop ) : null );
	const [ newDate, setNewDate ] = useState< string | null >( null );
	useEffect( () => {
		if ( route.query.new === '1' ) {
			setEditing( 'new' );
		} else if ( route.query.workshop ) {
			setEditing( Number( route.query.workshop ) );
		}
	}, [ route.query.new, route.query.workshop ] );

	const query = useMemo( () => {
		if ( view === 'calendar' ) {
			const start = new Date( month.getFullYear(), month.getMonth(), 1 );
			start.setDate( start.getDate() - ( ( start.getDay() + 6 ) % 7 ) );
			const end = new Date( start );
			end.setDate( end.getDate() + 41 );
			return { from: ymd( start ), to: ymd( end ), status: status || undefined, per_page: 100 };
		}
		const today = ymd( new Date() );
		return {
			from: when === 'upcoming' ? today : undefined,
			to: when === 'past' ? ymd( new Date( Date.now() - 86400000 ) ) : undefined,
			order: when === 'past' ? 'desc' : undefined,
			status: status || undefined,
			per_page: 50,
		};
	}, [ view, when, status, month ] );
	const list = useApi< Workshop[] >( 'workshops', query );

	const close = () => {
		setEditing( null );
		setNewDate( null );
		if ( route.query.new || route.query.workshop ) {
			replaceQuery( {} );
		}
	};

	return (
		<div>
			<PageHeader
				title="Workshops"
				subtitle="Live sessions, recordings and 1:1 consultations"
				actions={
					<Btn variant="primary" icon="plus" onClick={ () => setEditing( 'new' ) }>
						New workshop
					</Btn>
				}
				tabs={
					<Tabs
						value="workshops"
						tabs={ [
							{ key: 'workshops', label: 'Workshops', icon: 'calendar', to: '/workshops' },
							{ key: 'consultations', label: 'Consultations', icon: 'message', to: '/consultations' },
						] }
					/>
				}
			/>
			<div className="wvm-a-card is-flush">
				<FilterBar
					right={
						<Tabs
							variant="segmented"
							value={ view }
							onChange={ ( v ) => {
								setView( v as 'list' | 'calendar' );
								replaceQuery( { view: v === 'calendar' ? 'calendar' : undefined } );
							} }
							tabs={ [
								{ key: 'list', label: 'List', icon: 'list' },
								{ key: 'calendar', label: 'Calendar', icon: 'grid' },
							] }
						/>
					}
				>
					{ view === 'list' ? (
						<Tabs
							variant="segmented"
							value={ when }
							onChange={ ( v ) => setWhen( v as typeof when ) }
							tabs={ [
								{ key: 'upcoming', label: 'Upcoming' },
								{ key: 'past', label: 'Past' },
								{ key: 'all', label: 'All' },
							] }
						/>
					) : (
						<div className="wvm-a-monthnav">
							<button type="button" aria-label="Previous month" onClick={ () => setMonth( new Date( month.getFullYear(), month.getMonth() - 1, 1 ) ) }>
								<Icon name="chevronLeft" size={ 16 } />
							</button>
							<strong>{ month.toLocaleDateString( undefined, { month: 'long', year: 'numeric' } ) }</strong>
							<button type="button" aria-label="Next month" onClick={ () => setMonth( new Date( month.getFullYear(), month.getMonth() + 1, 1 ) ) }>
								<Icon name="chevronRight" size={ 16 } />
							</button>
							<Btn size="compact" variant="ghost" onClick={ () => setMonth( new Date( new Date().getFullYear(), new Date().getMonth(), 1 ) ) }>
								Today
							</Btn>
						</div>
					) }
					<FilterSelect label="Status" value={ status } onChange={ setStatus } options={ STATUSES.map( ( s ) => ( { value: s, label: STATUS_LABELS[ s ] || s } ) ) } />
				</FilterBar>
				{ view === 'list' ? (
					<DataTable
						columns={ [
							{
								key: 'date',
								header: 'When',
								width: 200,
								render: ( w ) => (
									<div className="wvm-a-datecell">
										<span className="wvm-a-datecell__cal">
											<em>{ new Date( w.starts_at ).toLocaleDateString( undefined, { month: 'short' } ) }</em>
											<strong>{ new Date( w.starts_at ).getDate() }</strong>
										</span>
										<div>
											<strong>{ new Date( w.starts_at ).toLocaleDateString( undefined, { weekday: 'short' } ) }</strong>
											<span>{ timeRange( w ) }</span>
										</div>
									</div>
								),
							},
							{ key: 'title', header: 'Workshop', render: ( w ) => <div className="wvm-a-person is-plain"><div><strong>{ w.title }</strong><span>{ w.host || 'No host set' }</span></div></div> },
							{ key: 'level', header: 'Access', render: ( w ) => <Chip tone={ w.required_level >= 2 ? 'primary' : w.required_level === 0 ? 'info' : 'neutral' }>{ w.required_level_label }</Chip> },
							{ key: 'status', header: 'Status', render: ( w ) => <StatusChip status={ w.status } /> },
							{
								key: 'regs',
								header: 'Registrations',
								render: ( w ) => (
									<div className="wvm-a-regcell">
										<span>
											<strong>{ w.registration_count }</strong>
											{ w.capacity ? <Muted> / { w.capacity }</Muted> : null }
										</span>
										{ w.capacity ? <Progress value={ w.registration_count } max={ w.capacity } /> : null }
									</div>
								),
							},
							{ key: 'rec', header: '', render: ( w ) => ( w.recording_url ? <span className="wvm-a-inline wvm-a-muted"><Icon name="video" size={ 14 } /> Recording</span> : null ) },
							{ key: 'act', header: '', align: 'right', render: ( w ) => <WorkshopMenu w={ w } onChanged={ list.reload } onEdit={ () => setEditing( w.id ) } /> },
						] }
						rows={ list.data }
						rowKey={ ( w ) => w.id }
						loading={ list.loading }
						error={ list.error }
						onRetry={ list.reload }
						onRowClick={ ( w ) => setEditing( w.id ) }
						empty={
							<EmptyState icon="calendar" title={ when === 'past' ? 'No past workshops' : 'No upcoming workshops' } action={ <Btn icon="plus" onClick={ () => setEditing( 'new' ) }>New workshop</Btn> }>
								Schedule a live session — eligible members are reminded 24h and 1h before.
							</EmptyState>
						}
					/>
				) : (
					<Calendar
						month={ month }
						items={ list.data || [] }
						onOpen={ ( id ) => setEditing( id ) }
						onNew={ ( d ) => {
							setNewDate( d );
							setEditing( 'new' );
						} }
					/>
				) }
			</div>
			<WorkshopEditor id={ editing } defaultDate={ newDate } onClose={ close } onSaved={ ( w ) => { list.reload(); if ( w ) { setEditing( w.id ); } } } onDeleted={ () => { list.reload(); close(); } } />
		</div>
	);
}

function Calendar( { month, items, onOpen, onNew }: { month: Date; items: Workshop[]; onOpen: ( id: number ) => void; onNew: ( ymd: string ) => void } ) {
	const start = new Date( month.getFullYear(), month.getMonth(), 1 );
	start.setDate( start.getDate() - ( ( start.getDay() + 6 ) % 7 ) );
	const days = Array.from( { length: 42 }, ( _, i ) => {
		const d = new Date( start );
		d.setDate( d.getDate() + i );
		return d;
	} );
	const today = ymd( new Date() );
	const byDay = new Map< string, Workshop[] >();
	items.forEach( ( w ) => {
		const k = ymd( new Date( w.starts_at ) );
		byDay.set( k, [ ...( byDay.get( k ) || [] ), w ] );
	} );
	return (
		<div className="wvm-a-cal">
			{ [ 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ].map( ( d ) => (
				<div key={ d } className="wvm-a-cal__dow">
					{ d }
				</div>
			) ) }
			{ days.map( ( d ) => {
				const k = ymd( d );
				const list = byDay.get( k ) || [];
				return (
					<div key={ k } className={ cx( 'wvm-a-cal__day', d.getMonth() !== month.getMonth() && 'is-out', k === today && 'is-today' ) }>
						<div className="wvm-a-cal__num">
							<span>{ d.getDate() }</span>
							<button type="button" className="wvm-a-cal__add" aria-label={ `New workshop on ${ k }` } onClick={ () => onNew( k ) }>
								<Icon name="plus" size={ 12 } />
							</button>
						</div>
						{ list.map( ( w ) => (
							<button key={ w.id } type="button" className={ `wvm-a-cal__ev is-${ w.status }` } onClick={ () => onOpen( w.id ) } title={ `${ w.title } · ${ timeRange( w ) }` }>
								<em>{ new Date( w.starts_at ).toLocaleTimeString( undefined, { hour: '2-digit', minute: '2-digit' } ) }</em>
								<span>{ w.title }</span>
							</button>
						) ) }
					</div>
				);
			} ) }
		</div>
	);
}

type WAction = 'publish' | 'cancel' | 'delete' | 'recording' | 'notify' | null;

function useWorkshopActions( w: Workshop | undefined, onChanged: ( w?: Workshop ) => void, onDeleted?: () => void ) {
	const toast = useToast();
	const [ action, setAction ] = useState< WAction >( null );
	const [ url, setUrl ] = useState( '' );
	const [ template, setTemplate ] = useState( 'workshop_24h' );
	const templates = useApi< EmailTemplateRow[] >( action === 'notify' ? 'email-templates' : null );
	useEffect( () => {
		if ( action === 'recording' ) {
			setUrl( w?.recording_url || '' );
		}
	}, [ action, w?.recording_url ] );
	const modals = w ? (
		<>
			<ConfirmModal
				open={ action === 'publish' }
				title="Publish workshop"
				confirmLabel="Publish"
				onClose={ () => setAction( null ) }
				onConfirm={ async () => {
					const r = await api.post< Workshop >( `workshops/${ w.id }/publish` );
					toast.success( 'Workshop published — reminders scheduled' );
					setAction( null );
					onChanged( r.data );
				} }
			>
				<p>
					<strong>{ w.title }</strong> becomes visible to eligible members ({ w.required_level_label } and above) and 24h/1h reminders are scheduled.
				</p>
			</ConfirmModal>
			<ReasonModal
				open={ action === 'cancel' }
				title="Cancel workshop"
				destructive
				reasonOptional
				submitLabel="Cancel workshop"
				description={ `${ w.registration_count } registered member(s) get an in-app notice.` }
				onClose={ () => setAction( null ) }
				onSubmit={ async ( reason ) => {
					const r = await api.post< Workshop >( `workshops/${ w.id }/cancel`, { reason } );
					toast.success( 'Workshop cancelled' );
					setAction( null );
					onChanged( r.data );
				} }
			/>
			<ConfirmModal
				open={ action === 'delete' }
				title="Delete draft"
				destructive
				confirmLabel="Delete"
				onClose={ () => setAction( null ) }
				onConfirm={ async () => {
					await api.del( `workshops/${ w.id }` );
					toast.success( 'Draft deleted' );
					setAction( null );
					onDeleted?.();
					onChanged();
				} }
			>
				<p>Drafts can be deleted permanently. Published workshops are cancelled instead.</p>
			</ConfirmModal>
			<FormModal
				open={ action === 'recording' }
				title="Attach recording"
				submitLabel="Save recording"
				canSubmit={ /^https?:\/\//.test( url ) }
				onClose={ () => setAction( null ) }
				description="Members who are eligible see the recording in their Past workshops. The workshop is marked as ended."
				onSubmit={ async () => {
					const r = await api.post< Workshop >( `workshops/${ w.id }/recording`, { url } );
					toast.success( 'Recording attached' );
					setAction( null );
					onChanged( r.data );
				} }
			>
				<TextField label="Recording URL" value={ url } onChange={ setUrl } placeholder="https://youtu.be/…" autoFocus help="Use an unlisted YouTube link or any members-only video URL." />
			</FormModal>
			<FormModal
				open={ action === 'notify' }
				title="Notify eligible members"
				submitLabel="Send"
				onClose={ () => setAction( null ) }
				description={ `Emails every member with ${ w.required_level_label } access or higher.` }
				onSubmit={ async () => {
					const r = await api.post< { recipients: number } >( `workshops/${ w.id }/notify`, { template_key: template } );
					toast.success( `Queued for ${ r.data.recipients } member(s)` );
					setAction( null );
				} }
			>
				<SelectField
					label="Email template"
					value={ template }
					onChange={ setTemplate }
					options={ ( templates.data || [ { key: 'workshop_24h', name: 'Workshop reminder (24h)' } as EmailTemplateRow, { key: 'workshop_1h', name: 'Workshop reminder (1h)' } as EmailTemplateRow ] )
						.filter( ( t ) => t.key.startsWith( 'workshop' ) || t.key === 'pay_link' )
						.map( ( t ) => ( { value: t.key, label: t.name } ) ) }
				/>
			</FormModal>
		</>
	) : null;
	return { setAction, modals };
}

function WorkshopMenu( { w, onChanged, onEdit }: { w: Workshop; onChanged: () => void; onEdit: () => void } ) {
	const { setAction, modals } = useWorkshopActions( w, onChanged );
	return (
		<span className="wvm-a-stop">
			<DropdownMenu icon={ <Icon name="more" size={ 18 } /> } label="Workshop actions" toggleProps={ { size: 'compact' } as never } popoverProps={ { placement: 'bottom-end', className: 'wvm-a-popover' } as never }>
				{ ( { onClose }: { onClose: () => void } ) => (
					<MenuGroup>
						<MenuItem icon={ <Icon name="edit" size={ 16 } /> } onClick={ () => ( onClose(), onEdit() ) }>
							Edit
						</MenuItem>
						{ ( w.status === 'draft' || w.status === 'cancelled' ) && (
							<MenuItem icon={ <Icon name="send" size={ 16 } /> } onClick={ () => ( onClose(), setAction( 'publish' ) ) }>
								Publish
							</MenuItem>
						) }
						{ [ 'scheduled', 'live' ].includes( w.status ) && (
							<MenuItem icon={ <Icon name="mail" size={ 16 } /> } onClick={ () => ( onClose(), setAction( 'notify' ) ) }>
								Notify members
							</MenuItem>
						) }
						<MenuItem icon={ <Icon name="video" size={ 16 } /> } onClick={ () => ( onClose(), setAction( 'recording' ) ) }>
							{ w.recording_url ? 'Change recording' : 'Attach recording' }
						</MenuItem>
						{ w.status !== 'cancelled' && w.status !== 'draft' && (
							<MenuItem icon={ <Icon name="x" size={ 16 } /> } isDestructive onClick={ () => ( onClose(), setAction( 'cancel' ) ) }>
								Cancel workshop
							</MenuItem>
						) }
						{ w.status === 'draft' && (
							<MenuItem icon={ <Icon name="trash" size={ 16 } /> } isDestructive onClick={ () => ( onClose(), setAction( 'delete' ) ) }>
								Delete draft
							</MenuItem>
						) }
					</MenuGroup>
				) }
			</DropdownMenu>
			{ modals }
		</span>
	);
}

interface WForm { title: string; description: string; starts_at: string; ends_at: string; host: string; required_level: number; organisation_id: string; join_url: string; capacity: string; publish: boolean }

function blankForm( date?: string | null ): WForm {
	const d = date ? new Date( date + 'T18:00' ) : new Date( Date.now() + 7 * 86400000 );
	if ( ! date ) {
		d.setHours( 18, 0, 0, 0 );
	}
	const e = new Date( d.getTime() + 3600000 );
	return { title: '', description: '', starts_at: isoToLocalInput( d.toISOString() ), ends_at: isoToLocalInput( e.toISOString() ), host: 'WitsValley Team', required_level: 1, organisation_id: '', join_url: '', capacity: '', publish: false };
}

function WorkshopEditor( { id, defaultDate, onClose, onSaved, onDeleted }: { id: number | 'new' | null; defaultDate: string | null; onClose: () => void; onSaved: ( w?: Workshop ) => void; onDeleted: () => void } ) {
	const toast = useToast();
	const isNew = id === 'new';
	const res = useApi< Workshop >( typeof id === 'number' ? `workshops/${ id }` : null );
	const orgs = useApi< Organisation[] >( can( 'wvm_manage_enterprise' ) && id ? 'organisations' : null );
	const w = typeof id === 'number' && res.data?.id === id ? res.data : undefined;
	const [ f, setF ] = useState< WForm >( blankForm() );
	const [ err, setErr ] = useState< unknown >( null );
	const [ busy, setBusy ] = useState( false );
	const actions = useWorkshopActions(
		w,
		( nw ) => {
			res.reload();
			onSaved( nw );
		},
		onDeleted
	);
	useEffect( () => {
		setErr( null );
		if ( isNew ) {
			setF( blankForm( defaultDate ) );
		} else if ( w ) {
			setF( { title: w.title, description: w.description, starts_at: isoToLocalInput( w.starts_at ), ends_at: isoToLocalInput( w.ends_at ), host: w.host, required_level: w.required_level, organisation_id: w.organisation_id ? String( w.organisation_id ) : '', join_url: w.join_url || '', capacity: w.capacity ? String( w.capacity ) : '', publish: false } );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ id, w?.id, w?.status, w?.recording_url, defaultDate ] );
	const set = ( p: Partial< WForm > ) => setF( { ...f, ...p } );
	const save = async () => {
		setBusy( true );
		setErr( null );
		const body = {
			title: f.title,
			description: f.description,
			starts_at: localInputToIso( f.starts_at ),
			ends_at: localInputToIso( f.ends_at ),
			host: f.host,
			required_level: f.required_level,
			organisation_id: f.organisation_id ? Number( f.organisation_id ) : null,
			join_url: f.join_url || null,
			capacity: f.capacity ? Number( f.capacity ) : null,
		};
		try {
			if ( isNew ) {
				const r = await api.post< Workshop >( 'workshops', { ...body, publish: f.publish } );
				toast.success( f.publish ? 'Workshop created and published' : 'Draft workshop created' );
				onSaved( r.data );
			} else if ( w ) {
				const r = await api.patch< Workshop >( `workshops/${ w.id }`, body );
				toast.success( 'Workshop saved' );
				res.setData( () => r.data );
				onSaved( r.data );
			}
		} catch ( e ) {
			setErr( e );
		} finally {
			setBusy( false );
		}
	};
	return (
		<>
			<EntityDrawer
				open={ !! id }
				onClose={ onClose }
				title={ isNew ? 'New workshop' : w?.title || 'Workshop' }
				badges={ w && <StatusChip status={ w.status } /> }
				subtitle={ w ? formatDateTime( w.starts_at ) : 'Times are in your browser’s timezone and stored in UTC.' }
				width={ 620 }
				footer={
					<>
						<Btn variant="primary" busy={ busy } onClick={ save } disabled={ ! f.title.trim() }>
							{ isNew ? ( f.publish ? 'Create & publish' : 'Create draft' ) : 'Save changes' }
						</Btn>
						{ w && ( w.status === 'draft' || w.status === 'cancelled' ) && (
							<Btn icon="send" onClick={ () => actions.setAction( 'publish' ) }>
								Publish
							</Btn>
						) }
						{ w && [ 'scheduled', 'live' ].includes( w.status ) && (
							<Btn icon="mail" onClick={ () => actions.setAction( 'notify' ) }>
								Notify
							</Btn>
						) }
						{ w && (
							<Btn icon="video" variant="ghost" onClick={ () => actions.setAction( 'recording' ) }>
								Recording
							</Btn>
						) }
						<span className="wvm-a-spacer" />
						{ w && w.status === 'draft' && <Btn variant="ghost" destructive icon="trash" onClick={ () => actions.setAction( 'delete' ) } title="Delete draft" /> }
						{ w && w.status !== 'draft' && w.status !== 'cancelled' && (
							<Btn variant="ghost" destructive onClick={ () => actions.setAction( 'cancel' ) }>
								Cancel workshop
							</Btn>
						) }
					</>
				}
			>
				{ !! err && <Notice2 tone="danger" title="Please check the form">{ Object.values( ( err as { fields?: Record< string, string > } ).fields || {} ).join( ' ' ) || ( err as Error ).message }</Notice2> }
				<TextField label="Title" value={ f.title } onChange={ ( title ) => set( { title } ) } error={ fieldError( err, 'title' ) } autoFocus={ isNew } />
				<TextareaField label="Description" value={ f.description } onChange={ ( description ) => set( { description } ) } rows={ 3 } />
				<FieldGrid>
					<TextField label="Starts" type="datetime-local" value={ f.starts_at } onChange={ ( starts_at ) => set( { starts_at } ) } error={ fieldError( err, 'starts_at' ) } />
					<TextField label="Ends" type="datetime-local" value={ f.ends_at } onChange={ ( ends_at ) => set( { ends_at } ) } error={ fieldError( err, 'ends_at' ) } />
					<TextField label="Host" value={ f.host } onChange={ ( host ) => set( { host } ) } />
					<LevelSelect label="Who can join" value={ f.required_level } onChange={ ( required_level ) => set( { required_level } ) } help={ `${ levelLabel( f.required_level ) } members and above` } />
					<TextField label="Join URL" value={ f.join_url } onChange={ ( join_url ) => set( { join_url } ) } placeholder="https://meet.google.com/…" error={ fieldError( err, 'join_url' ) } help="Revealed to eligible members 15 minutes before start." />
					<TextField label="Capacity" type="number" min={ 0 } value={ f.capacity } onChange={ ( capacity ) => set( { capacity } ) } placeholder="Unlimited" error={ fieldError( err, 'capacity' ) } />
				</FieldGrid>
				{ can( 'wvm_manage_enterprise' ) && (
					<SelectField
						label="Private to organisation"
						value={ f.organisation_id }
						onChange={ ( organisation_id ) => set( { organisation_id } ) }
						options={ [ { value: '', label: 'No — open to all eligible members' }, ...( orgs.data || [] ).map( ( o ) => ( { value: String( o.id ), label: o.name } ) ) ] }
					/>
				) }
				{ isNew && <Checkbox label="Publish immediately" checked={ f.publish } onChange={ ( publish ) => set( { publish } ) } help="Leave unticked to save as a draft." /> }

				{ w && (
					<div className="wvm-a-subsection">
						<h4>
							Registrations <Muted>({ w.registration_count }{ w.capacity ? ` of ${ w.capacity }` : '' })</Muted>
						</h4>
						{ w.capacity ? <Progress value={ w.registration_count } max={ w.capacity } /> : null }
						{ w.recording_url && (
							<p className="wvm-a-inline">
								<Icon name="video" size={ 14 } /> <a href={ w.recording_url } target="_blank" rel="noopener noreferrer">{ w.recording_url }</a>
							</p>
						) }
						{ w.registrations && w.registrations.length ? (
							<ul className="wvm-a-rowlist">
								{ w.registrations.map( ( r ) => (
									<li key={ r.member_id }>
										<div className="wvm-a-person">
											<Avatar name={ r.name } size={ 28 } />
											<div>
												<strong>{ r.name }</strong>
												<span>
													<Mono>{ r.public_id }</Mono> · { r.email }
												</span>
											</div>
										</div>
										<Muted>{ formatDate( r.created_at ) }</Muted>
									</li>
								) ) }
							</ul>
						) : (
							<p className="wvm-a-muted">No one has registered yet.</p>
						) }
					</div>
				) }
			</EntityDrawer>
			{ actions.modals }
		</>
	);
}
