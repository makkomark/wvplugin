import { useState } from 'react';
import type { Route } from '../../router';
import { href } from '../../router';
import { api, fieldError } from '../../lib/api';
import { config, decodeEntities } from '../../lib/config';
import { PageHeader, Btn, Notice2, Chip, A, cx } from '../../components/ui';
import { Icon } from '../../components/Icon';
import type { IconName } from '../../components/Icon';
import { TextField, SelectField, TextareaField, Toggle, FieldGrid, countryOptions } from '../../components/fields';
import { MapEditor, TokenInput } from '../../components/editors';
import { useToast } from '../../components/Toasts';
import { useSettingsGroup, SettingsFrame, Section } from './shared';
import { EmailsSettings } from './Emails';
import { NotificationsLog } from './Notifications';
import { KitSettings } from './Kit';
import { ToolsSettings } from './Tools';
import { BrandingSettings } from './Branding';

const GROUPS: { key: string; label: string; icon: IconName; desc: string }[] = [
	{ key: 'general', label: 'General', icon: 'building', desc: 'Business details, receipts, pages' },
	{ key: 'payments', label: 'Payments', icon: 'card', desc: 'Sandbox, currencies, gateways' },
	{ key: 'lifecycle', label: 'Lifecycle', icon: 'refresh', desc: 'Reminders, grace, expiry' },
	{ key: 'branding', label: 'Branding', icon: 'sparkles', desc: 'Brand colours & fonts' },
	{ key: 'emails', label: 'Emails', icon: 'mail', desc: 'Sender, provider, templates' },
	{ key: 'notifications', label: 'Email log', icon: 'inbox', desc: 'Sent & failed emails' },
	{ key: 'kit', label: 'Kit', icon: 'tag', desc: 'API key, tag map, sync' },
	{ key: 'access', label: 'Content access', icon: 'lock', desc: 'Paywall defaults' },
	{ key: 'tools', label: 'Tools', icon: 'wand', desc: 'Export, import, health, backups' },
];

export function Settings( { group, route }: { group: string; route: Route } ) {
	const g = GROUPS.find( ( x ) => x.key === group ) ? group : 'general';
	return (
		<div>
			<PageHeader title="Settings" subtitle="Owner-only configuration for WitsValley Members" />
			<div className="wvm-a-settings">
				<nav className="wvm-a-settings__nav" aria-label="Settings sections">
					{ GROUPS.map( ( x ) => (
						<a key={ x.key } href={ href( `/settings/${ x.key }` ) } className={ cx( g === x.key && 'is-active' ) }>
							<span className="wvm-a-settings__navicon">
								<Icon name={ x.icon } size={ 16 } />
							</span>
							<span>
								<strong>{ x.label }</strong>
								<em>{ x.desc }</em>
							</span>
						</a>
					) ) }
				</nav>
				<div className="wvm-a-settings__content">
					{ g === 'general' && <General /> }
					{ g === 'payments' && <Payments /> }
					{ g === 'lifecycle' && <Lifecycle /> }
					{ g === 'branding' && <BrandingSettings /> }
					{ g === 'emails' && <EmailsSettings route={ route } /> }
					{ g === 'notifications' && <NotificationsLog /> }
					{ g === 'kit' && <KitSettings /> }
					{ g === 'access' && <AccessLink /> }
					{ g === 'tools' && <ToolsSettings /> }
				</div>
			</div>
		</div>
	);
}

/* ---------------- general ---------------- */

interface GeneralValues extends Record< string, unknown > {
	business_name: string;
	address: string;
	support_email: string;
	receipt_prefix: string;
	receipt_next_number: number;
	dashboard_page_id: number;
	pricing_page_id: number;
	enterprise_page_id: number;
	terms_url: string;
	privacy_url: string;
	country_detection: { provider: string };
	analytics: boolean;
	pricing_eyebrow: string;
	pricing_title: string;
	pricing_subtitle: string;
	pricing_intro: string;
	pricing_closing: string;
}

