/* Shapes of the config objects localized by src/Frontend/Frontend.php and src/Admin/Menu.php. */

export interface MemberConfig {
	restUrl: string;
	nonce: string;
	isLoggedIn: boolean;
	user: { id: number; name: string; email: string; isStaff: boolean } | null;
	siteName: string;
	homeUrl: string;
	dashboardUrl: string;
	pricingUrl: string;
	enterpriseUrl: string;
	loginUrl: string;
	logoutUrl: string;
	lostPasswordUrl: string;
	adminUrl: string | null;
	termsUrl: string;
	privacyUrl: string;
	supportEmail: string;
	currencies: string[];
	defaultCurrency: Record< string, string >;
	sandbox: boolean;
	analytics: boolean;
	countries: Record< string, string >;
	timezones: string[];
	logoUrl: string;
	version: string;
}

export interface AdminPage { slug: string; title: string; route: string; url: string; allowed: boolean }

export interface AdminConfig {
	restUrl: string;
	restRoot: string;
	nonce: string;
	adminUrl: string;
	pages: AdminPage[];
	caps: Record< string, boolean >;
	user: { id: number; name: string; email: string; avatar: string };
	levels: Record< string, string >;
	currencies: string[];
	allCurrencies: string[];
	decimals: Record< string, number >;
	countries: Record< string, string >;
	timezones: string[];
	placeholders: string[];
	dashboardUrl: string;
	pricingUrl: string;
	enterpriseUrl: string;
	wooOrdersUrl: string;
	wooGatewaysUrl: string;
	actionSchedulerUrl: string;
	gateways: { id: string; title: string; enabled: boolean }[];
	postTypes: { name: string; label: string }[];
	sandbox: boolean;
	siteName: string;
	version: string;
}
