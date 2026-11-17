import { useEffect, useState } from 'react';
import type { Route } from '../router';
import { navigate, replaceQuery } from '../router';
import { useApi } from '../hooks/useApi';
import { api, fieldError, todayYmd } from '../lib/api';
import { config, countryName } from '../lib/config';
import type { Application, Organisation } from '../../shared/types';
import { formatDate, relativeTime } from '../../shared/format';
import { PageHeader, Btn, StatusChip, EmptyState, Tabs, DescList, A, ErrorState, Skeleton, Notice2, cx } from '../components/ui';
import { Icon } from '../components/Icon';
import { EntityDrawer, FormModal } from '../components/overlays';
import { TextField, TextareaField, FieldGrid } from '../components/fields';
import { useToast } from '../components/Toasts';

export const PIPELINE: { key: Application[ 'status' ]; label: string }[] = [
	{ key: 'new', label: 'New' },
	{ key: 'in_review', label: 'In review' },
	{ key: 'quoted', label: 'Quoted' },
	{ key: 'accepted', label: 'Accepted' },
	{ key: 'declined', label: 'Declined' },
];

export function EnterpriseTabs( { value, newCount }: { value: 'applications' | 'organisations'; newCount?: number | null } ) {
	return (
		<Tabs
			value={ value }
			tabs={ [
				{ key: 'applications', label: 'Applications', icon: 'inbox', to: '/enterprise/applications', count: newCount || null },
				{ key: 'organisations', label: 'Organisations', icon: 'building', to: '/enterprise/organisations' },
			] }
		/>
	);
}

export function Applications( { route }: { route: Route } ) {
	const res = useApi< Application[] >( 'applications', { per_page: 100 } );
	const [ open, setOpen ] = useState< number | null >( route.query.app ? Number( route.query.app ) : null );
	const [ drag, setDrag ] = useState< number | null >( null );
	const toast = useToast();
	const counts = ( res.meta?.counts || {} ) as Record< string, number >;
	const apps = res.data || [];
	const current = apps.find( ( a ) => a.id === open ) || null;

	const move = async ( id: number, status: Application[ 'status' ] ) => {
		const a = apps.find( ( x ) => x.id === id );
		if ( ! a || a.status === status ) {
			return;
		}
		res.setData( ( l ) => ( l || [] ).map( ( x ) => ( x.id === id ? { ...x, status } : x ) ) );
		try {
			await api.patch( `applications/${ id }`, { status } );
			toast.success( `${ a.org_name } moved to ${ PIPELINE.find( ( p ) => p.key === status )?.label }` );
			res.reload();
		} catch ( e ) {
			toast.error( e );
			res.reload();
		}
	};

	return (
		<div>
			<PageHeader title="Enterprise" subtitle="Applications from the enterprise form, and the organisations you serve" tabs={ <EnterpriseTabs value="applications" newCount={ counts.new } /> } actions={ <Btn icon="external" href={ config.enterpriseUrl } target="_blank">View application form</Btn> } />
			{ res.error && <ErrorState error={ res.error } onRetry={ res.reload } /> }
			<div className="wvm-a-kanban">
				{ PIPELINE.map( ( col ) => {
					const items = apps.filter( ( a ) => a.status === col.key );
					return (
						<section
							key={ col.key }
							className={ cx( 'wvm-a-kanban__col', `is-${ col.key }` ) }
							onDragOver={ ( e ) => e.preventDefault() }
							onDrop={ () => {
								if ( drag !== null ) {
									move( drag, col.key );
								}
								setDrag( null );
							} }
						>
							<header>
								<StatusChip status={ col.key } label={ col.label } />
								<span className="wvm-a-kanban__count">{ counts[ col.key ] ?? items.length }</span>
							</header>
							<div className="wvm-a-kanban__list">
								{ ! res.data && <Skeleton h={ 110 } r={ 14 } /> }
								{ res.data && items.length === 0 && <div className="wvm-a-kanban__empty">No applications</div> }
								{ items.map( ( a ) => (
									<article key={ a.id } className="wvm-a-appcard" draggable onDragStart={ () => setDrag( a.id ) } onDragEnd={ () => setDrag( null ) } onClick={ () => setOpen( a.id ) } tabIndex={ 0 } onKeyDown={ ( e ) => e.key === 'Enter' && setOpen( a.id ) }>
										<strong>{ a.org_name }</strong>
										<span>{ a.programme_type || 'Programme not specified' }</span>
										<div className="wvm-a-appcard__meta">
											<span>
												<Icon name="users" size={ 12 } /> { a.participants ?? '—' }
											</span>
											<span>
												<Icon name="globe" size={ 12 } /> { a.country || '—' }
											</span>
											<span>{ relativeTime( a.created_at ) }</span>
										</div>
										{ a.organisation_id && (
											<span className="wvm-a-appcard__org">
												<Icon name="building" size={ 12 } /> Converted
											</span>
										) }
									</article>
								) ) }
							</div>
						</section>
					);
				} ) }
			</div>
			{ res.data && apps.length === 0 && (
				<EmptyState icon="inbox" title="No applications yet">
					Applications submitted from the Enterprise page appear here as a pipeline.
				</EmptyState>
			) }
			<ApplicationDrawer
				app={ current }
				onClose={ () => {
					setOpen( null );
					if ( route.query.app ) {
						replaceQuery( {} );
					}
				} }
				onSaved={ res.reload }
			/>
		</div>
	);
}

