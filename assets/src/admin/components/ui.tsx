/* Small presentational building blocks shared by every screen. */
import { useState } from 'react';
import type { ReactNode, MouseEvent } from 'react';
import { Button, Spinner } from '@wordpress/components';
import { STATUS_LABELS, statusTone, initials } from '../../shared/format';
import type { Tone } from '../../shared/format';
import { Icon } from './Icon';
import type { IconName } from './Icon';
import { href, navigate } from '../router';
import { copyText } from '../lib/api';
import { useToast } from './Toasts';

export function cx( ...c: ( string | number | false | null | undefined )[] ): string {
	return c.filter( Boolean ).join( ' ' );
}

/* ---------- buttons ---------- */

export interface BtnProps {
	children?: ReactNode;
	variant?: 'primary' | 'secondary' | 'tertiary' | 'link' | 'ghost';
	icon?: IconName | string;
	onClick?: ( e: MouseEvent ) => void;
	href?: string;
	to?: string;
	target?: string;
	busy?: boolean;
	disabled?: boolean;
	destructive?: boolean;
	size?: 'default' | 'compact' | 'small';
	label?: string;
	className?: string;
	type?: 'button' | 'submit';
	title?: string;
}

export function Btn( p: BtnProps ) {
	const variant = p.variant === 'ghost' ? 'tertiary' : p.variant || 'secondary';
	const link = p.to ? href( p.to ) : p.href;
	const iconOnly = ! p.children && p.icon;
	return (
		<Button
			__next40pxDefaultSize={ ! p.size || p.size === 'default' }
			size={ p.size && p.size !== 'default' ? p.size : undefined }
			variant={ variant }
			className={ cx( 'wvm-a-btn', p.variant === 'ghost' && 'is-ghost', iconOnly && 'is-icon', p.className ) }
			isDestructive={ p.destructive }
			isBusy={ p.busy }
			disabled={ p.disabled || p.busy }
			accessibleWhenDisabled={ false }
			onClick={ p.onClick as never }
			href={ link as string }
			target={ p.target }
			rel={ p.target === '_blank' ? 'noopener noreferrer' : undefined }
			label={ p.label || ( iconOnly ? p.title : undefined ) }
			showTooltip={ iconOnly ? true : undefined }
			type={ link ? undefined : ( p.type || 'button' ) }
			icon={ p.icon ? <Icon name={ p.icon } size={ p.size === 'small' ? 14 : 16 } /> : undefined }
		>
			{ p.children }
		</Button>
	);
}

/** In-app link (hash). */
export function A( { to, children, className, title }: { to: string; children: ReactNode; className?: string; title?: string } ) {
	return (
		<a
			className={ cx( 'wvm-a-link', className ) }
			href={ href( to ) }
			title={ title }
			onClick={ ( e ) => {
				if ( e.metaKey || e.ctrlKey || e.shiftKey ) {
					return;
				}
				e.preventDefault();
				navigate( to );
			} }
		>
			{ children }
		</a>
	);
}

/* ---------- chips ---------- */

export function Chip( { tone = 'neutral', children, dot, className, icon }: { tone?: Tone; children: ReactNode; dot?: boolean; className?: string; icon?: IconName } ) {
	return (
		<span className={ cx( 'wvm-a-chip', `is-${ tone }`, className ) }>
			{ dot && <span className="wvm-a-chip__dot" /> }
			{ icon && <Icon name={ icon } size={ 12 } strokeWidth={ 2.2 } /> }
			{ children }
		</span>
	);
}

export function StatusChip( { status, label }: { status: string | null | undefined; label?: string } ) {
	const s = status || 'none';
	const tone = s === 'cancelled' || s === 'expired' ? 'neutral' : statusTone( s );
	return (
		<Chip tone={ tone } dot>
			{ label || STATUS_LABELS[ s ] || s.replace( /_/g, ' ' ) }
		</Chip>
	);
}

/* ---------- avatar ---------- */

const HUES = [ 252, 280, 200, 160, 330, 20, 220 ];
export function Avatar( { name, size = 36, src }: { name: string; size?: number; src?: string } ) {
	const [ broken, setBroken ] = useState( false );
	const h = HUES[ Array.from( name || '?' ).reduce( ( a, c ) => a + c.charCodeAt( 0 ), 0 ) % HUES.length ];
	return (
		<span className="wvm-a-avatar" style={ { width: size, height: size, fontSize: Math.round( size * 0.38 ), background: `linear-gradient(135deg, hsl(${ h } 85% 92%), hsl(${ h } 70% 84%))`, color: `hsl(${ h } 55% 32%)` } } aria-hidden="true">
			{ src && ! broken ? <img src={ src } alt="" onError={ () => setBroken( true ) } /> : initials( name || '?' ) }
		</span>
	);
}

