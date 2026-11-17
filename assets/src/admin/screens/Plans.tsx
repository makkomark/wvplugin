import { useEffect, useState } from 'react';
import type { Route } from '../router';
import { navigate, replaceQuery, withQuery } from '../router';
import { useApi } from '../hooks/useApi';
import { api, fieldError } from '../lib/api';
import { can, config, levelLabel } from '../lib/config';
import type { Plan } from '../../shared/types';
import { formatMoney, intervalLabel } from '../../shared/format';
import { PageHeader, Btn, Chip, Tabs, EmptyState, ErrorState, Skeleton, Notice2, Mono, cx } from '../components/ui';
import { Icon } from '../components/Icon';
import { EntityDrawer, ConfirmModal } from '../components/overlays';
import { TextField, SelectField, TextareaField, LevelSelect, Toggle, MoneyInput, FieldGrid, Checkbox } from '../components/fields';
import { ListEditor } from '../components/editors';
import { useToast } from '../components/Toasts';

const INTERVALS = [
	{ value: 'monthly', label: 'Monthly' },
	{ value: 'quarterly', label: 'Quarterly' },
	{ value: 'annual', label: 'Annual' },
	{ value: 'one_off', label: 'One-off' },
	{ value: 'custom', label: 'Custom' },
	{ value: 'contract', label: 'Contract (quoted)' },
	{ value: 'none', label: 'None (free)' },
];

function priceList( p: Plan ): [ string, number ][] {
	return Array.isArray( p.prices ) ? [] : Object.entries( p.prices );
}