function ApplicationDrawer( { app, onClose, onSaved }: { app: Application | null; onClose: () => void; onSaved: () => void } ) {
	const toast = useToast();
	const [ status, setStatus ] = useState< Application[ 'status' ] >( 'new' );
	const [ notes, setNotes ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ converting, setConverting ] = useState( false );
	useEffect( () => {
		if ( app ) {
			setStatus( app.status );
			setNotes( app.admin_notes || '' );
		}
	}, [ app?.id ] ); // eslint-disable-line react-hooks/exhaustive-deps
	if ( ! app ) {
		return null;
	}
	return (
		<>
			<EntityDrawer
				open
				onClose={ onClose }
				title={ app.org_name }
				badges={ <StatusChip status={ app.status } /> }
				subtitle={ `Applied ${ formatDate( app.created_at ) }` }
				width={ 600 }
				footer={
					<>
						<Btn
							variant="primary"
							busy={ busy }
							onClick={ async () => {
								setBusy( true );
								try {
									await api.patch( `applications/${ app.id }`, { status, admin_notes: notes } );
									toast.success( 'Application updated' );
									onSaved();
								} catch ( e ) {
									toast.error( e );
								} finally {
									setBusy( false );
								}
							} }
						>
							Save
						</Btn>
						<span className="wvm-a-spacer" />
						{ app.organisation_id ? (
							<Btn icon="building" onClick={ () => navigate( `/enterprise/organisations/${ app.organisation_id }` ) }>
								Open organisation
							</Btn>
						) : (
							<Btn icon="sparkles" onClick={ () => setConverting( true ) }>
								Convert to organisation
							</Btn>
						) }
					</>
				}
			>
				<div className="wvm-a-contactcard">
					<div>
						<strong>{ app.contact_name }</strong>
						<a href={ `mailto:${ app.email }` }>{ app.email }</a>
						{ app.phone && <a href={ `tel:${ app.phone }` }>{ app.phone }</a> }
					</div>
					<Btn size="compact" icon="mail" href={ `mailto:${ app.email }?subject=${ encodeURIComponent( 'Your WitsValley programme enquiry' ) }` }>
						Email
					</Btn>
				</div>
				<DescList
					items={ [
						[ 'Country', countryName( app.country ) ],
						[ 'Organisation size', app.org_size ],
						[ 'Programme', app.programme_type ],
						[ 'Participants', app.participants ],
						[ 'Duration', app.duration ],
						[ 'Start', app.start_preference ],
						[ 'Budget', app.budget_range ],
					] }
				/>
				{ app.message && <blockquote className="wvm-a-quote">{ app.message }</blockquote> }
				<div className="wvm-a-subsection">
					<h4>Pipeline</h4>
					<div className="wvm-a-flow">
						{ PIPELINE.map( ( s, i ) => (
							<button key={ s.key } type="button" className={ cx( 'wvm-a-flow__step', status === s.key && 'is-active' ) } onClick={ () => setStatus( s.key ) }>
								<span>{ i + 1 }</span>
								{ s.label }
							</button>
						) ) }
					</div>
					<TextareaField label="Admin notes" value={ notes } onChange={ setNotes } rows={ 4 } placeholder="Quote sent, call notes, decision makers…" />
				</div>
				{ app.organisation_id && (
					<Notice2 tone="success" title="Converted">
						This application became <A to={ `/enterprise/organisations/${ app.organisation_id }` }>organisation #{ app.organisation_id }</A>.
					</Notice2>
				) }
			</EntityDrawer>
			<ConvertModal open={ converting } app={ app } onClose={ () => setConverting( false ) } />
		</>
	);
}

