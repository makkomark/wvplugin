/* Globals injected by wp_localize_script and CSS module declarations. */
declare module '*.css';
declare module '*.scss';

interface Window {
	wvmMember?: import( './config' ).MemberConfig;
	wvmAdmin?: import( './config' ).AdminConfig;
	dataLayer?: Record< string, unknown >[];
}
