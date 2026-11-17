/* Slide-over drawer + modal dialogs (form, reason-required, confirm). */
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { Modal } from '@wordpress/components';
import { Icon } from './Icon';
import { Btn, cx, Notice2 } from './ui';
import { TextareaField, TextField } from './fields';
import { errorMessage, fieldError } from '../lib/api';

/* ---------- drawer ---------- */

export function EntityDrawer( {
	open,
	onClose,
	title,
	subtitle,
	badges,
	children,
	footer,
	width = 560,
	headerActions,
}: {
	open: boolean;
	onClose: () => void;
	title: ReactNode;
	subtitle?: ReactNode;
	badges?: ReactNode;
	children: ReactNode;
	footer?: ReactNode;
	width?: number;
	headerActions?: ReactNode;
} ) {
	const [ shown, setShown ] = useState( false );
	const panel = useRef< HTMLDivElement >( null );
	useEffect( () => {
		if ( ! open ) {
			setShown( false );
			return;
		}
		const raf = requestAnimationFrame( () => setShown( true ) );
		const onKey = ( e: KeyboardEvent ) => {
			// Let WP modals opened above the drawer handle Escape first.
			if ( e.key === 'Escape' && ! document.querySelector( '.components-modal__screen-overlay' ) ) {
				onClose();
			}
		};
		document.addEventListener( 'keydown', onKey );
		document.body.classList.add( 'wvm-a-noscroll' );
		setTimeout( () => panel.current?.focus(), 50 );
		return () => {
			cancelAnimationFrame( raf );
			document.removeEventListener( 'keydown', onKey );
			document.body.classList.remove( 'wvm-a-noscroll' );
		};
	}, [ open, onClose ] );
	if ( ! open ) {
		return null;
	}
	return createPortal(
		<div className={ cx( 'wvm-a-drawer', shown && 'is-shown' ) } role="dialog" aria-modal="true">
			<div className="wvm-a-drawer__backdrop" onClick={ onClose } />
			<div className="wvm-a-drawer__panel" style={ { width: `min(${ width }px, 100vw)` } } ref={ panel } tabIndex={ -1 }>
				<header className="wvm-a-drawer__head">
					<div className="wvm-a-drawer__titles">
						<h2>{ title }</h2>
						{ ( subtitle || badges ) && (
							<div className="wvm-a-drawer__sub">
								{ badges }
								{ subtitle && <span>{ subtitle }</span> }
							</div>
						) }
					</div>
					<div className="wvm-a-drawer__headactions">
						{ headerActions }
						<button type="button" className="wvm-a-drawer__close" onClick={ onClose } aria-label="Close">
							<Icon name="x" size={ 18 } />
						</button>
					</div>
				</header>
				<div className="wvm-a-drawer__body">{ children }</div>
				{ footer && <footer className="wvm-a-drawer__foot">{ footer }</footer> }
			</div>
		</div>,
		document.body
	);
}

/* ---------- modals ---------- */

export interface FormModalProps {
	open: boolean;
	title: string;
	onClose: () => void;
	onSubmit: () => Promise< unknown > | unknown;
	submitLabel?: string;
	destructive?: boolean;
	children?: ReactNode;
	description?: ReactNode;
	size?: 'small' | 'medium' | 'large';
	canSubmit?: boolean;
	/** Externally supplied error (e.g. from validation). */
	error?: unknown;
	icon?: string;
	hideCancel?: boolean;
}

