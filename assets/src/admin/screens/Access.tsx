import { useEffect, useState } from 'react';
import type { Route } from '../router';
import { replaceQuery } from '../router';
import { useApi } from '../hooks/useApi';
import { usePlans } from '../hooks/usePlans';
import { api, fieldError } from '../lib/api';
import { config, decodeEntities, levelEntries, levelLabel } from '../lib/config';
import type { AccessPost, AccessTerm } from '../lib/types';
import type { Entitlements, MemberRow } from '../../shared/types';
import { PageHeader, Btn, EmptyState, Tabs, Card, Chip, Muted, Notice2, Loading, ErrorState, DescList, cx } from '../components/ui';
import { Icon } from '../components/Icon';
import { DataTable } from '../components/DataTable';
import { FilterBar, SearchInput, FilterSelect } from '../components/FilterBar';
import { FormModal } from '../components/overlays';
import { TextField, TextareaField, LevelSelect, Checkbox, FieldGrid } from '../components/fields';
import { MemberPicker } from '../components/editors';
import { useToast } from '../components/Toasts';

function LevelPill( { value, onChange, disabled }: { value: number; onChange: ( v: number ) => void; disabled?: boolean } ) {
	return (
		<label className={ cx( 'wvm-a-levelpill', `is-l${ Math.min( value, 3 ) }` ) }>
			<Icon name={ value > 0 ? 'lock' : 'globe' } size={ 13 } />
			<select value={ value } disabled={ disabled } onChange={ ( e ) => onChange( Number( e.target.value ) ) } aria-label="Access level">
				{ levelEntries().map( ( [ n, l ] ) => (
					<option key={ n } value={ n }>
						{ l }
					</option>
				) ) }
			</select>
			<Icon name="chevronDown" size={ 12 } />
		</label>
	);
}

export function Access( { route }: { route: Route } ) {
	const [ tab, setTab ] = useState( route.query.tab || 'rules' );
	return (
		<div>
			<PageHeader
				title="Content access"
				subtitle="Choose which posts, pages and categories need a membership level"
				tabs={
					<Tabs
						value={ tab }
						onChange={ ( t ) => {
							setTab( t );
							replaceQuery( { tab: t } );
						} }
						tabs={ [
							{ key: 'rules', label: 'Protected content', icon: 'lock' },
							{ key: 'terms', label: 'Categories & tags', icon: 'tag' },
							{ key: 'settings', label: 'Paywall settings', icon: 'sliders' },
							{ key: 'check', label: 'Check access', icon: 'shield' },
						] }
					/>
				}
			/>
			{ tab === 'rules' && <Rules /> }
			{ tab === 'terms' && <Terms /> }
			{ tab === 'settings' && <AccessSettings /> }
			{ tab === 'check' && <CheckAccess /> }
		</div>
	);
}

