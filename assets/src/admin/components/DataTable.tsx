/* Server-paginated table: sortable headers, skeleton rows, empty state, row click, selection. */
import type { ReactNode } from 'react';
import { Icon } from './Icon';
import { Skeleton, EmptyState, ErrorState, cx } from './ui';
import type { Meta } from '../hooks/useApi';
import type { ApiError } from '../lib/api';

export interface Column< T > {
	key: string;
	header: ReactNode;
	render?: ( row: T ) => ReactNode;
	/** Sort key sent to the server (asc); descending is "-key". */
	sort?: string;
	align?: 'left' | 'right' | 'center';
	width?: number | string;
	className?: string;
	hideBelow?: number;
}

export interface DataTableProps< T > {
	columns: Column< T >[];
	rows: T[] | undefined;
	rowKey: ( row: T ) => number | string;
	loading?: boolean;
	error?: ApiError | null;
	onRetry?: () => void;
	meta?: Meta;
	onPage?: ( page: number ) => void;
	sort?: string;
	onSort?: ( sort: string ) => void;
	onRowClick?: ( row: T ) => void;
	selectable?: boolean;
	selected?: Set< number | string >;
	onSelect?: ( next: Set< number | string > ) => void;
	empty?: ReactNode;
	rowClassName?: ( row: T ) => string | undefined;
	expanded?: ( row: T ) => ReactNode | null;
	skeletonRows?: number;
	dense?: boolean;
}

export function DataTable< T >( p: DataTableProps< T > ) {
	const rows = p.rows || [];
	const showSkeleton = p.loading && ! p.rows;
	const allSelected = p.selectable && rows.length > 0 && rows.every( ( r ) => p.selected?.has( p.rowKey( r ) ) );
	const toggleAll = () => {
		const next = new Set( p.selected );
		if ( allSelected ) {
			rows.forEach( ( r ) => next.delete( p.rowKey( r ) ) );
		} else {
			rows.forEach( ( r ) => next.add( p.rowKey( r ) ) );
		}
		p.onSelect?.( next );
	};
	const toggleOne = ( k: number | string ) => {
		const next = new Set( p.selected );
		if ( next.has( k ) ) {
			next.delete( k );
		} else {
			next.add( k );
		}
		p.onSelect?.( next );
	};
	const colCount = p.columns.length + ( p.selectable ? 1 : 0 );

	if ( p.error && ! p.rows ) {
		return <ErrorState error={ p.error } onRetry={ p.onRetry } />;
	}

	return (
		<div className={ cx( 'wvm-a-table', p.loading && p.rows && 'is-reloading', p.dense && 'is-dense' ) }>
			<div className="wvm-a-table__scroll">
				<table>
					<thead>
						<tr>
							{ p.selectable && (
								<th className="wvm-a-table__check">
									<input type="checkbox" aria-label="Select all on this page" checked={ !! allSelected } onChange={ toggleAll } />
								</th>
							) }
							{ p.columns.map( ( c ) => {
								const active = p.sort && c.sort && ( p.sort === c.sort || p.sort === '-' + c.sort );
								const desc = active && p.sort?.startsWith( '-' );
								return (
									<th key={ c.key } style={ { width: c.width, textAlign: c.align } } className={ cx( c.className, c.sort && 'is-sortable', active && 'is-sorted' ) }>
										{ c.sort && p.onSort ? (
											<button type="button" onClick={ () => p.onSort?.( active && ! desc ? '-' + c.sort : c.sort! ) }>
												{ c.header }
												<Icon name="chevronDown" size={ 12 } className={ cx( 'wvm-a-table__sorticon', active && ! desc && 'is-asc' ) } />
											</button>
										) : (
											c.header
										) }
									</th>
								);
							} ) }
						</tr>
					</thead>
					<tbody>
						{ showSkeleton &&
							Array.from( { length: p.skeletonRows || 6 } ).map( ( _, i ) => (
								<tr key={ 's' + i } className="is-skeleton">
									{ p.selectable && <td /> }
									{ p.columns.map( ( c, j ) => (
										<td key={ c.key }>
											<Skeleton w={ j === 0 ? '70%' : `${ 40 + ( ( i * 7 + j * 13 ) % 45 ) }%` } h={ 12 } />
										</td>
									) ) }
								</tr>
							) ) }
						{ ! showSkeleton && rows.length === 0 && (
							<tr className="is-empty">
								<td colSpan={ colCount }>{ p.empty || <EmptyState title="Nothing here yet" /> }</td>
							</tr>
						) }
						{ rows.map( ( r ) => {
							const k = p.rowKey( r );
							const exp = p.expanded ? p.expanded( r ) : null;
							return [
								<tr
									key={ k }
									className={ cx( p.onRowClick && 'is-clickable', p.selected?.has( k ) && 'is-selected', p.rowClassName?.( r ), exp && 'is-expanded' ) }
									onClick={ ( e ) => {
										const t = e.target as HTMLElement;
										if ( t.closest( 'a,button,input,select,label,textarea,.wvm-a-stop' ) ) {
											return;
										}
										p.onRowClick?.( r );
									} }
								>
									{ p.selectable && (
										<td className="wvm-a-table__check">
											<input type="checkbox" aria-label="Select row" checked={ !! p.selected?.has( k ) } onChange={ () => toggleOne( k ) } />
										</td>
									) }
									{ p.columns.map( ( c ) => (
										<td key={ c.key } style={ { textAlign: c.align } } className={ c.className }>
											{ c.render ? c.render( r ) : String( ( r as Record< string, unknown > )[ c.key ] ?? '' ) }
										</td>
									) ) }
								</tr>,
								exp ? (
									<tr key={ k + '-x' } className="wvm-a-table__expand">
										<td colSpan={ colCount }>{ exp }</td>
									</tr>
								) : null,
							];
						} ) }
					</tbody>
				</table>
			</div>
			{ p.meta && p.onPage && <Pagination meta={ p.meta } onPage={ p.onPage } /> }
		</div>
	);
}

