<?php
declare( strict_types=1 );

namespace WitsValley\Members\Support;

/**
 * Grouped settings stored in wvm_settings (one row per group, JSON value).
 * Secret keys are encrypted at rest and masked when read for the UI.
 */
final class Settings {

	/** @var array<string,list<string>> Keys in each group that are secrets. */
	public const SECRETS = array(
		'kit'    => array( 'api_key', 'webhook_secret' ),
		'emails' => array( 'provider_key' ),
		'tools'  => array( 's3_secret' ),
	);

	/** @var array<string,array<string,mixed>> */
	private array $cache = array();

	/** @return array<string,array<string,mixed>> */
	public static function defaults(): array {
		return array(
			'payments'  => array(
				'gateways_by_country'         => array(
					'UG'      => array( 'flutterwave', 'pesapal', 'jpesa', 'wvm_test' ),
					'default' => array( 'flutterwave', 'paypal', 'ppcp-gateway', 'dpo', 'wvm_test' ),
				),
				'currencies_enabled'          => array( 'UGX', 'USD' ),
				'default_currency_by_country' => array(
					'UG'      => 'UGX',
					'default' => 'USD',
				),
				'sandbox_mode'                => true,
				'checkout_fields'             => array( 'billing_phone' ),
				'gateway_currencies'          => array(
					'paypal'       => array( 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'SEK', 'NOK', 'DKK', 'NZD', 'SGD', 'HKD', 'PLN', 'CZK', 'HUF', 'ILS', 'MXN', 'BRL', 'PHP', 'THB', 'TWD', 'MYR' ),
					'ppcp-gateway' => array( 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'SEK', 'NOK', 'DKK', 'NZD', 'SGD', 'HKD', 'PLN', 'CZK', 'HUF', 'ILS', 'MXN', 'BRL', 'PHP', 'THB', 'TWD', 'MYR' ),
				),
				'transaction_meta_keys'       => array(
					'flutterwave' => array( '_flutterwave_transaction_id', '_transaction_id', 'flw_ref' ),
					'pesapal'     => array( '_pesapal_tracking_id', 'pesapal_transaction_tracking_id', '_transaction_id' ),
					'dpo'         => array( '_dpo_transaction_token', 'dpo_trans_ref', '_transaction_id' ),
					'paypal'      => array( '_transaction_id', '_ppcp_paypal_order_id' ),
					'jpesa'       => array( '_jpesa_tid', '_transaction_id' ),
				),
			),
			'lifecycle' => array(
				'reminder_days'          => array( 7, 3 ),
				'grace_days'             => 7,
				'suspend_to_expire_days' => 30,
				'autocharge_retry_days'  => array( 1, 3, 5 ),
				'stale_order_hours'      => 24,
				'rejoin_window_days'     => 365,
				'time_unit'              => 'day',
			),
			'emails'    => array(
				'from_name'    => 'WitsValley',
				'from_email'   => '',
				'driver'       => 'wp_mail',
				'provider_key' => '',
				'bcc_admin_on' => array(),
				'admin_alerts' => '',
				'brand_color'  => '',
				'logo_url'     => '',
			),
			'branding'  => array(
				'primary_color'  => '#0B4C25',
				'accent_color'   => '#FFAC04',
				'font_source'    => 'theme',
				'body_font'      => '',
				'heading_font'   => '',
				'adobe_kit_id'   => '',
				'custom_css_url' => '',
				'admin_fonts'    => 'match',
				'dark_mode'      => 'off',
			),
			'kit'       => array(
				'api_key'                    => '',
				'tag_map'                    => array(),
				'create_subscribers_on_paid' => true,
				'create_subscribers_on_free' => true,
				'marketing_tag'              => 'WV-MARKETING',
				'webhook_secret'             => '',
				'webhook_ids'                => array(),
				'webhooks_connected_at'      => null,
				'last_reconcile_at'          => null,
				'last_reconcile_errors'      => 0,
			),
			'popup'     => array(
				'enabled'          => false,
				'mode'             => 'join',
				'eyebrow'          => 'Free book',
				'headline'         => 'Get the free WitsValley book',
				'headline_b'       => '',
				'body'             => 'Start reprogramming your subconscious mind today. Join free and get the book, plus a short series of guide emails to help you put it into practice.',
				'bullets'          => array( 'The free WitsValley book (PDF)', 'Guide emails to put it into practice', 'Free lessons and the weekly newsletter' ),
				'button'           => 'Join free & get the book',
				'email_button'     => 'Send me the free book',
				'note'             => 'No card needed. Unsubscribe any time.',
				'image_url'        => '',
				'book_url'         => '',
				'success_title'    => 'Welcome to WitsValley!',
				'success_body'     => 'Your free book is ready below, and your guide emails start shortly.',
				'email_success'    => 'Check your inbox: your free book and first guide are on their way.',
				'offer_tag'        => 'WV-FREE-BOOK',
				'lead_tag'         => 'WV-LEAD',
				'upgrade_enabled'  => true,
				'upgrade_eyebrow'  => 'Premium',
				'upgrade_headline' => 'Ready to go deeper?',
				'upgrade_body'     => 'Premium opens the full collection of WitsValley programmes, advanced reprogramming systems and exclusive learning resources.',
				'upgrade_button'   => 'See Premium',
				'upgrade_plan'     => 'premium',
				'delay_seconds'    => 20,
				'scroll_percent'   => 50,
				'exit_intent'      => true,
				'on_paywall'       => true,
				'min_pageviews'    => 1,
				'dismiss_days'     => 14,
				'post_types'       => array( 'post', 'page' ),
				'exclude_paths'    => array(),
			),
			'access'    => array(
				'default_level'     => 0,
				'teaser_paragraphs' => 2,
				'paywall_message'   => 'This content is for WitsValley members. Join or upgrade to keep reading.',
				'mode'              => 'teaser',
				'post_types'        => array( 'post', 'page' ),
			),
			'general'   => array(
				'business_name'       => 'WitsValley',
				'address'             => 'Kampala, Uganda',
				'support_email'       => '',
				'receipt_prefix'      => 'WV-R-',
				'receipt_next_number' => 1001,
				'dashboard_page_id'   => 0,
				'pricing_page_id'     => 0,
				'enterprise_page_id'  => 0,
				'terms_url'           => '',
				'pricing_eyebrow'     => 'WitsValley Membership',
				'pricing_title'       => 'Reprogram Your Subconscious Mind. Transform Your Life.',
				'pricing_subtitle'    => 'Awaken your awareness, master your inner world, and discover your untapped potential.',
				'pricing_intro'       => 'Explore practical subconscious mind reprogramming systems, guided protocols, spiritual awareness, and self-mastery programmes designed to transform how you think, feel, live, and experience the world.',
				'pricing_closing'     => 'Your journey to transformation begins here.',
				'privacy_url'         => '',
				'country_detection'   => array( 'provider' => 'woocommerce' ),
				'analytics'           => true,
			),
			'tools'     => array(
				'backup_enabled'   => true,
				'backup_keep_days' => 14,
				's3_endpoint'      => '',
				's3_bucket'        => '',
				's3_region'        => 'us-east-1',
				's3_key'           => '',
				's3_secret'        => '',
				'last_backup_at'   => null,
				'alerts_last_sent' => array(),
			),
		);
	}