export function Plans( { route }: { route: Route } ) {
	const toast = useToast();
	const res = useApi< Plan[] >( 'plans', { include_archived: 1 } );
	const [ order, setOrder ] = useState< number[] >( [] );
	const [ drag, setDrag ] = useState< number | null >( null );
	const [ over, setOver ] = useState< number | null >( null );
	const [ editing, setEditing ] = useState< Plan | 'new' | null >( null );
	const full = can( 'wvm_manage_plans_full' );

	const plans = res.data || [];
	const active = plans.filter( ( p ) => ! p.archived_at );
	const archived = plans.filter( ( p ) => p.archived_at );
	useEffect( () => {
		setOrder( active.slice().sort( ( a, b ) => a.sort_order - b.sort_order ).map( ( p ) => p.id ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ res.data ] );
	useEffect( () => {
		if ( route.query.new === '1' && full ) {
			setEditing( 'new' );
		} else if ( route.query.plan && res.data ) {
			const p = res.data.find( ( x ) => String( x.id ) === route.query.plan );
			if ( p ) {
				setEditing( p );
			}
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ route.query.new, route.query.plan, !! res.data ] );

	const byId = new Map( plans.map( ( p ) => [ p.id, p ] ) );
	const ordered = order.map( ( id ) => byId.get( id ) ).filter( Boolean ) as Plan[];

	const drop = async ( targetId: number ) => {
		if ( drag === null || drag === targetId ) {
			return;
		}
		const next = order.filter( ( id ) => id !== drag );
		next.splice( next.indexOf( targetId ) + ( order.indexOf( drag ) < order.indexOf( targetId ) ? 1 : 0 ), 0, drag );
		const prev = order;
		setOrder( next );
		try {
			await api.post( 'plans/reorder', { ids: [ ...next, ...archived.map( ( p ) => p.id ) ] } );
			toast.success( 'Plan order saved — the pricing page uses this order' );
		} catch ( e ) {
			setOrder( prev );
			toast.error( e );
		}
	};

	const closeEditor = () => {
		setEditing( null );
		if ( route.query.new || route.query.plan ) {
			replaceQuery( {} );
		}
	};

	return (
		<div>
			<PageHeader
				title="Plans"
				subtitle={ full ? 'Drag cards to reorder how plans appear on the pricing page.' : 'You can edit plan descriptions, benefits and access levels.' }
				actions={
					<>
						<Btn icon="external" href={ config.pricingUrl } target="_blank">
							View pricing page
						</Btn>
						{ full && (
							<Btn variant="primary" icon="plus" onClick={ () => setEditing( 'new' ) }>
								New plan
							</Btn>
						) }
					</>
				}
			/>
			{ res.error && ! res.data && <ErrorState error={ res.error } onRetry={ res.reload } /> }
			{ ! res.data && ! res.error && (
				<div className="wvm-a-plangrid">
					{ [ 0, 1, 2, 3 ].map( ( i ) => (
						<Skeleton key={ i } h={ 300 } r={ 18 } />
					) ) }
				</div>
			) }
			{ res.data && (
				<>
					<div className="wvm-a-plangrid">
						{ ordered.map( ( p ) => (
							<PlanCard
								key={ p.id }
								plan={ p }
								draggable={ full }
								dragging={ drag === p.id }
								over={ over === p.id && drag !== p.id }
								onDragStart={ () => setDrag( p.id ) }
								onDragEnd={ () => {
									setDrag( null );
									setOver( null );
								} }
								onDragOver={ () => setOver( p.id ) }
								onDrop={ () => drop( p.id ) }
								onOpen={ () => setEditing( p ) }
							/>
						) ) }
						{ full && (
							<button type="button" className="wvm-a-plancard is-new" onClick={ () => setEditing( 'new' ) }>
								<span>
									<Icon name="plus" size={ 22 } />
								</span>
								<strong>New plan</strong>
								<em>Monthly, annual, one-off or quoted</em>
							</button>
						) }
					</div>
					{ archived.length > 0 && (
						<>
							<div className="wvm-a-section-title">
								<h2>Archived</h2>
								<span className="wvm-a-muted">Hidden from pricing — existing members keep their plan</span>
							</div>
							<div className="wvm-a-plangrid is-archived">
								{ archived.map( ( p ) => (
									<PlanCard key={ p.id } plan={ p } onOpen={ () => setEditing( p ) } />
								) ) }
							</div>
						</>
					) }
				</>
			) }
			<PlanEditor
				plan={ editing }
				onClose={ closeEditor }
				onSaved={ ( p ) => {
					res.reload();
					if ( p ) {
						setEditing( p );
					}
				} }
			/>
		</div>
	);
}

function PlanCard( p: { plan: Plan; draggable?: boolean; dragging?: boolean; over?: boolean; onDragStart?: () => void; onDragEnd?: () => void; onDragOver?: () => void; onDrop?: () => void; onOpen: () => void } ) {
	const { plan } = p;
	const prices = priceList( plan );
	return (
		<article
			className={ cx( 'wvm-a-plancard', plan.highlight && 'is-highlight', p.dragging && 'is-dragging', p.over && 'is-over', plan.archived_at && 'is-archived' ) }
			draggable={ p.draggable }
			onDragStart={ ( e ) => {
				e.dataTransfer.effectAllowed = 'move';
				e.dataTransfer.setData( 'text/plain', String( plan.id ) );
				p.onDragStart?.();
			} }
			onDragEnd={ p.onDragEnd }
			onDragOver={ ( e ) => {
				e.preventDefault();
				p.onDragOver?.();
			} }
			onDrop={ ( e ) => {
				e.preventDefault();
				p.onDrop?.();
			} }
			onClick={ p.onOpen }
			tabIndex={ 0 }
			onKeyDown={ ( e ) => e.key === 'Enter' && p.onOpen() }
		>
			<header>
				{ p.draggable && (
					<span className="wvm-a-plancard__grip" title="Drag to reorder">
						<Icon name="grip" size={ 16 } />
					</span>
				) }
				<div className="wvm-a-plancard__chips">
					{ plan.is_free && <Chip tone="info">Free</Chip> }
					{ plan.is_quoted && <Chip tone="primary">Quoted</Chip> }
					{ plan.highlight && (
						<Chip tone="warning" icon="star">
							Highlighted
						</Chip>
					) }
					{ plan.visibility !== 'public' && <Chip tone="neutral">{ plan.visibility === 'hidden' ? 'Hidden' : 'Invite only' }</Chip> }
					{ plan.archived_at && <Chip tone="neutral">Archived</Chip> }
				</div>
			</header>
			<h3>{ plan.name }</h3>
			<Mono>{ plan.slug }</Mono>
			<div className="wvm-a-plancard__price">
				{ plan.is_free ? (
					<strong>Free</strong>
				) : plan.is_quoted ? (
					<strong>Quoted</strong>
				) : prices.length ? (
					<>
						<strong>{ formatMoney( prices[ 0 ][ 1 ], prices[ 0 ][ 0 ] ) }</strong>
						<span>{ intervalLabel( plan.interval, plan.interval_count ) }</span>
						{ prices.length > 1 && <em>{ prices.slice( 1 ).map( ( [ c, a ] ) => formatMoney( a, c ) ).join( ' · ' ) }</em> }
					</>
				) : (
					<span className="wvm-a-warntext">
						<Icon name="alert" size={ 14 } /> No prices set
					</span>
				) }
			</div>
			<p className="wvm-a-plancard__desc">{ plan.description }</p>
			<ul className="wvm-a-plancard__benefits">
				{ plan.benefits.slice( 0, 4 ).map( ( b, i ) => (
					<li key={ i }>
						<Icon name="check" size={ 13 } strokeWidth={ 2.4 } /> { b }
					</li>
				) ) }
				{ plan.benefits.length > 4 && <li className="is-more">+{ plan.benefits.length - 4 } more</li> }
			</ul>
			<footer>
				<span>
					<Icon name="users" size={ 14 } /> <strong>{ plan.member_count ?? 0 }</strong> member{ plan.member_count === 1 ? '' : 's' }
				</span>
				<span>
					<Icon name="lock" size={ 14 } /> L{ plan.level } · { levelLabel( plan.level ) }
				</span>
			</footer>
		</article>
	);
}

interface PlanForm {
	name: string;
	slug: string;
	description: string;
	level: number;
	interval: string;
	interval_count: number;
	trial_days: number;
	kit_tag: string;
	visibility: 'public' | 'hidden' | 'invite';
	highlight: boolean;
	is_quoted: boolean;
	is_free: boolean;
	benefits: string[];
}

function toForm( p: Plan | null ): PlanForm {
	return p
		? { name: p.name, slug: p.slug, description: p.description, level: p.level, interval: p.interval, interval_count: p.interval_count, trial_days: p.trial_days, kit_tag: p.kit_tag, visibility: p.visibility, highlight: p.highlight, is_quoted: p.is_quoted, is_free: p.is_free, benefits: p.benefits.slice() }
		: { name: '', slug: '', description: '', level: 1, interval: 'monthly', interval_count: 1, trial_days: 0, kit_tag: '', visibility: 'public', highlight: false, is_quoted: false, is_free: false, benefits: [] };
}

function PlanEditor( { plan, onClose, onSaved }: { plan: Plan | 'new' | null; onClose: () => void; onSaved: ( p?: Plan ) => void } ) {
	const toast = useToast();
	const isNew = plan === 'new';
	const existing = plan && plan !== 'new' ? plan : null;
	const full = can( 'wvm_manage_plans_full' );
	const [ tab, setTab ] = useState( 'general' );
	const [ f, setF ] = useState< PlanForm >( toForm( existing ) );
	const [ newPrices, setNewPrices ] = useState< Record< string, number | null > >( {} );
	const [ err, setErr ] = useState< unknown >( null );
	const [ busy, setBusy ] = useState( false );
	const [ priceNotice, setPriceNotice ] = useState( false );
	const [ confirmArchive, setConfirmArchive ] = useState( false );
	const [ slugTouched, setSlugTouched ] = useState( false );

	useEffect( () => {
		setF( toForm( existing ) );
		setErr( null );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ plan === 'new' ? 'new' : existing?.id, existing?.updated_at ] );

	useEffect( () => {
		setTab( 'general' );
		setPriceNotice( false );
		setSlugTouched( false );
		setNewPrices( isNew ? Object.fromEntries( config.currencies.map( ( c ) => [ c, null ] ) ) : {} );
	}, [ plan === 'new' ? 'new' : existing?.id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const set = ( patch: Partial< PlanForm > ) => setF( { ...f, ...patch } );
	const locked = ! full; // Content role: benefits/level/description only.

	const save = async () => {
		setBusy( true );
		setErr( null );
		try {
			if ( isNew ) {
				const prices = Object.entries( newPrices )
					.filter( ( [ , v ] ) => v !== null && v !== undefined )
					.map( ( [ currency, amount_minor ] ) => ( { currency, amount_minor } ) );
				const r = await api.post< Plan >( 'plans', { ...f, kit_tag: f.kit_tag || undefined, prices: f.is_free ? [] : prices } );
				toast.success( `Plan “${ r.data.name }” created` );
				onSaved( r.data );
			} else if ( existing ) {
				const body = locked ? { description: f.description, level: f.level, benefits: f.benefits } : { ...f, slug: undefined, is_free: undefined };
				const r = await api.patch< Plan & { price_changed?: boolean } >( `plans/${ existing.id }`, body );
				toast.success( 'Plan saved' );
				if ( r.data.price_changed ) {
					setPriceNotice( true );
				}
				onSaved( r.data );
			}
		} catch ( e ) {
			setErr( e );
			toast.error( e );
			const fields = Object.keys( ( e as { fields?: Record< string, string > } ).fields || {} );
			if ( fields.some( ( k ) => [ 'name', 'slug', 'level', 'interval', 'interval_count', 'trial_days', 'kit_tag' ].includes( k ) ) ) {
				setTab( 'general' );
			}
		} finally {
			setBusy( false );
		}
	};

	const footer = (
		<>
			<Btn variant="primary" busy={ busy } onClick={ save } disabled={ ! f.name.trim() || ( isNew && ! f.slug.trim() ) }>
				{ isNew ? 'Create plan' : 'Save changes' }
			</Btn>
			{ existing && full && ! existing.archived_at && (
				<Btn variant="ghost" destructive icon="archive" onClick={ () => setConfirmArchive( true ) }>
					Archive
				</Btn>
			) }
			{ existing && full && existing.archived_at && (
				<Btn
					icon="restore"
					onClick={ async () => {
						try {
							const r = await api.post< Plan >( `plans/${ existing.id }/restore` );
							toast.success( 'Plan restored' );
							onSaved( r.data );
						} catch ( e ) {
							toast.error( e );
						}
					} }
				>
					Restore
				</Btn>
			) }
			<span className="wvm-a-spacer" />
			{ existing?.woo_product_url && (
				<Btn variant="ghost" icon="external" href={ existing.woo_product_url } target="_blank">
					Woo product
				</Btn>
			) }
		</>
	);

	return (
		<>
			<EntityDrawer
				open={ !! plan }
				onClose={ onClose }
				title={ isNew ? 'New plan' : existing?.name || 'Plan' }
				subtitle={ existing ? `${ existing.member_count ?? 0 } members · ${ existing.slug }` : 'Create a plan — it appears on the pricing page when public.' }
				badges={ existing?.archived_at ? <Chip tone="neutral">Archived</Chip> : undefined }
				footer={ footer }
				width={ 640 }
			>
				{ locked && (
					<Notice2 tone="info" title="Limited editing">
						Your role can change the description, benefits and access level. Prices, slug and visibility are managed by the owner.
					</Notice2>
				) }
				{ priceNotice && existing && <PriceNotice plan={ existing } /> }
				<Tabs
					variant="segmented"
					value={ tab }
					onChange={ setTab }
					tabs={ [
						{ key: 'general', label: 'General' },
						{ key: 'prices', label: 'Prices', count: existing ? priceList( existing ).length : Object.values( newPrices ).filter( ( v ) => v !== null ).length },
						{ key: 'benefits', label: 'Benefits', count: f.benefits.length },
						{ key: 'visibility', label: 'Visibility' },
					] }
				/>
				<div className="wvm-a-drawer__section">
					{ tab === 'general' && (
						<>
							<FieldGrid>
								<TextField
									label="Name"
									value={ f.name }
									disabled={ locked }
									onChange={ ( name ) => {
										const patch: Partial< PlanForm > = { name };
										if ( isNew && ! slugTouched ) {
											patch.slug = name.toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' ).slice( 0, 50 );
										}
										set( patch );
									} }
									error={ fieldError( err, 'name' ) }
								/>
								<TextField
									label="Slug"
									value={ f.slug }
									disabled={ ! isNew }
									onChange={ ( slug ) => {
										setSlugTouched( true );
										set( { slug } );
									} }
									help={ isNew ? 'Lowercase, used in URLs and Kit. Can’t be changed later.' : 'The slug is permanent.' }
									error={ fieldError( err, 'slug' ) }
								/>
							</FieldGrid>
							<TextareaField label="Description" value={ f.description } onChange={ ( description ) => set( { description } ) } rows={ 3 } error={ fieldError( err, 'description' ) } />
							<FieldGrid>
								<LevelSelect value={ f.level } onChange={ ( level ) => set( { level } ) } error={ fieldError( err, 'level' ) } help="Content at or below this level unlocks." />
								<TextField label="Kit tag" value={ f.kit_tag } disabled={ locked } onChange={ ( kit_tag ) => set( { kit_tag: kit_tag.toUpperCase() } ) } placeholder={ f.slug ? `WV-${ f.slug.toUpperCase() }` : 'WV-…' } error={ fieldError( err, 'kit_tag' ) } />
								<SelectField label="Billing interval" value={ f.interval } disabled={ locked || f.is_free } onChange={ ( interval ) => set( { interval } ) } options={ INTERVALS } error={ fieldError( err, 'interval' ) } />
								<TextField label="Every" type="number" min={ 1 } value={ f.interval_count } disabled={ locked || f.is_free } onChange={ ( v ) => set( { interval_count: Number( v ) || 1 } ) } help={ `${ f.interval_count } × ${ f.interval }` } error={ fieldError( err, 'interval_count' ) } />
								<TextField label="Trial days" type="number" min={ 0 } value={ f.trial_days } disabled={ locked } onChange={ ( v ) => set( { trial_days: Number( v ) || 0 } ) } error={ fieldError( err, 'trial_days' ) } />
							</FieldGrid>
							{ isNew && <Checkbox label="This is the free plan" checked={ f.is_free } onChange={ ( is_free ) => set( { is_free, interval: is_free ? 'none' : 'monthly', level: is_free ? 0 : f.level } ) } help="There can only be one free plan. Free plans have no prices or Woo product." /> }
						</>
					) }
					{ tab === 'prices' && ( isNew ? <NewPrices form={ f } prices={ newPrices } setPrices={ setNewPrices } /> : existing && <PriceRows plan={ existing } locked={ locked } onChanged={ ( p ) => { setPriceNotice( true ); onSaved( p ); } } /> ) }
					{ tab === 'benefits' && (
						<>
							<p className="wvm-a-help">Shown as a checklist on the pricing page and the member dashboard. Drag to reorder.</p>
							<ListEditor value={ f.benefits } onChange={ ( benefits ) => set( { benefits } ) } placeholder="e.g. Monthly live workshop" />
						</>
					) }
					{ tab === 'visibility' && (
						<>
							<div className="wvm-a-radiocards is-3">
								{ (
									[
										[ 'public', 'Public', 'On the pricing page' ],
										[ 'hidden', 'Hidden', 'Only via direct link or admin' ],
										[ 'invite', 'Invite only', 'Assigned by admins or orgs' ],
									] as const
								 ).map( ( [ k, t, s ] ) => (
									<label key={ k } className={ cx( f.visibility === k && 'is-active', locked && 'is-disabled' ) }>
										<input type="radio" disabled={ locked } checked={ f.visibility === k } onChange={ () => set( { visibility: k } ) } />
										<strong>{ t }</strong>
										<span>{ s }</span>
									</label>
								) ) }
							</div>
							<Toggle label="Highlight on pricing page" checked={ f.highlight } disabled={ locked } onChange={ ( highlight ) => set( { highlight } ) } help="Adds a “Most popular” badge and accent border." />
							<Toggle label="Quoted plan" checked={ f.is_quoted } disabled={ locked } onChange={ ( is_quoted ) => set( { is_quoted } ) } help="Shows “Talk to us” instead of a price and links to the Enterprise application form." />
						</>
					) }
				</div>
			</EntityDrawer>
			{ existing && (
				<ConfirmModal
					open={ confirmArchive }
					title={ `Archive “${ existing.name }”?` }
					destructive
					confirmLabel="Archive plan"
					onClose={ () => setConfirmArchive( false ) }
					onConfirm={ async () => {
						const r = await api.del< Plan >( `plans/${ existing.id }` );
						setConfirmArchive( false );
						toast.success( 'Plan archived' );
						onSaved( r.data );
					} }
				>
					<p>
						The plan disappears from the pricing page and its Woo product is made private. The <strong>{ existing.member_count ?? 0 } existing members</strong> keep their plan and renew normally. You can restore it any time.
					</p>
				</ConfirmModal>
			) }
		</>
	);
}

function PriceNotice( { plan }: { plan: Plan } ) {
	return (
		<Notice2
			tone="warning"
			icon="info"
			title="Price changes apply to new orders"
			actions={
				<Btn size="compact" icon="users" onClick={ () => navigate( withQuery( '/members', { plan: plan.slug, status: 'active' } ) ) }>
					View { plan.member_count ?? '' } members
				</Btn>
			}
		>
			Existing members keep their price until renewal — notify them?
		</Notice2>
	);
}

function PriceRows( { plan, locked, onChanged }: { plan: Plan; locked: boolean; onChanged: ( p: Plan ) => void } ) {
	const toast = useToast();
	const current = Array.isArray( plan.prices ) ? {} : ( plan.prices as Record< string, number > );
	const [ drafts, setDrafts ] = useState< Record< string, number | null > >( current );
	const [ busy, setBusy ] = useState< string | null >( null );
	const [ addCur, setAddCur ] = useState( '' );
	useEffect( () => setDrafts( Array.isArray( plan.prices ) ? {} : { ...( plan.prices as Record< string, number > ) } ), [ plan.updated_at, plan.prices ] );
	if ( plan.is_free ) {
		return <EmptyState icon="layers" title="Free plans have no prices" />;
	}
	const currencies = Object.keys( drafts );
	const missing = config.allCurrencies.filter( ( c ) => ! currencies.includes( c ) );
	const savePrice = async ( cur: string ) => {
		const v = drafts[ cur ];
		if ( v === null || v === undefined || v < 0 ) {
			toast.error( 'Enter an amount first' );
			return;
		}
		setBusy( cur );
		try {
			const r = await api.put< Plan >( `plans/${ plan.id }/prices/${ cur }`, { amount_minor: v } );
			toast.success( `${ cur } price saved` );
			onChanged( r.data );
		} catch ( e ) {
			toast.error( e );
		} finally {
			setBusy( null );
		}
	};
	const removePrice = async ( cur: string ) => {
		if ( current[ cur ] === undefined ) {
			const next = { ...drafts };
			delete next[ cur ];
			setDrafts( next );
			return;
		}
		setBusy( cur );
		try {
			const r = await api.del< Plan >( `plans/${ plan.id }/prices/${ cur }` );
			toast.success( `${ cur } price removed` );
			onChanged( r.data );
		} catch ( e ) {
			toast.error( e );
		} finally {
			setBusy( null );
		}
	};
	return (
		<div className="wvm-a-prices">
			{ plan.is_quoted && <Notice2 tone="info">This plan is quoted — prices are optional and only used for invoices you create.</Notice2> }
			{ currencies.length === 0 && <p className="wvm-a-muted">No prices yet. Add a currency below.</p> }
			{ currencies.map( ( cur ) => {
				const dirty = drafts[ cur ] !== current[ cur ];
				return (
					<div key={ cur } className={ cx( 'wvm-a-pricerow', dirty && 'is-dirty' ) }>
						<MoneyInput label={ `${ cur } price${ config.currencies.includes( cur ) ? '' : ' (currency not enabled at checkout)' }` } currency={ cur } value={ drafts[ cur ] } onChange={ ( v ) => setDrafts( { ...drafts, [ cur ]: v } ) } disabled={ locked } help={ current[ cur ] !== undefined ? `Saved: ${ formatMoney( current[ cur ], cur ) } ${ intervalLabel( plan.interval, plan.interval_count ) }` : 'Not saved yet' } />
						{ ! locked && (
							<div className="wvm-a-pricerow__btns">
								<Btn size="compact" variant={ dirty ? 'primary' : 'secondary' } busy={ busy === cur } disabled={ ! dirty } onClick={ () => savePrice( cur ) }>
									Save
								</Btn>
								<Btn size="compact" variant="ghost" icon="trash" title={ `Remove ${ cur } price` } onClick={ () => removePrice( cur ) } />
							</div>
						) }
					</div>
				);
			} ) }
			{ ! locked && missing.length > 0 && (
				<div className="wvm-a-addcur">
					<select value={ addCur } onChange={ ( e ) => setAddCur( e.target.value ) } aria-label="Add currency">
						<option value="">Add a currency…</option>
						{ missing.map( ( c ) => (
							<option key={ c } value={ c }>
								{ c }
								{ config.currencies.includes( c ) ? '' : ' (not enabled)' }
							</option>
						) ) }
					</select>
					<Btn
						size="compact"
						icon="plus"
						disabled={ ! addCur }
						onClick={ () => {
							setDrafts( { ...drafts, [ addCur ]: null } );
							setAddCur( '' );
						} }
					>
						Add
					</Btn>
				</div>
			) }
		</div>
	);
}

function NewPrices( { form, prices, setPrices }: { form: PlanForm; prices: Record< string, number | null >; setPrices: ( p: Record< string, number | null > ) => void } ) {
	if ( form.is_free ) {
		return <EmptyState icon="layers" title="Free plans have no prices" />;
	}
	return (
		<div className="wvm-a-prices">
			<p className="wvm-a-help">Set a price for each currency you sell in. Leave a currency empty to hide the plan for it.</p>
			{ Object.keys( prices ).map( ( cur ) => (
				<div key={ cur } className="wvm-a-pricerow">
					<MoneyInput label={ `${ cur } price` } currency={ cur } value={ prices[ cur ] } onChange={ ( v ) => setPrices( { ...prices, [ cur ]: v } ) } help={ intervalLabel( form.interval, form.interval_count ) } />
				</div>
			) ) }
			<div className="wvm-a-addcur">
				<select
					value=""
					onChange={ ( e ) => e.target.value && setPrices( { ...prices, [ e.target.value ]: null } ) }
					aria-label="Add currency"
				>
					<option value="">Add a currency…</option>
					{ config.allCurrencies
						.filter( ( c ) => ! ( c in prices ) )
						.map( ( c ) => (
							<option key={ c } value={ c }>
								{ c }
							</option>
						) ) }
				</select>
			</div>
		</div>
	);
}
