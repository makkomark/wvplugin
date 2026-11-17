/* Audit timeline + before/after JSON diff. */
import { useState } from 'react';
import type { ReactNode } from 'react';
import type { AuditRow } from '../../shared/types';
import { formatDateTime, relativeTime } from '../../shared/format';
import { Icon } from './Icon';
import type { IconName } from './Icon';
import { A, cx, Avatar } from './ui';

export function humanAction( action: string ): string {
	const s = action.replace( /[._]/g, ' ' ).trim();
	return s.charAt( 0 ).toUpperCase() + s.slice( 1 );
}

const ENTITY_ICON: Record< string, IconName > = {
	member: 'user',
	order: 'receipt',
	subscription: 'refresh',
	plan: 'layers',
	organisation: 'building',
	application: 'inbox',
	workshop: 'calendar',
	consultation: 'message',
	settings: 'sliders',
	email_template: 'mail',
	post: 'lock',
	term: 'tag',
};

export function entityLink( row: Pick< AuditRow, 'entity_type' | 'entity_id' | 'before' | 'after' > ): { to: string; label: string } | null {
	const id = row.entity_id;
	const any = ( row.after || row.before || {} ) as Record< string, unknown >;
	switch ( row.entity_type ) {
		case 'member':
			return id ? { to: `/members/${ id }`, label: `Member #${ id }` } : null;
		case 'order':
			return id ? { to: `/payments?order=${ id }`, label: `Order #${ id }` } : null;
		case 'subscription':
			return any.member_id ? { to: `/members/${ any.member_id }`, label: `Subscription #${ id }` } : null;
		case 'plan':
			return id ? { to: `/plans?plan=${ id }`, label: `Plan #${ id }` } : null;
		case 'organisation':
			return id ? { to: `/enterprise/organisations/${ id }`, label: ( any.name as string ) || `Organisation #${ id }` } : null;
		case 'application':
			return id ? { to: `/enterprise/applications?app=${ id }`, label: ( any.org_name as string ) || `Application #${ id }` } : null;
		case 'workshop':
			return id ? { to: `/workshops?workshop=${ id }`, label: ( any.title as string ) || `Workshop #${ id }` } : null;
		case 'consultation':
			return id ? { to: `/consultations?id=${ id }`, label: `Consultation #${ id }` } : null;
		case 'email_template':
			return any.key ? { to: `/settings/emails?template=${ any.key }`, label: String( any.name || any.key ) } : null;
		case 'settings':
			return { to: '/settings/general', label: 'Settings' };
		default:
			return id ? { to: `/audit?entity_type=${ row.entity_type }&entity_id=${ id }`, label: `${ row.entity_type } #${ id }` } : null;
	}
}

export function AuditTimeline( { rows, compact, showEntity = true }: { rows: AuditRow[]; compact?: boolean; showEntity?: boolean } ) {
	const [ open, setOpen ] = useState< number | null >( null );
	return (
		<ol className={ cx( 'wvm-a-timeline', compact && 'is-compact' ) }>
			{ rows.map( ( r ) => {
				const link = showEntity ? entityLink( r ) : null;
				const hasDiff = !! ( r.before || r.after );
				return (
					<li key={ r.id } className="wvm-a-timeline__item">
						<span className={ cx( 'wvm-a-timeline__dot', `is-${ r.actor_type }` ) }>
							<Icon name={ ENTITY_ICON[ r.entity_type ] || 'activity' } size={ 13 } />
						</span>
						<div className="wvm-a-timeline__body">
							<div className="wvm-a-timeline__line">
								<strong>{ humanAction( r.action ) }</strong>
								{ link && (
									<>
										<span className="wvm-a-muted"> · </span>
										<A to={ link.to }>{ link.label }</A>
									</>
								) }
							</div>
							<div className="wvm-a-timeline__meta">
								<span className="wvm-a-timeline__actor">
									{ r.actor_type === 'admin' ? <Avatar name={ r.actor_name } size={ 16 } /> : null }
									{ r.actor_name }
								</span>
								<span title={ formatDateTime( r.created_at ) }>{ relativeTime( r.created_at ) }</span>
								{ hasDiff && ! compact && (
									<button type="button" className="wvm-a-timeline__toggle" onClick={ () => setOpen( open === r.id ? null : r.id ) }>
										{ open === r.id ? 'Hide changes' : 'View changes' }
										<Icon name="chevronDown" size={ 12 } className={ open === r.id ? 'is-flipped' : '' } />
									</button>
								) }
							</div>
							{ r.reason && <div className="wvm-a-timeline__reason">“{ r.reason }”</div> }
							{ open === r.id && <JsonDiff before={ r.before } after={ r.after } /> }
						</div>
					</li>
				);
			} ) }
		</ol>
	);
}

function show( v: unknown ): ReactNode {
	if ( v === undefined ) {
		return <span className="wvm-a-muted">—</span>;
	}
	if ( v === null ) {
		return <span className="wvm-a-muted">null</span>;
	}
	if ( typeof v === 'object' ) {
		return <code>{ JSON.stringify( v ) }</code>;
	}
	if ( typeof v === 'boolean' ) {
		return <code>{ v ? 'true' : 'false' }</code>;
	}
	return String( v );
}

/** Key-by-key diff of two flat-ish objects; changed keys highlighted. */
export function JsonDiff( { before, after }: { before: Record< string, unknown > | null; after: Record< string, unknown > | null } ) {
	const [ onlyChanged, setOnlyChanged ] = useState( !! ( before && after ) );
	const keys = Array.from( new Set( [ ...Object.keys( before || {} ), ...Object.keys( after || {} ) ] ) );
	const changed = ( k: string ) => JSON.stringify( before?.[ k ] ) !== JSON.stringify( after?.[ k ] );
	const list = keys.filter( ( k ) => ! onlyChanged || changed( k ) );
	return (
		<div className="wvm-a-diff">
			<div className="wvm-a-diff__bar">
				<span>
					{ keys.filter( changed ).length } changed of { keys.length } fields
				</span>
				{ before && after && (
					<label>
						<input type="checkbox" checked={ onlyChanged } onChange={ ( e ) => setOnlyChanged( e.target.checked ) } /> Only changed
					</label>
				) }
			</div>
			<div className="wvm-a-diff__scroll">
				<table>
					<thead>
						<tr>
							<th>Field</th>
							<th>Before</th>
							<th>After</th>
						</tr>
					</thead>
					<tbody>
						{ list.length === 0 && (
							<tr>
								<td colSpan={ 3 } className="wvm-a-muted">
									No field changes recorded.
								</td>
							</tr>
						) }
						{ list.map( ( k ) => (
							<tr key={ k } className={ changed( k ) ? 'is-changed' : '' }>
								<th>{ k }</th>
								<td className="is-before">{ before ? show( before[ k ] ) : <span className="wvm-a-muted">—</span> }</td>
								<td className="is-after">{ after ? show( after[ k ] ) : <span className="wvm-a-muted">—</span> }</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</div>
	);
}
