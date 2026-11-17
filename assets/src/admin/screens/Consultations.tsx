import { useEffect, useState } from 'react';
import type { Route } from '../router';
import { replaceQuery } from '../router';
import { useApi } from '../hooks/useApi';
import { api, fieldError, isoToLocalInput, localInputToIso } from '../lib/api';
import type { Consultation } from '../../shared/types';
import { formatDateTime, relativeTime } from '../../shared/format';
import { PageHeader, Btn, StatusChip, EmptyState, Tabs, Avatar, A, DescList, Skeleton, ErrorState, Card, cx } from '../components/ui';
import { Icon } from '../components/Icon';
import { EntityDrawer } from '../components/overlays';
import { TextField, TextareaField } from '../components/fields';
import { useToast } from '../components/Toasts';

const FLOW: { key: Consultation[ 'status' ]; label: string }[] = [
	{ key: 'new', label: 'New' },
	{ key: 'scheduled', label: 'Scheduled' },
	{ key: 'done', label: 'Done' },
	{ key: 'declined', label: 'Declined' },
];

export function Consultations( { route }: { route: Route } ) {
	const res = useApi< Consultation[] >( 'consultations', { per_page: 100 } );
	const [ tab, setTab ] = useState< string >( route.query.status || 'new' );
	const [ open, setOpen ] = useState< number | null >( route.query.id ? Number( route.query.id ) : null );
	const all = res.data || [];
	const counts = FLOW.reduce< Record< string, number > >( ( a, s ) => ( { ...a, [ s.key ]: all.filter( ( c ) => c.status === s.key ).length } ), {} );
	const rows = tab === 'all' ? all : all.filter( ( c ) => c.status === tab );
	const current = all.find( ( c ) => c.id === open ) || null;
	return (
		<div>
			<PageHeader
				title="Workshops"
				subtitle="Live sessions, recordings and 1:1 consultations"
				tabs={
					<Tabs
						value="consultations"
						tabs={ [
							{ key: 'workshops', label: 'Workshops', icon: 'calendar', to: '/workshops' },
							{ key: 'consultations', label: 'Consultations', icon: 'message', to: '/consultations', count: counts.new || null },
						] }
					/>
				}
			/>
			<div className="wvm-a-inboxbar">
				<Tabs
					variant="segmented"
					value={ tab }
					onChange={ ( t ) => {
						setTab( t );
						replaceQuery( { status: t } );
					} }
					tabs={ [ ...FLOW.map( ( s ) => ( { key: s.key, label: s.label, count: counts[ s.key ] } ) ), { key: 'all', label: 'All', count: all.length } ] }
				/>
			</div>
			{ res.error && <ErrorState error={ res.error } onRetry={ res.reload } /> }
			{ ! res.data && ! res.error && (
				<div className="wvm-a-stack">
					<Skeleton h={ 96 } r={ 18 } />
					<Skeleton h={ 96 } r={ 18 } />
				</div>
			) }
			{ res.data && rows.length === 0 && (
				<Card>
					<EmptyState icon="message" title={ tab === 'new' ? 'Inbox zero' : 'Nothing here' }>
						Premium members can request 1:1 consultations from their dashboard. New requests show up here.
					</EmptyState>
				</Card>
			) }
			<div className="wvm-a-inbox">
				{ rows.map( ( c ) => (
					<button key={ c.id } type="button" className={ cx( 'wvm-a-inbox__item', c.status === 'new' && 'is-unread' ) } onClick={ () => setOpen( c.id ) }>
						<Avatar name={ c.member_name } size={ 40 } />
						<div className="wvm-a-inbox__main">
							<div className="wvm-a-inbox__top">
								<strong>{ c.member_name }</strong>
								<span className="wvm-a-muted">{ c.member_public_id }</span>
								<span className="wvm-a-inbox__time">{ relativeTime( c.created_at ) }</span>
							</div>
							<div className="wvm-a-inbox__topic">{ c.topic }</div>
							<div className="wvm-a-inbox__meta">
								<span>
									<Icon name="clock" size={ 13 } /> { c.status === 'scheduled' && c.scheduled_at ? formatDateTime( c.scheduled_at ) : c.preferred_dates || 'No preference' }
								</span>
							</div>
						</div>
						<StatusChip status={ c.status } />
					</button>
				) ) }
			</div>
			<ConsultationDrawer
				c={ current }
				onClose={ () => {
					setOpen( null );
					if ( route.query.id ) {
						replaceQuery( { status: tab } );
					}
				} }
				onSaved={ ( next ) => res.setData( ( list ) => ( list || [] ).map( ( x ) => ( x.id === next.id ? next : x ) ) ) }
			/>
		</div>
	);
}

