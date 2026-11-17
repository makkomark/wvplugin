/* Workshops tab: upcoming (register, calendar, join), past (recordings), consultations (Premium+). */
import { useState } from '@wordpress/element';
import type { Workshop } from '../../../../shared/types';
import { api, errorMessage } from '../../../lib/api';
import { cx, dateParts, formatTime, formatWhen, zoneLabel } from '../../../lib/util';
import { useAsync } from '../../../hooks/useAsync';
import { useNow } from '../../../hooks/useMisc';
import { Card, Chip } from '../../../components/Card';
import { Button } from '../../../components/Button';
import { Segmented } from '../../../components/Field';
import { EmptyState, ErrorState, SkeletonCard } from '../../../components/Feedback';
import { countdownText } from '../../../components/Countdown';
import { Icon } from '../../../components/Icon';
import { useToast } from '../../../components/Toast';
import { useDashboard, useTz } from '../context';
import { Consultations } from './Consultations';

export function WorkshopsTab() {
	const [ when, setWhen ] = useState< 'upcoming' | 'past' >( 'upcoming' );
	const list = useAsync< Workshop[] >( async () => ( await api.get< Workshop[] >( 'me/workshops', { when } ) ).data, [ when ] );

	return (
		<div className="wvm-page">
			<div className="wvm-page__head">
				<div>
					<h1 className="wvm-h1">Workshops</h1>
					<p className="wvm-muted">Live sessions with WitsValley experts, and recordings to catch up any time.</p>
				</div>
				<Segmented
					label="Show"
					value={ when }
					onChange={ setWhen }
					options={ [
						{ value: 'upcoming', label: 'Upcoming' },
						{ value: 'past', label: 'Past & recordings' },
					] }
				/>
			</div>

			{ list.error && ! list.data && <ErrorState message={ list.error } onRetry={ list.reload } /> }
			{ list.loading && ! list.data && (
				<div className="wvm-stack">
					<SkeletonCard lines={ 2 } />
					<SkeletonCard lines={ 2 } />
				</div>
			) }
			{ list.data && list.data.length === 0 && (
				<Card pad="lg">
					<EmptyState icon="calendar" title={ when === 'upcoming' ? 'No upcoming workshops' : 'No past workshops yet' }>
						{ when === 'upcoming' ? "New sessions are announced by email and here — we'll remind you the day before." : 'Recordings of past sessions will appear here.' }
					</EmptyState>
				</Card>
			) }
			{ list.data && list.data.length > 0 && (
				<div className="wvm-stack">
					{ list.data.map( ( w ) => (
						<WorkshopCard key={ `${ when }-${ w.id }` } workshop={ w } past={ when === 'past' } onChange={ ( next ) => list.setData( ( prev ) => ( prev || [] ).map( ( x ) => ( x.id === next.id ? next : x ) ) ) } />
					) ) }
				</div>
			) }

			<Consultations />
		</div>
	);
}

