import { useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { useApi } from '../hooks/useApi';
import { api, todayYmd } from '../lib/api';
import { config, countryName, gatewayTitle, currencyDecimals } from '../lib/config';
import type { InsightsSummary } from '../lib/types';
import { PageHeader, Btn, Card, Skeleton, ErrorState, EmptyState, Tabs, Muted } from '../components/ui';
import { Icon } from '../components/Icon';
import { KpiTile } from '../components/widgets';
import { AreaChart, BarChart, Donut, HBars, Gauge, SERIES, monthLabel, compact } from '../components/charts';
import { FilterSelect, FilterDate } from '../components/FilterBar';

type Q = { from: string; to: string; currency: string; months: number };

function compactMoney( cur: string ) {
	const d = currencyDecimals( cur );
	return ( minor: number ) => compact( minor / Math.pow( 10, d ) );
}

function ChartCard< T >( { title, subtitle, metric, q, render, table, isEmpty, className }: { title: string; subtitle?: string; metric: string; q: Q; render: ( d: T ) => ReactNode; table?: ( d: T ) => [ string, ReactNode ][]; isEmpty?: ( d: T ) => boolean; className?: string } ) {
	const res = useApi< T >( `insights/${ metric }`, q );
	const [ asTable, setAsTable ] = useState( false );
	return (
		<Card
			className={ 'wvm-a-chartcard ' + ( className || '' ) }
			title={ title }
			subtitle={ subtitle }
			actions={
				<>
					{ table && <Btn size="small" variant="ghost" icon={ asTable ? 'chart' : 'list' } title={ asTable ? 'Show chart' : 'Show as table' } onClick={ () => setAsTable( ! asTable ) } /> }
					<Btn size="small" variant="ghost" icon="download" title="Export CSV" href={ api.downloadUrl( `insights/${ metric }/export.csv`, q ) } />
				</>
			}
		>
			{ res.error && <ErrorState error={ res.error } onRetry={ res.reload } compact /> }
			{ ! res.data && ! res.error && <Skeleton h={ 200 } r={ 12 } /> }
			{ res.data !== undefined && ( isEmpty && isEmpty( res.data ) ? (
				<EmptyState icon="chart" title="No data for this range" />
			) : asTable && table ? (
				<table className="wvm-a-minitable">
					<tbody>
						{ table( res.data ).map( ( [ k, v ], i ) => (
							<tr key={ i }>
								<th>{ k }</th>
								<td>{ v }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) : (
				render( res.data )
			) ) }
		</Card>
	);
}

export function Insights() {
	const [ currency, setCurrency ] = useState( config.currencies[ 0 ] );
	const [ range, setRange ] = useState( '30' );
	const [ from, setFrom ] = useState( todayYmd( -30 ) );
	const [ to, setTo ] = useState( todayYmd() );
	const [ months, setMonths ] = useState( 6 );
	const q = useMemo< Q >( () => ( { from, to, currency, months } ), [ from, to, currency, months ] );
	const summary = useApi< InsightsSummary >( 'insights/summary', { currency } );
	const s = summary.data;
	const fmt = compactMoney( currency );

	const pickRange = ( r: string ) => {
		setRange( r );
		if ( r !== 'custom' ) {
			setFrom( todayYmd( -Number( r ) ) );
			setTo( todayYmd() );
		}
	};

	return (
		<div>
			<PageHeader
				title="Insights"
				subtitle="Revenue, growth and retention. Figures are cached for up to 10 minutes."
				actions={
					<div className="wvm-a-insightsbar">
						<Tabs variant="segmented" value={ currency } onChange={ setCurrency } tabs={ config.currencies.map( ( c ) => ( { key: c, label: c } ) ) } />
					</div>
				}
			/>
			<div className="wvm-a-insightsfilters">
				<Tabs
					variant="segmented"
					value={ range }
					onChange={ pickRange }
					tabs={ [
						{ key: '7', label: '7 days' },
						{ key: '30', label: '30 days' },
						{ key: '90', label: '90 days' },
						{ key: '365', label: '12 months' },
						{ key: 'custom', label: 'Custom' },
					] }
				/>
				{ range === 'custom' && (
					<>
						<FilterDate label="From" value={ from } onChange={ setFrom } />
						<FilterDate label="To" value={ to } onChange={ setTo } />
					</>
				) }
				<FilterSelect label="Trend window" value={ String( months ) } onChange={ ( v ) => setMonths( Number( v ) || 6 ) } options={ [ 3, 6, 12, 24 ].map( ( n ) => ( { value: String( n ), label: `${ n } months` } ) ) } />
				<span className="wvm-a-muted wvm-a-insightsfilters__note">
					<Icon name="info" size={ 13 } /> Range applies to gateway, country and cancellation charts; trends use the window.
				</span>
			</div>

			{ summary.error && <ErrorState error={ summary.error } onRetry={ summary.reload } /> }
			<div className="wvm-a-kpis">
				{ s ? (
					<>
						<KpiTile label="MRR" icon="zap" value={ s.mrr_display } sub={ `${ s.active_paid } paying members` } />
						<KpiTile label="Revenue this month" icon="receipt" value={ s.revenue_month_display } sub="Paid orders" />
						<KpiTile label="Active paid" icon="users" tone="success" value={ s.active_paid } sub={ `${ s.active_free } on free` } />
						<KpiTile label="New this month" icon="sparkles" tone="info" value={ s.new_members_month } sub="Member sign-ups" />
						<KpiTile label="Churn" icon="activity" tone={ s.churn_rate > 5 ? 'danger' : 'warning' } value={ `${ s.churn_rate }%` } sub={ `${ s.past_due } past due now` } />
						<KpiTile label="Renewal success" icon="refresh" tone="success" value={ `${ s.renewal_success }%` } sub={ `${ s.needs_review } in review queue` } />
					</>
				) : (
					[ 0, 1, 2, 3, 4, 5 ].map( ( i ) => <Skeleton key={ i } h={ 118 } r={ 18 } /> )
				) }
			</div>

			<div className="wvm-a-chartgrid">
				<ChartCard< { month: string; total_minor: number; total_display: string }[] >
					className="is-wide"
					title="Revenue by month"
					subtitle={ `Paid orders in ${ currency }` }
					metric="revenue_by_month"
					q={ q }
					render={ ( d ) => <AreaChart data={ d.map( ( r ) => ( { label: monthLabel( r.month ), value: r.total_minor, display: r.total_display } ) ) } format={ fmt } name="Revenue" height={ 240 } /> }
					table={ ( d ) => d.map( ( r ) => [ r.month, r.total_display ] ) }
				/>
				<ChartCard< { plan: string; slug: string; count: number }[] >
					title="Active members by plan"
					metric="active_by_plan"
					q={ q }
					isEmpty={ ( d ) => d.every( ( r ) => ! r.count ) }
					render={ ( d ) => <Donut data={ d.map( ( r ) => ( { label: r.plan, value: r.count } ) ) } centerLabel="active" /> }
					table={ ( d ) => d.map( ( r ) => [ r.plan, r.count ] ) }
				/>
				<ChartCard< { month: string; new: number; churned: number }[] >
					title="New vs churned"
					subtitle="Paid memberships per month"
					metric="new_vs_churned"
					q={ q }
					render={ ( d ) => <BarChart data={ d.map( ( r ) => ( { label: monthLabel( r.month ), values: [ r.new, r.churned ] } ) ) } series={ [ { name: 'New', color: SERIES[ 0 ] }, { name: 'Churned', color: SERIES[ 1 ] } ] } /> }
					table={ ( d ) => d.map( ( r ) => [ r.month, `${ r.new } new · ${ r.churned } churned` ] ) }
				/>
				<ChartCard< { month: string; free_signups: number; converted: number; rate: number }[] >
					title="Free → paid conversion"
					subtitle="Free sign-ups and how many upgraded"
					metric="free_to_paid"
					q={ q }
					render={ ( d ) => <BarChart data={ d.map( ( r ) => ( { label: monthLabel( r.month ), values: [ r.free_signups, r.converted ], displays: [ String( r.free_signups ), `${ r.converted } (${ r.rate }%)` ] } ) ) } series={ [ { name: 'Free sign-ups', color: SERIES[ 5 ] }, { name: 'Converted', color: SERIES[ 2 ] } ] } /> }
					table={ ( d ) => d.map( ( r ) => [ r.month, `${ r.converted } of ${ r.free_signups } (${ r.rate }%)` ] ) }
				/>
				<ChartCard< { gateway: string; orders: number; total_minor: number; total_display: string }[] >
					title="Revenue by gateway"
					metric="revenue_by_gateway"
					q={ q }
					isEmpty={ ( d ) => ! d.length }
					render={ ( d ) => <HBars data={ d.map( ( r ) => ( { label: gatewayTitle( r.gateway ), value: r.total_minor, display: r.total_display, sub: `${ r.orders } orders` } ) ) } /> }
					table={ ( d ) => d.map( ( r ) => [ gatewayTitle( r.gateway ), `${ r.total_display } · ${ r.orders } orders` ] ) }
				/>
				<ChartCard< { country: string; orders: number; total_minor: number; total_display: string }[] >
					title="Revenue by country"
					metric="revenue_by_country"
					q={ q }
					isEmpty={ ( d ) => ! d.length }
					render={ ( d ) => <HBars color={ SERIES[ 2 ] } data={ d.map( ( r ) => ( { label: countryName( r.country ), value: r.total_minor, display: r.total_display, sub: `${ r.orders } orders` } ) ) } /> }
					table={ ( d ) => d.map( ( r ) => [ countryName( r.country ), `${ r.total_display } · ${ r.orders } orders` ] ) }
				/>
				<ChartCard< { month: string; rate: number; paid: number; total: number; by_method: { gateway: string | null; paid: number | string }[] } >
					title="Renewal success"
					subtitle="Renewals due this month that were paid"
					metric="renewal_success"
					q={ q }
					render={ ( d ) => (
						<div className="wvm-a-renewal">
							<Gauge value={ d.rate } label={ `${ d.paid } of ${ d.total }` } color={ SERIES[ 2 ] } />
							<div className="wvm-a-renewal__methods">
								{ d.by_method.length ? (
									<HBars color={ SERIES[ 2 ] } data={ d.by_method.map( ( m ) => ( { label: gatewayTitle( m.gateway || 'manual' ), value: Number( m.paid ), display: `${ m.paid } paid` } ) ) } />
								) : (
									<Muted>No renewals paid yet this month — breakdown by payment method appears here.</Muted>
								) }
							</div>
						</div>
					) }
				/>
				<ChartCard< { reason: string; count: number }[] >
					title="Cancellation reasons"
					metric="cancellation_reasons"
					q={ q }
					isEmpty={ ( d ) => ! d.length }
					render={ ( d ) => <HBars color={ SERIES[ 1 ] } data={ d.map( ( r ) => ( { label: r.reason, value: r.count, display: r.count } ) ) } /> }
					table={ ( d ) => d.map( ( r ) => [ r.reason, r.count ] ) }
				/>
				<ChartCard< { days: number; items: { currency: string; count: number; total_minor: number; total_display: string }[] } >
					title="Manual renewal exposure"
					subtitle="Renewals due in the next 7 days with no saved payment method"
					metric="manual_renewal_exposure"
					q={ q }
					render={ ( d ) =>
						d.items.length ? (
							<div className="wvm-a-exposure">
								{ d.items.map( ( it ) => (
									<div key={ it.currency }>
										<strong>{ it.total_display }</strong>
										<span>
											{ it.count } member{ it.count === 1 ? '' : 's' } · { it.currency }
										</span>
									</div>
								) ) }
								<Btn size="compact" icon="users" to={ `/members?renewal_within_days=${ d.days }` }>
									See members
								</Btn>
							</div>
						) : (
							<EmptyState icon="check" title={ `No manual renewals due in ${ d.days } days` } />
						)
					}
				/>
			</div>
		</div>
	);
}
