import { useEffect, useMemo, useState } from 'react';
import type { Route } from '../router';
import { replaceQuery } from '../router';
import { useApi } from '../hooks/useApi';
import { api } from '../lib/api';
import type { AuditRow } from '../../shared/types';
import { formatDateTime, relativeTime } from '../../shared/format';
import { PageHeader, Btn, EmptyState, Muted, Chip, A, Avatar, Mono } from '../components/ui';
import { Icon } from '../components/Icon';
import { DataTable } from '../components/DataTable';
import { FilterBar, SearchInput, FilterSelect, FilterDate } from '../components/FilterBar';
import { JsonDiff, entityLink, humanAction } from '../components/AuditTimeline';

interface AuditFilters { entity_type: string; entity_id: string; actor: string; action: string; from: string; to: string }
const EMPTY: AuditFilters = { entity_type: '', entity_id: '', actor: '', action: '', from: '', to: '' };
const TYPES = [ 'member', 'subscription', 'order', 'plan', 'organisation', 'application', 'workshop', 'consultation', 'email_template', 'settings', 'post', 'term' ];

export function Audit( { route }: { route: Route } ) {
	const init = () => {
		const f = { ...EMPTY };
		( Object.keys( EMPTY ) as ( keyof AuditFilters )[] ).forEach( ( k ) => {
			if ( route.query[ k ] ) {
				f[ k ] = route.query[ k ];
			}
		} );
		return f;
	};
	const [ filters, setFilters ] = useState< AuditFilters >( init );
	const [ page, setPage ] = useState( 1 );
	const [ open, setOpen ] = useState< Set< number > >( new Set() );
	useEffect( () => setFilters( init() ), [ JSON.stringify( route.query ) ] ); // eslint-disable-line react-hooks/exhaustive-deps
	const query = useMemo( () => ( { ...filters, page, per_page: 50 } ), [ filters, page ] );
	const res = useApi< AuditRow[] >( 'audit', query );
	const update = ( p: Partial< AuditFilters > ) => {
		const next = { ...filters, ...p };
		setFilters( next );
		setPage( 1 );
		replaceQuery( next as unknown as Record< string, unknown > );
	};
	const toggle = ( id: number ) => {
		const n = new Set( open );
		if ( n.has( id ) ) {
			n.delete( id );
		} else {
			n.add( id );
		}
		setOpen( n );
	};
	const active = Object.values( filters ).some( Boolean );
	return (
		<div>
			<PageHeader
				title="Audit log"
				subtitle="Every change to members, payments, plans and settings — append-only."
				actions={
					<Btn icon="download" href={ api.downloadUrl( 'audit/export.csv', filters as unknown as Record< string, string > ) }>
						Export CSV
					</Btn>
				}
			/>
			<div className="wvm-a-card is-flush">
				<FilterBar active={ active } onClear={ () => update( EMPTY ) }>
					<SearchInput value={ filters.action } onChange={ ( action ) => update( { action } ) } placeholder="Action starts with… e.g. order." />
					<FilterSelect label="Entity" value={ filters.entity_type } onChange={ ( entity_type ) => update( { entity_type } ) } options={ TYPES.map( ( t ) => ( { value: t, label: t.replace( /_/g, ' ' ) } ) ) } />
					{ filters.entity_type && (
						<label className="wvm-a-fsel is-active">
							<span className="wvm-a-fsel__label">ID</span>
							<input className="wvm-a-fsel__input" value={ filters.entity_id } onChange={ ( e ) => update( { entity_id: e.target.value.replace( /\D/g, '' ) } ) } placeholder="any" />
						</label>
					) }
					<FilterSelect label="Actor" value={ filters.actor } onChange={ ( actor ) => update( { actor } ) } options={ [ { value: 'admin', label: 'Staff' }, { value: 'member', label: 'Members' }, { value: 'system', label: 'System' } ] } />
					<FilterDate label="From" value={ filters.from } onChange={ ( from ) => update( { from } ) } />
					<FilterDate label="To" value={ filters.to } onChange={ ( to ) => update( { to } ) } />
				</FilterBar>
				<DataTable
					columns={ [
						{ key: 'when', header: 'When', width: 150, render: ( r ) => <span title={ formatDateTime( r.created_at ) }><strong className="wvm-a-strong">{ relativeTime( r.created_at ) }</strong><br /><Muted>{ formatDateTime( r.created_at ) }</Muted></span> },
						{ key: 'actor', header: 'Actor', render: ( r ) => <span className="wvm-a-inline">{ r.actor_type === 'system' ? <span className="wvm-a-sysdot"><Icon name="zap" size={ 12 } /></span> : <Avatar name={ r.actor_name } size={ 24 } /> }{ r.actor_name }</span> },
						{ key: 'action', header: 'Action', render: ( r ) => <div className="wvm-a-ordercell"><strong className="wvm-a-strong">{ humanAction( r.action ) }</strong><Mono>{ r.action }</Mono></div> },
						{
							key: 'entity',
							header: 'Entity',
							render: ( r ) => {
								const l = entityLink( r );
								return l ? <A to={ l.to }>{ l.label }</A> : <Chip tone="neutral">{ r.entity_type }</Chip>;
							},
						},
						{ key: 'reason', header: 'Reason', render: ( r ) => ( r.reason ? <span className="wvm-a-reason">“{ r.reason }”</span> : <Muted>—</Muted> ) },
						{
							key: 'diff',
							header: '',
							align: 'right',
							render: ( r ) =>
								r.before || r.after ? (
									<Btn size="compact" variant="ghost" onClick={ () => toggle( r.id ) }>
										{ open.has( r.id ) ? 'Hide' : 'Changes' }
										<Icon name="chevronDown" size={ 12 } className={ open.has( r.id ) ? 'is-flipped' : '' } />
									</Btn>
								) : null,
						},
					] }
					rows={ res.data }
					rowKey={ ( r ) => r.id }
					loading={ res.loading }
					error={ res.error }
					onRetry={ res.reload }
					meta={ res.meta }
					onPage={ setPage }
					onRowClick={ ( r ) => ( r.before || r.after ) && toggle( r.id ) }
					expanded={ ( r ) => ( open.has( r.id ) ? <JsonDiff before={ r.before } after={ r.after } /> : null ) }
					empty={ <EmptyState icon="list" title={ active ? 'No entries match' : 'No audit entries yet' } /> }
				/>
			</div>
		</div>
	);
}