function Rules() {
	const toast = useToast();
	const [ q, setQ ] = useState( '' );
	const [ postType, setPostType ] = useState( '' );
	const [ level, setLevel ] = useState( '' );
	const [ page, setPage ] = useState( 1 );
	const [ adding, setAdding ] = useState( false );
	const [ editing, setEditing ] = useState< AccessPost | null >( null );
	const res = useApi< AccessPost[] >( 'access/rules', { q, post_type: postType, level, page, per_page: 20 } );
	const patch = async ( p: AccessPost, body: Record< string, unknown >, msg: string ) => {
		try {
			const r = await api.patch< AccessPost >( `access/posts/${ p.id }`, body );
			res.setData( ( l ) => ( l || [] ).map( ( x ) => ( x.id === p.id ? r.data : x ) ) );
			toast.success( msg );
			return r.data;
		} catch ( e ) {
			toast.error( e );
			return null;
		}
	};
	return (
		<Card pad={ false }>
			<FilterBar
				active={ !! ( q || postType || level ) }
				onClear={ () => ( setQ( '' ), setPostType( '' ), setLevel( '' ) ) }
				right={
					<Btn variant="primary" icon="plus" size="compact" onClick={ () => setAdding( true ) }>
						Protect content
					</Btn>
				}
			>
				<SearchInput value={ q } onChange={ ( v ) => ( setQ( v ), setPage( 1 ) ) } placeholder="Search protected content" />
				<FilterSelect label="Type" value={ postType } onChange={ ( v ) => ( setPostType( v ), setPage( 1 ) ) } options={ config.postTypes.map( ( p ) => ( { value: p.name, label: p.label } ) ) } />
				<FilterSelect label="Level" value={ level } onChange={ ( v ) => ( setLevel( v ), setPage( 1 ) ) } options={ levelEntries().filter( ( [ n ] ) => n > 0 ).map( ( [ n, l ] ) => ( { value: String( n ), label: l } ) ) } />
			</FilterBar>
			<DataTable
				columns={ [
					{ key: 'title', header: 'Content', render: ( p ) => <div className="wvm-a-person is-plain"><div><strong>{ decodeEntities( p.title ) }</strong><span>{ p.post_type } · { p.status }</span></div></div> },
					{ key: 'level', header: 'Required level', render: ( p ) => <LevelPill value={ p.level } onChange={ ( v ) => patch( p, { level: v }, `${ decodeEntities( p.title ) } now requires ${ levelLabel( v ) }` ) } /> },
					{ key: 'plans', header: 'Specific plans', render: ( p ) => ( p.plans.length ? p.plans.map( ( s ) => <Chip key={ s } tone="primary">{ s }</Chip> ) : <Muted>Any plan at level</Muted> ) },
					{ key: 'teaser', header: 'Teaser', render: ( p ) => ( p.teaser_paragraphs === null ? <Muted>Default</Muted> : `${ p.teaser_paragraphs } ¶` ) },
					{ key: 'source', header: 'Rule from', render: ( p ) => <Chip tone={ p.source === 'post' ? 'info' : 'neutral' }>{ p.source }</Chip> },
					{
						key: 'act',
						header: '',
						align: 'right',
						render: ( p ) => (
							<span className="wvm-a-rowactions">
								<Btn size="compact" variant="ghost" icon="edit" onClick={ () => setEditing( p ) }>
									Edit
								</Btn>
								<Btn size="compact" variant="ghost" icon="external" href={ p.view_url } target="_blank" title="View" />
								<Btn
									size="compact"
									variant="ghost"
									destructive
									onClick={ async () => {
										if ( await patch( p, { level: null, plans: [] }, 'Protection removed' ) ) {
											res.reload();
										}
									} }
								>
									Unprotect
								</Btn>
							</span>
						),
					},
				] }
				rows={ res.data }
				rowKey={ ( p ) => p.id }
				loading={ res.loading }
				error={ res.error }
				onRetry={ res.reload }
				meta={ res.meta }
				onPage={ setPage }
				empty={
					<EmptyState icon="lock" title="No protected content yet" action={ <Btn icon="plus" onClick={ () => setAdding( true ) }>Protect content</Btn> }>
						Everything is public. Protect posts here, from the post editor’s WitsValley box, or by category.
					</EmptyState>
				}
			/>
			<ProtectModal open={ adding } onClose={ () => setAdding( false ) } onDone={ res.reload } />
			<RuleModal post={ editing } onClose={ () => setEditing( null ) } onDone={ ( p ) => { res.setData( ( l ) => ( l || [] ).map( ( x ) => ( x.id === p.id ? p : x ) ) ); setEditing( null ); } } />
		</Card>
	);
}

function ProtectModal( { open, onClose, onDone }: { open: boolean; onClose: () => void; onDone: () => void } ) {
	const toast = useToast();
	const [ q, setQ ] = useState( '' );
	const [ level, setLevel ] = useState( 1 );
	const res = useApi< AccessPost[] >( open ? 'access/posts' : null, { q } );
	const [ busy, setBusy ] = useState< number | null >( null );
	return (
		<FormModal open={ open } title="Protect content" onClose={ onClose } submitLabel="Done" hideCancel onSubmit={ onClose } size="large" description="Find a post or page and choose the level a member needs to read it.">
			<div className="wvm-a-protectbar">
				<SearchInput value={ q } onChange={ setQ } placeholder="Search posts, pages…" autoFocus />
				<LevelSelect label="Protect at" value={ level } onChange={ setLevel } />
			</div>
			<ul className="wvm-a-rowlist is-tall">
				{ ! res.data && <Loading /> }
				{ ( res.data || [] ).map( ( p ) => (
					<li key={ p.id }>
						<div>
							<strong>{ decodeEntities( p.title ) }</strong>
							<span>
								{ p.post_type } · currently <b>{ p.level_label }</b>
								{ p.source !== 'post' && p.source !== 'default' ? ` (from ${ p.source })` : '' }
							</span>
						</div>
						<Btn
							size="compact"
							variant={ p.level === level ? 'secondary' : 'primary' }
							icon={ p.level === level ? 'check' : 'lock' }
							busy={ busy === p.id }
							disabled={ p.level === level }
							onClick={ async () => {
								setBusy( p.id );
								try {
									const r = await api.patch< AccessPost >( `access/posts/${ p.id }`, { level } );
									res.setData( ( l ) => ( l || [] ).map( ( x ) => ( x.id === p.id ? r.data : x ) ) );
									toast.success( `${ decodeEntities( p.title ) } → ${ levelLabel( level ) }` );
									onDone();
								} catch ( e ) {
									toast.error( e );
								} finally {
									setBusy( null );
								}
							} }
						>
							{ p.level === level ? 'Protected' : `Set ${ levelLabel( level ) }` }
						</Btn>
					</li>
				) ) }
				{ res.data && res.data.length === 0 && <li className="wvm-a-muted">No matching content.</li> }
			</ul>
		</FormModal>
	);
}

