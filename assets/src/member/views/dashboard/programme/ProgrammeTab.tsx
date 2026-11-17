/* Our programme (seat holders) + Team sub-tab (org admins). */
import { useState } from '@wordpress/element';
import type { FormEvent } from 'react';
import type { Organisation, Seat, Workshop } from '../../../../shared/types';
import { formatDate } from '../../../../shared/format';
import { api, errorMessage } from '../../../lib/api';
import { cx, formatTime, formatWhen, parseEmails, zoneLabel } from '../../../lib/util';
import { useAsync } from '../../../hooks/useAsync';
import { Card, CardHeader, Chip, StatusChip } from '../../../components/Card';
import { Button } from '../../../components/Button';
import { Textarea } from '../../../components/Field';
import { Banner, EmptyState, ErrorState, Skeleton, SkeletonCard, ProgressBar } from '../../../components/Feedback';
import { Icon } from '../../../components/Icon';
import { Avatar } from '../../../components/Avatar';
import { Confirm } from '../../../components/Confirm';
import { useToast } from '../../../components/Toast';
import { useDashboard, useTz } from '../context';

export function ProgrammeTab( { sub }: { sub: 'overview' | 'team' } ) {
	const { overview } = useDashboard();
	const org = useAsync< Organisation | null >( async () => ( await api.get< Organisation | null >( 'me/organisation' ) ).data, [] );
	const isAdmin = overview.organisation?.role === 'admin';

	return (
		<div className="wvm-page">
			<div className="wvm-page__head">
				<div>
					<p className="wvm-eyebrow">{ overview.organisation?.name }</p>
					<h1 className="wvm-h1">Our programme</h1>
				</div>
				{ isAdmin && (
					<nav className="wvm-seg" aria-label="Programme sections">
						<a href="#/programme" className={ cx( 'wvm-seg__opt', sub === 'overview' && 'is-active' ) } aria-current={ sub === 'overview' ? 'page' : undefined }>Overview</a>
						<a href="#/programme/team" className={ cx( 'wvm-seg__opt', sub === 'team' && 'is-active' ) } aria-current={ sub === 'team' ? 'page' : undefined }>Team</a>
					</nav>
				) }
			</div>
			{ org.error && ! org.data && <ErrorState message={ org.error } onRetry={ org.reload } /> }
			{ org.loading && org.data === undefined && (
				<div className="wvm-grid">
					<SkeletonCard hero height={ 200 } />
					<SkeletonCard height={ 200 } />
				</div>
			) }
			{ org.data === null && (
				<Card pad="lg">
					<EmptyState icon="building" title="You're not part of an organisation programme">If your employer or school invited you, open the link in your invitation email.</EmptyState>
				</Card>
			) }
			{ org.data && ( sub === 'team' && isAdmin ? <Team org={ org.data } onOrgChange={ org.reload } /> : <Overview org={ org.data } /> ) }
		</div>
	);
}

