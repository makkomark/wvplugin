/* Admin config (window.wvmAdmin) with safe defaults + capability helpers. */
import type { AdminConfig } from '../../shared/config';
import { decimals as fallbackDecimals } from '../../shared/format';

const raw = ( window.wvmAdmin || {} ) as Partial< AdminConfig >;

export const config: AdminConfig = {
	restUrl: raw.restUrl || '/wp-json/wv-members/v1/',
	restRoot: raw.restRoot || '/wp-json/',
	nonce: raw.nonce || '',
	adminUrl: raw.adminUrl || '/wp-admin/',
	pages: raw.pages || [],
	caps: raw.caps || {},
	user: raw.user || { id: 0, name: '', email: '', avatar: '' },
	levels: raw.levels || { 0: 'Public', 1: 'Basic', 2: 'Premium', 3: 'Enterprise' },
	currencies: raw.currencies && raw.currencies.length ? raw.currencies : [ 'UGX', 'USD' ],
	allCurrencies: raw.allCurrencies || [ 'UGX', 'USD' ],
	decimals: raw.decimals || {},
	countries: raw.countries || {},
	timezones: raw.timezones || [],
	placeholders: raw.placeholders || [],
	dashboardUrl: raw.dashboardUrl || '',
	pricingUrl: raw.pricingUrl || '',
	enterpriseUrl: raw.enterpriseUrl || '',
	wooOrdersUrl: raw.wooOrdersUrl || '',
	wooGatewaysUrl: raw.wooGatewaysUrl || '',
	actionSchedulerUrl: raw.actionSchedulerUrl || '',
	gateways: raw.gateways || [],
	postTypes: raw.postTypes || [],
	branding: raw.branding || { primary: '#0B4C25', accent: '#FFAC04', font: '', heading: '' },
	// wp_localize_script stringifies top-level scalars ("1" / "").
	sandbox: [ true, 1, '1', 'true' ].includes( raw.sandbox as never ),
	siteName: raw.siteName || 'WitsValley',
	version: raw.version || '',
};

export type Cap =
	| 'wvm_access'
	| 'wvm_manage_settings'
	| 'wvm_manage_plans'
	| 'wvm_manage_plans_full'
	| 'wvm_view_members'
	| 'wvm_edit_members'
	| 'wvm_view_payments'
	| 'wvm_manage_payments'
	| 'wvm_manage_workshops'
	| 'wvm_manage_enterprise'
	| 'wvm_view_insights'
	| 'wvm_manage_content_access'
	| 'wvm_impersonate'
	| 'wvm_view_audit';

export function can( cap: Cap ): boolean {
	return config.caps[ cap ] === true;
}

export function canAny( ...caps: Cap[] ): boolean {
	return caps.some( can );
}

/** Decimal places for a currency, from the server's Money::decimals map. */
export function currencyDecimals( currency: string ): number {
	const cur = ( currency || '' ).toUpperCase();
	const d = config.decimals[ cur ];
	return typeof d === 'number' ? d : fallbackDecimals( cur );
}

export function levelEntries(): [ number, string ][] {
	return Object.entries( config.levels ).map( ( [ k, v ] ) => [ Number( k ), String( v ) ] as [ number, string ] );
}

export function levelLabel( level: number | null | undefined ): string {
	if ( level === null || level === undefined ) {
		return '—';
	}
	const l = ( config.levels as Record< string, string > )[ String( level ) ];
	return l || `Level ${ level }`;
}

export function countryName( code: string | null | undefined ): string {
	if ( ! code ) {
		return '—';
	}
	return config.countries[ code ] ? decodeEntities( config.countries[ code ] ) : code;
}

export function gatewayTitle( id: string | null | undefined ): string {
	if ( ! id ) {
		return '—';
	}
	const g = config.gateways.find( ( x ) => x.id === id );
	return g ? g.title : id;
}

const txt = typeof document !== 'undefined' ? document.createElement( 'textarea' ) : null;
export function decodeEntities( s: string ): string {
	if ( ! s || ! txt || s.indexOf( '&' ) === -1 ) {
		return s;
	}
	txt.innerHTML = s;
	return txt.value;
}
