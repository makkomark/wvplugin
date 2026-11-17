import { useEffect, useState } from 'react';
import { useApi } from '../../hooks/useApi';
import { api } from '../../lib/api';
import type { KitLogRow, KitStatus, TagRule } from '../../lib/types';
import { relativeTime, formatDateTime } from '../../../shared/format';
import { Btn, Chip, Mono, Muted, EmptyState, Notice2, ResultBlock, StatusChip, A, Card, Skeleton, Tabs, cx } from '../../components/ui';
import { Icon } from '../../components/Icon';
import { DataTable } from '../../components/DataTable';
import { FilterBar, FilterSelect } from '../../components/FilterBar';
import { TextField, Toggle } from '../../components/fields';
import { TokenInput } from '../../components/editors';
import { useToast } from '../../components/Toasts';
import { useSettingsGroup, SettingsFrame, Section } from './shared';

interface KitValues extends Record< string, unknown > {
	api_key: string;
	api_key_set?: boolean;
	create_subscribers_on_paid: boolean;
	tag_map?: unknown;
	last_reconcile_at?: string | null;
	last_reconcile_errors?: number;
}

interface TagMapData { map: Record< string, TagRule >; defaults: Record< string, TagRule >; plan_tags: string[]; states: string[] }

const STATE_HELP: Record< string, string > = {
	free: 'On the free plan',
	active: 'Paid and in good standing',
	past_due: 'Renewal unpaid, in grace',
	suspended: 'Grace over — access removed',
	expired: 'Lapsed beyond the suspension window',
	cancelled: 'Cancelled and ended',
};

export function KitSettings() {
	const toast = useToast();
	const s = useSettingsGroup< KitValues >( 'kit' );
	const status = useApi< KitStatus >( 'kit/status' );
	const [ tab, setTab ] = useState( 'connection' );
	const [ testing, setTesting ] = useState( false );
	const [ testResult, setTestResult ] = useState< { ok: boolean; data: unknown } | null >( null );
	const [ reconciling, setReconciling ] = useState( false );
	const v = s.values;
	const st = status.data;
	return (
		<SettingsFrame title="Kit" description="Kit (ConvertKit) delivers your content. The plugin keeps each member’s WV- tags in sync with their membership state." state={ tab === 'connection' ? s : undefined }>
			<div className="wvm-a-kitstatus">
				{ ! st ? (
					<Skeleton h={ 78 } r={ 14 } />
				) : (
					<>
						<div className={ cx( 'wvm-a-kitstatus__main', st.connected ? 'is-ok' : st.configured ? 'is-error' : 'is-off' ) }>
							<span className="wvm-a-kitstatus__dot" />
							<div>
								<strong>{ st.connected ? `Connected${ st.account_name ? ` to ${ st.account_name }` : '' }` : st.configured ? 'Key saved but Kit isn’t responding' : 'Not connected' }</strong>
								<span>{ st.last_reconcile_at ? `Last reconcile ${ relativeTime( st.last_reconcile_at ) }` : 'Never reconciled' }</span>
							</div>
						</div>
						<div className="wvm-a-kitstatus__num">
							<strong>{ st.synced_members }</strong>
							<span>Linked members</span>
						</div>
						<div className={ cx( 'wvm-a-kitstatus__num', st.error_count_24h > 0 && 'is-bad' ) }>
							<strong>{ st.error_count_24h }</strong>
							<span>Errors (24h)</span>
						</div>
						<div className={ cx( 'wvm-a-kitstatus__num', st.members_with_errors > 0 && 'is-bad' ) }>
							<strong>{ st.members_with_errors }</strong>
							<span>Members with errors</span>
						</div>
						<div className="wvm-a-kitstatus__actions">
							<Btn
								icon="refresh"
								busy={ reconciling }
								onClick={ async () => {
									setReconciling( true );
									try {
										const r = await api.post< { queued: number } >( 'kit/reconcile' );
										toast.success( r.data.queued ? `Reconcile queued for ${ r.data.queued } members` : 'Nothing queued — add your Kit API key first' );
										status.reload();
									} catch ( e ) {
										toast.error( e );
									} finally {
										setReconciling( false );
									}
								} }
							>
								Reconcile now
							</Btn>
						</div>
					</>
				) }
			</div>
			<Tabs
				variant="segmented"
				value={ tab }
				onChange={ setTab }
				tabs={ [
					{ key: 'connection', label: 'Connection' },
					{ key: 'tagmap', label: 'Tag map' },
					{ key: 'tags', label: 'Kit tags' },
					{ key: 'log', label: 'Sync log' },
				] }
			/>
			{ tab === 'connection' && v && (
				<>
					<Section title="API key" description="Kit → Settings → Developer → API v4 key. Stored encrypted.">
						<TextField label="API key" type="password" value={ v.api_key } onChange={ ( api_key ) => s.set( { api_key } ) } placeholder={ v.api_key_set ? '' : 'kit_…' } help={ v.api_key_set ? 'A key is saved. Replace it by typing a new one.' : 'No key saved yet.' } />
						<div className="wvm-a-inline">
							<Btn
								icon="zap"
								busy={ testing }
								onClick={ async () => {
									setTesting( true );
									setTestResult( null );
									try {
										if ( s.dirty ) {
											await s.save();
										}
										const r = await api.post( 'kit/test-connection' );
										setTestResult( { ok: true, data: r.data } );
										status.reload();
									} catch ( e ) {
										setTestResult( { ok: false, data: ( e as Error ).message } );
									} finally {
										setTesting( false );
									}
								} }
							>
								{ s.dirty ? 'Save & test connection' : 'Test connection' }
							</Btn>
						</div>
						{ testResult && (
							<Notice2 tone={ testResult.ok ? 'success' : 'danger' } title={ testResult.ok ? 'Kit accepted the key' : 'Connection failed' }>
								{ testResult.ok ? <ResultBlock data={ testResult.data } /> : String( testResult.data ) }
							</Notice2>
						) }
					</Section>
					<Section title="Subscribers">
						<Toggle label="Create Kit subscribers when a member pays" checked={ v.create_subscribers_on_paid } onChange={ ( create_subscribers_on_paid ) => s.set( { create_subscribers_on_paid } ) } help="Paid members need a Kit subscriber to receive content, even without marketing consent. Free members are only added with consent." />
					</Section>
				</>
			) }
			{ tab === 'tagmap' && <TagMapEditor /> }
			{ tab === 'tags' && <KitTags /> }
			{ tab === 'log' && <KitLog /> }
		</SettingsFrame>
	);
}

