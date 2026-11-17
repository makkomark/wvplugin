/*
 * Tiny fetch client for the wv-members/v1 REST namespace.
 * Responses are { data, meta?, error? }. Errors throw ApiError with field messages.
 */
import type { ApiEnvelope, ApiErrorBody, PageMeta } from './types';

export class ApiError extends Error {
	code: string;
	status: number;
	fields: Record< string, string >;
	constructor( body: Partial< ApiErrorBody >, status: number ) {
		super( body.message || 'Something went wrong. Please try again.' );
		this.code = body.code || 'wvm_error';
		this.status = status;
		this.fields = ( body.fields as Record< string, string > ) || {};
	}
}

export interface ClientConfig { restUrl: string; nonce: string }

export type Query = Record< string, string | number | boolean | null | undefined | ( string | number )[] >;

export function buildQuery( query?: Query ): string {
	if ( ! query ) {
		return '';
	}
	const params = new URLSearchParams();
	Object.entries( query ).forEach( ( [ key, value ] ) => {
		if ( value === undefined || value === null || value === '' || value === false ) {
			return;
		}
		if ( Array.isArray( value ) ) {
			value.forEach( ( v ) => params.append( `${ key }[]`, String( v ) ) );
			return;
		}
		params.append( key, value === true ? '1' : String( value ) );
	} );
	const s = params.toString();
	return s ? `?${ s }` : '';
}

export function createClient( config: ClientConfig ) {
	let nonce = config.nonce;
	const base = config.restUrl.replace( /\/?$/, '/' );

	function url( path: string, query?: Query ): string {
		const clean = path.replace( /^\//, '' );
		// Support "plain" permalinks (?rest_route=) where restUrl already has a query string.
		const sep = base.includes( '?' ) ? '&' : '?';
		const q = buildQuery( query );
		return base + clean + ( q ? ( base.includes( '?' ) ? q.replace( '?', sep ) : q ) : '' );
	}

	async function request< T >( method: string, path: string, body?: unknown, query?: Query ): Promise< { data: T; meta?: PageMeta & Record< string, unknown > } > {
		let res: Response;
		try {
			res = await fetch( url( path, query ), {
				method,
				credentials: 'same-origin',
				headers: {
					Accept: 'application/json',
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce,
				},
				body: body === undefined ? undefined : JSON.stringify( body ),
			} );
		} catch ( e ) {
			throw new ApiError( { code: 'wvm_network', message: 'You appear to be offline. Check your connection and try again.' }, 0 );
		}
		let json: ApiEnvelope< T > | { code?: string; message?: string } | null = null;
		try {
			json = await res.json();
		} catch ( e ) {
			json = null;
		}
		if ( ! res.ok || ( json && 'error' in json && json.error ) ) {
			const errBody = json && 'error' in json && json.error ? json.error : { code: ( json as { code?: string } )?.code, message: ( json as { message?: string } )?.message };
			throw new ApiError( errBody as ApiErrorBody, res.status );
		}
		const env = json as ApiEnvelope< T >;
		const maybe = env?.data as unknown as { nonce?: string } | null;
		if ( maybe && typeof maybe === 'object' && typeof maybe.nonce === 'string' ) {
			nonce = maybe.nonce;
		}
		return { data: env?.data as T, meta: env?.meta };
	}

	return {
		get: < T >( path: string, query?: Query ) => request< T >( 'GET', path, undefined, query ),
		post: < T >( path: string, body: unknown = {} ) => request< T >( 'POST', path, body ),
		patch: < T >( path: string, body: unknown = {} ) => request< T >( 'PATCH', path, body ),
		put: < T >( path: string, body: unknown = {} ) => request< T >( 'PUT', path, body ),
		del: < T >( path: string, body?: unknown ) => request< T >( 'DELETE', path, body ),
		/** Authenticated download link (CSV, PDF, ICS): appends _wpnonce for cookie auth. */
		downloadUrl: ( path: string, query?: Query ) => url( path, { ...( query || {} ), _wpnonce: nonce } ),
		setNonce: ( n: string ) => {
			nonce = n;
		},
		getNonce: () => nonce,
	};
}

export type Client = ReturnType< typeof createClient >;
