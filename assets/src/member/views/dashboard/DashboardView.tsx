/* [wvm_dashboard] — logged-out login card / invite flow, or the member app shell. */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import type { MeOverview } from '../../../shared/types';
import { api, errorMessage } from '../../lib/api';
import { config } from '../../lib/config';
import { useAsync } from '../../hooks/useAsync';
import { useHashRoute } from '../../hooks/useHashRoute';
import { DashboardContext } from './context';
import { Shell, ShellSkeleton } from './Shell';
import { LoginCard } from './LoginCard';
import { InviteView } from './InviteView';
import { ErrorState } from '../../components/Feedback';
import { MembershipTab } from './membership/MembershipTab';
import { WorkshopsTab } from './workshops/WorkshopsTab';
import { BillingTab } from './billing/BillingTab';
import { ProfileTab } from './profile/ProfileTab';
import { ProgrammeTab } from './programme/ProgrammeTab';
import { Notices, type BootParams } from './Notices';
import { ErrorBoundary } from '../../components/ErrorBoundary';

const PARAM_KEYS = [ 'welcome', 'pending', 'invite', 'pay', 'email_token' ];

function readParams(): BootParams {
	const q = new URLSearchParams( window.location.search );
	const params: BootParams = {
		welcome: q.get( 'welcome' ) === '1',
		pending: q.get( 'pending' ) || null,
		invite: q.get( 'invite' ) || null,
		pay: q.get( 'pay' ) || null,
		emailToken: q.get( 'email_token' ) || null,
	};
	// Clean the URL so a refresh doesn't replay one-off actions.
	if ( PARAM_KEYS.some( ( k ) => q.has( k ) ) ) {
		PARAM_KEYS.forEach( ( k ) => q.delete( k ) );
		const qs = q.toString();
		try {
			window.history.replaceState( window.history.state, '', window.location.pathname + ( qs ? `?${ qs }` : '' ) + window.location.hash );
		} catch ( e ) {
			// ignore
		}
	}
	return params;
}

export function DashboardView() {
	const [ params ] = useState< BootParams >( readParams );

	if ( ! config.isLoggedIn ) {
		if ( params.invite ) {
			return <InviteView token={ params.invite } standalone />;
		}
		return <LoginCard notice={ params.pay } />;
	}
	return <MemberApp params={ params } />;
}

function MemberApp( { params }: { params: BootParams } ) {
	const [ route, navigate ] = useHashRoute();
	const me = useAsync< MeOverview >( async () => ( await api.get< MeOverview >( 'me' ) ).data, [] );
	const [ unread, setUnread ] = useState< number | null >( null );

	useEffect( () => {
		window.scrollTo?.( { top: 0, behavior: 'smooth' } );
	}, [ route ] );

	const setUnreadCb = useCallback( ( n: number ) => setUnread( n ), [] );

	const ctx = useMemo( () => {
		if ( ! me.data ) {
			return null;
		}
		const overview = unread === null ? me.data : { ...me.data, unread };
		return { overview, reload: me.reload, navigate, route, setUnread: setUnreadCb };
	}, [ me.data, me.reload, navigate, route, unread, setUnreadCb ] );

	if ( ! ctx ) {
		if ( me.error ) {
			return (
				<div className="wvm-dash wvm-dash--error">
					<ErrorState message={ me.error || errorMessage( null ) } onRetry={ me.reload } />
				</div>
			);
		}
		return <ShellSkeleton />;
	}

	const org = ctx.overview.organisation;
	let view;
	const base = route.split( '/' )[ 0 ];
	switch ( base ) {
		case 'workshops':
			view = <WorkshopsTab />;
			break;
		case 'billing':
			view = <BillingTab />;
			break;
		case 'profile':
			view = <ProfileTab />;
			break;
		case 'programme':
			view = org ? <ProgrammeTab sub={ route === 'programme/team' && org.role === 'admin' ? 'team' : 'overview' } /> : <MembershipTab />;
			break;
		default:
			view = <MembershipTab />;
	}

	return (
		<DashboardContext.Provider value={ ctx }>
			<Shell>
				<Notices params={ params } />
				<ErrorBoundary key={ base }>{ view }</ErrorBoundary>
			</Shell>
		</DashboardContext.Provider>
	);
}
