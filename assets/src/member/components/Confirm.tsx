import { useState } from '@wordpress/element';
import type { ReactNode } from 'react';
import { Modal } from './Modal';
import { Button } from './Button';
import { Banner } from './Feedback';
import { errorMessage } from '../lib/api';

interface ConfirmProps {
	open: boolean;
	onClose: () => void;
	title: ReactNode;
	children?: ReactNode;
	confirmLabel: string;
	tone?: 'primary' | 'danger';
	onConfirm: () => Promise< unknown > | void;
}

/** Confirmation dialog that runs an async action and shows its error inline. */
export function Confirm( { open, onClose, title, children, confirmLabel, tone = 'primary', onConfirm }: ConfirmProps ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const run = async () => {
		setBusy( true );
		setError( null );
		try {
			await onConfirm();
			onClose();
		} catch ( e ) {
			setError( errorMessage( e ) );
		} finally {
			setBusy( false );
		}
	};

	return (
		<Modal
			open={ open }
			onClose={ () => ! busy && onClose() }
			title={ title }
			size="sm"
			locked={ busy }
			footer={
				<>
					<Button variant="ghost" onClick={ onClose } disabled={ busy }>Cancel</Button>
					<Button variant={ tone } onClick={ run } loading={ busy } data-autofocus>
						{ confirmLabel }
					</Button>
				</>
			}
		>
			{ children && <div className="wvm-prose">{ children }</div> }
			{ error && <Banner tone="danger" className="wvm-mt-4">{ error }</Banner> }
		</Modal>
	);
}
