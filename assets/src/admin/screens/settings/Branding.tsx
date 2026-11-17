/* Settings › Branding: brand colours (everything else is derived) + font source (theme, system, Google, Adobe kit, custom). */
import type { CSSProperties } from 'react';
import { fieldError } from '../../lib/api';
import { Btn, Chip } from '../../components/ui';
import { Icon } from '../../components/Icon';
import { TextField, SelectField, FieldGrid } from '../../components/fields';
import { useSettingsGroup, SettingsFrame, Section } from './shared';

interface BrandingValues extends Record< string, unknown > {
	primary_color: string;
	accent_color: string;
	font_source: 'theme' | 'system' | 'google' | 'adobe' | 'custom';
	body_font: string;
	heading_font: string;
	adobe_kit_id: string;
	custom_css_url: string;
	admin_fonts: 'match' | 'system';
	dark_mode: 'auto' | 'off';
}

const DEFAULTS = { primary_color: '#0B4C25', accent_color: '#FFAC04' };

/* ---- colour maths (mirrors src/Support/Branding.php) ---- */
function rgb( hex: string ): [ number, number, number ] {
	const h = /^#?([0-9a-f]{6})$/i.exec( hex.trim() );
	if ( ! h ) {
		return [ 0, 0, 0 ];
	}
	const n = parseInt( h[ 1 ], 16 );
	return [ ( n >> 16 ) & 255, ( n >> 8 ) & 255, n & 255 ];
}
function mix( a: string, b: string, t: number ): string {
	const x = rgb( a );
	const y = rgb( b );
	return '#' + x.map( ( v, i ) => Math.round( v + ( y[ i ] - v ) * t ).toString( 16 ).padStart( 2, '0' ) ).join( '' );
}
function lum( hex: string ): number {
	const c = rgb( hex ).map( ( v ) => {
		const s = v / 255;
		return s <= 0.03928 ? s / 12.92 : Math.pow( ( s + 0.055 ) / 1.055, 2.4 );
	} );
	return 0.2126 * c[ 0 ] + 0.7152 * c[ 1 ] + 0.0722 * c[ 2 ];
}
function contrast( a: string, b: string ): number {
	const x = lum( a );
	const y = lum( b );
	return ( Math.max( x, y ) + 0.05 ) / ( Math.min( x, y ) + 0.05 );
}
function onColor( bg: string ): string {
	return contrast( bg, '#ffffff' ) >= contrast( bg, '#0e1f15' ) ? '#ffffff' : '#0e1f15';
}
const isHex = ( v: string ) => /^#?[0-9a-f]{6}$/i.test( v.trim() );
const norm = ( v: string ) => ( v.startsWith( '#' ) ? v : '#' + v ).toUpperCase();

function ColourField( { label, value, onChange, help, error }: { label: string; value: string; onChange: ( v: string ) => void; help: string; error?: string } ) {
	const ok = isHex( value );
	return (
		<div className="wvm-a-field">
			<span className="wvm-a-label">{ label }</span>
			<div className="wvm-a-colorfield">
				<input type="color" value={ ok ? norm( value ) : '#000000' } onChange={ ( e ) => onChange( e.target.value.toUpperCase() ) } aria-label={ label } />
				<input className="wvm-a-input" value={ value } onChange={ ( e ) => onChange( e.target.value ) } spellCheck={ false } aria-label={ `${ label } hex value` } />
			</div>
			<p className="wvm-a-help">{ error || help }</p>
		</div>
	);
}

function fontStack( v: BrandingValues, which: 'body' | 'heading' ): string {
	if ( v.font_source === 'theme' || v.font_source === 'system' ) {
		return 'inherit';
	}
	const name = ( which === 'heading' ? v.heading_font || v.body_font : v.body_font ).trim();
	return name ? `"${ name.replace( /"/g, '' ) }", -apple-system, "Segoe UI", Roboto, sans-serif` : 'inherit';
}