function ConsultationDrawer( { c, onClose, onSaved }: { c: Consultation | null; onClose: () => void; onSaved: ( c: Consultation ) => void } ) {
	const toast = useToast();
	const [ status, setStatus ] = useState< Consultation[ 'status' ] >( 'new' );
	const [ when, setWhen ] = useState( '' );
	const [ url, setUrl ] = useState( '' );
	const [ notes, setNotes ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ err, setErr ] = useState< unknown >( null );
	useEffect( () => {
		if ( c ) {
			setStatus( c.status );
			setWhen( isoToLocalInput( c.scheduled_at ) );
			setUrl( c.meeting_url || '' );
			setNotes( c.admin_notes || '' );
			setErr( null );
		}
	}, [ c?.id ] ); // eslint-disable-line react-hooks/exhaustive-deps
	if ( ! c ) {
		return null;
	}
	const save = async ( next: Consultation[ 'status' ] = status ) => {
		setBusy( true );
		setErr( null );
		try {
			const r = await api.patch< Consultation >( `consultations/${ c.id }`, { status: next, scheduled_at: when ? localInputToIso( when ) : undefined, meeting_url: url || undefined, admin_notes: notes } );
			setStatus( r.data.status );
			onSaved( r.data );
			toast.success( next === 'scheduled' && c.status !== 'scheduled' ? 'Scheduled — the member has been emailed' : 'Consultation updated' );
		} catch ( e ) {
			setErr( e );
			toast.error( e );
		} finally {
			setBusy( false );
		}
	};
	const needsSchedule = status === 'scheduled' && ( ! when || ! url );
	return (
		<EntityDrawer
			open
			onClose={ onClose }
			title={ c.topic }
			badges={ <StatusChip status={ c.status } /> }
			subtitle={ `Requested ${ relativeTime( c.created_at ) }` }
			width={ 560 }
			footer={
				<>
					<Btn variant="primary" busy={ busy } disabled={ needsSchedule } onClick={ () => save() }>
						Save
					</Btn>
					{ c.status === 'scheduled' && (
						<Btn icon="check" onClick={ () => save( 'done' ) } disabled={ busy }>
							Mark done
						</Btn>
					) }
					<span className="wvm-a-spacer" />
					{ c.status !== 'declined' && c.status !== 'done' && (
						<Btn variant="ghost" destructive onClick={ () => save( 'declined' ) } disabled={ busy }>
							Decline
						</Btn>
					) }
				</>
			}
		>
			<A to={ `/members/${ c.member_id }` } className="wvm-a-membercard">
				<Avatar name={ c.member_name } size={ 38 } />
				<div>
					<strong>{ c.member_name }</strong>
					<span>
						{ c.member_public_id } · { c.member_email }
					</span>
				</div>
			</A>
			<DescList cols={ 1 } items={ [ [ 'Preferred dates', c.preferred_dates ], [ 'Member’s notes', c.notes ] ] } />
			<div className="wvm-a-subsection">
				<h4>Workflow</h4>
				<div className="wvm-a-flow">
					{ FLOW.map( ( s, i ) => (
						<button key={ s.key } type="button" className={ cx( 'wvm-a-flow__step', status === s.key && 'is-active', FLOW.findIndex( ( x ) => x.key === status ) > i && s.key !== 'declined' && 'is-past' ) } onClick={ () => setStatus( s.key ) }>
							<span>{ i + 1 }</span>
							{ s.label }
						</button>
					) ) }
				</div>
				{ ( status === 'scheduled' || c.scheduled_at ) && (
					<>
						<TextField label="Date & time" type="datetime-local" value={ when } onChange={ setWhen } error={ fieldError( err, 'scheduled_at' ) } help="Shown to the member in their timezone." />
						<TextField label="Meeting URL" value={ url } onChange={ setUrl } placeholder="https://meet.google.com/…" error={ fieldError( err, 'meeting_url' ) } />
						{ needsSchedule && <p className="wvm-a-help">A date and meeting link are needed to schedule — the member gets a confirmation email.</p> }
					</>
				) }
				<TextareaField label="Admin notes" value={ notes } onChange={ setNotes } rows={ 4 } placeholder="Internal notes — not shown to the member." />
			</div>
		</EntityDrawer>
	);
}
