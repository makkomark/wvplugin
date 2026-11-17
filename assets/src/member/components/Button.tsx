import type { ButtonHTMLAttributes, AnchorHTMLAttributes, ReactNode } from 'react';
import { Icon, type IconName } from './Icon';
import { cx } from '../lib/util';

export type ButtonVariant = 'primary' | 'secondary' | 'ghost' | 'danger' | 'light' | 'link';
export type ButtonSize = 'sm' | 'md' | 'lg';

interface Common {
	variant?: ButtonVariant;
	size?: ButtonSize;
	loading?: boolean;
	icon?: IconName;
	iconRight?: IconName;
	block?: boolean;
	children?: ReactNode;
}

type ButtonProps = Common & ButtonHTMLAttributes< HTMLButtonElement > & { href?: undefined };
type LinkProps = Common & AnchorHTMLAttributes< HTMLAnchorElement > & { href: string };

function classes( { variant = 'primary', size = 'md', block, loading }: Common, extra?: string ) {
	return cx( 'wvm-btn', `wvm-btn--${ variant }`, `wvm-btn--${ size }`, block && 'wvm-btn--block', loading && 'is-loading', extra );
}

function Inner( { icon, iconRight, loading, children, size }: Common ) {
	const s = size === 'sm' ? 16 : 18;
	return (
		<>
			{ loading && <span className="wvm-btn__spinner" aria-hidden="true" /> }
			{ ! loading && icon && <Icon name={ icon } size={ s } /> }
			{ children !== undefined && children !== null && <span className="wvm-btn__label">{ children }</span> }
			{ ! loading && iconRight && <Icon name={ iconRight } size={ s } /> }
		</>
	);
}

export function Button( props: ButtonProps | LinkProps ) {
	const { variant, size, loading, icon, iconRight, block, children, className, ...rest } = props;
	const common: Common = { variant, size, loading, icon, iconRight, block, children };
	if ( typeof ( props as LinkProps ).href === 'string' ) {
		const a = rest as AnchorHTMLAttributes< HTMLAnchorElement >;
		return (
			<a { ...a } className={ classes( common, className ) } aria-busy={ loading || undefined }>
				<Inner { ...common } />
			</a>
		);
	}
	const b = rest as ButtonHTMLAttributes< HTMLButtonElement >;
	return (
		<button type="button" { ...b } className={ classes( common, className ) } disabled={ b.disabled || loading } aria-busy={ loading || undefined }>
			<Inner { ...common } />
		</button>
	);
}

interface IconButtonProps extends ButtonHTMLAttributes< HTMLButtonElement > {
	icon: IconName;
	label: string;
	badge?: number;
}

export function IconButton( { icon, label, badge, className, ...rest }: IconButtonProps ) {
	return (
		<button type="button" { ...rest } className={ cx( 'wvm-iconbtn', className ) } aria-label={ label } title={ label }>
			<Icon name={ icon } size={ 20 } />
			{ !! badge && badge > 0 && <span className="wvm-iconbtn__badge" aria-hidden="true">{ badge > 9 ? '9+' : badge }</span> }
		</button>
	);
}