function General() {
	const toast = useToast();
	const s = useSettingsGroup< GeneralValues >( 'general' );
	const [ creating, setCreating ] = useState( false );
	const v = s.values;
	const page = ( label: string, key: 'dashboard_page_id' | 'pricing_page_id' | 'enterprise_page_id', url: string ) =>
		v && (
			<div className="wvm-a-pagefield">
				<TextField label={ label } type="number" min={ 0 } value={ v[ key ] || '' } onChange={ ( n ) => s.set( { [ key ]: Number( n ) || 0 } as Partial< GeneralValues > ) } error={ fieldError( s.error, key ) } />
				<div className="wvm-a-pagefield__links">
					{ v[ key ] ? (
						<>
							<a href={ url } target="_blank" rel="noopener noreferrer">
								<Icon name="external" size={ 12 } /> View
							</a>
							<a href={ `${ config.adminUrl }post.php?post=${ v[ key ] }&action=edit` }>
								<Icon name="edit" size={ 12 } /> Edit
							</a>
						</>
					) : (
						<Chip tone="warning">Not set</Chip>
					) }
				</div>
			</div>
		);
	return (
		<SettingsFrame title="General" description="How your business appears on receipts, emails and the member dashboard." state={ s }>
			{ v && (
				<>
					<Section title="Business" description="Printed on receipts and in email footers.">
						<FieldGrid>
							<TextField label="Business name" value={ v.business_name } onChange={ ( business_name ) => s.set( { business_name } ) } />
							<TextField label="Support email" type="email" value={ v.support_email } onChange={ ( support_email ) => s.set( { support_email } ) } />
						</FieldGrid>
						<TextareaField label="Address" value={ v.address } onChange={ ( address ) => s.set( { address } ) } rows={ 2 } />
					</Section>
					<Section title="Pricing page header" description="The text above the plan cards on the Membership page. Leave a line empty to hide it.">
						<TextField label="Eyebrow label" value={ v.pricing_eyebrow } onChange={ ( pricing_eyebrow ) => s.set( { pricing_eyebrow } ) } />
						<TextField label="Headline" value={ v.pricing_title } onChange={ ( pricing_title ) => s.set( { pricing_title } ) } />
						<TextField label="Subheadline" value={ v.pricing_subtitle } onChange={ ( pricing_subtitle ) => s.set( { pricing_subtitle } ) } />
						<TextareaField label="Introduction" value={ v.pricing_intro } onChange={ ( pricing_intro ) => s.set( { pricing_intro } ) } rows={ 3 } />
						<TextField label="Closing line" value={ v.pricing_closing } onChange={ ( pricing_closing ) => s.set( { pricing_closing } ) } help="Shown in the brand colour, e.g. “Your journey to transformation begins here.”" />
					</Section>
					<Section title="Receipts" description="Receipt numbers are sequential and never reused.">
						<FieldGrid>
							<TextField label="Prefix" value={ v.receipt_prefix } onChange={ ( receipt_prefix ) => s.set( { receipt_prefix } ) } />
							<TextField label="Next number" type="number" min={ 1 } value={ v.receipt_next_number } onChange={ ( n ) => s.set( { receipt_next_number: Number( n ) || 1 } ) } help={ `Next receipt: ${ v.receipt_prefix }${ v.receipt_next_number }` } />
						</FieldGrid>
					</Section>
					<Section
						title="Pages"
						description="The pages that host the dashboard, pricing table and enterprise form."
					>
						<FieldGrid cols={ 3 }>
							{ page( 'Dashboard page ID', 'dashboard_page_id', config.dashboardUrl ) }
							{ page( 'Pricing page ID', 'pricing_page_id', config.pricingUrl ) }
							{ page( 'Enterprise page ID', 'enterprise_page_id', config.enterpriseUrl ) }
						</FieldGrid>
						<div>
							<Btn
								icon="refresh"
								busy={ creating }
								onClick={ async () => {
									setCreating( true );
									try {
										const r = await api.post< GeneralValues >( 'tools/create-pages' );
										s.res.setData( () => r.data );
										toast.success( 'Plugin pages checked and recreated where missing' );
									} catch ( e ) {
										toast.error( e );
									} finally {
										setCreating( false );
									}
								} }
							>
								Recreate pages
							</Btn>
						</div>
						<FieldGrid>
							<TextField label="Terms URL" value={ v.terms_url } onChange={ ( terms_url ) => s.set( { terms_url } ) } placeholder="https://…" />
							<TextField label="Privacy URL" value={ v.privacy_url } onChange={ ( privacy_url ) => s.set( { privacy_url } ) } placeholder="https://…" />
						</FieldGrid>
					</Section>
					<Section title="Visitors" description="Country detection picks the currency and gateways shown at signup.">
						<SelectField
							label="Country detection"
							value={ v.country_detection?.provider || 'woocommerce' }
							onChange={ ( provider ) => s.set( { country_detection: { ...( v.country_detection || {} ), provider } } ) }
							options={ [
								{ value: 'woocommerce', label: 'WooCommerce geolocation (MaxMind)' },
								{ value: 'ipapi', label: 'ipapi.co lookup' },
								{ value: 'cloudflare', label: 'Cloudflare CF-IPCountry header' },
							] }
							help="The Cloudflare header is always used when present."
						/>
						<Toggle label="Analytics events" checked={ !! v.analytics } onChange={ ( analytics ) => s.set( { analytics } ) } help="Push signup and payment events to the dataLayer for GA/GTM." />
					</Section>
				</>
			) }
		</SettingsFrame>
	);
}

