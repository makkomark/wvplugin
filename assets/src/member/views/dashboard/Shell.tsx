/* App shell: top bar (brand, bell, avatar menu), sidebar on desktop, bottom nav on mobile, sticky pay bar. */
import { useCallback, useState } from '@wordpress/element';
import type { ReactNode } from 'react';
import { config } from '../../lib/config';
import { api, errorMessage, go } from '../../lib/api';
import { cx, firstName } from '../../lib/util';
import { useDismiss } from '../../hooks/useMisc';
import { Icon, type IconName } from '../../components/Icon';
import { Brand } from '../../components/Brand';
import { Avatar } from '../../components/Avatar';
import { Skeleton, SkeletonCard } from '../../components/Feedback';
import { StatusChip } from '../../components/Card';
import { Button } from '../../components/Button';
import { useToast } from '../../components/Toast';
import { useDashboard, isOrgCovered, orgStatus } from './context';
import { NotificationBell } from './NotificationBell';

interface NavItem { route: string; label: string; short: string; icon: IconName }

function useNav(): NavItem[] {
	const { overview } = useDashboard();
	const items: NavItem[] = [
		{ route: '', label: 'Membership', short: 'Home', icon: 'home' },
		{ route: 'workshops', label: 'Workshops', short: 'Workshops', icon: 'calendar' },
		{ route: 'billing', label: 'Billing', short: 'Billing', icon: 'card' },
	];
	if ( overview.organisation ) {
		items.push( { route: 'programme', label: 'Our programme', short: 'Programme', icon: 'building' } );
	}
	items.push( { route: 'profile', label: 'Profile', short: 'Profile', icon: 'user' } );
	return items;
}

function isActive( current: string, item: string ): boolean {
	const base = current.split( '/' )[ 0 ];
	return item === '' ? ! [ 'workshops', 'billing', 'profile', 'programme' ].includes( base ) : base === item;
}

export function Shell( { children }: { children: ReactNode } ) {
	const { overview, route } = useDashboard();
	const nav = useNav();
	const needsPay = overview.state === 'past_due' || overview.state === 'suspended';

	return (
		<div className={ cx( 'wvm-dash', needsPay && 'has-paybar' ) }>
			<TopBar />
			<div className="wvm-dash__body">
				<aside className="wvm-sidebar" aria-label="Dashboard">
					<nav className="wvm-sidenav">
						{ nav.map( ( n ) => (
							<a key={ n.route } href={ `#/${ n.route }` } className={ cx( 'wvm-sidenav__item', isActive( route, n.route ) && 'is-active' ) } aria-current={ isActive( route, n.route ) ? 'page' : undefined }>
								<Icon name={ n.icon } size={ 20 } />
								<span>{ n.label }</span>
							</a>
						) ) }
					</nav>
					<SidebarSummary />
				</aside>
				<main className="wvm-dash__main" id="wvm-main">
					{ children }
				</main>
			</div>
			{ needsPay && <PayBar /> }
			<nav className="wvm-bottomnav" aria-label="Dashboard">
				{ nav.map( ( n ) => (
					<a key={ n.route } href={ `#/${ n.route }` } className={ cx( 'wvm-bottomnav__item', isActive( route, n.route ) && 'is-active' ) } aria-current={ isActive( route, n.route ) ? 'page' : undefined }>
						<Icon name={ n.icon } size={ 22 } />
						<span>{ n.short }</span>
					</a>
				) ) }
			</nav>
		</div>
	);
}

function SidebarSummary() {
	const { overview } = useDashboard();
	const sub = overview.subscription;
	const org = isOrgCovered( overview ) ? overview.organisation : null;
	return (
		<div className="wvm-sidesum">
			<p className="wvm-sidesum__label">{ org ? 'Your programme' : 'Your plan' }</p>
			<p className="wvm-sidesum__plan">{ org ? org.programme_title || org.name : sub ? sub.plan.name : 'No plan yet' }</p>
			<StatusChip status={ org ? orgStatus( overview ) : overview.state === 'ended' ? 'expired' : overview.state } />
			<p className="wvm-sidesum__id">{ overview.member.public_id }</p>
			{ config.supportEmail && (
				<a className="wvm-sidesum__help" href={ `mailto:${ config.supportEmail }` }>
					<Icon name="message" size={ 16 } /> Need help?
				</a>
			) }
		</div>
	);
}

function TopBar() {
	return (
		<header className="wvm-topbar">
			<Brand />
			<div className="wvm-topbar__actions">
				<NotificationBell />
				<AvatarMenu />
			</div>
		</header>
	);
}

