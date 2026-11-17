<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Notification;

use WitsValley\Members\Support\Settings;

/**
 * Renders templates: placeholder substitution, [button] shortcode and branded HTML layout.
 */
final class Renderer {

	public function __construct( private Settings $settings ) {}

	/**
	 * @param array<string,mixed> $template Row with subject, preview, body_html, body_text.
	 * @param array<string,mixed> $vars
	 * @return array{subject:string, html:string, text:string}
	 */
	public function render( array $template, array $vars ): array {
		$subject = $this->replace( (string) $template['subject'], $vars, false );
		$preview = $this->replace( (string) ( $template['preview'] ?? '' ), $vars, false );
		$body    = $this->replace( (string) $template['body_html'], $vars, true );
		$body    = (string) preg_replace_callback(
			'#\[button url="([^"]*)"\](.*?)\[/button\]#s',
			fn( $m ) => $this->button( html_entity_decode( $m[1] ), $m[2] ),
			$body
		);
		$text = $template['body_text'] ? $this->replace( (string) $template['body_text'], $vars, false ) : TemplateDefaults::toText( $this->replace( (string) $template['body_html'], $vars, false ) );
		return array(
			'subject' => wp_specialchars_decode( $subject ),
			'html'    => $this->layout( $body, $preview ),
			'text'    => $text,
		);
	}

	/** @param array<string,mixed> $vars */
	private function replace( string $tpl, array $vars, bool $escape ): string {
		return (string) preg_replace_callback(
			'/\{([a-z_]+)\}/',
			static function ( $m ) use ( $vars, $escape ) {
				if ( ! array_key_exists( $m[1], $vars ) ) {
					return $m[0];
				}
				$value = is_scalar( $vars[ $m[1] ] ) ? (string) $vars[ $m[1] ] : '';
				return $escape ? esc_html( $value ) : $value;
			},
			$tpl
		);
	}

	private function button( string $url, string $label ): string {
		$color = esc_attr( (string) $this->settings->get( 'emails', 'brand_color', '#5B3DF5' ) );
		if ( '' === $url || str_starts_with( $url, '{' ) ) {
			return '';
		}
		return '<table role="presentation" cellspacing="0" cellpadding="0" style="margin:24px 0"><tr><td style="border-radius:12px;background:' . $color . '">'
			. '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:14px 26px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:12px;font-size:15px">' . esc_html( wp_strip_all_tags( $label ) ) . '</a></td></tr></table>';
	}

	public function layout( string $body, string $preview = '' ): string {
		$brand    = esc_attr( (string) $this->settings->get( 'emails', 'brand_color', '#5B3DF5' ) );
		$logo     = (string) $this->settings->get( 'emails', 'logo_url', '' );
		$business = esc_html( (string) $this->settings->get( 'general', 'business_name', 'WitsValley' ) );
		$address  = esc_html( (string) $this->settings->get( 'general', 'address', '' ) );
		$support  = (string) $this->settings->get( 'general', 'support_email', '' ) ?: (string) get_option( 'admin_email' );
		$header   = $logo ? '<img src="' . esc_url( $logo ) . '" alt="' . $business . '" height="32" style="height:32px">' : '<span style="font-size:20px;font-weight:800;color:#0F0B2E;letter-spacing:-0.02em">' . $business . '<span style="color:' . $brand . '">.</span></span>';
		return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $business . '</title>'
			. '<style>body{margin:0;background:#F4F3FA;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Inter,Roboto,Helvetica,Arial,sans-serif;color:#1E1B3A}p{font-size:15px;line-height:1.6;margin:0 0 14px}.kv{width:100%;border-collapse:collapse;margin:8px 0 4px}.kv td{padding:8px 0;border-bottom:1px solid #ECEAF5;font-size:14px}.kv td:last-child{text-align:right;font-weight:600}a{color:' . $brand . '}</style></head>'
			. '<body><span style="display:none!important;opacity:0;color:transparent;height:0;width:0;overflow:hidden">' . esc_html( $preview ) . '</span>'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#F4F3FA;padding:32px 12px"><tr><td align="center">'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px">'
			. '<tr><td style="padding:0 8px 18px">' . $header . '</td></tr>'
			. '<tr><td style="background:#ffffff;border-radius:20px;padding:32px 28px;box-shadow:0 1px 2px rgba(15,11,46,.06),0 8px 24px rgba(15,11,46,.06)">' . $body . '</td></tr>'
			. '<tr><td style="padding:20px 8px;font-size:12px;line-height:1.6;color:#7A7699">' . $business . ( $address ? ' · ' . $address : '' ) . '<br>Questions? <a href="mailto:' . esc_attr( $support ) . '">' . esc_html( $support ) . '</a></td></tr>'
			. '</table></td></tr></table></body></html>';
	}
}
