import { createClient, ApiError } from '../../shared/api';
import type { Query } from '../../shared/api';
import { config, currencyDecimals } from './config';

export const api = createClient( { restUrl: config.restUrl, nonce: config.nonce } );
export { ApiError };
export type { Query };

export function errorMessage( e: unknown ): string {
	if ( e instanceof ApiError ) {
		const fields = Object.values( e.fields || {} );
		if ( e.code === 'wvm_validation' && fields.length ) {
			return fields.join( ' ' );
		}
		return e.message;
	}
	if ( e instanceof Error ) {
		return e.message;
	}
	return 'Something went wrong. Please try again.';
}

export function fieldError( e: unknown, field: string ): string | undefined {
	return e instanceof ApiError ? e.fields?.[ field ] : undefined;
}

/* ---------- money ---------- */

export function minorToMajorString( minor: number | null | undefined, currency: string ): string {
	if ( minor === null || minor === undefined || Number.isNaN( minor ) ) {
		return '';
	}
	const d = currencyDecimals( currency );
	return ( minor / Math.pow( 10, d ) ).toFixed( d );
}

export function majorStringToMinor( major: string, currency: string ): number | null {
	const clean = String( major ).replace( /[^0-9.\-]/g, '' );
	if ( clean === '' || clean === '-' || clean === '.' ) {
		return null;
	}
	const n = parseFloat( clean );
	if ( Number.isNaN( n ) ) {
		return null;
	}
	return Math.round( n * Math.pow( 10, currencyDecimals( currency ) ) );
}

/* ---------- dates ---------- */

const pad = ( n: number ) => String( n ).padStart( 2, '0' );

/** ISO → value for <input type="datetime-local"> in the browser's timezone. */
export function isoToLocalInput( iso: string | null | undefined ): string {
	if ( ! iso ) {
		return '';
	}
	const d = new Date( iso );
	if ( Number.isNaN( d.getTime() ) ) {
		return '';
	}
	return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }T${ pad( d.getHours() ) }:${ pad( d.getMinutes() ) }`;
}

/** <input type="datetime-local"> value (local time) → ISO UTC string. */
export function localInputToIso( v: string ): string {
	if ( ! v ) {
		return '';
	}
	const d = new Date( v );
	return Number.isNaN( d.getTime() ) ? '' : d.toISOString();
}

export function todayYmd( offsetDays = 0 ): string {
	const d = new Date();
	d.setDate( d.getDate() + offsetDays );
	return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;
}

export function ymd( d: Date ): string {
	return `${ d.getFullYear() }-${ pad( d.getMonth() + 1 ) }-${ pad( d.getDate() ) }`;
}

/** Trigger a browser download of a JSON value. */
export function downloadJson( data: unknown, filename: string ): void {
	const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
	const url = URL.createObjectURL( blob );
	const a = document.createElement( 'a' );
	a.href = url;
	a.download = filename;
	document.body.appendChild( a );
	a.click();
	a.remove();
	setTimeout( () => URL.revokeObjectURL( url ), 1000 );
}

export async function copyText( text: string ): Promise< boolean > {
	try {
		await navigator.clipboard.writeText( text );
		return true;
	} catch ( e ) {
		const ta = document.createElement( 'textarea' );
		ta.value = text;
		document.body.appendChild( ta );
		ta.select();
		const ok = document.execCommand( 'copy' );
		ta.remove();
		return ok;
	}
}