function Overview( { org }: { org: Organisation } ) {
	const tz = useTz();
	const workshops = useAsync< Workshop[] >( async () => ( await api.get< Workshop[] >( 'me/workshops', { when: 'upcoming' } ) ).data.filter( ( w ) => w.organisation_id === org.id ), [ org.id ] );
	const content = org.content || [];

	return (
		<div className="wvm-grid">
			<Card variant="hero" pad="lg" className="wvm-progcard">
				<div className="wvm-plancard__glow" aria-hidden="true" />
				<span className="wvm-plancard__eyebrow">Programme</span>
				<h2 className="wvm-plancard__name">{ org.programme_title || org.name }</h2>
				{ org.programme_description && <p className="wvm-progcard__desc">{ org.programme_description }</p> }
				<div className="wvm-plancard__meta">
					<div>
						<span className="wvm-plancard__metalabel">Organisation</span>
						<span className="wvm-plancard__metavalue">{ org.name }</span>
					</div>
					<div>
						<span className="wvm-plancard__metalabel">Term ends</span>
						<span className="wvm-plancard__metavalue">{ formatDate( org.term_end ) }</span>
					</div>
					<div>
						<span className="wvm-plancard__metalabel">Access</span>
						<span className="wvm-plancard__metavalue">{ org.level_label }</span>
					</div>
				</div>
			</Card>

			<Card pad="lg">
				<CardHeader icon="calendar" title="Upcoming sessions" subtitle="Private workshops for your organisation." />
				{ ! workshops.data && <div className="wvm-stack"><Skeleton h={ 52 } r={ 12 } /><Skeleton h={ 52 } r={ 12 } /></div> }
				{ workshops.data && workshops.data.length === 0 && <EmptyState compact icon="calendar" title="No sessions scheduled">Your programme manager will add sessions here.</EmptyState> }
				{ workshops.data && workshops.data.length > 0 && (
					<ul className="wvm-list">
						{ workshops.data.map( ( w ) => (
							<li key={ w.id } className="wvm-list__item">
								<div>
									<p className="wvm-list__title">{ w.title }</p>
									<p className="wvm-list__meta">{ formatWhen( w.starts_at, tz ) } – { formatTime( w.ends_at, tz ) } { zoneLabel( tz ) }{ w.host && ` · ${ w.host }` }</p>
								</div>
								<Button size="sm" variant="secondary" href="#/workshops">Details</Button>
							</li>
						) ) }
					</ul>
				) }
			</Card>

			<Card pad="lg" className="wvm-span-2">
				<CardHeader icon="book" title="Materials" subtitle="Lessons and resources selected for your programme." />
				{ content.length === 0 ? (
					<EmptyState compact icon="book" title="No materials yet">Materials appear here as your programme manager adds them.</EmptyState>
				) : (
					<ul className="wvm-materials">
						{ content.map( ( c ) => (
							<li key={ c.post_id }>
								<a className="wvm-material" href={ c.url }>
									<span className="wvm-material__icon" aria-hidden="true"><Icon name={ /video|lesson/i.test( c.post_type ) ? 'play' : 'book' } size={ 20 } /></span>
									<span className="wvm-material__body">
										<span className="wvm-material__title">{ c.title }</span>
										{ c.excerpt && <span className="wvm-material__text">{ c.excerpt }</span> }
									</span>
									<Icon name="arrow-right" size={ 18 } className="wvm-material__go" />
								</a>
							</li>
						) ) }
					</ul>
				) }
			</Card>
		</div>
	);
}

interface InviteResult { invited: string[]; skipped: Record< string, string > }