function RuleModal( { post, onClose, onDone }: { post: AccessPost | null; onClose: () => void; onDone: ( p: AccessPost ) => void } ) {
	const { plans } = usePlans();
	const [ level, setLevel ] = useState( 0 );
	const [ chosen, setChosen ] = useState< string[] >( [] );
	const [ teaser, setTeaser ] = useState( '' );
	useEffect( () => {
		if ( post ) {
			setLevel( post.level );
			setChosen( post.plans );
			setTeaser( post.teaser_paragraphs === null ? '' : String( post.teaser_paragraphs ) );
		}
	}, [ post ] );
	if ( ! post ) {
		return null;
	}
	return (
		<FormModal
			open
			title={ `Access for “${ decodeEntities( post.title ) }”` }
			onClose={ onClose }
			submitLabel="Save rule"
			onSubmit={ async () => {
				const r = await api.patch< AccessPost >( `access/posts/${ post.id }`, { level, plans: chosen, teaser_paragraphs: teaser === '' ? null : Number( teaser ) } );
				onDone( r.data );
			} }
		>
			<FieldGrid>
				<LevelSelect label="Required level" value={ level } onChange={ setLevel } />
				<TextField label="Teaser paragraphs" type="number" min={ 0 } value={ teaser } onChange={ setTeaser } placeholder="Use default" help="Paragraphs shown before the paywall." />
			</FieldGrid>
			<div className="wvm-a-field">
				<span className="wvm-a-label">Or only these plans</span>
				<div className="wvm-a-checkgrid">
					{ plans.map( ( p ) => (
						<Checkbox key={ p.slug } label={ p.name } checked={ chosen.includes( p.slug ) } onChange={ ( on ) => setChosen( on ? [ ...chosen, p.slug ] : chosen.filter( ( s ) => s !== p.slug ) ) } />
					) ) }
				</div>
				<p className="wvm-a-help">When plans are chosen, members on any of them can read it regardless of level.</p>
			</div>
		</FormModal>
	);
}

function Terms() {
	const toast = useToast();
	const res = useApi< AccessTerm[] >( 'access/terms' );
	const [ q, setQ ] = useState( '' );
	const rows = ( res.data || [] ).filter( ( t ) => ! q || t.name.toLowerCase().includes( q.toLowerCase() ) );
	return (
		<Card pad={ false }>
			<FilterBar>
				<SearchInput value={ q } onChange={ setQ } placeholder="Filter categories and tags" delay={ 0 } />
			</FilterBar>
			<DataTable
				columns={ [
					{ key: 'name', header: 'Term', render: ( t ) => <div className="wvm-a-person is-plain"><div><strong>{ decodeEntities( t.name ) }</strong><span>{ t.taxonomy_label }</span></div></div> },
					{ key: 'count', header: 'Posts', align: 'right', render: ( t ) => <span className="wvm-a-num">{ t.count }</span> },
					{
						key: 'level',
						header: 'Default level for its posts',
						render: ( t ) => (
							<LevelPill
								value={ t.level }
								onChange={ async ( v ) => {
									try {
										const r = await api.patch< AccessTerm >( `access/terms/${ t.id }`, { level: v } );
										res.setData( ( l ) => ( l || [] ).map( ( x ) => ( x.id === t.id ? { ...x, ...r.data } : x ) ) );
										toast.success( `${ decodeEntities( t.name ) } posts now default to ${ levelLabel( v ) }` );
									} catch ( e ) {
										toast.error( e );
									}
								} }
							/>
						),
					},
				] }
				rows={ res.data ? rows : undefined }
				rowKey={ ( t ) => t.id }
				loading={ res.loading }
				error={ res.error }
				onRetry={ res.reload }
				empty={ <EmptyState icon="tag" title="No categories or tags" /> }
			/>
			<div className="wvm-a-cardfoot">
				<Icon name="info" size={ 14 } /> A post’s own level always wins over its categories. Posts without either use the default level in Paywall settings.
			</div>
		</Card>
	);
}

