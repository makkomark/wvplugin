/* Bell with unread badge + popover list (GET /me/notifications, mark read / all read). */
import { useCallback, useState } from '@wordpress/element';
import type { InAppNotification } from '../../../shared/types';
import { relativeTime } from '../../../shared/format';
import { api, errorMessage } from '../../lib/api';
import { cx } from '../../lib/util';
import { useDismiss } from '../../hooks/useMisc';
import { IconButton } from '../../components/Button';
import { Icon, type IconName } from '../../components/Icon';
import { EmptyState, Skeleton } from '../../components/Feedback';
import { useDashboard } from './context';

const TYPE_ICON: Record< string, IconName > = {
	welcome: 'sparkles',
	receipt: 'card',
	payment: 'card',
	renewal: 'refresh',
	plan_changed: 'zap',
	cancel_scheduled: 'info',
	workshop: 'calendar',
	consultation: 'message',
	grace_started: 'alert',
	suspended: 'alert',
	restored: 'check',
	expired: 'clock',
	organisation: 'building',
};

function iconFor( type: string ): IconName {
	const key = Object.keys( TYPE_ICON ).find( ( k ) => type.startsWith( k ) );
	return key ? TYPE_ICON[ key ] : 'bell';
}

export function NotificationBell() {
	const { overview, setUnread, navigate } = useDashboard();
	const [ open, setOpen ] = useState( false );
	const [ items, setItems ] = useState< InAppNotification[] | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const close = useCallback( () => setOpen( false ), [] );
	const ref = useDismiss< HTMLDivElement >( open, close );

	const load = async () => {
		setError( null );
		try {
			const res = await api.get< InAppNotification[] >( 'me/notifications' );
			setItems( res.data );
			const u = res.meta && typeof res.meta.unread === 'number' ? ( res.meta.unread as number ) : res.data.filter( ( n ) => ! n.read ).length;
			setUnread( u );
		} catch ( e ) {
			setError( errorMessage( e ) );
		}
	};

	const toggle = () => {
		const next = ! open;
		setOpen( next );
		if ( next ) {
			load();
		}
	};

	const markRead = async ( n: InAppNotification ) => {
		if ( ! n.read ) {
			setItems( ( list ) => list?.map( ( x ) => ( x.id === n.id ? { ...x, read: true } : x ) ) || null );
			try {
				const { data } = await api.post< { unread: number } >( `me/notifications/${ n.id }/read` );
				setUnread( data.unread );
			} catch ( e ) {
				// non-critical
			}
		}
		if ( n.url ) {
			close();
			const hash = n.url.indexOf( '#/' );
			const sameDash = hash >= 0 && n.url.slice( 0, hash ).replace( /\/$/, '' ) === window.location.origin + window.location.pathname.replace( /\/$/, '' );
			if ( sameDash ) {
				navigate( n.url.slice( hash + 2 ) );
			} else {
				window.location.href = n.url;
			}
		}
	};

	const readAll = async () => {
		setItems( ( list ) => list?.map( ( x ) => ( { ...x, read: true } ) ) || null );
		setUnread( 0 );
		try {
			await api.post( 'me/notifications/read-all' );
		} catch ( e ) {
			load();
		}
	};

	const unread = overview.unread;

	return (
		<div className="wvm-menu" ref={ ref }>
			<IconButton icon="bell" label={ unread ? `Notifications (${ unread } unread)` : 'Notifications' } badge={ unread } onClick={ toggle } aria-expanded={ open } aria-haspopup="dialog" />
			{ open && (
				<div className="wvm-popover wvm-popover--notes" role="dialog" aria-label="Notifications">
					<div className="wvm-popover__head">
						<p className="wvm-popover__title">Notifications</p>
						{ unread > 0 && (
							<button type="button" className="wvm-link wvm-small" onClick={ readAll }>Mark all read</button>
						) }
					</div>
					<div className="wvm-notes">
						{ error && <p className="wvm-notes__error">{ error }</p> }
						{ ! items && ! error && [ 0, 1, 2 ].map( ( i ) => (
							<div key={ i } className="wvm-note">
								<Skeleton w={ 36 } h={ 36 } r={ 12 } />
								<div style={ { flex: 1 } }>
									<Skeleton w="70%" h={ 12 } />
									<Skeleton w="90%" h={ 10 } className="wvm-mt-2" />
								</div>
							</div>
						) ) }
						{ items && items.length === 0 && <EmptyState compact icon="bell" title="You're all caught up">We'll let you know about renewals, workshops and receipts here.</EmptyState> }
						{ items?.map( ( n ) => (
							<button key={ n.id } type="button" className={ cx( 'wvm-note', ! n.read && 'is-unread' ) } onClick={ () => markRead( n ) }>
								<span className="wvm-note__icon" aria-hidden="true"><Icon name={ iconFor( n.type ) } size={ 18 } /></span>
								<span className="wvm-note__body">
									<span className="wvm-note__title">{ n.title }</span>
									{ n.body && <span className="wvm-note__text">{ n.body }</span> }
									<span className="wvm-note__time">{ relativeTime( n.created_at ) }</span>
								</span>
								{ ! n.read && <span className="wvm-note__dot" aria-label="Unread" /> }
							</button>
						) ) }
					</div>
				</div>
			) }
		</div>
	);
}
