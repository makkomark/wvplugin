/* Shared subscription actions (pay-now / resume) with busy state + toasts. */
import { useState } from '@wordpress/element';
import type { Subscription } from '../../../shared/types';
import { api, errorMessage, go } from '../../lib/api';
import { useToast } from '../../components/Toast';
import { useDashboard } from './context';

export function usePayNow() {
	const toast = useToast();
	const [ busy, setBusy ] = useState( false );
	const pay = async () => {
		setBusy( true );
		try {
			const { data } = await api.post< { redirect: string; checkout_url?: string } >( 'me/subscription/pay-now' );
			go( data.redirect || data.checkout_url || '' );
		} catch ( e ) {
			toast.error( errorMessage( e ) );
			setBusy( false );
		}
	};
	return { pay, busy };
}

export function useResume() {
	const toast = useToast();
	const { reload } = useDashboard();
	const [ busy, setBusy ] = useState( false );
	const resume = async () => {
		setBusy( true );
		try {
			await api.post< Subscription >( 'me/subscription/resume' );
			await reload();
			toast.success( 'Welcome back — your membership will renew as normal.' );
		} catch ( e ) {
			toast.error( errorMessage( e ) );
		} finally {
			setBusy( false );
		}
	};
	return { resume, busy };
}