function WorkshopCard( { workshop: w, past, onChange }: { workshop: Workshop; past: boolean; onChange: ( w: Workshop ) => void } ) {
	const tz = useTz();
	const toast = useToast();
	const { navigate, reload } = useDashboard();
	const now = useNow( 30000 );
	const [ busy, setBusy ] = useState< 'reg' | 'join' | null >( null );
	const d = dateParts( w.starts_at, tz );
	const startMs = new Date( w.starts_at ).getTime();
	const endMs = new Date( w.ends_at ).getTime();
	const live = now >= startMs && now <= endMs;
	const joinable = !! w.can_join_now || ( !! w.eligible && now >= startMs - 15 * 60000 && now <= endMs + 3600000 );

	const toggleReg = async () => {
		setBusy( 'reg' );
		try {
			const res = w.registered ? await api.del< Workshop >( `me/workshops/${ w.id }/register` ) : await api.post< Workshop >( `me/workshops/${ w.id }/register` );
			onChange( res.data );
			toast.success( res.data.registered ? "You're registered — we'll send a reminder." : 'Registration cancelled.' );
			reload();
		} catch ( e ) {
			toast.error( errorMessage( e ) );
		} finally {
			setBusy( null );
		}
	};

	const join = async () => {
		setBusy( 'join' );
		// Open the tab synchronously so popup blockers allow it, then point it at the join URL.
		const tab = window.open( '', '_blank' );
		try {
			const { data } = await api.get< { url: string } >( `me/workshops/${ w.id }/join` );
			if ( tab ) {
				tab.opener = null;
				tab.location.href = data.url;
			} else {
				window.location.href = data.url;
			}
		} catch ( e ) {
			tab?.close();
			toast.error( errorMessage( e ) );
		} finally {
			setBusy( null );
		}
	};

	const calendar = api.downloadUrl( `me/workshops/${ w.id }/calendar.ics` );

	return (
		<Card pad="md" className={ cx( 'wvm-ws', ! w.eligible && 'is-locked', live && 'is-live' ) } as="article">
			<div className="wvm-ws__date" aria-hidden="true">
				<span className="wvm-ws__month">{ d.month }</span>
				<span className="wvm-ws__day">{ d.day }</span>
				<span className="wvm-ws__wday">{ d.weekday }</span>
			</div>
			<div className="wvm-ws__main">
				<div className="wvm-ws__chips">
					<Chip tone={ w.required_level >= 2 ? 'primary' : w.required_level === 1 ? 'info' : 'neutral' }>{ w.required_level_label }</Chip>
					{ w.organisation_id && <Chip tone="neutral" icon="building">Private</Chip> }
					{ ! past && live && <Chip tone="danger" dot>Live now</Chip> }
					{ ! past && ! live && <Chip tone="neutral" icon="clock">{ countdownText( w.starts_at, now, w.ends_at ) }</Chip> }
					{ w.registered && ! past && <Chip tone="success" icon="check">Registered</Chip> }
					{ w.is_full && ! w.registered && ! past && <Chip tone="warning">Full</Chip> }
				</div>
				<h3 className="wvm-ws__title">{ w.title }</h3>
				<p className="wvm-ws__when">
					{ formatWhen( w.starts_at, tz ) } – { formatTime( w.ends_at, tz ) } <span className="wvm-subtle">{ zoneLabel( tz ) }</span>
					{ w.host && <> · with { w.host }</> }
				</p>
				{ w.description && <p className="wvm-ws__desc">{ w.description }</p> }

				<div className="wvm-ws__actions">
					{ ! w.eligible ? (
						<>
							<span className="wvm-ws__lock"><Icon name="lock" size={ 16 } /> Included with { w.required_level_label }</span>
							<Button size="sm" onClick={ () => navigate( 'billing' ) } iconRight="arrow-right">Upgrade to join</Button>
						</>
					) : past ? (
						w.recording_url ? (
							<Button size="sm" href={ w.recording_url } target="_blank" rel="noreferrer" icon="play">Watch recording</Button>
						) : (
							<span className="wvm-subtle wvm-small">Recording coming soon</span>
						)
					) : (
						<>
							{ joinable && (
								<Button size="sm" onClick={ join } loading={ busy === 'join' } icon="video">Join now</Button>
							) }
							<Button size="sm" variant={ w.registered || joinable ? 'ghost' : 'primary' } onClick={ toggleReg } loading={ busy === 'reg' } disabled={ ! w.registered && !! w.is_full } icon={ w.registered ? 'x' : 'plus' }>
								{ w.registered ? 'Unregister' : 'Register' }
							</Button>
							<Button size="sm" variant="ghost" href={ calendar } icon="calendar">Add to calendar</Button>
							{ ! joinable && <span className="wvm-ws__hint"><Icon name="video" size={ 14 } /> Join link opens 15 min before</span> }
						</>
					) }
				</div>
			</div>
		</Card>
	);
}