function ConvertModal( { open, app, onClose }: { open: boolean; app: Application; onClose: () => void } ) {
	const toast = useToast();
	const [ f, setF ] = useState( { name: '', seats: '', term_start: '', term_end: '', contract_ref: '', programme_title: '', contact_email: '' } );
	const [ err, setErr ] = useState< unknown >( null );
	useEffect( () => {
		if ( open ) {
			const end = new Date();
			end.setFullYear( end.getFullYear() + 1 );
			setF( { name: app.org_name, seats: String( app.participants || 10 ), term_start: todayYmd(), term_end: end.toISOString().slice( 0, 10 ), contract_ref: '', programme_title: app.programme_type || '', contact_email: app.email } );
			setErr( null );
		}
	}, [ open, app ] );
	const set = ( p: Partial< typeof f > ) => setF( { ...f, ...p } );
	return (
		<FormModal
			open={ open }
			title="Convert to organisation"
			submitLabel="Create organisation"
			description="Creates the organisation, marks the application accepted and invites the contact as the organisation admin."
			onClose={ onClose }
			onSubmit={ async () => {
				try {
					const r = await api.post< Organisation >( `applications/${ app.id }/convert`, { ...f, seats: Number( f.seats ) } );
					toast.success( `${ r.data.name } created — invite sent to ${ f.contact_email }` );
					onClose();
					navigate( `/enterprise/organisations/${ r.data.id }` );
				} catch ( e ) {
					setErr( e );
					throw e;
				}
			} }
		>
			<FieldGrid>
				<TextField label="Organisation name" value={ f.name } onChange={ ( name ) => set( { name } ) } error={ fieldError( err, 'name' ) } />
				<TextField label="Seats" type="number" min={ 1 } value={ f.seats } onChange={ ( seats ) => set( { seats } ) } error={ fieldError( err, 'seats' ) } />
				<TextField label="Term start" type="date" value={ f.term_start } onChange={ ( term_start ) => set( { term_start } ) } error={ fieldError( err, 'term_start' ) } />
				<TextField label="Term end" type="date" value={ f.term_end } onChange={ ( term_end ) => set( { term_end } ) } error={ fieldError( err, 'term_end' ) } />
				<TextField label="Contract reference" value={ f.contract_ref } onChange={ ( contract_ref ) => set( { contract_ref } ) } placeholder="e.g. KTH-2026-01" />
				<TextField label="Programme title" value={ f.programme_title } onChange={ ( programme_title ) => set( { programme_title } ) } />
			</FieldGrid>
			<TextField label="Contact email (org admin)" type="email" value={ f.contact_email } onChange={ ( contact_email ) => set( { contact_email } ) } help="Gets an invite to accept the admin seat." />
		</FormModal>
	);
}