interface AccessSettingsData { default_level: number; teaser_paragraphs: number; paywall_message: string; mode: 'teaser' | 'redirect' | 'login'; post_types: string[] }

function AccessSettings() {
	const toast = useToast();
	const res = useApi< AccessSettingsData >( 'access/settings' );
	const [ f, setF ] = useState< AccessSettingsData | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ err, setErr ] = useState< unknown >( null );
	useEffect( () => {
		if ( res.data ) {
			setF( res.data );
		}
	}, [ res.data ] );
	if ( res.error ) {
		return <ErrorState error={ res.error } onRetry={ res.reload } />;
	}
	if ( ! f ) {
		return <Loading />;
	}
	const set = ( p: Partial< AccessSettingsData > ) => setF( { ...f, ...p } );
	const dirty = JSON.stringify( f ) !== JSON.stringify( res.data );
	return (
		<div className="wvm-a-grid-side">
			<Card title="Paywall" icon="lock">
				<FieldGrid>
					<LevelSelect label="Default level" value={ f.default_level } onChange={ ( default_level ) => set( { default_level } ) } help="Applies to content with no rule of its own." />
					<TextField label="Teaser paragraphs" type="number" min={ 0 } value={ f.teaser_paragraphs } onChange={ ( v ) => set( { teaser_paragraphs: Number( v ) || 0 } ) } error={ fieldError( err, 'teaser_paragraphs' ) } />
				</FieldGrid>
				<div className="wvm-a-field">
					<span className="wvm-a-label">When someone can’t access content</span>
					<div className="wvm-a-radiocards is-3">
						{ (
							[
								[ 'teaser', 'Show teaser', 'First paragraphs + paywall card' ],
								[ 'redirect', 'Redirect', 'Send them to the pricing page' ],
								[ 'login', 'Ask to log in', 'Login prompt, then pricing' ],
							] as const
						 ).map( ( [ k, t, s ] ) => (
							<label key={ k } className={ cx( f.mode === k && 'is-active' ) }>
								<input type="radio" checked={ f.mode === k } onChange={ () => set( { mode: k } ) } />
								<strong>{ t }</strong>
								<span>{ s }</span>
							</label>
						) ) }
					</div>
				</div>
				<TextareaField label="Paywall message" value={ f.paywall_message } onChange={ ( paywall_message ) => set( { paywall_message } ) } rows={ 3 } />
				<div className="wvm-a-field">
					<span className="wvm-a-label">Post types with the access box</span>
					<div className="wvm-a-checkgrid">
						{ config.postTypes.map( ( p ) => (
							<Checkbox key={ p.name } label={ `${ p.label } (${ p.name })` } checked={ f.post_types.includes( p.name ) } onChange={ ( on ) => set( { post_types: on ? [ ...f.post_types, p.name ] : f.post_types.filter( ( x ) => x !== p.name ) } ) } />
						) ) }
					</div>
				</div>
				<div className="wvm-a-formfoot">
					<Btn
						variant="primary"
						busy={ busy }
						disabled={ ! dirty }
						onClick={ async () => {
							setBusy( true );
							setErr( null );
							try {
								const r = await api.patch< AccessSettingsData >( 'access/settings', f );
								res.setData( () => r.data );
								toast.success( 'Access settings saved' );
							} catch ( e ) {
								setErr( e );
								toast.error( e );
							} finally {
								setBusy( false );
							}
						} }
					>
						Save settings
					</Btn>
					{ dirty && <span className="wvm-a-dirty">Unsaved changes</span> }
				</div>
			</Card>
			<Card title="Preview" icon="eye" className="wvm-a-paywallpreview">
				<div className="wvm-a-pwp">
					<div className="wvm-a-pwp__lines">
						{ Array.from( { length: Math.min( 4, f.teaser_paragraphs ) } ).map( ( _, i ) => (
							<span key={ i } style={ { width: `${ 92 - i * 9 }%` } } />
						) ) }
					</div>
					<div className="wvm-a-pwp__card">
						<Icon name="lock" size={ 18 } />
						<p>{ f.paywall_message }</p>
						<span className="wvm-a-pwp__btn">{ f.mode === 'login' ? 'Log in to continue' : 'See membership plans' }</span>
					</div>
				</div>
			</Card>
		</div>
	);
}

