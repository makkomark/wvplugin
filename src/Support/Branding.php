<?php
declare( strict_types=1 );

namespace WitsValley\Members\Support;

/**
 * Brand colours + fonts → CSS custom properties for every plugin surface
 * (member app, pricing, checkout, paywall, admin), emails and PDF receipts.
 *
 * Colours: two brand colours (primary + accent); every shade, gradient and dark-mode variant is derived.
 * Fonts: theme (reads the active theme's body/heading fonts), system, Google Fonts,
 * Adobe Fonts kit (Typekit) or any custom font stylesheet.
 */
final class Branding {

	public const DEFAULT_PRIMARY = '#0B4C25';
	public const DEFAULT_ACCENT  = '#FFAC04';

	public const SYSTEM_STACK = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif';

	public const FONT_SOURCES = array( 'theme', 'system', 'google', 'adobe', 'custom' );

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueueFront' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAdmin' ), 20 );
		add_action( 'admin_head', array( $this, 'adminThemeFontFaces' ) );
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ---------------------------------------------------------------- */

	/** @return array<string,mixed> */
	public function values(): array {
		$v                  = $this->settings->group( 'branding' );
		$v['primary_color'] = self::hex( (string) ( $v['primary_color'] ?? '' ), self::DEFAULT_PRIMARY );
		$v['accent_color']  = self::hex( (string) ( $v['accent_color'] ?? '' ), self::DEFAULT_ACCENT );
		$v['font_source']   = in_array( $v['font_source'] ?? '', self::FONT_SOURCES, true ) ? (string) $v['font_source'] : 'theme';
		return $v;
	}

	public function primary(): string {
		return (string) $this->values()['primary_color'];
	}

	public function accent(): string {
		return (string) $this->values()['accent_color'];
	}

	/* ------------------------------------------------------------------
	 * Colour tokens
	 * ---------------------------------------------------------------- */

	/** @return array<string,string> CSS custom properties for light mode. */
	public function tokens(): array {
		$p          = $this->primary();
		$a          = $this->accent();
		$prgb       = implode( ', ', self::rgb( $p ) );
		$argb       = implode( ', ', self::rgb( $a ) );
		$hero_light = self::mix( $p, '#ffffff', 0.18 );
		$hero_dark  = self::mix( $p, '#000000', 0.45 );
		return array(
			'--wvm-primary'     => $p,
			'--wvm-primary-rgb' => $prgb,
			'--wvm-primary-400' => self::mix( $p, '#ffffff', 0.28 ),
			'--wvm-primary-600' => self::mix( $p, '#000000', 0.15 ),
			'--wvm-primary-700' => self::mix( $p, '#000000', 0.3 ),
			'--wvm-primary-900' => $hero_dark,
			'--wvm-primary-200' => self::mix( $p, '#ffffff', 0.62 ),
			'--wvm-primary-100' => self::mix( $p, '#ffffff', 0.84 ),
			'--wvm-primary-50'  => self::mix( $p, '#ffffff', 0.93 ),
			'--wvm-on-primary'  => self::onColor( $p ),
			'--wvm-accent'      => $a,
			'--wvm-accent-rgb'  => $argb,
			'--wvm-accent-600'  => self::mix( $a, '#000000', 0.12 ),
			'--wvm-accent-100'  => self::mix( $a, '#ffffff', 0.8 ),
			'--wvm-accent-50'   => self::mix( $a, '#ffffff', 0.9 ),
			'--wvm-on-accent'   => self::onColor( $a ),
			'--wvm-hero'        => "radial-gradient(85% 110% at 100% 0%, rgba({$argb}, 0.30) 0%, rgba({$argb}, 0) 55%), radial-gradient(120% 140% at 0% 0%, {$hero_light} 0%, {$p} 48%, {$hero_dark} 100%)",
			'--wvm-hero-shadow' => "0 24px 48px -24px rgba({$prgb}, 0.6)",
			'--wvm-ring'        => "0 0 0 4px rgba({$prgb}, 0.14)",
		);
	}

	/** @return array<string,string> Overrides inside prefers-color-scheme: dark (auto theme only). */
	public function darkTokens(): array {
		$p    = $this->primary();
		$a    = $this->accent();
		$lift = self::mix( $p, '#ffffff', self::luminance( $p ) < 0.08 ? 0.3 : 0.12 );
		return array(
			'--wvm-primary'     => $lift,
			'--wvm-primary-rgb' => implode( ', ', self::rgb( $lift ) ),
			'--wvm-primary-400' => self::mix( $lift, '#ffffff', 0.25 ),
			'--wvm-primary-600' => self::mix( $lift, '#ffffff', 0.12 ),
			'--wvm-primary-700' => self::mix( $lift, '#ffffff', 0.22 ),
			'--wvm-primary-200' => self::mix( $lift, '#ffffff', 0.55 ),
			'--wvm-primary-100' => self::mix( $p, '#0b1410', 0.55 ),
			'--wvm-primary-50'  => self::mix( $p, '#0b1410', 0.72 ),
			'--wvm-on-primary'  => self::onColor( $lift ),
			'--wvm-accent-100'  => self::mix( $a, '#0b1410', 0.75 ),
			'--wvm-accent-50'   => self::mix( $a, '#0b1410', 0.85 ),
		);
	}

	/* ------------------------------------------------------------------
	 * Fonts
	 * ---------------------------------------------------------------- */

	/**
	 * Resolved font stacks.
	 *
	 * @return array{body:string, heading:string, stylesheet:?string, source:string}
	 */
	public function fonts( bool $forAdmin = false ): array {
		$v       = $this->values();
		$source  = (string) $v['font_source'];
		$body    = trim( (string) ( $v['body_font'] ?? '' ) );
		$heading = trim( (string) ( $v['heading_font'] ?? '' ) );
		$sheet   = null;

		switch ( $source ) {
			case 'theme':
				[ $body, $heading ] = $this->themeFonts();
				break;
			case 'google':
				$families = array_values( array_unique( array_filter( array( $body, $heading ) ) ) );
				if ( $families ) {
					$sheet = 'https://fonts.googleapis.com/css2?' . implode(
						'&',
						array_map( static fn( $f ) => 'family=' . str_replace( '%20', '+', rawurlencode( $f ) ) . ':wght@400;500;600;700;800', $families )
					) . '&display=swap';
				}
				$body    = $body ? self::quote( $body ) : '';
				$heading = $heading ? self::quote( $heading ) : '';
				break;
			case 'adobe':
				$kit     = preg_replace( '/[^a-z0-9]/i', '', (string) ( $v['adobe_kit_id'] ?? '' ) );
				$sheet   = $kit ? 'https://use.typekit.net/' . $kit . '.css' : null;
				$body    = $body ? self::quote( $body ) : '';
				$heading = $heading ? self::quote( $heading ) : '';
				break;
			case 'custom':
				$url     = esc_url_raw( (string) ( $v['custom_css_url'] ?? '' ) );
				$sheet   = $url ? $url : null;
				$body    = $body ? self::quote( $body ) : '';
				$heading = $heading ? self::quote( $heading ) : '';
				break;
			default:
				$body    = '';
				$heading = '';
		}
		if ( $forAdmin && 'system' === ( $v['admin_fonts'] ?? 'match' ) ) {
			return array(
				'body'       => self::SYSTEM_STACK,
				'heading'    => self::SYSTEM_STACK,
				'stylesheet' => null,
				'source'     => 'system',
			);
		}
		$body_stack    = $body ? $body . ', ' . self::SYSTEM_STACK : self::SYSTEM_STACK;
		$heading_stack = $heading ? $heading . ', ' . self::SYSTEM_STACK : $body_stack;
		return array(
			'body'       => $body_stack,
			'heading'    => $heading_stack,
			'stylesheet' => $sheet,
			'source'     => $source,
		);
	}

	/**
	 * The active theme's body and heading font-family values (block themes via theme.json / global styles).
	 *
	 * @return array{0:string,1:string}
	 */
	public function themeFonts(): array {
		if ( ! function_exists( 'wp_get_global_styles' ) || ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
			return array( 'inherit', '' );
		}
		$read    = static function ( array $path ): string {
			$value = wp_get_global_styles( $path );
			return is_string( $value ) ? $value : '';
		};
		$body    = $this->resolvePreset( $read( array( 'typography', 'fontFamily' ) ) );
		$heading = $this->resolvePreset( $read( array( 'elements', 'heading', 'typography', 'fontFamily' ) ) );
		if ( '' === $heading ) {
			$heading = $this->resolvePreset( $read( array( 'elements', 'h1', 'typography', 'fontFamily' ) ) );
		}
		return array( $body ?: 'inherit', $heading );
	}

	/** Convert "var:preset|font-family|slug" or var(--wp--preset--font-family--slug) into the actual stack. */
	private function resolvePreset( string $value ): string {
		$slug = '';
		if ( preg_match( '/^var:preset\|font-family\|([a-z0-9\-_]+)$/i', $value, $m ) ) {
			$slug = $m[1];
		} elseif ( preg_match( '/--wp--preset--font-family--([a-z0-9\-_]+)/i', $value, $m ) ) {
			$slug = $m[1];
		}
		if ( '' === $slug ) {
			return $value;
		}
		$families = (array) wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
		foreach ( array( 'custom', 'theme', 'default' ) as $origin ) {
			foreach ( (array) ( $families[ $origin ] ?? array() ) as $f ) {
				if ( isset( $f['slug'], $f['fontFamily'] ) && $f['slug'] === $slug ) {
					return (string) $f['fontFamily'];
				}
			}
		}
		return 'var(--wp--preset--font-family--' . $slug . ')';
	}

	/* ------------------------------------------------------------------
	 * CSS output
	 * ---------------------------------------------------------------- */

	public function css( bool $forAdmin = false ): string {
		$fonts                       = $this->fonts( $forAdmin );
		$light                       = $this->tokens();
		$light['--wvm-font']         = $fonts['body'];
		$light['--wvm-font-heading'] = $fonts['heading'];
		$css                         = ':root,.wvm-theme{' . self::declarations( $light ) . '}';
		if ( 'off' !== ( $this->values()['dark_mode'] ?? 'auto' ) ) {
			$css .= '@media (prefers-color-scheme: dark){.wvm-theme.wvm-theme--auto{' . self::declarations( $this->darkTokens() ) . '}}';
		} else {
			$css .= '.wvm-theme.wvm-theme--auto{color-scheme:light}';
		}
		return $css;
	}

	/** @param array<string,string> $vars */
	private static function declarations( array $vars ): string {
		$out = '';
		foreach ( $vars as $k => $v ) {
			$out .= $k . ':' . str_replace( array( '<', '>', '{', '}' ), '', $v ) . ';';
		}
		return $out;
	}

	public function enqueueFront(): void {
		$fonts = $this->fonts();
		if ( $fonts['stylesheet'] ) {
			wp_enqueue_style( 'wvm-brand-fonts', $fonts['stylesheet'], array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
		// wvm-public loads on every front-end page (paywall, pricing, dashboard, checkout); the tokens are global.
		if ( ! wp_style_is( 'wvm-public', 'enqueued' ) ) {
			wp_enqueue_style( 'wvm-public', WVM_URL . 'assets/css/public.css', array(), WVM_VERSION );
		}
		wp_add_inline_style( 'wvm-public', $this->css() );
	}

	public function enqueueAdmin(): void {
		if ( ! wp_style_is( 'wvm-admin', 'enqueued' ) && ! wp_style_is( 'wvm-admin', 'registered' ) ) {
			return;
		}
		$fonts = $this->fonts( true );
		if ( $fonts['stylesheet'] ) {
			wp_enqueue_style( 'wvm-brand-fonts', $fonts['stylesheet'], array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
		wp_add_inline_style( 'wvm-admin', $this->css( true ) );
	}

	/** Theme fonts in wp-admin: print the block theme's @font-face rules on WitsValley screens. */
	public function adminThemeFontFaces(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 !== strpos( $page, 'wvm' ) ) {
			return;
		}
		$v = $this->values();
		if ( 'theme' === $v['font_source'] && 'system' !== ( $v['admin_fonts'] ?? 'match' ) && function_exists( 'wp_print_font_faces' ) ) {
			wp_print_font_faces();
		}
	}

	/** Font stack for emails (web fonts are best-effort in mail clients). */
	public function emailFontStack(): string {
		$fonts = $this->fonts();
		return str_contains( $fonts['body'], 'var(' ) || 'inherit' === $fonts['body'] ? self::SYSTEM_STACK : $fonts['body'];
	}

	/** Data for the admin app (preview + charts). @return array<string,mixed> */
	public function forApp(): array {
		$fonts = $this->fonts( true );
		return array(
			'primary'  => $this->primary(),
			'accent'   => $this->accent(),
			'darkMode' => 'auto' === ( $this->values()['dark_mode'] ?? 'off' ) ? 'auto' : 'off',
			'font'     => $fonts['body'],
			'heading'  => $fonts['heading'],
		);
	}

	/* ------------------------------------------------------------------
	 * Colour maths
	 * ---------------------------------------------------------------- */

	public static function hex( string $value, string $fallback ): string {
		$value = trim( $value );
		if ( preg_match( '/^#?([0-9a-f]{3})$/i', $value, $m ) ) {
			$value = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
		}
		if ( preg_match( '/^#?([0-9a-f]{6})$/i', $value, $m ) ) {
			return '#' . strtoupper( $m[1] );
		}
		return $fallback;
	}

	/** @return array{0:int,1:int,2:int} */
	public static function rgb( string $hex ): array {
		$hex = ltrim( self::hex( $hex, '#000000' ), '#' );
		return array( (int) hexdec( substr( $hex, 0, 2 ) ), (int) hexdec( substr( $hex, 2, 2 ) ), (int) hexdec( substr( $hex, 4, 2 ) ) );
	}

	/** Mix $a toward $b by $t (0..1). */
	public static function mix( string $a, string $b, float $t ): string {
		$x = self::rgb( $a );
		$y = self::rgb( $b );
		$c = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$c[] = (int) round( $x[ $i ] + ( $y[ $i ] - $x[ $i ] ) * $t );
		}
		return sprintf( '#%02X%02X%02X', $c[0], $c[1], $c[2] );
	}

	public static function luminance( string $hex ): float {
		$l = array_map(
			static function ( int $v ): float {
				$s = $v / 255;
				return $s <= 0.03928 ? $s / 12.92 : ( ( $s + 0.055 ) / 1.055 ) ** 2.4;
			},
			self::rgb( $hex )
		);
		return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
	}

	public static function contrast( string $a, string $b ): float {
		$x = self::luminance( $a );
		$y = self::luminance( $b );
		return ( max( $x, $y ) + 0.05 ) / ( min( $x, $y ) + 0.05 );
	}

	/** Readable text colour on top of $bg. */
	public static function onColor( string $bg ): string {
		return self::contrast( $bg, '#ffffff' ) >= self::contrast( $bg, '#0E1F15' ) ? '#FFFFFF' : '#0E1F15';
	}

	private static function quote( string $family ): string {
		$family = trim( str_replace( array( '"', "'", ';', '{', '}', '<', '>' ), '', $family ) );
		return str_contains( $family, ',' ) ? $family : '"' . $family . '"';
	}
}