export function FormModal( p: FormModalProps ) {
	const [ busy, setBusy ] = useState( false );
	const [ err, setErr ] = useState< unknown >( null );
	useEffect( () => {
		if ( p.open ) {
			setErr( null );
			setBusy( false );
		}
	}, [ p.open ] );
	if ( ! p.open ) {
		return null;
	}
	const submit = async () => {
		setBusy( true );
		setErr( null );
		try {
			await p.onSubmit();
		} catch ( e ) {
			setErr( e );
		} finally {
			setBusy( false );
		}
	};
	const shownErr = err || p.error;
	return (
		<Modal title={ p.title } onRequestClose={ () => ! busy && p.onClose() } className={ cx( 'wvm-a-modal', p.destructive && 'is-destructive' ) } overlayClassName="wvm-a-modal-overlay" size={ p.size || 'medium' }>
			<form
				onSubmit={ ( e ) => {
					e.preventDefault();
					if ( p.canSubmit !== false ) {
						submit();
					}
				} }
			>
				{ p.description && <div className="wvm-a-modal__desc">{ p.description }</div> }
				<div className="wvm-a-modal__body">{ p.children }</div>
				{ !! shownErr && (
					<Notice2 tone="danger" title="Couldn’t complete that">
						{ errorMessage( shownErr ) }
					</Notice2>
				) }
				<div className="wvm-a-modal__foot">
					{ ! p.hideCancel && (
						<Btn variant="tertiary" onClick={ p.onClose } disabled={ busy }>
							Cancel
						</Btn>
					) }
					<Btn variant="primary" type="submit" busy={ busy } disabled={ p.canSubmit === false } destructive={ p.destructive }>
						{ p.submitLabel || 'Save' }
					</Btn>
				</div>
			</form>
		</Modal>
	);
}

/** Sensitive action that requires a written reason (and optionally a typed confirmation). */
export function ReasonModal( {
	open,
	title,
	onClose,
	onSubmit,
	submitLabel = 'Confirm',
	destructive,
	description,
	children,
	reasonLabel = 'Reason',
	reasonPlaceholder = 'Why are you doing this? This is saved in the audit log.',
	confirmText,
	reasonOptional,
	canSubmit = true,
}: {
	open: boolean;
	title: string;
	onClose: () => void;
	onSubmit: ( reason: string ) => Promise< unknown > | unknown;
	submitLabel?: string;
	destructive?: boolean;
	description?: ReactNode;
	children?: ReactNode;
	reasonLabel?: string;
	reasonPlaceholder?: string;
	confirmText?: string;
	reasonOptional?: boolean;
	canSubmit?: boolean;
} ) {
	const [ reason, setReason ] = useState( '' );
	const [ typed, setTyped ] = useState( '' );
	const [ err, setErr ] = useState< unknown >( null );
	useEffect( () => {
		if ( open ) {
			setReason( '' );
			setTyped( '' );
			setErr( null );
		}
	}, [ open ] );
	const ok = ( reasonOptional || reason.trim().length > 0 ) && ( ! confirmText || typed.trim() === confirmText ) && canSubmit;
	return (
		<FormModal
			open={ open }
			title={ title }
			onClose={ onClose }
			destructive={ destructive }
			submitLabel={ submitLabel }
			description={ description }
			canSubmit={ ok }
			onSubmit={ async () => {
				setErr( null );
				try {
					await onSubmit( reason.trim() );
				} catch ( e ) {
					setErr( e );
					throw e;
				}
			} }
		>
			{ children }
			<TextareaField label={ reasonLabel + ( reasonOptional ? ' (optional)' : '' ) } value={ reason } onChange={ setReason } rows={ 3 } placeholder={ reasonPlaceholder } error={ fieldError( err, 'reason' ) } />
			{ confirmText && (
				<TextField
					label={
						<>
							Type <code>{ confirmText }</code> to confirm
						</>
					}
					value={ typed }
					onChange={ setTyped }
				/>
			) }
		</FormModal>
	);
}

export function ConfirmModal( { open, title, children, onClose, onConfirm, confirmLabel = 'Confirm', destructive }: { open: boolean; title: string; children?: ReactNode; onClose: () => void; onConfirm: () => Promise< unknown > | unknown; confirmLabel?: string; destructive?: boolean } ) {
	return (
		<FormModal open={ open } title={ title } onClose={ onClose } onSubmit={ onConfirm } submitLabel={ confirmLabel } destructive={ destructive } size="small">
			{ children }
		</FormModal>
	);
}