function Team( { org, onOrgChange }: { org: Organisation; onOrgChange: () => void } ) {
	const { overview } = useDashboard();
	const toast = useToast();
	const seats = useAsync< Seat[] >( async () => ( await api.get< Seat[] >( 'me/organisation/seats' ) ).data, [] );
	const [ text, setText ] = useState( '' );
	const [ error, setError ] = useState< string | undefined >();
	const [ busy, setBusy ] = useState( false );
	const [ result, setResult ] = useState< InviteResult | null >( null );
	const [ removing, setRemoving ] = useState< Seat | null >( null );
	const [ withdrawing, setWithdrawing ] = useState< Seat | null >( null );
	const [ resending, setResending ] = useState< number | null >( null );

	const resend = async ( s: Seat ) => {
		setResending( s.id );
		try {
			const { data } = await api.post< Seat[] >( `me/organisation/invites/${ s.id }/resend` );
			seats.setData( data );
			toast.success( `Invite resent to ${ s.email }.` );
		} catch ( e ) {
			toast.error( errorMessage( e ) );
		} finally {
			setResending( null );
		}
	};

	const parsed = parseEmails( text );
	const list = seats.data || [];
	const used = list.filter( ( s ) => s.status === 'joined' ).length;
	const pending = list.filter( ( s ) => s.status === 'invited' ).length;
	const available = Math.max( 0, org.seats - used - pending );

	const invite = async ( e: FormEvent ) => {
		e.preventDefault();
		setError( undefined );
		if ( parsed.length === 0 ) {
			setError( 'Paste at least one email address.' );
			return;
		}
		setBusy( true );
		try {
			// Lines with commas look like a CSV export: let the server parse it.
			const isCsv = /,.*\n|\n.*,/.test( text ) || /;/.test( text );
			const { data } = await api.post< InviteResult >( 'me/organisation/invites', isCsv ? { csv: text } : { emails: parsed } );
			setResult( data );
			if ( data.invited.length ) {
				setText( '' );
				toast.success( `${ data.invited.length } invite${ data.invited.length === 1 ? '' : 's' } sent.` );
			}
			seats.reload();
			onOrgChange();
		} catch ( err ) {
			setError( errorMessage( err ) );
		} finally {
			setBusy( false );
		}
	};

	const onFile = ( file: File | undefined ) => {
		if ( ! file ) {
			return;
		}
		const reader = new FileReader();
		reader.onload = () => setText( String( reader.result || '' ) );
		reader.readAsText( file );
	};

	return (
		<div className="wvm-grid">
			<Card pad="lg">
				<CardHeader icon="users" title="Seats" subtitle={ `${ org.name } · term ends ${ formatDate( org.term_end ) }` } />
				<div className="wvm-seats">
					<div className="wvm-seats__nums">
						<span className="wvm-stat">{ used }</span>
						<span className="wvm-muted"> of { org.seats } seats used</span>
					</div>
					<ProgressBar value={ org.seats ? ( used + pending ) / org.seats : 0 } />
					<div className="wvm-seats__legend">
						<span><i className="wvm-legend wvm-legend--used" /> { used } joined</span>
						<span><i className="wvm-legend wvm-legend--pending" /> { pending } invited</span>
						<span><i className="wvm-legend" /> { available } available</span>
					</div>
				</div>
			</Card>

			<Card pad="lg">
				<CardHeader icon="plus" title="Invite people" subtitle="Each person gets an email with a link to join. Seats are counted when you invite." />
				<form className="wvm-stack" onSubmit={ invite } noValidate>
					<Textarea
						label="Email addresses"
						rows={ 4 }
						value={ text }
						onValue={ setText }
						placeholder={ 'jane@company.com, john@company.com\nor paste a CSV column' }
						error={ error }
						hint={ parsed.length ? `${ parsed.length } email${ parsed.length === 1 ? '' : 's' } found${ parsed.length > available ? ` · only ${ available } seat${ available === 1 ? '' : 's' } left` : '' }` : 'Separate with commas, spaces or new lines.' }
					/>
					<div className="wvm-row wvm-row--between">
						<label className="wvm-btn wvm-btn--ghost wvm-btn--sm wvm-file">
							<Icon name="download" size={ 16 } className="wvm-rot-180" />
							<span className="wvm-btn__label">Upload CSV</span>
							<input type="file" accept=".csv,text/csv,text/plain" onChange={ ( e ) => onFile( e.target.files?.[ 0 ] ) } />
						</label>
						<Button type="submit" loading={ busy } disabled={ available === 0 } icon="mail">Send invites</Button>
					</div>
					{ available === 0 && <Banner tone="warning">All seats are in use. Remove someone or contact us to add seats.</Banner> }
				</form>
				{ result && (
					<div className="wvm-invite-result">
						{ result.invited.length > 0 && (
							<Banner tone="success" title={ `Invited ${ result.invited.length }` }>{ result.invited.join( ', ' ) }</Banner>
						) }
						{ Object.keys( result.skipped ).length > 0 && (
							<Banner tone="warning" title={ `Skipped ${ Object.keys( result.skipped ).length }` }>
								<ul className="wvm-skipped">
									{ Object.entries( result.skipped ).map( ( [ email, why ] ) => (
										<li key={ email }><strong>{ email }</strong> — { why }</li>
									) ) }
								</ul>
							</Banner>
						) }
					</div>
				) }
			</Card>

			<Card pad="none" className="wvm-span-2">
				<div className="wvm-pad wvm-pad--b0">
					<CardHeader icon="users" title="Team members" subtitle={ `${ list.filter( ( s ) => s.status !== 'revoked' ).length } people` } />
				</div>
				{ ! seats.data && ! seats.error && <div className="wvm-pad wvm-stack">{ [ 0, 1, 2 ].map( ( i ) => <Skeleton key={ i } h={ 52 } r={ 12 } /> ) }</div> }
				{ seats.error && ! seats.data && <div className="wvm-pad"><p className="wvm-muted">{ seats.error }</p></div> }
				{ seats.data && (
					<div className="wvm-table wvm-table--seats" role="table" aria-label="Team members">
						<div className="wvm-table__head" role="row">
							<span role="columnheader">Person</span>
							<span role="columnheader">Role</span>
							<span role="columnheader">Status</span>
							<span role="columnheader">Since</span>
							<span role="columnheader" className="wvm-right"><span className="wvm-sr">Actions</span></span>
						</div>
						{ list.map( ( s ) => {
							const self = s.member_id === overview.member.id;
							return (
								<div key={ s.id } className={ cx( 'wvm-table__row', s.status === 'revoked' && 'is-muted' ) } role="row">
									<span role="cell" className="wvm-person">
										<Avatar name={ s.name || s.email } size={ 34 } />
										<span>
											<span className="wvm-table__title">{ s.name || s.email }{ self && <span className="wvm-subtle"> (you)</span> }</span>
											{ s.name && <span className="wvm-table__sub">{ s.email }</span> }
										</span>
									</span>
									<span role="cell"><Chip tone={ s.role === 'admin' ? 'primary' : 'neutral' }>{ s.role === 'admin' ? 'Admin' : 'Member' }</Chip></span>
									<span role="cell"><StatusChip status={ s.status } /></span>
									<span role="cell" className="wvm-table__date">{ formatDate( s.joined_at || s.invited_at || s.revoked_at ) }</span>
									<span role="cell" className="wvm-right">
										{ ! self && s.status === 'joined' && s.member_id && (
											<Button size="sm" variant="ghost" icon="trash" onClick={ () => setRemoving( s ) } aria-label={ `Remove ${ s.name || s.email }` }>Remove</Button>
										) }
										{ s.status === 'invited' && (
											<span className="wvm-row wvm-row--tight wvm-seat-actions">
												<Button size="sm" variant="ghost" icon="mail" onClick={ () => resend( s ) } loading={ resending === s.id } aria-label={ `Resend invite to ${ s.email }` }>Resend</Button>
												<Button size="sm" variant="ghost" icon="x" onClick={ () => setWithdrawing( s ) } aria-label={ `Withdraw invite for ${ s.email }` }>Withdraw</Button>
											</span>
										) }
									</span>
								</div>
							);
						} ) }
					</div>
				) }
			</Card>

			<Confirm
				open={ !! removing }
				onClose={ () => setRemoving( null ) }
				title={ `Remove ${ removing?.name || removing?.email }?` }
				confirmLabel="Remove from team"
				tone="danger"
				onConfirm={ async () => {
					if ( ! removing?.member_id ) {
						return;
					}
					const { data } = await api.del< Seat[] >( `me/organisation/seats/${ removing.member_id }` );
					seats.setData( data );
					onOrgChange();
					toast.success( 'Removed from your team.' );
				} }
			>
				<p>They'll lose access to the programme straight away and their seat becomes available.</p>
			</Confirm>
			<Confirm
				open={ !! withdrawing }
				onClose={ () => setWithdrawing( null ) }
				title="Withdraw this invite?"
				confirmLabel="Withdraw invite"
				tone="danger"
				onConfirm={ async () => {
					if ( ! withdrawing ) {
						return;
					}
					const { data } = await api.del< Seat[] >( `me/organisation/invites/${ withdrawing.id }` );
					seats.setData( data );
					onOrgChange();
					toast.success( 'Invite withdrawn — the seat is free again.' );
				} }
			>
				<p>The invitation link sent to <strong>{ withdrawing?.email }</strong> will stop working and the seat becomes available.</p>
			</Confirm>
		</div>
	);
}