function Preview( { v }: { v: BrandingValues } ) {
	const p = isHex( v.primary_color ) ? norm( v.primary_color ) : DEFAULTS.primary_color;
	const a = isHex( v.accent_color ) ? norm( v.accent_color ) : DEFAULTS.accent_color;
	const [ ar, ag, ab ] = rgb( a );
	const hero = `radial-gradient(85% 110% at 100% 0%, rgba(${ ar }, ${ ag }, ${ ab }, 0.3) 0%, rgba(${ ar }, ${ ag }, ${ ab }, 0) 55%), radial-gradient(120% 140% at 0% 0%, ${ mix( p, '#ffffff', 0.18 ) } 0%, ${ p } 48%, ${ mix( p, '#000000', 0.45 ) } 100%)`;
	const body = fontStack( v, 'body' );
	const heading = fontStack( v, 'heading' );
	const wrap: CSSProperties = { fontFamily: body };
	return (
		<div className="wvm-a-brandpreview" style={ wrap }>
			<div className="wvm-a-brandpreview__hero" style={ { background: hero, color: onColor( p ) } }>
				<span className="wvm-a-brandpreview__chip" style={ { background: a, color: onColor( a ) } }>
					Most popular
				</span>
				<span className="wvm-a-brandpreview__eyebrow">Your plan</span>
				<strong style={ { fontFamily: heading } }>Premium</strong>
				<span className="wvm-a-brandpreview__price">UGX 50,000 <em>/ month</em></span>
				<span className="wvm-a-brandpreview__btn" style={ { color: p } }>
					Manage plan →
				</span>
			</div>
			<div className="wvm-a-brandpreview__side">
				<h4 style={ { fontFamily: heading } }>Grow your money skills</h4>
				<p>Practical lessons, live workshops and expert help. This is how body text looks.</p>
				<div className="wvm-a-brandpreview__row">
					<span className="wvm-a-brandpreview__primary" style={ { background: p, color: onColor( p ) } }>
						Join Premium
					</span>
					<span className="wvm-a-brandpreview__ghost" style={ { color: p, borderColor: mix( p, '#ffffff', 0.62 ) } }>
						Log in
					</span>
				</div>
				<div className="wvm-a-brandpreview__row">
					<span className="wvm-a-brandpreview__pill" style={ { background: mix( p, '#ffffff', 0.9 ), color: p } }>
						Active
					</span>
					<span className="wvm-a-brandpreview__pill" style={ { background: mix( a, '#ffffff', 0.85 ), color: '#0e1f15' } }>
						Renews in 7 days
					</span>
				</div>
			</div>
		</div>
	);
}

