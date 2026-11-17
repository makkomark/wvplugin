import { createContext, useContext } from '@wordpress/element';
import type { MeOverview, PublicPlan } from '../../../shared/types';

export interface DashboardCtx {
	overview: MeOverview;
	/** Refetch GET /me in the background. */
	reload: () => Promise< void >;
	navigate: ( route: string ) => void;
	route: string;
	setUnread: ( n: number ) => void;
}

export const DashboardContext = createContext< DashboardCtx | null >( null );

export function useDashboard(): DashboardCtx {
	const ctx = useContext( DashboardContext );
	if ( ! ctx ) {
		throw new Error( 'useDashboard outside provider' );
	}
	return ctx;
}

/** Member's time zone (falls back to browser). */
export function useTz(): string | undefined {
	const { overview } = useDashboard();
	return overview.member.timezone || undefined;
}

/** States with live paid access (plan changes go through upgrade/downgrade). */
export function isLivePaid( o: MeOverview ): boolean {
	const s = o.subscription;
	return !! s && ! s.plan.is_free && ( s.status === 'active' || s.status === 'past_due' );
}

/**
 * Access comes from an organisation seat rather than a personal plan.
 * (GET /me's `subscription` only reports personal subscriptions.)
 */
export function isOrgCovered( o: MeOverview ): boolean {
	return !! o.organisation && ! ( o.subscription && [ 'active', 'past_due' ].includes( o.subscription.status ) && ! o.subscription.plan.is_free );
}

/** Status of the enterprise-seat subscription (GET /me `org_subscription`), defaulting to active. */
export function orgStatus( o: MeOverview ): string {
	return o.org_subscription?.status || 'active';
}

/**
 * Programme term end: the organisation's term date, falling back to the seat subscription's period end.
 * (The subscription's end is stored as 23:59:59Z, which rolls into the next day in East Africa.)
 */
export function orgTermEnd( o: MeOverview ): string | null {
	return o.organisation?.term_end || o.org_subscription?.current_period_end || null;
}

/** The next plan up from the member's current level (for upsells). */
export function nextPlanUp( o: MeOverview ): PublicPlan | null {
	const subLevel = o.subscription && [ 'active', 'past_due' ].includes( o.subscription.status ) ? o.subscription.plan.level : -1;
	const level = Math.max( subLevel, o.organisation ? o.entitlements.level : -1 );
	const candidates = o.plans.filter( ( p ) => p.level > level && ! p.is_quoted && p.available && ! p.is_free ).sort( ( a, b ) => a.level - b.level );
	return candidates[ 0 ] || null;
}