function TagMapEditor() {
	const toast = useToast();
	const res = useApi< TagMapData >( 'kit/tag-map' );
	const [ map, setMap ] = useState< Record< string, TagRule > | null >( null );
	const [ busy, setBusy ] = useState( false );
	useEffect( () => {
		if ( res.data ) {
			setMap( res.data.map );
		}
	}, [ res.data ] );
	if ( ! res.data || ! map ) {
		return <Skeleton h={ 300 } r={ 14 } />;
	}
	const d = res.data;
	const dirty = JSON.stringify( map ) !== JSON.stringify( d.map );
	const suggestions = [ '{plan_tag}', '{plan_tags}', '{other_plan_tags}', 'WV-FREE', 'WV-PAST-DUE', 'WV-EXPIRED', 'WV-CANCELLED', ...d.plan_tags ].map( ( t ) => ( { value: t, label: t } ) );
	return (
		<div className="wvm-a-stack">
			<Notice2 tone="info" icon="tag" title="Tokens">
				<code>{ '{plan_tag}' }</code> = the member’s current plan tag · <code>{ '{plan_tags}' }</code> = every plan tag ({ d.plan_tags.join( ', ' ) }) · <code>{ '{other_plan_tags}' }</code> = every plan tag except the current one. Tags not starting with <code>WV-</code> are never touched.
			</Notice2>
			<div className="wvm-a-tagmap">
				<div className="wvm-a-tagmap__head">
					<span>State</span>
					<span>Add tags</span>
					<span>Remove tags</span>
				</div>
				{ d.states.map( ( state ) => {
					const rule = map[ state ] || { add: [], remove: [] };
					const isDefault = JSON.stringify( rule ) === JSON.stringify( d.defaults[ state ] );
					return (
						<div key={ state } className="wvm-a-tagmap__row">
							<div className="wvm-a-tagmap__state">
								<StatusChip status={ state } />
								<span>{ STATE_HELP[ state ] }</span>
								{ ! isDefault && (
									<button type="button" className="wvm-a-linkbtn" onClick={ () => setMap( { ...map, [ state ]: d.defaults[ state ] } ) }>
										Restore default
									</button>
								) }
							</div>
							<TokenInput value={ rule.add } onChange={ ( add ) => setMap( { ...map, [ state ]: { ...rule, add: add as string[] } } ) } suggestions={ suggestions } placeholder="WV-…" />
							<TokenInput value={ rule.remove } onChange={ ( remove ) => setMap( { ...map, [ state ]: { ...rule, remove: remove as string[] } } ) } suggestions={ suggestions } placeholder="WV-…" />
						</div>
					);
				} ) }
			</div>
			<div className="wvm-a-formfoot">
				<Btn
					variant="primary"
					busy={ busy }
					disabled={ ! dirty }
					onClick={ async () => {
						setBusy( true );
						try {
							const r = await api.put< { map: Record< string, TagRule > } >( 'kit/tag-map', { map } );
							res.setData( ( prev ) => ( prev ? { ...prev, map: r.data.map } : prev ) );
							toast.success( 'Tag map saved — run Reconcile to apply it to everyone' );
						} catch ( e ) {
							toast.error( e );
						} finally {
							setBusy( false );
						}
					} }
				>
					Save tag map
				</Btn>
				<Btn variant="ghost" disabled={ ! dirty } onClick={ () => setMap( d.map ) }>
					Discard
				</Btn>
			</div>
		</div>
	);
}

