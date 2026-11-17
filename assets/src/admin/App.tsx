import { Component, useEffect } from 'react';
import type { ReactNode } from 'react';
import { useRoute, sectionOf, href } from './router';
import type { Route } from './router';
import { config, can } from './lib/config';
import type { Cap } from './lib/config';
import { Icon } from './components/Icon';
import type { IconName } from './components/Icon';
import { ToastProvider } from './components/Toasts';
import { Avatar, ErrorState, EmptyState, Btn, cx } from './components/ui';
import { Home } from './screens/Home';
import { Members } from './screens/Members';
import { MemberDetail } from './screens/MemberDetail';
import { Payments } from './screens/Payments';
import { Plans } from './screens/Plans';
import { Workshops } from './screens/Workshops';
import { Consultations } from './screens/Consultations';
import { Applications } from './screens/Applications';
import { Organisations } from './screens/Organisations';
import { OrganisationDetail } from './screens/OrganisationDetail';
import { Access } from './screens/Access';
import { Insights } from './screens/Insights';
import { Audit } from './screens/Audit';
import { Settings } from './screens/settings/Settings';

interface NavItem { key: string; slug: string; label: string; icon: IconName; to: string; cap: Cap }

export const NAV: NavItem[] = [
	{ key: 'home', slug: 'wvm', label: 'Home', icon: 'home', to: '/', cap: 'wvm_access' },
	{ key: 'members', slug: 'wvm-members', label: 'Members', icon: 'users', to: '/members', cap: 'wvm_view_members' },
	{ key: 'payments', slug: 'wvm-payments', label: 'Payments', icon: 'card', to: '/payments', cap: 'wvm_view_payments' },
	{ key: 'plans', slug: 'wvm-plans', label: 'Plans', icon: 'layers', to: '/plans', cap: 'wvm_manage_plans' },
	{ key: 'workshops', slug: 'wvm-workshops', label: 'Workshops', icon: 'calendar', to: '/workshops', cap: 'wvm_manage_workshops' },
	{ key: 'enterprise', slug: 'wvm-enterprise', label: 'Enterprise', icon: 'building', to: '/enterprise/applications', cap: 'wvm_manage_enterprise' },
	{ key: 'access', slug: 'wvm-access', label: 'Content', icon: 'lock', to: '/access', cap: 'wvm_manage_content_access' },
	{ key: 'insights', slug: 'wvm-insights', label: 'Insights', icon: 'chart', to: '/insights', cap: 'wvm_view_insights' },
	{ key: 'audit', slug: 'wvm-audit', label: 'Audit', icon: 'list', to: '/audit', cap: 'wvm_view_audit' },
	{ key: 'settings', slug: 'wvm-settings', label: 'Settings', icon: 'sliders', to: '/settings/general', cap: 'wvm_manage_settings' },
];

function allowed( item: NavItem ): boolean {
	const page = config.pages.find( ( p ) => p.slug === item.slug );
	if ( page && ! page.allowed ) {
		return false;
	}
	return item.cap === 'wvm_access' || can( item.cap );
}

const ROUTE_CAP: Partial< Record< Route[ 'name' ], Cap > > = {
	members: 'wvm_view_members',
	member: 'wvm_view_members',
	payments: 'wvm_view_payments',
	review: 'wvm_view_payments',
	plans: 'wvm_manage_plans',
	workshops: 'wvm_manage_workshops',
	consultations: 'wvm_manage_workshops',
	applications: 'wvm_manage_enterprise',
	organisations: 'wvm_manage_enterprise',
	organisation: 'wvm_manage_enterprise',
	access: 'wvm_manage_content_access',
	insights: 'wvm_view_insights',
	audit: 'wvm_view_audit',
	settings: 'wvm_manage_settings',
};

/** Keep the WP admin submenu highlight in sync with the in-app section. */
function syncWpMenu( section: string ) {
	const item = NAV.find( ( n ) => n.key === section );
	const menu = document.getElementById( 'toplevel_page_wvm' );
	if ( ! item || ! menu ) {
		return;
	}
	menu.querySelectorAll( '.wp-submenu li' ).forEach( ( li ) => {
		const a = li.querySelector( 'a' );
		const match = !! a && new RegExp( `[?&]page=${ item.slug }(&|$)` ).test( a.getAttribute( 'href' ) || '' );
		li.classList.toggle( 'current', match );
		a?.classList.toggle( 'current', match );
		if ( a ) {
			if ( match ) {
				a.setAttribute( 'aria-current', 'page' );
			} else {
				a.removeAttribute( 'aria-current' );
			}
		}
	} );
	document.title = `${ item.label } ‹ ${ config.siteName } — WitsValley`;
}

