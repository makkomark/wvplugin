/* Formatting helpers. Money is always integer minor units + ISO currency. */

const ZERO_DECIMAL = [ 'UGX', 'RWF', 'JPY', 'KRW', 'XOF', 'XAF' ];

export function decimals( currency: string ): number {
	return ZERO_DECIMAL.includes( currency.toUpperCase() ) ? 0 : 2;
}

export function formatMoney( minor: number | null | undefined, currency: string ): string {
	if ( minor === null || minor === undefined ) {
		return '—';
	}
	const cur = currency.toUpperCase();
	const d = decimals( cur );
	const major = minor / Math.pow( 10, d );
	try {
		return new Intl.NumberFormat( undefined, { style: 'currency', currency: cur, minimumFractionDigits: d, maximumFractionDigits: d, currencyDisplay: cur === 'USD' ? 'narrowSymbol' : 'code' } ).format( major );
	} catch ( e ) {
		return `${ cur } ${ major.toFixed( d ) }`;
	}
}

export function toMinor( major: string | number, currency: string ): number {
	const n = typeof major === 'number' ? major : parseFloat( String( major ).replace( /[^0-9.\-]/g, '' ) );
	if ( Number.isNaN( n ) ) {
		return 0;
	}
	return Math.round( n * Math.pow( 10, decimals( currency ) ) );
}

export function toMajor( minor: number, currency: string ): string {
	const d = decimals( currency );
	return ( minor / Math.pow( 10, d ) ).toFixed( d );
}

export function formatDate( iso: string | null | undefined, opts: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short', year: 'numeric' }, timeZone?: string ): string {
	if ( ! iso ) {
		return '—';
	}
	const d = new Date( iso );
	if ( Number.isNaN( d.getTime() ) ) {
		return '—';
	}
	try {
		return new Intl.DateTimeFormat( undefined, { ...opts, timeZone } ).format( d );
	} catch ( e ) {
		return d.toLocaleDateString();
	}
}

export function formatDateTime( iso: string | null | undefined, timeZone?: string ): string {
	return formatDate( iso, { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }, timeZone );
}

export function relativeTime( iso: string | null | undefined ): string {
	if ( ! iso ) {
		return '—';
	}
	const diff = ( new Date( iso ).getTime() - Date.now() ) / 1000;
	const abs = Math.abs( diff );
	const rtf = new Intl.RelativeTimeFormat( undefined, { numeric: 'auto' } );
	const units: [ Intl.RelativeTimeFormatUnit, number ][] = [
		[ 'year', 31536000 ],
		[ 'month', 2592000 ],
		[ 'week', 604800 ],
		[ 'day', 86400 ],
		[ 'hour', 3600 ],
		[ 'minute', 60 ],
	];
	for ( const [ unit, secs ] of units ) {
		if ( abs >= secs ) {
			return rtf.format( Math.round( diff / secs ), unit );
		}
	}
	return rtf.format( Math.round( diff ), 'second' );
}

export function intervalLabel( interval: string, count = 1 ): string {
	const map: Record< string, string > = { monthly: 'month', quarterly: 'quarter', annual: 'year', custom: 'period' };
	const unit = map[ interval ];
	if ( ! unit ) {
		return '';
	}
	return count > 1 ? `every ${ count } ${ unit }s` : `/ ${ unit }`;
}

export const STATUS_LABELS: Record< string, string > = {
	pending: 'Pending',
	active: 'Active',
	past_due: 'Past due',
	suspended: 'Suspended',
	expired: 'Expired',
	cancelled: 'Cancelled',
	cancelling: 'Cancelling',
	none: 'No plan',
	free: 'Free',
	paid: 'Paid',
	failed: 'Failed',
	refunded: 'Refunded',
	needs_review: 'Needs review',
	draft: 'Draft',
	scheduled: 'Scheduled',
	live: 'Live',
	ended: 'Ended',
	new: 'New',
	in_review: 'In review',
	quoted: 'Quoted',
	accepted: 'Accepted',
	declined: 'Declined',
	done: 'Done',
	invited: 'Invited',
	joined: 'Joined',
	revoked: 'Revoked',
	queued: 'Queued',
	sent: 'Sent',
	ok: 'OK',
	error: 'Error',
	warning: 'Warning',
	confirmed: 'Confirmed',
};

export type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'primary';

export function statusTone( status: string ): Tone {
	switch ( status ) {
		case 'active':
		case 'paid':
		case 'joined':
		case 'sent':
		case 'ok':
		case 'done':
		case 'accepted':
		case 'confirmed':
		case 'live':
			return 'success';
		case 'past_due':
		case 'pending':
		case 'cancelling':
		case 'needs_review':
		case 'warning':
		case 'invited':
		case 'queued':
		case 'in_review':
		case 'quoted':
			return 'warning';
		case 'suspended':
		case 'failed':
		case 'error':
		case 'declined':
		case 'revoked':
			return 'danger';
		case 'scheduled':
		case 'new':
		case 'free':
			return 'info';
		default:
			return 'neutral';
	}
}

export function initials( name: string ): string {
	return name
		.split( /\s+/ )
		.filter( Boolean )
		.slice( 0, 2 )
		.map( ( p ) => p[ 0 ]?.toUpperCase() )
		.join( '' ) || '?';
}
