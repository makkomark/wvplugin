import { config } from '../lib/config';
import { decodeEntities } from '../lib/config';

/** Site logo, or a gradient monogram when no logo is configured. */
export function Brand( { compact }: { compact?: boolean } ) {
	const name = decodeEntities( config.siteName || 'WitsValley' );
	return (
		<a className="wvm-brand" href={ config.homeUrl } aria-label={ `${ name } home` }>
			{ config.logoUrl ? (
				<img className="wvm-brand__logo" src={ config.logoUrl } alt="" />
			) : (
				<span className="wvm-brand__mark" aria-hidden="true">{ name.charAt( 0 ).toUpperCase() }</span>
			) }
			{ ! compact && <span className="wvm-brand__name">{ name }</span> }
		</a>
	);
}

/** Split "UGX 20,000" → { prefix: "UGX", amount: "20,000", suffix: "" } for big-number display. */
export function splitMoney( display: string | null | undefined ): { prefix: string; amount: string; suffix: string } {
	if ( ! display ) {
		return { prefix: '', amount: '—', suffix: '' };
	}
	const m = display.match( /^([^\d\-]*?)\s*(-?[\d][\d.,\s]*)(.*)$/ );
	if ( ! m ) {
		return { prefix: '', amount: display, suffix: '' };
	}
	return { prefix: m[ 1 ].trim(), amount: m[ 2 ].trim(), suffix: m[ 3 ].trim() };
}

export function Money( { display, size = 'lg' }: { display: string | null | undefined; size?: 'md' | 'lg' | 'xl' } ) {
	const p = splitMoney( display );
	return (
		<span className={ `wvm-money wvm-money--${ size }` }>
			{ p.prefix && <span className="wvm-money__cur">{ p.prefix }</span> }
			<span className="wvm-money__amt">{ p.amount }</span>
			{ p.suffix && <span className="wvm-money__cur">{ p.suffix }</span> }
		</span>
	);
}
