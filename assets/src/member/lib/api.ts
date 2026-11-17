/* Shared API client instance + helpers for the member app. */
import { createClient, ApiError } from '../../shared/api';
import { config } from './config';

export const api = createClient( { restUrl: config.restUrl, nonce: config.nonce } );

export { ApiError };

const GENERIC = 'Something went wrong. Please try again.';

/** User-facing message for any thrown value. ApiError messages are already friendly. */
export function errorMessage( e: unknown, fallback = GENERIC ): string {
	if ( e instanceof ApiError ) {
		if ( e.status >= 500 && e.code === 'wvm_server_error' ) {
			return fallback;
		}
		return e.message || fallback;
	}
	return fallback;
}

/** Field errors keyed by field name (empty object when none). */
export function fieldErrors( e: unknown ): Record< string, string > {
	return e instanceof ApiError ? e.fields || {} : {};
}

/** Push an analytics event to window.dataLayer when analytics is enabled. */
export function track( event: string, data: Record< string, unknown > = {} ): void {
	if ( ! config.analytics ) {
		return;
	}
	try {
		window.dataLayer = window.dataLayer || [];
		window.dataLayer.push( { event, ...data } );
	} catch ( e ) {
		// Never let analytics break the UI.
	}
}

/** Navigate the browser (full page). */
export function go( url: string ): void {
	window.location.href = url;
}

/** Reload, optionally landing on a dashboard hash route. */
export function reloadTo( hash?: string ): void {
	if ( hash !== undefined ) {
		window.location.hash = hash;
	}
	window.location.reload();
}
