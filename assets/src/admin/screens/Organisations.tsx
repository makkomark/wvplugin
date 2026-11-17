import { useEffect, useState } from 'react';
import type { Route } from '../router';
import { navigate } from '../router';
import { useApi } from '../hooks/useApi';
import { api, fieldError, todayYmd } from '../lib/api';
import type { Organisation, Application } from '../../shared/types';
import { formatDate, STATUS_LABELS } from '../../shared/format';
import { PageHeader, Btn, StatusChip, EmptyState, Progress, Muted, Mono, Card } from '../components/ui';
import { DataTable } from '../components/DataTable';
import { FilterBar, SearchInput, FilterSelect } from '../components/FilterBar';
import { FormModal } from '../components/overlays';
import { TextField, TextareaField, LevelSelect, FieldGrid } from '../components/fields';
import { useToast } from '../components/Toasts';
import { EnterpriseTabs } from './Applications';

export function Organisations( { route }: { route: Route } ) {
	const [ q, setQ ] = useState( '' );
	const [ status, setStatus ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ creating, setCreating ] = useState( route.query.new === '1' );
	const res = useApi< Organisation[] >( 'organisations', { q, status, page, per_page: 25 } );
	const apps = useApi< Application[] >( 'applications', { status: 'new', per_page: 1 } );
	return (
		<div>
			<PageHeader
				title="Enterprise"
				subtitle="Applications from the enterprise form, and the organisations you serve"
				tabs={ <EnterpriseTabs value="organisations" newCount={ apps.meta ? Number( apps.meta.total ) : null } /> }
				actions={
					<Btn variant="primary" icon="plus" onClick={ () => setCreating( true ) }>
						New organisation
					</Btn>
				}
			/>
			<Card pad={ false }>
				<FilterBar active={ !! ( q || status ) } onClear={ () => ( setQ( '' ), setStatus( '' ) ) }>
					<SearchInput value={ q } onChange={ ( v ) => ( setQ( v ), setPage( 1 ) ) } placeholder="Search organisations" />
					<FilterSelect label="Status" value={ status } onChange={ ( v ) => ( setStatus( v ), setPage( 1 ) ) } options={ [ 'active', 'suspended', 'expired' ].map( ( s ) => ( { value: s, label: STATUS_LABELS[ s ] || s } ) ) } />
				</FilterBar>
				<DataTable
					columns={ [
						{ key: 'name', header: 'Organisation', render: ( o ) => <div className="wvm-a-person is-plain"><div><strong>{ o.name }</strong><span>{ o.programme_title || 'No programme title' }</span></div></div> },
						{ key: 'status', header: 'Status', render: ( o ) => <StatusChip status={ o.status } /> },
						{
							key: 'seats',
							header: 'Seats',
							width: 220,
							render: ( o ) => (
								<div className="wvm-a-seatcell">
									<span>
										<strong>{ o.seats_used }</strong> joined · { o.seats_pending } invited <Muted>/ { o.seats }</Muted>
									</span>
									<Progress value={ o.seats_used + o.seats_pending } max={ o.seats } tone={ o.seats_available <= 0 ? 'danger' : o.seats_available < o.seats * 0.1 ? 'warning' : 'primary' } />
								</div>
							),
						},
						{ key: 'term', header: 'Term', render: ( o ) => `${ formatDate( o.term_start ) } → ${ formatDate( o.term_end ) }` },
						{ key: 'ref', header: 'Contract', render: ( o ) => ( o.contract_ref ? <Mono>{ o.contract_ref }</Mono> : <Muted>—</Muted> ) },
						{ key: 'contact', header: 'Contact', render: ( o ) => o.contact_name || o.contact_email || <Muted>Not joined yet</Muted> },
					] }
					rows={ res.data }
					rowKey={ ( o ) => o.id }
					loading={ res.loading }
					error={ res.error }
					onRetry={ res.reload }
					meta={ res.meta }
					onPage={ setPage }
					onRowClick={ ( o ) => navigate( `/enterprise/organisations/${ o.id }` ) }
					empty={
						<EmptyState icon="building" title="No organisations yet" action={ <Btn icon="plus" onClick={ () => setCreating( true ) }>New organisation</Btn> }>
							Convert an application or create an organisation directly to start inviting seats.
						</EmptyState>
					}
				/>
			</Card>
			<NewOrgModal open={ creating } onClose={ () => setCreating( false ) } />
		</div>
	);
}

function NewOrgModal( { open, onClose }: { open: boolean; onClose: () => void } ) {
	const toast = useToast();
	const blank = () => {
		const end = new Date();
		end.setFullYear( end.getFullYear() + 1 );
		return { name: '', seats: '10', level: 3, term_start: todayYmd(), term_end: end.toISOString().slice( 0, 10 ), contract_ref: '', programme_title: '', programme_description: '', contact_email: '', contact_name: '', notes: '' };
	};
	const [ f, setF ] = useState( blank );
	const [ err, setErr ] = useState< unknown >( null );
	useEffect( () => {
		if ( open ) {
			setF( blank() );
			setErr( null );
		}
	}, [ open ] );
	const set = ( p: Partial< ReturnType< typeof blank > > ) => setF( { ...f, ...p } );
	return (
		<FormModal
			open={ open }
			title="New organisation"
			submitLabel="Create organisation"
			canSubmit={ !! f.name.trim() && Number( f.seats ) > 0 }
			onClose={ onClose }
			onSubmit={ async () => {
				try {
					const r = await api.post< Organisation >( 'organisations', { ...f, seats: Number( f.seats ), contact_email: f.contact_email || undefined } );
					toast.success( `${ r.data.name } created` );
					onClose();
					navigate( `/enterprise/organisations/${ r.data.id }` );
				} catch ( e ) {
					setErr( e );
					throw e;
				}
			} }
		>
			<FieldGrid>
				<TextField label="Name" value={ f.name } onChange={ ( name ) => set( { name } ) } error={ fieldError( err, 'name' ) } autoFocus />
				<TextField label="Seats" type="number" min={ 1 } value={ f.seats } onChange={ ( seats ) => set( { seats } ) } error={ fieldError( err, 'seats' ) } />
				<TextField label="Term start" type="date" value={ f.term_start } onChange={ ( term_start ) => set( { term_start } ) } />
				<TextField label="Term end" type="date" value={ f.term_end } onChange={ ( term_end ) => set( { term_end } ) } />
				<TextField label="Contract reference" value={ f.contract_ref } onChange={ ( contract_ref ) => set( { contract_ref } ) } />
				<LevelSelect value={ f.level } onChange={ ( level ) => set( { level } ) } />
			</FieldGrid>
			<TextField label="Programme title" value={ f.programme_title } onChange={ ( programme_title ) => set( { programme_title } ) } />
			<TextareaField label="Programme description" value={ f.programme_description } onChange={ ( programme_description ) => set( { programme_description } ) } rows={ 2 } help="Shown to seat holders on their Our Programme tab." />
			<FieldGrid>
				<TextField label="Contact email (org admin)" type="email" value={ f.contact_email } onChange={ ( contact_email ) => set( { contact_email } ) } help="Optional — invited as the organisation admin." />
				<TextField label="Contact name" value={ f.contact_name } onChange={ ( contact_name ) => set( { contact_name } ) } />
			</FieldGrid>
		</FormModal>
	);
}