function Screen( { route }: { route: Route } ) {
	const cap = ROUTE_CAP[ route.name ];
	if ( cap && ! can( cap ) ) {
		return <ErrorState error={ { message: 'Forbidden', status: 403 } } />;
	}
	switch ( route.name ) {
		case 'home':
			return <Home />;
		case 'members':
			return <Members route={ route } />;
		case 'member':
			return <MemberDetail key={ route.params.id } id={ Number( route.params.id ) } route={ route } />;
		case 'payments':
		case 'review':
			return <Payments route={ route } />;
		case 'plans':
			return <Plans route={ route } />;
		case 'workshops':
			return <Workshops route={ route } />;
		case 'consultations':
			return <Consultations route={ route } />;
		case 'applications':
			return <Applications route={ route } />;
		case 'organisations':
			return <Organisations route={ route } />;
		case 'organisation':
			return <OrganisationDetail key={ route.params.id } id={ Number( route.params.id ) } />;
		case 'access':
			return <Access route={ route } />;
		case 'insights':
			return <Insights />;
		case 'audit':
			return <Audit route={ route } />;
		case 'settings':
			return <Settings group={ route.params.group || 'general' } route={ route } />;
		default:
			return (
				<EmptyState icon="search" title="Page not found" action={ <Btn to="/" variant="primary">Go to Home</Btn> }>
					There’s nothing at <code>{ route.path }</code>.
				</EmptyState>
			);
	}
}

class Boundary extends Component< { children: ReactNode; resetKey: string }, { error: Error | null } > {
	state = { error: null as Error | null };
	static getDerivedStateFromError( error: Error ) {
		return { error };
	}
	componentDidUpdate( prev: { resetKey: string } ) {
		if ( prev.resetKey !== this.props.resetKey && this.state.error ) {
			this.setState( { error: null } );
		}
	}
	render() {
		if ( this.state.error ) {
			return <ErrorState error={ { message: 'This screen hit an unexpected error: ' + this.state.error.message } } onRetry={ () => this.setState( { error: null } ) } />;
		}
		return this.props.children;
	}
}

export function App() {
	const route = useRoute();
	const section = sectionOf( route.name );
	useEffect( () => {
		syncWpMenu( section );
	}, [ section ] );
	useEffect( () => {
		window.scrollTo( { top: 0 } );
	}, [ route.path ] );
	const items = NAV.filter( allowed );
	return (
		<ToastProvider>
			<div className="wvm-a-app">
				<header className="wvm-a-topbar">
					<div className="wvm-a-topbar__inner">
						<a className="wvm-a-brand" href={ href( '/' ) }>
							<span className="wvm-a-brand__mark">W</span>
							<span className="wvm-a-brand__name">
								{ config.siteName }
								<em>Members</em>
							</span>
						</a>
						<nav className="wvm-a-nav" aria-label="WitsValley">
							{ items.map( ( n ) => (
								<a key={ n.key } href={ href( n.to ) } className={ cx( 'wvm-a-nav__item', section === n.key && 'is-active' ) } aria-current={ section === n.key ? 'page' : undefined }>
									<Icon name={ n.icon } size={ 16 } />
									<span>{ n.label }</span>
								</a>
							) ) }
						</nav>
						<div className="wvm-a-topbar__right">
							{ config.sandbox && (
								<a className="wvm-a-sandbox" href={ can( 'wvm_manage_settings' ) ? href( '/settings/payments' ) : undefined } title="Sandbox mode is on — real payments are not processed">
									<span className="wvm-a-sandbox__dot" />
									Sandbox
								</a>
							) }
							<span className="wvm-a-me" title={ config.user.email }>
								<Avatar name={ config.user.name } size={ 30 } src={ config.user.avatar } />
							</span>
						</div>
					</div>
				</header>
				<main className="wvm-a-main">
					<Boundary resetKey={ route.path }>
						<Screen route={ route } />
					</Boundary>
				</main>
			</div>
		</ToastProvider>
	);
}