export function Pagination( { meta, onPage }: { meta: Meta; onPage: ( p: number ) => void } ) {
	const page = Number( meta.page || 1 );
	const pages = Math.max( 1, Number( meta.pages || 1 ) );
	const per = Number( meta.per_page || 20 );
	const total = Number( meta.total || 0 );
	const from = total === 0 ? 0 : ( page - 1 ) * per + 1;
	const to = Math.min( total, page * per );
	const nums: ( number | '…' )[] = [];
	for ( let i = 1; i <= pages; i++ ) {
		if ( i === 1 || i === pages || Math.abs( i - page ) <= 1 ) {
			nums.push( i );
		} else if ( nums[ nums.length - 1 ] !== '…' ) {
			nums.push( '…' );
		}
	}
	return (
		<div className="wvm-a-pager">
			<span className="wvm-a-pager__info">
				{ total === 0 ? 'No results' : (
					<>
						Showing <strong>{ from }–{ to }</strong> of <strong>{ total.toLocaleString() }</strong>
					</>
				) }
			</span>
			{ pages > 1 && (
				<div className="wvm-a-pager__nav">
					<button type="button" disabled={ page <= 1 } onClick={ () => onPage( page - 1 ) } aria-label="Previous page">
						<Icon name="chevronLeft" size={ 14 } />
					</button>
					{ nums.map( ( n, i ) =>
						n === '…' ? (
							<span key={ 'e' + i } className="wvm-a-pager__gap">…</span>
						) : (
							<button key={ n } type="button" className={ n === page ? 'is-current' : '' } onClick={ () => onPage( n ) }>
								{ n }
							</button>
						)
					) }
					<button type="button" disabled={ page >= pages } onClick={ () => onPage( page + 1 ) } aria-label="Next page">
						<Icon name="chevronRight" size={ 14 } />
					</button>
				</div>
			) }
		</div>
	);
}