function AvatarMenu() {
	const { overview, navigate } = useDashboard();
	const [ open, setOpen ] = useState( false );
	const close = useCallback( () => setOpen( false ), [] );
	const ref = useDismiss< HTMLDivElement >( open, close );
	const m = overview.member;
	return (
		<div className="wvm-menu" ref={ ref }>
			<button type="button" className="wvm-avatarbtn" aria-haspopup="menu" aria-expanded={ open } onClick={ () => setOpen( ( o ) => ! o ) }>
				<Avatar name={ m.name } url={ m.avatar_url } size={ 36 } />
				<span className="wvm-avatarbtn__name">{ firstName( m.name ) }</span>
				<Icon name="chevron-down" size={ 16 } />
			</button>
			{ open && (
				<div className="wvm-popover wvm-popover--menu" role="menu">
					<div className="wvm-popover__who">
						<Avatar name={ m.name } url={ m.avatar_url } size={ 40 } />
						<div>
							<p className="wvm-popover__name">{ m.name }</p>
							<p className="wvm-popover__email">{ m.email }</p>
						</div>
					</div>
					<button type="button" role="menuitem" className="wvm-menuitem" onClick={ () => { close(); navigate( 'profile' ); } }>
						<Icon name="user" size={ 18 } /> Profile &amp; settings
					</button>
					<button type="button" role="menuitem" className="wvm-menuitem" onClick={ () => { close(); navigate( 'billing' ); } }>
						<Icon name="card" size={ 18 } /> Billing
					</button>
					{ config.adminUrl && (
						<a role="menuitem" className="wvm-menuitem" href={ config.adminUrl }>
							<Icon name="settings" size={ 18 } /> Admin
						</a>
					) }
					<div className="wvm-menu__sep" />
					<a role="menuitem" className="wvm-menuitem wvm-menuitem--danger" href={ config.logoutUrl }>
						<Icon name="logout" size={ 18 } /> Log out
					</a>
				</div>
			) }
		</div>
	);
}

/** Sticky mobile pay-now bar during grace / suspension. */
function PayBar() {
	const { overview } = useDashboard();
	const toast = useToast();
	const [ busy, setBusy ] = useState( false );
	const sub = overview.subscription;
	const pay = async () => {
		setBusy( true );
		try {
			const { data } = await api.post< { redirect: string } >( 'me/subscription/pay-now' );
			go( data.redirect );
		} catch ( e ) {
			toast.error( errorMessage( e ) );
			setBusy( false );
		}
	};
	return (
		<div className="wvm-paybar" role="region" aria-label="Payment due">
			<div className="wvm-paybar__text">
				<strong>{ overview.state === 'suspended' ? 'Membership paused' : 'Payment due' }</strong>
				<span>{ sub?.amount_display }{ overview.state === 'past_due' && sub?.grace_ends_display ? ` · by ${ sub.grace_ends_display }` : '' }</span>
			</div>
			<Button size="sm" onClick={ pay } loading={ busy } variant={ overview.state === 'suspended' ? 'danger' : 'primary' }>
				{ overview.state === 'suspended' ? 'Reactivate' : 'Pay now' }
			</Button>
		</div>
	);
}

export function ShellSkeleton() {
	return (
		<div className="wvm-dash" aria-busy="true">
			<span className="wvm-sr">Loading your dashboard…</span>
			<header className="wvm-topbar">
				<Brand />
				<div className="wvm-topbar__actions">
					<Skeleton w={ 40 } h={ 40 } r={ 12 } />
					<Skeleton w={ 36 } h={ 36 } r={ 999 } />
				</div>
			</header>
			<div className="wvm-dash__body">
				<aside className="wvm-sidebar">
					<div className="wvm-sidenav">
						{ [ 0, 1, 2, 3 ].map( ( i ) => (
							<Skeleton key={ i } h={ 44 } r={ 12 } className="wvm-mt-2" />
						) ) }
					</div>
				</aside>
				<main className="wvm-dash__main">
					<Skeleton w="45%" h={ 28 } />
					<Skeleton w="30%" h={ 14 } className="wvm-mt-3" />
					<div className="wvm-grid wvm-mt-6">
						<SkeletonCard hero height={ 220 } />
						<SkeletonCard height={ 220 } />
						<SkeletonCard />
						<SkeletonCard />
					</div>
				</main>
			</div>
		</div>
	);
}