export function BrandingSettings() {
	const s = useSettingsGroup< BrandingValues >( 'branding' );
	const v = s.values;
	const needsNames = v && [ 'google', 'adobe', 'custom' ].includes( v.font_source );
	const pc = v && isHex( v.primary_color ) ? contrast( norm( v.primary_color ), '#ffffff' ) : 0;
	return (
		<SettingsFrame
			title="Branding"
			description="Brand colours and fonts used on the member dashboard, pricing and enterprise pages, checkout, paywall, admin, emails and PDF receipts."
			state={ s }
			actions={
				<Btn variant="ghost" icon="refresh" onClick={ () => s.set( { ...DEFAULTS } ) }>
					WitsValley defaults
				</Btn>
			}
		>
			{ v && (
				<>
					<Section title="Colours" description="Pick two colours; hover, light and dark shades, gradients and dark-mode variants are generated automatically.">
						<FieldGrid>
							<ColourField
								label="Primary"
								value={ v.primary_color }
								onChange={ ( primary_color ) => s.set( { primary_color } ) }
								help="Buttons, links, active states and the gradient cards."
								error={ fieldError( s.error, 'primary_color' ) }
							/>
							<ColourField
								label="Accent"
								value={ v.accent_color }
								onChange={ ( accent_color ) => s.set( { accent_color } ) }
								help="Highlights: “Most popular” badge, gradient glow, counters."
								error={ fieldError( s.error, 'accent_color' ) }
							/>
						</FieldGrid>
						{ pc > 0 && pc < 4.5 && (
							<p className="wvm-a-help">
								<Chip tone="warning">Low contrast</Chip> White text on this primary colour has a contrast of { pc.toFixed( 1 ) }:1 — text will switch to dark automatically, but a deeper colour reads better.
							</p>
						) }
						<Preview v={ v } />
						<SelectField
							label="Dark mode (member dashboard)"
							value={ v.dark_mode }
							onChange={ ( d ) => s.set( { dark_mode: d as BrandingValues[ 'dark_mode' ] } ) }
							options={ [
								{ value: 'auto', label: 'Follow the visitor’s device setting' },
								{ value: 'off', label: 'Always light' },
							] }
						/>
					</Section>
					<Section
						title="Fonts"
						description={
							<>
								Use your theme’s fonts, or load a font kit. With <strong>Theme</strong>, the plugin reads the active theme’s body and heading fonts (block themes) so the dashboard matches the rest of the site.
							</>
						}
					>
						<SelectField
							label="Font source"
							value={ v.font_source }
							onChange={ ( f ) => s.set( { font_source: f as BrandingValues[ 'font_source' ] } ) }
							error={ fieldError( s.error, 'font_source' ) }
							options={ [
								{ value: 'theme', label: 'Theme fonts (inherit from the active theme)' },
								{ value: 'system', label: 'System fonts (fastest, no download)' },
								{ value: 'google', label: 'Google Fonts' },
								{ value: 'adobe', label: 'Adobe Fonts kit (Typekit)' },
								{ value: 'custom', label: 'Custom font stylesheet (self-hosted or other kit)' },
							] }
						/>
						{ v.font_source === 'adobe' && (
							<TextField
								label="Adobe Fonts kit ID"
								value={ v.adobe_kit_id }
								onChange={ ( adobe_kit_id ) => s.set( { adobe_kit_id } ) }
								placeholder="e.g. abc1defg"
								help="From fonts.adobe.com › Web Projects. Loads https://use.typekit.net/{kit}.css. Add your site’s domain to the web project."
								error={ fieldError( s.error, 'adobe_kit_id' ) }
							/>
						) }
						{ v.font_source === 'custom' && (
							<TextField
								label="Font stylesheet URL"
								value={ v.custom_css_url }
								onChange={ ( custom_css_url ) => s.set( { custom_css_url } ) }
								placeholder="https://…/fonts.css"
								help="Any HTTPS stylesheet with @font-face rules (self-hosted fonts, Fontshare, Bunny Fonts…)."
							/>
						) }
						{ needsNames && (
							<FieldGrid>
								<TextField
									label="Body font family"
									value={ v.body_font }
									onChange={ ( body_font ) => s.set( { body_font } ) }
									placeholder={ v.font_source === 'google' ? 'e.g. Inter' : 'e.g. proxima-nova' }
									help={ v.font_source === 'google' ? 'Exact Google Fonts family name.' : 'The CSS font-family name from your kit.' }
								/>
								<TextField
									label="Heading font family"
									value={ v.heading_font }
									onChange={ ( heading_font ) => s.set( { heading_font } ) }
									placeholder="Leave empty to use the body font"
								/>
							</FieldGrid>
						) }
						<SelectField
							label="Fonts in wp-admin"
							value={ v.admin_fonts }
							onChange={ ( a ) => s.set( { admin_fonts: a as BrandingValues[ 'admin_fonts' ] } ) }
							options={ [
								{ value: 'match', label: 'Match the front end' },
								{ value: 'system', label: 'System fonts' },
							] }
						/>
						<p className="wvm-a-help">
							<Icon name="info" size={ 12 } /> Emails use the body font where mail apps support it and fall back to system fonts.
						</p>
					</Section>
				</>
			) }
		</SettingsFrame>
	);
}