	/** @return list<string> */
	public static function groups(): array {
		return array_keys( self::defaults() );
	}

	/** @return array<string,mixed> */
	public function group( string $group ): array {
		if ( isset( $this->cache[ $group ] ) ) {
			return $this->cache[ $group ];
		}
		global $wpdb;
		$table    = Db::table( 'settings' );
		$raw      = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$table} WHERE setting_key = %s", $group ) ); // phpcs:ignore WordPress.DB
		$stored   = $raw ? json_decode( (string) $raw, true ) : array();
		$defaults = self::defaults()[ $group ] ?? array();
		$values   = array_merge( $defaults, is_array( $stored ) ? $stored : array() );
		foreach ( self::SECRETS[ $group ] ?? array() as $secret ) {
			if ( isset( $values[ $secret ] ) && is_string( $values[ $secret ] ) ) {
				$values[ $secret ] = (string) Encryption::decrypt( $values[ $secret ] );
			}
		}
		$this->cache[ $group ] = $values;
		return $values;
	}

	public function get( string $group, string $key, mixed $default = null ): mixed {
		$values = $this->group( $group );
		return $values[ $key ] ?? $default;
	}

	/**
	 * Group values safe for the admin UI (secrets masked).
	 *
	 * @return array<string,mixed>
	 */
	public function publicGroup( string $group ): array {
		$values = $this->group( $group );
		foreach ( self::SECRETS[ $group ] ?? array() as $secret ) {
			$values[ $secret . '_set' ] = '' !== (string) ( $values[ $secret ] ?? '' );
			$values[ $secret ]          = Encryption::mask( (string) ( $values[ $secret ] ?? '' ) );
		}
		return $values;
	}

	/**
	 * Merge $patch into the group. Masked secret values (unchanged in UI) are ignored.
	 *
	 * @param array<string,mixed> $patch
	 * @return array{before:array<string,mixed>, after:array<string,mixed>}
	 */
	public function update( string $group, array $patch ): array {
		if ( ! array_key_exists( $group, self::defaults() ) ) {
			throw DomainError::notFound( 'settings group' );
		}
		$before  = $this->group( $group );
		$allowed = array_keys( self::defaults()[ $group ] );
		$secrets = self::SECRETS[ $group ] ?? array();
		$next    = $before;
		foreach ( $patch as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}
			if ( in_array( $key, $secrets, true ) && is_string( $value ) && str_contains( $value, '•' ) ) {
				continue;
			}
			$next[ $key ] = $value;
		}
		$this->persist( $group, $next );
		return array(
			'before' => $this->redact( $group, $before ),
			'after'  => $this->redact( $group, $next ),
		);
	}

	/** Internal write used by jobs (e.g. last_reconcile_at). */
	public function set( string $group, string $key, mixed $value ): void {
		$values         = $this->group( $group );
		$values[ $key ] = $value;
		$this->persist( $group, $values );
	}

	/**
	 * Atomically increment an integer setting and return the value before increment.
	 */
	public function increment( string $group, string $key ): int {
		return Db::transaction(
			function () use ( $group, $key ): int {
				global $wpdb;
				$table = Db::table( 'settings' );
				$wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$table} WHERE setting_key = %s FOR UPDATE", $group ) ); // phpcs:ignore WordPress.DB
				unset( $this->cache[ $group ] );
				$current = (int) $this->get( $group, $key, 1 );
				$this->set( $group, $key, $current + 1 );
				return $current;
			}
		);
	}

	/** @param array<string,mixed> $values */
	private function persist( string $group, array $values ): void {
		global $wpdb;
		$stored = $values;
		foreach ( self::SECRETS[ $group ] ?? array() as $secret ) {
			if ( isset( $stored[ $secret ] ) && '' !== $stored[ $secret ] ) {
				$stored[ $secret ] = Encryption::encrypt( (string) $stored[ $secret ] );
			}
		}
		$wpdb->replace( // phpcs:ignore WordPress.DB
			Db::table( 'settings' ),
			array(
				'setting_key' => $group,
				'value'       => wp_json_encode( $stored ),
				'updated_at'  => Clock::mysql(),
			)
		);
		$this->cache[ $group ] = $values;
	}

	/**
	 * @param array<string,mixed> $values
	 * @return array<string,mixed>
	 */
	private function redact( string $group, array $values ): array {
		foreach ( self::SECRETS[ $group ] ?? array() as $secret ) {
			if ( isset( $values[ $secret ] ) ) {
				$values[ $secret ] = Encryption::mask( (string) $values[ $secret ] );
			}
		}
		return $values;
	}

	public function flush(): void {
		$this->cache = array();
	}

	/** Lifecycle durations scale: 'day' in production, 'minute' to compress dunning on staging. */
	public function lifecycleSeconds( int $days ): int {
		$unit = $this->get( 'lifecycle', 'time_unit', 'day' );
		return $days * ( 'minute' === $unit ? MINUTE_IN_SECONDS : DAY_IN_SECONDS );
	}
}