/* ---------- layout ---------- */

export function Card( { children, className, title, actions, pad = true, subtitle, icon }: { children: ReactNode; className?: string; title?: ReactNode; actions?: ReactNode; pad?: boolean; subtitle?: ReactNode; icon?: IconName } ) {
	return (
		<section className={ cx( 'wvm-a-card', ! pad && 'is-flush', className ) }>
			{ ( title || actions ) && (
				<header className="wvm-a-card__head">
					<div className="wvm-a-card__titles">
						{ title && (
							<h3 className="wvm-a-card__title">
								{ icon && <span className="wvm-a-card__icon"><Icon name={ icon } size={ 16 } /></span> }
								{ title }
							</h3>
						) }
						{ subtitle && <p className="wvm-a-card__sub">{ subtitle }</p> }
					</div>
					{ actions && <div className="wvm-a-card__actions">{ actions }</div> }
				</header>
			) }
			<div className="wvm-a-card__body">{ children }</div>
		</section>
	);
}

export interface Crumb { label: string; to?: string }

export function PageHeader( { title, subtitle, crumbs, actions, tabs, children }: { title: ReactNode; subtitle?: ReactNode; crumbs?: Crumb[]; actions?: ReactNode; tabs?: ReactNode; children?: ReactNode } ) {
	return (
		<div className="wvm-a-pagehead">
			{ crumbs && crumbs.length > 0 && (
				<nav className="wvm-a-crumbs" aria-label="Breadcrumb">
					{ crumbs.map( ( c, i ) => (
						<span key={ i } className="wvm-a-crumbs__item">
							{ c.to ? <A to={ c.to }>{ c.label }</A> : <span>{ c.label }</span> }
							{ i < crumbs.length - 1 && <Icon name="chevronRight" size={ 12 } /> }
						</span>
					) ) }
				</nav>
			) }
			<div className="wvm-a-pagehead__row">
				<div className="wvm-a-pagehead__titles">
					<h1 className="wvm-a-pagehead__title">{ title }</h1>
					{ subtitle && <p className="wvm-a-pagehead__sub">{ subtitle }</p> }
				</div>
				{ actions && <div className="wvm-a-pagehead__actions">{ actions }</div> }
			</div>
			{ children }
			{ tabs && <div className="wvm-a-pagehead__tabs">{ tabs }</div> }
		</div>
	);
}

export interface TabDef { key: string; label: ReactNode; count?: number | null; icon?: IconName; to?: string; hidden?: boolean }

/** Pill / underline tabs. With `to`, tabs are hash links. */
export function Tabs( { tabs, value, onChange, variant = 'pill', className }: { tabs: TabDef[]; value: string; onChange?: ( k: string ) => void; variant?: 'pill' | 'line' | 'segmented'; className?: string } ) {
	return (
		<div className={ cx( 'wvm-a-tabs', `is-${ variant }`, className ) } role="tablist">
			{ tabs
				.filter( ( t ) => ! t.hidden )
				.map( ( t ) => {
					const inner = (
						<>
							{ t.icon && <Icon name={ t.icon } size={ 15 } /> }
							<span>{ t.label }</span>
							{ t.count !== undefined && t.count !== null && <span className="wvm-a-tabs__count">{ t.count }</span> }
						</>
					);
					const cls = cx( 'wvm-a-tabs__tab', value === t.key && 'is-active' );
					return t.to ? (
						<a key={ t.key } role="tab" aria-selected={ value === t.key } className={ cls } href={ href( t.to ) }>
							{ inner }
						</a>
					) : (
						<button key={ t.key } type="button" role="tab" aria-selected={ value === t.key } className={ cls } onClick={ () => onChange?.( t.key ) }>
							{ inner }
						</button>
					);
				} ) }
		</div>
	);
}

export function EmptyState( { icon = 'inbox', title, children, action }: { icon?: IconName; title: ReactNode; children?: ReactNode; action?: ReactNode } ) {
	return (
		<div className="wvm-a-empty">
			<div className="wvm-a-empty__icon">
				<Icon name={ icon } size={ 22 } />
			</div>
			<h4 className="wvm-a-empty__title">{ title }</h4>
			{ children && <p className="wvm-a-empty__text">{ children }</p> }
			{ action && <div className="wvm-a-empty__action">{ action }</div> }
		</div>
	);
}

