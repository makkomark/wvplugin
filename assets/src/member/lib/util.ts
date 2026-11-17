/* Small helpers. */

export function cx( ...parts: ( string | false | null | undefined )[] ): string {
	return parts.filter( Boolean ).join( ' ' );
}

export function firstName( name: string | null | undefined ): string {
	return ( name || '' ).trim().split( /\s+/ )[ 0 ] || '';
}

/** "Mon 19 Nov, 10:00" in a time zone (falls back to browser zone). */
export function formatWhen( iso: string | null | undefined, timeZone?: string ): string {
	if ( ! iso ) {
		return '—';
	}
	const d = new Date( iso );
	try {
		return new Intl.DateTimeFormat( undefined, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: timeZone || undefined } ).format( d );
	} catch ( e ) {
		return d.toLocaleString();
	}
}

export function formatTime( iso: string | null | undefined, timeZone?: string ): string {
	if ( ! iso ) {
		return '';
	}
	try {
		return new Intl.DateTimeFormat( undefined, { hour: '2-digit', minute: '2-digit', timeZone: timeZone || undefined } ).format( new Date( iso ) );
	} catch ( e ) {
		return '';
	}
}

export function dateParts( iso: string, timeZone?: string ): { day: string; month: string; weekday: string } {
	const d = new Date( iso );
	const f = ( o: Intl.DateTimeFormatOptions ) => {
		try {
			return new Intl.DateTimeFormat( undefined, { ...o, timeZone: timeZone || undefined } ).format( d );
		} catch ( e ) {
			return new Intl.DateTimeFormat( undefined, o ).format( d );
		}
	};
	return { day: f( { day: 'numeric' } ), month: f( { month: 'short' } ), weekday: f( { weekday: 'short' } ) };
}

/** Short timezone label, e.g. "EAT" or "GMT+3". */
export function zoneLabel( timeZone?: string ): string {
	try {
		const parts = new Intl.DateTimeFormat( undefined, { timeZoneName: 'short', timeZone: timeZone || undefined } ).formatToParts( new Date() );
		return parts.find( ( p ) => p.type === 'timeZoneName' )?.value || '';
	} catch ( e ) {
		return '';
	}
}

/** Split "a@b.c, d@e.f\n..." into trimmed unique emails. */
export function parseEmails( text: string ): string[] {
	const found = text.match( /[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/gi ) || [];
	return Array.from( new Set( found.map( ( e ) => e.toLowerCase() ) ) );
}

export function describeUserAgent( ua: string ): string {
	if ( ! ua ) {
		return 'Unknown device';
	}
	const browsers: [ string, string ][] = [ [ 'Edg', 'Edge' ], [ 'OPR', 'Opera' ], [ 'Chrome', 'Chrome' ], [ 'Firefox', 'Firefox' ], [ 'Safari', 'Safari' ] ];
	const systems: [ string, string ][] = [ [ 'Android', 'Android' ], [ 'iPhone', 'iPhone' ], [ 'iPad', 'iPad' ], [ 'Windows', 'Windows' ], [ 'Mac OS', 'macOS' ], [ 'Linux', 'Linux' ] ];
	const b = browsers.find( ( [ n ] ) => ua.includes( n ) )?.[ 1 ] || 'Browser';
	const o = systems.find( ( [ n ] ) => ua.includes( n ) )?.[ 1 ] || 'Unknown OS';
	return `${ b } on ${ o }`;
}

export function isMobileUA( ua: string ): boolean {
	return /Android|iPhone|iPad|Mobile/i.test( ua );
}