/* ---------------- payments ---------------- */

interface PaymentValues extends Record< string, unknown > {
	gateways_by_country: Record< string, string[] >;
	currencies_enabled: string[];
	default_currency_by_country: Record< string, string >;
	sandbox_mode: boolean;
	checkout_fields: string[];
	transaction_meta_keys: Record< string, string[] >;
	gateway_currencies: Record< string, string[] >;
}

function Payments() {
	const s = useSettingsGroup< PaymentValues >( 'payments' );
	const v = s.values;
	const gwSuggest = config.gateways.map( ( g ) => ( { value: g.id, label: g.title } ) );
	const countryHint = (
		<>
			Use ISO country codes (UG, KE…) and <code>default</code> for everyone else.
		</>
	);
	return (
		<SettingsFrame
			title="Payments"
			description="Gateways themselves are configured in WooCommerce; here you choose who sees which."
			state={ s }
			actions={
				<Btn icon="external" href={ config.wooGatewaysUrl }>
					Woo gateway settings
				</Btn>
			}
		>
			{ v && (
				<>
					<Section title="Mode">
						<div className={ cx( 'wvm-a-sandboxcard', v.sandbox_mode && 'is-on' ) }>
							<Toggle label="Sandbox mode" checked={ v.sandbox_mode } onChange={ ( sandbox_mode ) => s.set( { sandbox_mode } ) } help="Enables the WitsValley Test gateway and forces gateways into test mode where supported." />
							{ v.sandbox_mode ? (
								<Notice2 tone="warning" title="Sandbox is ON">
									Real payments are not processed. Turn this off before launch.
								</Notice2>
							) : (
								<Notice2 tone="success" title="Live">
									Payments are processed for real. Switching to sandbox affects every checkout immediately.
								</Notice2>
							) }
						</div>
					</Section>
					<Section title="Currencies" description="Currencies members can pay in. Each plan needs a price per currency.">
						<div className="wvm-a-checkpills">
							{ config.allCurrencies.map( ( c ) => {
								const on = v.currencies_enabled.includes( c );
								return (
									<button key={ c } type="button" className={ cx( on && 'is-on' ) } onClick={ () => s.set( { currencies_enabled: on ? v.currencies_enabled.filter( ( x ) => x !== c ) : [ ...v.currencies_enabled, c ] } ) }>
										{ on && <Icon name="check" size={ 12 } strokeWidth={ 2.6 } /> }
										{ c }
									</button>
								);
							} ) }
						</div>
						<div className="wvm-a-field">
							<span className="wvm-a-label">Default currency by country</span>
							<MapEditor< string >
								value={ v.default_currency_by_country }
								onChange={ ( default_currency_by_country ) => s.set( { default_currency_by_country } ) }
								keyLabel="Country"
								valueLabel="Currency"
								keyHint={ countryHint }
								newValue={ () => v.currencies_enabled[ 0 ] || 'USD' }
								renderValue={ ( val, set ) => (
									<select className="wvm-a-input" value={ val } onChange={ ( e ) => set( e.target.value ) }>
										{ config.allCurrencies.map( ( c ) => (
											<option key={ c } value={ c }>
												{ c }
											</option>
										) ) }
									</select>
								) }
							/>
						</div>
					</Section>
					<Section title="Gateways by country" description="Which payment methods appear at checkout, in order, based on the billing country.">
						<MapEditor< string[] >
							value={ v.gateways_by_country }
							onChange={ ( gateways_by_country ) => s.set( { gateways_by_country } ) }
							keyLabel="Country"
							valueLabel="Gateway IDs"
							keyHint={ countryHint }
							newValue={ () => [] }
							renderValue={ ( val, set ) => <TokenInput value={ val } onChange={ ( x ) => set( x as string[] ) } suggestions={ gwSuggest } placeholder="gateway id…" /> }
						/>
						<p className="wvm-a-help">
							Installed: { config.gateways.map( ( g ) => (
								<span key={ g.id } className="wvm-a-gwchip">
									<code>{ g.id }</code> { g.enabled ? '' : <em>(disabled)</em> }
								</span>
							) ) }
						</p>
					</Section>
					<Section
						title="Gateway currencies"
						description="Which currencies each gateway can charge. A gateway is hidden on orders in other currencies (e.g. PayPal does not support UGX). Gateways not listed accept any currency."
					>
						<MapEditor< string[] >
							value={ v.gateway_currencies || {} }
							onChange={ ( gateway_currencies ) => s.set( { gateway_currencies } ) }
							keyLabel="Gateway"
							valueLabel="Currencies it can charge"
							keyPlaceholder="gateway id"
							newValue={ () => [ ...config.currencies ] }
							renderValue={ ( val, set ) => <TokenInput value={ val } onChange={ ( x ) => set( ( x as string[] ).map( ( c ) => c.toUpperCase() ) ) } placeholder="UGX, USD…" /> }
						/>
					</Section>
					<Section title="Transaction ID meta keys" description="Where each gateway stores its transaction reference on the Woo order. Used for provider_ref and duplicate detection.">
						<MapEditor< string[] >
							value={ v.transaction_meta_keys }
							onChange={ ( transaction_meta_keys ) => s.set( { transaction_meta_keys } ) }
							keyLabel="Gateway"
							valueLabel="Meta keys (first match wins)"
							keyPlaceholder="gateway id"
							newValue={ () => [ '_transaction_id' ] }
							renderValue={ ( val, set ) => <TokenInput value={ val } onChange={ ( x ) => set( x as string[] ) } placeholder="_meta_key…" /> }
						/>
					</Section>
					<Section title="Checkout fields" description="Billing fields kept on the trimmed order-pay page.">
						<TokenInput value={ v.checkout_fields } onChange={ ( x ) => s.set( { checkout_fields: x as string[] } ) } placeholder="billing_phone" />
					</Section>
				</>
			) }
		</SettingsFrame>
	);
}

