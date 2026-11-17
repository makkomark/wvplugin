import { useApi } from '../hooks/useApi';
import { can, config } from '../lib/config';
import type { HomeData } from '../lib/types';
import { Card, Btn, ErrorState, Skeleton, EmptyState, A, Chip, cx } from '../components/ui';
import { Icon } from '../components/Icon';
import type { IconName } from '../components/Icon';
import { KpiTile, HealthPanel } from '../components/widgets';
import { AuditTimeline } from '../components/AuditTimeline';
import { href } from '../router';
import { formatDate } from '../../shared/format';

const ATTENTION_ICON: Record< string, IconName > = { review: 'shield', past_due: 'clock', consultations: 'message', applications: 'building', kit: 'tag', email: 'mail' };

function greeting(): string {
	const h = new Date().getHours();
	return h < 12 ? 'Good morning' : h < 18 ? 'Good afternoon' : 'Good evening';
}

export function Home() {
	const home = useApi< HomeData >( 'admin/home' );
	const showInsights = can( 'wvm_view_insights' );
	const rev = useApi< { month: string; total_minor: number }[] >( showInsights ? 'insights/revenue_by_month' : null, { months: 6 } );
	const nvc = useApi< { month: string; new: number; churned: number }[] >( showInsights ? 'insights/new_vs_churned' : null, { months: 6 } );
	const d = home.data;

	if ( home.error && ! d ) {
		return <ErrorState error={ home.error } onRetry={ home.reload } />;
	}

	const urgent = ( d?.attention || [] ).filter( ( a ) => a.count > 0 );
	const firstName = ( config.user.name || '' ).split( ' ' )[ 0 ];
	const revSpark = ( rev.data || [] ).map( ( r ) => r.total_minor );
	const newSpark = ( nvc.data || [] ).map( ( r ) => r.new );
	const churnSpark = ( nvc.data || [] ).map( ( r ) => r.churned );

	const quick = [
		can( 'wvm_edit_members' ) && { label: 'Add member', icon: 'users' as IconName, to: '/members?new=1' },
		can( 'wvm_manage_payments' ) && { label: 'Record manual payment', icon: 'receipt' as IconName, to: '/payments?manual=1' },
		can( 'wvm_manage_workshops' ) && { label: 'New workshop', icon: 'calendar' as IconName, to: '/workshops?new=1' },
		can( 'wvm_manage_plans_full' ) && { label: 'New plan', icon: 'layers' as IconName, to: '/plans?new=1' },
	].filter( Boolean ) as { label: string; icon: IconName; to: string }[];

	return (
		<div className="wvm-a-home">
			<section className="wvm-a-hero">
				<div className="wvm-a-hero__main">
					<span className="wvm-a-hero__date">{ formatDate( new Date().toISOString(), { weekday: 'long', day: 'numeric', month: 'long' } ) }</span>
					<h1>
						{ greeting() }
						{ firstName ? `, ${ firstName }` : '' }
					</h1>
					<p>
						{ ! d ? (
							'Loading your day…'
						) : urgent.length ? (
							<>
								{ urgent.length === 1 ? 'One area needs' : `${ urgent.length } areas need` } your attention today: { urgent.map( ( u ) => `${ u.label.toLowerCase() } (${ u.count })` ).join( ', ' ) }.
							</>
						) : (
							'Everything is running smoothly. Nothing needs your attention right now.'
						) }
					</p>
					{ quick.length > 0 && (
						<div className="wvm-a-hero__actions">
							{ quick.map( ( q ) => (
								<a key={ q.to } className="wvm-a-hero__btn" href={ href( q.to ) }>
									<Icon name="plus" size={ 15 } strokeWidth={ 2.2 } />
									{ q.label }
								</a>
							) ) }
						</div>
					) }
				</div>
				{ d?.stats && (
					<div className="wvm-a-hero__stat">
						<span>Monthly recurring revenue</span>
						<strong>{ d.stats.mrr_display }</strong>
						<em>
							{ d.stats.active_paid } paying members · { d.stats.revenue_month_display } collected this month
						</em>
					</div>
				) }
				<div className="wvm-a-hero__orb" aria-hidden="true" />
			</section>

			<div className="wvm-a-section-title">
				<h2>Needs attention</h2>
			</div>
			<div className="wvm-a-attention">
				{ ! d &&
					[ 0, 1, 2, 3 ].map( ( i ) => (
						<div key={ i } className="wvm-a-attn is-loading">
							<Skeleton w={ 34 } h={ 34 } r={ 10 } />
							<Skeleton w="60%" h={ 12 } />
						</div>
					) ) }
				{ d?.attention.map( ( a ) => (
					<a key={ a.key } href={ href( a.route ) } className={ cx( 'wvm-a-attn', `is-${ a.tone }`, a.count === 0 && 'is-clear' ) }>
						<span className="wvm-a-attn__icon">
							<Icon name={ ATTENTION_ICON[ a.key ] || 'info' } size={ 17 } />
						</span>
						<span className="wvm-a-attn__count">{ a.count }</span>
						<span className="wvm-a-attn__label">{ a.label }</span>
						<span className="wvm-a-attn__go">
							{ a.count === 0 ? <Chip tone="success">All clear</Chip> : <Icon name="arrowRight" size={ 15 } /> }
						</span>
					</a>
				) ) }
			</div>

			{ d?.stats && (
				<>
					<div className="wvm-a-section-title">
						<h2>Key numbers</h2>
						<A to="/insights">
							Open insights <Icon name="arrowRight" size={ 13 } />
						</A>
					</div>
					<div className="wvm-a-kpis">
						<KpiTile label="MRR" icon="zap" value={ d.stats.mrr_display } sub={ d.stats.currency + ' · active & past due' } spark={ revSpark } />
						<KpiTile label="Active paid" icon="users" tone="success" value={ d.stats.active_paid } sub={ `${ d.stats.new_members_month } joined this month` } spark={ newSpark } to="/members?status=active" />
						<KpiTile label="Active free" icon="user" tone="info" value={ d.stats.active_free } sub="Free plan members" to="/members?plan=free" />
						<KpiTile label="Revenue this month" icon="receipt" value={ d.stats.revenue_month_display } sub="Paid orders" spark={ revSpark } to="/payments?status=paid" />
						<KpiTile label="Churn" icon="activity" tone={ d.stats.churn_rate > 5 ? 'danger' : 'warning' } value={ `${ d.stats.churn_rate }%` } sub="This month" spark={ churnSpark } />
						<KpiTile label="Renewal success" icon="refresh" tone="success" value={ `${ d.stats.renewal_success }%` } sub="Renewals paid this month" />
					</div>
				</>
			) }

			<div className="wvm-a-home__grid">
				<div className="wvm-a-home__col">
					<Card title="Today" icon="clock" subtitle={ formatDate( new Date().toISOString(), { weekday: 'long', day: 'numeric', month: 'short' } ) }>
						{ ! d ? (
							<Skeleton h={ 80 } />
						) : (
							<div className="wvm-a-today">
								<a className="wvm-a-today__item" href={ href( '/members?sort=-joined' ) }>
									<span>New members</span>
									<strong>{ d.today.new_members }</strong>
								</a>
								<a className="wvm-a-today__item" href={ href( '/payments?status=paid&from=' + new Date().toISOString().slice( 0, 10 ) ) }>
									<span>Payments today</span>
									<strong>{ d.today.payments.reduce( ( a, p ) => a + p.count, 0 ) }</strong>
									<em>{ d.today.payments.map( ( p ) => p.total_display ).join( ' · ' ) || 'None yet' }</em>
								</a>
								<a className="wvm-a-today__item" href={ href( '/members?renewal_within_days=1' ) }>
									<span>Renewals due</span>
									<strong>{ d.today.renewals_due }</strong>
								</a>
								<a className="wvm-a-today__item" href={ href( '/workshops' ) }>
									<span>Workshops today</span>
									<strong>{ d.today.workshops.length }</strong>
									<em>{ d.today.workshops.map( ( w ) => w.title ).join( ', ' ) || 'None scheduled' }</em>
								</a>
							</div>
						) }
					</Card>
					{ can( 'wvm_view_audit' ) && (
						<Card
							title="Recent activity"
							icon="activity"
							actions={
								<Btn size="compact" variant="ghost" to="/audit">
									View all
								</Btn>
							}
						>
							{ ! d ? (
								<Skeleton h={ 160 } />
							) : d.activity.length ? (
								<AuditTimeline rows={ d.activity } compact />
							) : (
								<EmptyState icon="activity" title="No activity yet" />
							) }
						</Card>
					) }
				</div>
				<div className="wvm-a-home__col is-side">
					<Card title="System health" icon="shield" actions={ <Btn size="compact" variant="ghost" icon="refresh" onClick={ home.reload } title="Refresh" /> }>
						{ d ? <HealthPanel checks={ d.health.checks } status={ d.health.status } /> : <Skeleton h={ 240 } /> }
					</Card>
				</div>
			</div>
		</div>
	);
}
