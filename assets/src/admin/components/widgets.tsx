/* KPI tile, health panel, stat strip. */
import type { ReactNode } from 'react';
import type { HealthCheck } from '../../shared/types';
import { Icon } from './Icon';
import type { IconName } from './Icon';
import { Sparkline } from './charts';
import { cx, Chip } from './ui';
import { href } from '../router';

export function KpiTile( { label, value, sub, spark, icon, tone = 'primary', to, delta }: { label: string; value: ReactNode; sub?: ReactNode; spark?: number[]; icon?: IconName; tone?: 'primary' | 'success' | 'warning' | 'danger' | 'info'; to?: string; delta?: { value: string; good: boolean } | null } ) {
	const inner = (
		<>
			<div className="wvm-a-kpi__top">
				{ icon && (
					<span className={ `wvm-a-kpi__icon is-${ tone }` }>
						<Icon name={ icon } size={ 16 } />
					</span>
				) }
				<span className="wvm-a-kpi__label">{ label }</span>
				{ delta && <span className={ cx( 'wvm-a-kpi__delta', delta.good ? 'is-good' : 'is-bad' ) }>{ delta.value }</span> }
			</div>
			<div className="wvm-a-kpi__value">{ value }</div>
			<div className="wvm-a-kpi__foot">
				{ sub && <span className="wvm-a-kpi__sub">{ sub }</span> }
				{ spark && spark.length > 1 && <Sparkline values={ spark } color={ tone === 'danger' ? '#e5484d' : tone === 'success' ? '#12b886' : tone === 'warning' ? '#f59f00' : 'var(--wvm-primary)' } height={ 30 } /> }
			</div>
		</>
	);
	return to ? (
		<a className="wvm-a-kpi is-link" href={ href( to ) }>
			{ inner }
		</a>
	) : (
		<div className="wvm-a-kpi">{ inner }</div>
	);
}

const STATUS_TONE: Record< string, 'success' | 'warning' | 'danger' > = { ok: 'success', warning: 'warning', error: 'danger' };

export function HealthPanel( { checks, status }: { checks: HealthCheck[]; status?: string } ) {
	const counts = checks.reduce< Record< string, number > >( ( a, c ) => ( { ...a, [ c.status ]: ( a[ c.status ] || 0 ) + 1 } ), {} );
	return (
		<div className="wvm-a-health">
			<div className="wvm-a-health__summary">
				<span className={ `wvm-a-health__big is-${ status || 'ok' }` }>
					<Icon name={ status === 'error' ? 'alert' : status === 'warning' ? 'info' : 'check' } size={ 18 } strokeWidth={ 2.2 } />
				</span>
				<div>
					<strong>{ status === 'error' ? 'Action needed' : status === 'warning' ? 'Mostly healthy' : 'All systems healthy' }</strong>
					<span>
						{ counts.ok || 0 } ok · { counts.warning || 0 } warnings · { counts.error || 0 } errors
					</span>
				</div>
			</div>
			<ul className="wvm-a-health__list">
				{ checks.map( ( c ) => (
					<li key={ c.key } className={ `is-${ c.status }` }>
						<span className={ `wvm-a-health__dot is-${ c.status }` } aria-label={ c.status } />
						<div className="wvm-a-health__text">
							<span className="wvm-a-health__label">{ c.label }</span>
							<span className="wvm-a-health__detail">{ c.detail }</span>
						</div>
						{ c.action ? (
							<a className="wvm-a-health__action" href={ c.action }>
								{ c.status === 'ok' ? 'View' : 'Fix' }
								<Icon name="arrowUpRight" size={ 13 } />
							</a>
						) : (
							<Chip tone={ STATUS_TONE[ c.status ] || 'neutral' }>{ c.status === 'ok' ? 'OK' : c.status }</Chip>
						) }
					</li>
				) ) }
			</ul>
		</div>
	);
}

export function StatStrip( { items }: { items: { label: string; value: ReactNode; icon?: IconName }[] } ) {
	return (
		<div className="wvm-a-statstrip">
			{ items.map( ( it ) => (
				<div key={ it.label } className="wvm-a-statstrip__item">
					{ it.icon && <Icon name={ it.icon } size={ 16 } /> }
					<div>
						<span>{ it.label }</span>
						<strong>{ it.value }</strong>
					</div>
				</div>
			) ) }
		</div>
	);
}
