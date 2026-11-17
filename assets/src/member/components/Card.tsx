import type { ReactNode, HTMLAttributes } from 'react';
import { cx } from '../lib/util';
import { Icon, type IconName } from './Icon';
import { STATUS_LABELS, statusTone, type Tone } from '../../shared/format';

interface CardProps extends HTMLAttributes< HTMLDivElement > {
	variant?: 'default' | 'hero' | 'soft' | 'outline';
	pad?: 'sm' | 'md' | 'lg' | 'none';
	as?: 'div' | 'section' | 'article';
}

export function Card( { variant = 'default', pad = 'md', as = 'div', className, children, ...rest }: CardProps ) {
	const Tag = as;
	return (
		<Tag { ...rest } className={ cx( 'wvm-card', `wvm-card--${ variant }`, `wvm-card--pad-${ pad }`, className ) }>
			{ children }
		</Tag>
	);
}

interface CardHeaderProps {
	title: ReactNode;
	subtitle?: ReactNode;
	icon?: IconName;
	action?: ReactNode;
	eyebrow?: ReactNode;
}

export function CardHeader( { title, subtitle, icon, action, eyebrow }: CardHeaderProps ) {
	return (
		<div className="wvm-card__header">
			{ icon && (
				<span className="wvm-card__icon" aria-hidden="true">
					<Icon name={ icon } size={ 20 } />
				</span>
			) }
			<div className="wvm-card__heading">
				{ eyebrow && <div className="wvm-eyebrow">{ eyebrow }</div> }
				<h3 className="wvm-card__title">{ title }</h3>
				{ subtitle && <p className="wvm-card__subtitle">{ subtitle }</p> }
			</div>
			{ action && <div className="wvm-card__action">{ action }</div> }
		</div>
	);
}

interface ChipProps {
	tone?: Tone;
	children: ReactNode;
	icon?: IconName;
	dot?: boolean;
	className?: string;
}

export function Chip( { tone = 'neutral', children, icon, dot, className }: ChipProps ) {
	return (
		<span className={ cx( 'wvm-chip', `wvm-chip--${ tone }`, className ) }>
			{ dot && <span className="wvm-chip__dot" aria-hidden="true" /> }
			{ icon && <Icon name={ icon } size={ 14 } /> }
			{ children }
		</span>
	);
}

export function StatusChip( { status, label }: { status: string; label?: string } ) {
	return (
		<Chip tone={ statusTone( status ) } dot>
			{ label || STATUS_LABELS[ status ] || status }
		</Chip>
	);
}

/** Section title row used inside tabs. */
export function SectionTitle( { title, subtitle, action }: { title: ReactNode; subtitle?: ReactNode; action?: ReactNode } ) {
	return (
		<div className="wvm-section-title">
			<div>
				<h2 className="wvm-h2">{ title }</h2>
				{ subtitle && <p className="wvm-muted">{ subtitle }</p> }
			</div>
			{ action }
		</div>
	);
}