function CheckAccess() {
	const [ postQ, setPostQ ] = useState( '' );
	const [ post, setPost ] = useState< AccessPost | null >( null );
	const [ member, setMember ] = useState< MemberRow | null >( null );
	const posts = useApi< AccessPost[] >( post ? null : 'access/posts', { q: postQ } );
	const check = useApi< { post_id: number; user_id: number; rule: { level: number; plans: string[]; orgs: number[]; teaser: number; source: string }; entitlements: Entitlements; can_view: boolean } >( post ? 'access/check' : null, { post_id: post?.id, user_id: member?.user_id ?? 0 } );
	return (
		<div className="wvm-a-grid2">
			<Card title="Who and what" icon="search" subtitle="See exactly why someone can or can’t read a post.">
				<div className="wvm-a-field">
					<span className="wvm-a-label">Content</span>
					{ post ? (
						<div className="wvm-a-picker__chosen">
							<Icon name="lock" size={ 18 } />
							<div>
								<strong>{ decodeEntities( post.title ) }</strong>
								<span>
									{ post.post_type } · { post.level_label }
								</span>
							</div>
							<button type="button" className="wvm-a-iconbtn" aria-label="Change content" onClick={ () => setPost( null ) }>
								<Icon name="x" size={ 14 } />
							</button>
						</div>
					) : (
						<>
							<SearchInput value={ postQ } onChange={ setPostQ } placeholder="Search posts and pages" />
							<ul className="wvm-a-rowlist is-picker">
								{ ( posts.data || [] ).slice( 0, 6 ).map( ( p ) => (
									<li key={ p.id }>
										<button type="button" onClick={ () => setPost( p ) }>
											<strong>{ decodeEntities( p.title ) }</strong>
											<span>
												{ p.post_type } · { p.level_label }
											</span>
										</button>
									</li>
								) ) }
							</ul>
						</>
					) }
				</div>
				<MemberPicker label="Member (leave empty to test a logged-out visitor)" value={ member } onChange={ setMember } />
			</Card>
			<Card title="Result" icon="shield">
				{ ! post && <EmptyState icon="shield" title="Pick some content to check" /> }
				{ post && check.loading && <Loading /> }
				{ check.error && <ErrorState error={ check.error } compact /> }
				{ post && check.data && (
					<>
						<div className={ cx( 'wvm-a-verdict', check.data.can_view ? 'is-yes' : 'is-no' ) }>
							<Icon name={ check.data.can_view ? 'check' : 'lock' } size={ 22 } strokeWidth={ 2.2 } />
							<div>
								<strong>{ check.data.can_view ? 'Can read the full content' : 'Sees the paywall' }</strong>
								<span>{ member ? member.name : 'Logged-out visitor' }</span>
							</div>
						</div>
						<DescList
							items={ [
								[ 'Required level', `${ check.data.rule.level } · ${ levelLabel( check.data.rule.level ) }` ],
								[ 'Rule source', check.data.rule.source ],
								[ 'Plans allowed', check.data.rule.plans.length ? check.data.rule.plans.join( ', ' ) : 'Any at level' ],
								[ 'Teaser', `${ check.data.rule.teaser } paragraphs` ],
								[ 'Member level', `${ check.data.entitlements.level } · ${ levelLabel( check.data.entitlements.level ) }` ],
								[ 'Member plans', check.data.entitlements.plan_slugs.join( ', ' ) || '—' ],
								[ 'Organisations', check.data.entitlements.org_ids.join( ', ' ) || '—' ],
							] }
						/>
						{ ! check.data.can_view && <Notice2 tone="info">They need { levelLabel( check.data.rule.level ) } or higher{ check.data.rule.plans.length ? ` or one of: ${ check.data.rule.plans.join( ', ' ) }` : '' }.</Notice2> }
					</>
				) }
			</Card>
		</div>
	);
}

