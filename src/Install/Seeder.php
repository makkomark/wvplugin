<?php
declare( strict_types=1 );

namespace WitsValley\Members\Install;

use WitsValley\Members\Domain\Kit\TagMap;
use WitsValley\Members\Domain\Notification\EmailTemplateRepository;
use WitsValley\Members\Domain\Notification\TemplateDefaults;
use WitsValley\Members\Domain\Plan\PlanRepository;
use WitsValley\Members\Domain\Plan\PlanService;
use WitsValley\Members\Support\Settings;

/**
 * Idempotent seed: launch plans, email templates, default settings, tag map, pages.
 */
final class Seeder {

	public static function run(): void {
		self::settings();
		self::templates();
		self::plans();
		self::pages();
	}

	public static function settings(): void {
		$settings = wvm( Settings::class );
		foreach ( Settings::groups() as $group ) {
			global $wpdb;
			$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT setting_key FROM ' . \WitsValley\Members\Support\Db::table( 'settings' ) . ' WHERE setting_key = %s', $group ) ); // phpcs:ignore WordPress.DB
			if ( ! $exists ) {
				$values = Settings::defaults()[ $group ];
				if ( 'kit' === $group ) {
					$values['tag_map'] = TagMap::defaults();
				}
				if ( 'general' === $group ) {
					$values['support_email'] = (string) get_option( 'admin_email' );
					$values['privacy_url']   = (string) get_privacy_policy_url();
				}
				if ( 'emails' === $group ) {
					$values['from_email']   = (string) get_option( 'admin_email' );
					$values['admin_alerts'] = (string) get_option( 'admin_email' );
				}
				$settings->update( $group, $values );
			}
		}
		$settings->flush();
	}

	public static function templates(): void {
		$repo = wvm( EmailTemplateRepository::class );
		foreach ( TemplateDefaults::all() as $key => $t ) {
			if ( ! $repo->byKey( $key ) ) {
				$repo->insert(
					array(
						'template_key' => $key,
						'name'         => $t['name'],
						'subject'      => $t['subject'],
						'preview'      => $t['preview'],
						'body_html'    => $t['html'],
						'body_text'    => $t['text'],
						'enabled'      => true,
					)
				);
			}
		}
	}

	/** @return list<array<string,mixed>> */
	public static function launchPlans(): array {
		return array(
			array(
				'slug'        => 'free',
				'name'        => 'Free',
				'description' => 'Start learning with our free newsletter and selected lessons.',
				'level'       => 0,
				'interval'    => 'none',
				'kit_tag'     => 'WV-FREE',
				'is_free'     => true,
				'benefits'    => array( 'Weekly WitsValley newsletter', 'Selected free lessons', 'Community updates' ),
			),
			array(
				'slug'        => 'basic',
				'name'        => 'Basic',
				'description' => 'Core lessons and monthly live sessions.',
				'level'       => 1,
				'interval'    => 'monthly',
				'kit_tag'     => 'WV-BASIC',
				'benefits'    => array( 'All Basic lessons by email', 'Video lessons (members-only links)', 'Monthly live workshop', 'Member dashboard & receipts' ),
				'prices'      => array(
					array( 'currency' => 'UGX', 'amount_minor' => 20000 ),
					array( 'currency' => 'USD', 'amount_minor' => 600 ),
				),
			),
			array(
				'slug'        => 'premium',
				'name'        => 'Premium',
				'description' => 'Everything in Basic plus deep-dive programmes and 1:1 consultations.',
				'level'       => 2,
				'interval'    => 'monthly',
				'kit_tag'     => 'WV-PREMIUM',
				'highlight'   => true,
				'benefits'    => array( 'Everything in Basic', 'Premium deep-dive programmes', 'All live workshops + recordings', 'Request 1:1 consultations', 'Priority support' ),
				'prices'      => array(
					array( 'currency' => 'UGX', 'amount_minor' => 50000 ),
					array( 'currency' => 'USD', 'amount_minor' => 1500 ),
				),
			),
			array(
				'slug'        => 'enterprise',
				'name'        => 'Enterprise & Private',
				'description' => 'Tailored programmes for teams, schools and organisations.',
				'level'       => 3,
				'interval'    => 'contract',
				'kit_tag'     => 'WV-ENTERPRISE',
				'is_quoted'   => true,
				'benefits'    => array( 'Custom programme for your team', 'Private workshops', 'Seat management for your admins', 'Dedicated account manager' ),
			),
		);
	}

	public static function plans(): void {
		$repo = wvm( PlanRepository::class );
		if ( $repo->count() > 0 ) {
			return;
		}
		$service = wvm( PlanService::class );
		foreach ( self::launchPlans() as $plan ) {
			try {
				$service->create( $plan );
			} catch ( \Throwable $e ) {
				error_log( 'WVM seed plan failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
		}
	}

	/** Create (or repair) the Dashboard / Membership / Enterprise pages. */
	public static function pages( bool $force = false ): void {
		$settings = wvm( Settings::class );
		$pages    = array(
			'dashboard_page_id'  => array( 'dashboard', __( 'Dashboard', 'wv-members' ), '[wvm_dashboard]' ),
			'pricing_page_id'    => array( 'membership', __( 'Membership', 'wv-members' ), '[wvm_pricing]' ),
			'enterprise_page_id' => array( 'enterprise', __( 'Enterprise & Private programmes', 'wv-members' ), '[wvm_enterprise_form]' ),
		);
		foreach ( $pages as $key => [ $slug, $title, $shortcode ] ) {
			$id = (int) $settings->get( 'general', $key, 0 );
			if ( $id && 'publish' === get_post_status( $id ) && ! $force ) {
				continue;
			}
			if ( $id && 'publish' === get_post_status( $id ) ) {
				continue;
			}
			$existing = get_page_by_path( $slug );
			if ( $existing && 'publish' === $existing->post_status ) {
				if ( ! has_shortcode( (string) $existing->post_content, trim( $shortcode, '[]' ) ) ) {
					wp_update_post( array( 'ID' => $existing->ID, 'post_content' => $existing->post_content . "\n\n<!-- wp:shortcode -->\n" . $shortcode . "\n<!-- /wp:shortcode -->" ) );
				}
				$settings->set( 'general', $key, (int) $existing->ID );
				continue;
			}
			$new = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $title,
					'post_name'    => $slug,
					'post_content' => "<!-- wp:shortcode -->\n" . $shortcode . "\n<!-- /wp:shortcode -->",
				)
			);
			if ( $new && ! is_wp_error( $new ) ) {
				$settings->set( 'general', $key, (int) $new );
			}
		}
	}
}
