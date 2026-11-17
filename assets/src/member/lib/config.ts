/* Member app config (window.wvmMember, localized by src/Frontend/Frontend.php) with safe fallbacks. */
import type { MemberConfig } from '../../shared/config';

const fallback: MemberConfig = {
	restUrl: '/wp-json/wv-members/v1/',
	nonce: '',
	isLoggedIn: false,
	user: null,
	siteName: 'WitsValley',
	homeUrl: '/',
	dashboardUrl: '/dashboard/',
	pricingUrl: '/membership/',
	enterpriseUrl: '/enterprise/',
	loginUrl: '/wp-login.php',
	logoutUrl: '/wp-login.php?action=logout',
	lostPasswordUrl: '/wp-login.php?action=lostpassword',
	adminUrl: null,
	termsUrl: '',
	privacyUrl: '',
	supportEmail: '',
	currencies: [ 'UGX' ],
	defaultCurrency: {},
	sandbox: false,
	analytics: false,
	countries: {},
	timezones: [],
	logoUrl: '',
	version: '0',
};

export const config: MemberConfig = { ...fallback, ...( window.wvmMember || {} ) };
if ( ! config.currencies || config.currencies.length === 0 ) {
	config.currencies = [ 'UGX' ];
}

export function countryName( code: string | null | undefined ): string {
	if ( ! code ) {
		return '';
	}
	return config.countries[ code ] || code;
}

/** Countries as sorted [code, name] pairs for selects. */
export function countryOptions(): { value: string; label: string }[] {
	return Object.entries( config.countries )
		.map( ( [ value, label ] ) => ( { value, label: decodeEntities( label ) } ) )
		.sort( ( a, b ) => a.label.localeCompare( b.label ) );
}

export function decodeEntities( s: string ): string {
	if ( ! s || s.indexOf( '&' ) === -1 ) {
		return s;
	}
	const el = document.createElement( 'textarea' );
	el.innerHTML = s;
	return el.value;
}
