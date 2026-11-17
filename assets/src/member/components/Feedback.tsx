/* Banner, Skeleton, EmptyState, ProgressRing, Spinner-free loading pieces. */
import type { ReactNode } from 'react';
import { Icon, type IconName } from './Icon';
import { cx } from '../lib/util';
import type { Tone } from '../../shared/format';

const TONE_ICON: Record< Tone, IconName > = {
	success: 'check',
	warning: 'alert',
	danger: 'alert',
	info: 'info',
	neutral: 'info',
	primary: 'sparkles',
};

interface BannerProps {
	tone?: Tone;
	title?: ReactNode;
	children?: ReactNode;
	actions?: ReactNode;
	icon?: IconName;
	onDismiss?: () => void;
	className?: string;
}

export function Banner( { tone = 'info', title, children, actions, icon, onDismiss, className }: BannerProps ) {
	return (
		<div className={ cx( 'wvm-banner', `wvm-banner--${ tone }`, className ) } role={ tone === 'danger' || tone === 'warning' ? 'alert' : 'status' }>
			<span className="wvm-banner__icon" aria-hidden="true">
				<Icon name={ icon || TONE_ICON[ tone ] } size={ 18 } strokeWidth={ 2.4 } />
			</span>
			<div className="wvm-banner__body">
				{ title && <p className="wvm-banner__title">{ title }</p> }
				{ children && <div className="wvm-banner__text">{ children }</div> }
			</div>
			{ actions && <div className="wvm-banner__actions">{ actions }</div> }
			{ onDismiss && (
				<button type="button" className="wvm-banner__close" onClick={ onDismiss } aria-label="Dismiss">
					<Icon name="x" size={ 16 } />
				</button>
			) }
		</div>
	);
}

export function Skeleton( { w, h = 14, r, className }: { w?: number | string; h?: number | string; r?: number | string; className?: string } ) {
	return <span className={ cx( 'wvm-skel', className ) } style={ { width: w ?? '100%', height: h, borderRadius: r } } aria-hidden="true" />;
}

/** A card-shaped skeleton block with a few lines. */
export function SkeletonCard( { lines = 3, hero, height }: { lines?: number; hero?: boolean; height?: number } ) {
	return (
		<div className={ cx( 'wvm-card', 'wvm-card--pad-md', 'wvm-skel-card', hero && 'wvm-skel-card--hero' ) } style={ height ? { minHeight: height } : undefined } aria-hidden="true">
			<Skeleton w="40%" h={ 12 } />
			<Skeleton w="65%" h={ 26 } className="wvm-mt-3" />
			{ Array.from( { length: lines } ).map( ( _, i ) => (
				<Skeleton key={ i } w={ `${ 90 - i * 15 }%` } h={ 12 } className="wvm-mt-3" />
			) ) }
		</div>
	);
}

export function Loading( { label = 'Loading…', cards = 2 }: { label?: string; cards?: number } ) {
	return (
		<div className="wvm-grid" aria-busy="true" aria-live="polite">
			<span className="wvm-sr">{ label }</span>
			{ Array.from( { length: cards } ).map( ( _, i ) => (
				<SkeletonCard key={ i } />
			) ) }
		</div>
	);
}

interface EmptyProps {
	icon?: IconName;
	title: ReactNode;
	children?: ReactNode;
	action?: ReactNode;
	compact?: boolean;
}

export function EmptyState( { icon = 'sparkles', title, children, action, compact }: EmptyProps ) {
	return (
		<div className={ cx( 'wvm-empty', compact && 'wvm-empty--compact' ) }>
			<span className="wvm-empty__icon" aria-hidden="true">
				<Icon name={ icon } size={ compact ? 20 : 24 } />
			</span>
			<p className="wvm-empty__title">{ title }</p>
			{ children && <div className="wvm-empty__text">{ children }</div> }
			{ action && <div className="wvm-empty__action">{ action }</div> }
		</div>
	);
}

export function ErrorState( { message, onRetry }: { message: string; onRetry?: () => void } ) {
	return (
		<div className="wvm-card wvm-card--pad-md">
			<EmptyState icon="alert" title="We couldn't load this" action={ onRetry && <button type="button" className="wvm-btn wvm-btn--secondary wvm-btn--sm" onClick={ onRetry }><Icon name="refresh" size={ 16 } /><span className="wvm-btn__label">Try again</span></button> }>
				{ message }
			</EmptyState>
		</div>
	);
}

interface RingProps {
	value: number; // 0..1
	size?: number;
	stroke?: number;
	children?: ReactNode;
	tone?: 'primary' | 'warning' | 'danger' | 'success' | 'light';
	label?: string;
}

export function ProgressRing( { value, size = 96, stroke = 9, children, tone = 'primary', label }: RingProps ) {
	const r = ( size - stroke ) / 2;
	const c = 2 * Math.PI * r;
	const v = Math.max( 0, Math.min( 1, value ) );
	return (
		<div className={ cx( 'wvm-ring', `wvm-ring--${ tone }` ) } style={ { width: size, height: size } } role="img" aria-label={ label }>
			<svg width={ size } height={ size } viewBox={ `0 0 ${ size } ${ size }` } aria-hidden="true">
				<circle className="wvm-ring__track" cx={ size / 2 } cy={ size / 2 } r={ r } strokeWidth={ stroke } fill="none" />
				<circle
					className="wvm-ring__bar"
					cx={ size / 2 }
					cy={ size / 2 }
					r={ r }
					strokeWidth={ stroke }
					fill="none"
					strokeLinecap="round"
					strokeDasharray={ c }
					strokeDashoffset={ c * ( 1 - v ) }
					transform={ `rotate(-90 ${ size / 2 } ${ size / 2 })` }
				/>
			</svg>
			<div className="wvm-ring__center">{ children }</div>
		</div>
	);
}

export function ProgressBar( { value, tone = 'primary' }: { value: number; tone?: string } ) {
	return (
		<div className={ cx( 'wvm-bar', `wvm-bar--${ tone }` ) } aria-hidden="true">
			<span style={ { width: `${ Math.max( 0, Math.min( 1, value ) ) * 100 }%` } } />
		</div>
	);
}
