import { useApi } from './useApi';
import { can } from '../lib/config';
import type { Plan, PublicPlan } from '../../shared/types';

export interface PlanLite {
	id: number;
	name: string;
	slug: string;
	level: number;
	is_free: boolean;
	is_quoted: boolean;
	prices: Record< string, number >;
	archived: boolean;
}

/**
 * Plan options for pickers. Full list needs wvm_manage_plans; Finance/Support fall back
 * to the public plan list (visible plans only).
 */
export function usePlans( includeArchived = false ): { plans: PlanLite[]; loading: boolean } {
	const full = can( 'wvm_manage_plans' );
	const res = useApi< ( Plan | PublicPlan )[] >( full ? 'plans' : 'public/plans', full ? { include_archived: includeArchived ? 1 : undefined } : undefined );
	const plans: PlanLite[] = ( res.data || [] ).map( ( p ) => ( {
		id: p.id,
		name: p.name,
		slug: p.slug,
		level: p.level,
		is_free: !! p.is_free,
		is_quoted: !! p.is_quoted,
		prices: Array.isArray( p.prices ) ? {} : ( p.prices as Record< string, number > ),
		archived: !! ( p as Plan ).archived_at,
	} ) );
	return { plans, loading: res.loading };
}

export function planOptions( plans: PlanLite[], opts: { any?: string; paidOnly?: boolean } = {} ): { label: string; value: string }[] {
	const list = plans.filter( ( p ) => ! opts.paidOnly || ! p.is_free ).map( ( p ) => ( { label: p.name + ( p.archived ? ' (archived)' : '' ), value: String( p.id ) } ) );
	return opts.any !== undefined ? [ { label: opts.any, value: '' }, ...list ] : list;
}
