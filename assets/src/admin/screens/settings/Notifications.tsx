import { useState } from 'react';
import { useApi } from '../../hooks/useApi';
import { api } from '../../lib/api';
import type { EmailTemplateRow, NotificationRow } from '../../lib/types';
import { formatDateTime, relativeTime } from '../../../shared/format';
import { Btn, StatusChip, Mono, Muted, EmptyState, A } from '../../components/ui';
import { DataTable } from '../../components/DataTable';
import { FilterBar, FilterSelect } from '../../components/FilterBar';
import { useToast } from '../../components/Toasts';
import { SettingsFrame } from './shared';

export function NotificationsLog() {
	const toast = useToast();
	const [ status, setStatus ] = useState( '' );
	const [ template, setTemplate ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ busy, setBusy ] = useState< number | null >( null );
	const res = useApi< NotificationRow[] >( 'notifications', { status, template, page, per_page: 25 } );
	const templates = useApi< EmailTemplateRow[] >( 'email-templates' );
	const name = ( key: string ) => templates.data?.find( ( t ) => t.key === key )?.name || key;
	return (
		<SettingsFrame title="Email log" description="Every transactional email the plugin queued, with delivery status. Failed emails retry automatically up to 3 times.">
			<div className="wvm-a-card is-flush is-inset">
				<FilterBar active={ !! ( status || template ) } onClear={ () => ( setStatus( '' ), setTemplate( '' ) ) }>
					<FilterSelect label="Status" value={ status } onChange={ ( v ) => ( setStatus( v ), setPage( 1 ) ) } options={ [ { value: 'queued', label: 'Queued' }, { value: 'sent', label: 'Sent' }, { value: 'failed', label: 'Failed' } ] } />
					<FilterSelect label="Template" value={ template } onChange={ ( v ) => ( setTemplate( v ), setPage( 1 ) ) } options={ ( templates.data || [] ).map( ( t ) => ( { value: t.key, label: t.name } ) ) } />
				</FilterBar>
				<DataTable
					columns={ [
						{ key: 'subject', header: 'Email', render: ( n ) => <div className="wvm-a-person is-plain"><div><strong>{ n.subject }</strong><span>{ name( n.template_key ) } · <Mono>{ n.template_key }</Mono></span></div></div> },
						{ key: 'to', header: 'To', render: ( n ) => ( n.member_id ? <A to={ `/members/${ n.member_id }?tab=emails` }>{ n.to_email }</A> : n.to_email ) },
						{ key: 'status', header: 'Status', render: ( n ) => <div className="wvm-a-ordercell"><StatusChip status={ n.status } />{ n.error && <span className="wvm-a-errortext" title={ n.error }>{ n.error }</span> }</div> },
						{ key: 'attempts', header: 'Tries', align: 'center', render: ( n ) => n.attempts },
						{ key: 'when', header: 'When', render: ( n ) => <span title={ formatDateTime( n.sent_at || n.created_at ) }>{ relativeTime( n.sent_at || n.created_at ) }</span> },
						{
							key: 'act',
							header: '',
							align: 'right',
							render: ( n ) =>
								n.status !== 'sent' ? (
									<Btn
										size="compact"
										icon="refresh"
										busy={ busy === n.id }
										onClick={ async () => {
											setBusy( n.id );
											try {
												await api.post( `notifications/${ n.id }/retry` );
												toast.success( 'Email re-queued' );
												res.reload();
											} catch ( e ) {
												toast.error( e );
											} finally {
												setBusy( null );
											}
										} }
									>
										Retry
									</Btn>
								) : (
									<Muted>—</Muted>
								),
						},
					] }
					rows={ res.data }
					rowKey={ ( n ) => n.id }
					loading={ res.loading }
					error={ res.error }
					onRetry={ res.reload }
					meta={ res.meta }
					onPage={ setPage }
					empty={ <EmptyState icon="mail" title="No emails match" /> }
				/>
			</div>
		</SettingsFrame>
	);
}