export function ErrorState( { error, onRetry, compact }: { error: { message: string; status?: number } | null | undefined; onRetry?: () => void; compact?: boolean } ) {
	if ( ! error ) {
		return null;
	}
	const forbidden = error.status === 403 || error.status === 401;
	return (
		<div className={ cx( 'wvm-a-errorstate', forbidden && 'is-forbidden', compact && 'is-compact' ) }>
			<div className="wvm-a-errorstate__icon">
				<Icon name={ forbidden ? 'lock' : 'alert' } size={ 22 } />
			</div>
			<h4>{ forbidden ? 'You don’t have access to this' : 'We couldn’t load this' }</h4>
			<p>{ forbidden ? 'Your WitsValley role doesn’t include this area. Ask the site owner if you need access.' : error.message }</p>
			{ onRetry && ! forbidden && (
				<Btn icon="refresh" onClick={ onRetry } size="compact">
					Try again
				</Btn>
			) }
		</div>
	);
}

export function Loading( { label = 'Loading…' }: { label?: string } ) {
	return (
		<div className="wvm-a-loading">
			<Spinner />
			<span>{ label }</span>
		</div>
	);
}

export function Skeleton( { w = '100%', h = 14, r = 6, className }: { w?: number | string; h?: number; r?: number; className?: string } ) {
	return <span className={ cx( 'wvm-a-skel', className ) } style={ { width: w, height: h, borderRadius: r } } />;
}

/** Definition list (label/value pairs). */
export function DescList( { items, cols = 2 }: { items: ( [ ReactNode, ReactNode ] | false | null | undefined | '' | 0 )[]; cols?: 1 | 2 | 3 } ) {
	return (
		<dl className={ `wvm-a-desc cols-${ cols }` }>
			{ items.filter( Boolean ).map( ( it, i ) => {
				const [ k, v ] = it as [ ReactNode, ReactNode ];
				return (
					<div key={ i } className="wvm-a-desc__item">
						<dt>{ k }</dt>
						<dd>{ v === null || v === undefined || v === '' ? <span className="wvm-a-muted">—</span> : v }</dd>
					</div>
				);
			} ) }
		</dl>
	);
}

export function CopyButton( { text, label = 'Copy', size = 'compact' }: { text: string; label?: string; size?: 'compact' | 'small' } ) {
	const toast = useToast();
	const [ done, setDone ] = useState( false );
	return (
		<Btn
			size={ size }
			icon={ done ? 'check' : 'copy' }
			onClick={ async () => {
				if ( await copyText( text ) ) {
					setDone( true );
					toast.success( 'Copied to clipboard' );
					setTimeout( () => setDone( false ), 1500 );
				}
			} }
		>
			{ label }
		</Btn>
	);
}

export function Progress( { value, max, tone = 'primary' }: { value: number; max: number; tone?: 'primary' | 'warning' | 'danger' } ) {
	const pct = max > 0 ? Math.min( 100, ( value / max ) * 100 ) : 0;
	return (
		<div className={ `wvm-a-progress is-${ tone }` } role="progressbar" aria-valuenow={ value } aria-valuemax={ max }>
			<span style={ { width: pct + '%' } } />
		</div>
	);
}

/** Pretty-print an action result (kit resync, replay, import counts). */
export function ResultBlock( { data }: { data: unknown } ) {
	return <pre className="wvm-a-result">{ JSON.stringify( data, null, 2 ) }</pre>;
}

export function Muted( { children }: { children: ReactNode } ) {
	return <span className="wvm-a-muted">{ children }</span>;
}

export function Mono( { children }: { children: ReactNode } ) {
	return <span className="wvm-a-mono">{ children }</span>;
}

export function Notice2( { tone = 'info', children, icon, title, actions }: { tone?: 'info' | 'warning' | 'danger' | 'success'; children?: ReactNode; icon?: IconName; title?: ReactNode; actions?: ReactNode } ) {
	return (
		<div className={ `wvm-a-notice is-${ tone }` }>
			<span className="wvm-a-notice__icon">
				<Icon name={ icon || ( tone === 'success' ? 'check' : tone === 'info' ? 'info' : 'alert' ) } size={ 16 } />
			</span>
			<div className="wvm-a-notice__body">
				{ title && <strong>{ title }</strong> }
				{ children && <div>{ children }</div> }
			</div>
			{ actions && <div className="wvm-a-notice__actions">{ actions }</div> }
		</div>
	);
}