function KitTags() {
	const res = useApi< { id: number | string; name: string; owned: boolean }[] >( 'kit/tags' );
	if ( ! res.data ) {
		return <Skeleton h={ 160 } r={ 14 } />;
	}
	if ( ! res.data.length ) {
		return (
			<Card>
				<EmptyState icon="tag" title="No tags to show">
					Connect Kit to list the tags on your account. WV- tags are created automatically the first time they’re needed.
				</EmptyState>
			</Card>
		);
	}
	return (
		<div className="wvm-a-checkpills is-wrap">
			{ res.data.map( ( t ) => (
				<span key={ t.id } className={ cx( 'wvm-a-kittag', t.owned && 'is-owned' ) } title={ t.owned ? 'Managed by WitsValley Members' : 'Not managed — never changed by the plugin' }>
					{ t.owned && <Icon name="check" size={ 12 } strokeWidth={ 2.6 } /> }
					{ t.name }
					<Mono>#{ t.id }</Mono>
				</span>
			) ) }
		</div>
	);
}

function KitLog() {
	const [ status, setStatus ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const res = useApi< KitLogRow[] >( 'kit/log', { status, page, per_page: 25 } );
	return (
		<div className="wvm-a-card is-flush is-inset">
			<FilterBar active={ !! status } onClear={ () => setStatus( '' ) }>
				<FilterSelect label="Status" value={ status } onChange={ ( v ) => ( setStatus( v ), setPage( 1 ) ) } options={ [ { value: 'ok', label: 'OK' }, { value: 'error', label: 'Error' } ] } />
			</FilterBar>
			<DataTable
				columns={ [
					{ key: 'when', header: 'When', render: ( r ) => <span title={ formatDateTime( r.created_at ) }>{ relativeTime( r.created_at ) }</span> },
					{ key: 'member', header: 'Member', render: ( r ) => ( r.member_id ? <A to={ `/members/${ r.member_id }` }>#{ r.member_id }</A> : <Muted>—</Muted> ) },
					{ key: 'action', header: 'Action', render: ( r ) => <Chip tone="neutral">{ r.action.replace( /_/g, ' ' ) }</Chip> },
					{ key: 'tag', header: 'Tag', render: ( r ) => ( r.tag ? <Mono>{ r.tag }</Mono> : <Muted>—</Muted> ) },
					{ key: 'status', header: 'Result', render: ( r ) => <StatusChip status={ r.status } /> },
					{ key: 'response', header: 'Response', render: ( r ) => <span className="wvm-a-ellipsis wvm-a-muted" title={ r.response || '' }>{ r.response || '—' }</span> },
				] }
				rows={ res.data }
				rowKey={ ( r ) => r.id }
				loading={ res.loading }
				error={ res.error }
				onRetry={ res.reload }
				meta={ res.meta }
				onPage={ setPage }
				empty={ <EmptyState icon="activity" title="No Kit activity yet">Every add/remove tag call is logged here.</EmptyState> }
			/>
		</div>
	);
}