/* ---------------- lifecycle ---------------- */

interface LifecycleValues extends Record< string, unknown > {
	reminder_days: number[];
	grace_days: number;
	suspend_to_expire_days: number;
	autocharge_retry_days: number[];
	stale_order_hours: number;
	rejoin_window_days: number;
	time_unit: 'day' | 'minute';
}

function Lifecycle() {
	const s = useSettingsGroup< LifecycleValues >( 'lifecycle' );
	const v = s.values;
	const unit = v?.time_unit === 'minute' ? 'minutes' : 'days';
	return (
		<SettingsFrame title="Lifecycle" description="Renewal reminders, grace period and when memberships suspend and expire." state={ s }>
			{ v && (
				<>
					<div className="wvm-a-lifeline">
						{ [
							[ 'Reminders', `${ v.reminder_days.join( ', ' ) } ${ unit } before` ],
							[ 'Renewal due', 'Order + pay link' ],
							[ 'Grace (past due)', `${ v.grace_days } ${ unit }` ],
							[ 'Suspended', `${ v.suspend_to_expire_days } ${ unit }` ],
							[ 'Expired', `Rejoin within ${ v.rejoin_window_days } ${ unit }` ],
						].map( ( [ t, d ], i ) => (
							<div key={ t } className="wvm-a-lifeline__step">
								<span>{ i + 1 }</span>
								<strong>{ t }</strong>
								<em>{ d }</em>
							</div>
						) ) }
					</div>
					<Section title="Reminders">
						<TokenInput label="Send renewal reminders (days before)" numeric value={ v.reminder_days } onChange={ ( x ) => s.set( { reminder_days: x as number[] } ) } help="Each value sends one reminder. Default 7 and 3." />
						<TokenInput label="Auto-charge retries (days after a failed charge)" numeric value={ v.autocharge_retry_days } onChange={ ( x ) => s.set( { autocharge_retry_days: x as number[] } ) } />
					</Section>
					<Section title="Grace & expiry">
						<FieldGrid>
							<TextField label="Grace days" type="number" min={ 0 } value={ v.grace_days } onChange={ ( n ) => s.set( { grace_days: Number( n ) || 0 } ) } help="Past due keeps access this long." />
							<TextField label="Suspended → expired (days)" type="number" min={ 0 } value={ v.suspend_to_expire_days } onChange={ ( n ) => s.set( { suspend_to_expire_days: Number( n ) || 0 } ) } />
							<TextField label="Cancel unpaid orders after (hours)" type="number" min={ 1 } value={ v.stale_order_hours } onChange={ ( n ) => s.set( { stale_order_hours: Number( n ) || 1 } ) } />
							<TextField label="Rejoin window (days)" type="number" min={ 0 } value={ v.rejoin_window_days } onChange={ ( n ) => s.set( { rejoin_window_days: Number( n ) || 0 } ) } help="Expired members paying within this window restore their old membership." />
						</FieldGrid>
					</Section>
					<Section title="Time unit" description="For staging tests only.">
						<div className="wvm-a-radiocards">
							<label className={ cx( v.time_unit === 'day' && 'is-active' ) }>
								<input type="radio" checked={ v.time_unit === 'day' } onChange={ () => s.set( { time_unit: 'day' } ) } />
								<strong>Days</strong>
								<span>Production — every value above is in days.</span>
							</label>
							<label className={ cx( v.time_unit === 'minute' && 'is-active' ) }>
								<input type="radio" checked={ v.time_unit === 'minute' } onChange={ () => s.set( { time_unit: 'minute' } ) } />
								<strong>Minutes</strong>
								<span>Minute compresses dunning for staging tests — a 7-day grace becomes 7 minutes.</span>
							</label>
						</div>
						{ v.time_unit === 'minute' && <Notice2 tone="danger" title="Never use minutes on a live site">Members would be suspended within minutes of a missed renewal.</Notice2> }
					</Section>
				</>
			) }
		</SettingsFrame>
	);
}

function AccessLink() {
	return (
		<SettingsFrame title="Content access" description="Paywall defaults live with the rest of the access rules.">
			<div className="wvm-a-linkcard">
				<span>
					<Icon name="lock" size={ 22 } />
				</span>
				<div>
					<strong>Content access settings</strong>
					<p>Default level, teaser length, paywall message and which post types get the access box.</p>
				</div>
				<A to="/access?tab=settings">
					Open <Icon name="arrowRight" size={ 13 } />
				</A>
			</div>
			<p className="wvm-a-help">Countries known to WooCommerce: { countryOptions().length }. Example: { decodeEntities( config.countries.UG || 'Uganda' ) } = UG.</p>
		</SettingsFrame>
	);
}
