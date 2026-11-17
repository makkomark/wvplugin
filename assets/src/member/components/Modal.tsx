/*
 * Modal / bottom sheet. Centered dialog on desktop, bottom sheet under 640px.
 * Rendered in a portal inside a themed host so tokens + scoped styles apply; focus trap + Esc + scroll lock.
 */
import { createPortal, useEffect, useRef, useId } from '@wordpress/element';
import type { ReactNode } from 'react';
import { Icon } from './Icon';
import { cx } from '../lib/util';

interface ModalProps {
	open: boolean;
	onClose: () => void;
	title: ReactNode;
	description?: ReactNode;
	children: ReactNode;
	footer?: ReactNode;
	size?: 'sm' | 'md' | 'lg';
	/** Prevent closing via backdrop/Esc (e.g. while submitting). */
	locked?: boolean;
	icon?: ReactNode;
}

let openCount = 0;
let host: HTMLElement | null = null;

function getHost(): HTMLElement {
	if ( host && document.body.contains( host ) ) {
		return host;
	}
	host = document.createElement( 'div' );
	host.className = 'wvm-root wvm-theme wvm-theme--auto wvm-portal';
	document.body.appendChild( host );
	return host;
}

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

export function Modal( { open, onClose, title, description, children, footer, size = 'md', locked, icon }: ModalProps ) {
	const panel = useRef< HTMLDivElement >( null );
	const restore = useRef< HTMLElement | null >( null );
	const titleId = useId();
	const descId = useId();
	const closeRef = useRef( onClose );
	closeRef.current = onClose;
	const lockedRef = useRef( locked );
	lockedRef.current = locked;

	useEffect( () => {
		if ( ! open ) {
			return;
		}
		restore.current = document.activeElement as HTMLElement | null;
		openCount++;
		document.documentElement.classList.add( 'wvm-scroll-lock' );
		const t = window.setTimeout( () => {
			const el = panel.current;
			if ( ! el ) {
				return;
			}
			const first = el.querySelector< HTMLElement >( '[data-autofocus]' ) || el.querySelector< HTMLElement >( 'input:not([type="hidden"]):not([tabindex="-1"]), select, textarea' ) || el;
			first.focus( { preventScroll: true } );
		}, 40 );
		const onKey = ( e: KeyboardEvent ) => {
			if ( e.key === 'Escape' && ! lockedRef.current ) {
				e.stopPropagation();
				closeRef.current();
				return;
			}
			if ( e.key === 'Tab' && panel.current ) {
				const nodes = Array.from( panel.current.querySelectorAll< HTMLElement >( FOCUSABLE ) ).filter( ( n ) => n.offsetParent !== null || n === document.activeElement );
				if ( nodes.length === 0 ) {
					e.preventDefault();
					return;
				}
				const first = nodes[ 0 ];
				const last = nodes[ nodes.length - 1 ];
				if ( e.shiftKey && ( document.activeElement === first || document.activeElement === panel.current ) ) {
					e.preventDefault();
					last.focus();
				} else if ( ! e.shiftKey && document.activeElement === last ) {
					e.preventDefault();
					first.focus();
				}
			}
		};
		document.addEventListener( 'keydown', onKey, true );
		return () => {
			window.clearTimeout( t );
			document.removeEventListener( 'keydown', onKey, true );
			openCount = Math.max( 0, openCount - 1 );
			if ( openCount === 0 ) {
				document.documentElement.classList.remove( 'wvm-scroll-lock' );
			}
			restore.current?.focus?.( { preventScroll: true } );
		};
	}, [ open ] );

	if ( ! open ) {
		return null;
	}

	return createPortal(
		<div className="wvm-modal" role="presentation">
			<div className="wvm-modal__backdrop" onClick={ () => ! locked && onClose() } aria-hidden="true" />
			<div
				ref={ panel }
				className={ cx( 'wvm-modal__panel', `wvm-modal__panel--${ size }` ) }
				role="dialog"
				aria-modal="true"
				aria-labelledby={ titleId }
				aria-describedby={ description ? descId : undefined }
				tabIndex={ -1 }
			>
				<div className="wvm-modal__grip" aria-hidden="true" />
				<div className="wvm-modal__head">
					{ icon && <div className="wvm-modal__icon">{ icon }</div> }
					<div className="wvm-modal__titles">
						<h2 id={ titleId } className="wvm-modal__title">{ title }</h2>
						{ description && <p id={ descId } className="wvm-modal__desc">{ description }</p> }
					</div>
					<button type="button" className="wvm-modal__close" onClick={ onClose } aria-label="Close" disabled={ locked }>
						<Icon name="x" size={ 20 } />
					</button>
				</div>
				<div className="wvm-modal__body">{ children }</div>
				{ footer && <div className="wvm-modal__foot">{ footer }</div> }
			</div>
		</div>,
		getHost()
	);
}
