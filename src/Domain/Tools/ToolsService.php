<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Tools;

use WitsValley\Members\Domain\Access\MediaGuard;
use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Kit\KitSync;
use WitsValley\Members\Domain\Notification\EmailTemplateRepository;
use WitsValley\Members\Domain\Notification\Notifier;
use WitsValley\Members\Domain\Plan\PlanPriceRepository;
use WitsValley\Members\Domain\Plan\PlanRepository;
use WitsValley\Members\Install\Schema;
use WitsValley\Members\Jobs\Scheduler;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Settings;

/**
 * Export/import of configuration, health checks + alerts, table backups (local + optional S3-compatible).
 */
final class ToolsService {

	public function __construct(
		private Settings $settings,
		private PlanRepository $plans,
		private PlanPriceRepository $prices,
		private EmailTemplateRepository $templates,
		private Scheduler $scheduler,
		private Audit $audit
	) {}

	/** @return array<string,mixed> settings (secrets excluded) + plans + templates */
	public function export(): array {
		$settings = array();
		foreach ( Settings::groups() as $group ) {
			$values = $this->settings->group( $group );
			foreach ( Settings::SECRETS[ $group ] ?? array() as $secret ) {
				unset( $values[ $secret ] );
			}
			$settings[ $group ] = $values;
		}
		$plans = array();
		foreach ( $this->plans->all( true ) as $p ) {
			$p['prices'] = $this->prices->forPlan( (int) $p['id'] );
			unset( $p['woo_product_id'] );
			$plans[] = $p;
		}
		$templates = array_map(
			static fn( $t ) => array_intersect_key( $t, array_flip( array( 'template_key', 'name', 'subject', 'preview', 'body_html', 'body_text', 'enabled' ) ) ),
			$this->templates->where( array(), 'template_key ASC' )
		);
		$this->audit->log( 'tools.exported', 'settings', null );
		return array(
			'format'      => 'wv-members-config',
			'version'     => WVM_VERSION,
			'exported_at' => gmdate( 'c' ),
			'settings'    => $settings,
			'plans'       => $plans,
			'templates'   => array_values( $templates ),
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,int>
	 */
	public function import( array $data ): array {
		if ( ( $data['format'] ?? '' ) !== 'wv-members-config' ) {
			throw DomainError::validation( array( 'file' => 'This is not a WitsValley Members export file.' ) );
		}
		$counts = array(
			'settings'  => 0,
			'plans'     => 0,
			'templates' => 0,
		);
		foreach ( (array) ( $data['settings'] ?? array() ) as $group => $values ) {
			if ( in_array( $group, Settings::groups(), true ) && is_array( $values ) ) {
				foreach ( Settings::SECRETS[ $group ] ?? array() as $secret ) {
					unset( $values[ $secret ] );
				}
				$this->settings->update( (string) $group, $values );
				++$counts['settings'];
			}
		}
		$service = wvm( \WitsValley\Members\Domain\Plan\PlanService::class );
		foreach ( (array) ( $data['plans'] ?? array() ) as $p ) {
			$existing = $this->plans->findBy( 'slug', (string) ( $p['slug'] ?? '' ) );
			$prices   = array();
			foreach ( (array) ( $p['prices'] ?? array() ) as $cur => $amt ) {
				$prices[] = array(
					'currency'     => $cur,
					'amount_minor' => (int) $amt,
				);
			}
			$dto = array(
				'slug'           => $p['slug'] ?? '',
				'name'           => $p['name'] ?? '',
				'description'    => $p['description'] ?? '',
				'level'          => (int) ( $p['level'] ?? 0 ),
				'interval'       => $p['interval_unit'] ?? 'monthly',
				'interval_count' => (int) ( $p['interval_count'] ?? 1 ),
				'trial_days'     => (int) ( $p['trial_days'] ?? 0 ),
				'kit_tag'        => $p['kit_tag'] ?? '',
				'visibility'     => $p['visibility'] ?? 'public',
				'benefits'       => (array) ( $p['benefits'] ?? array() ),
				'is_quoted'      => ! empty( $p['is_quoted'] ),
				'highlight'      => ! empty( $p['highlight'] ),
				'prices'         => $prices,
			);
			if ( $existing ) {
				unset( $dto['slug'] );
				$service->update( (int) $existing['id'], $dto );
			} else {
				$dto['is_free'] = ! empty( $p['is_free'] );
				$service->create( $dto );
			}
			++$counts['plans'];
		}
		foreach ( (array) ( $data['templates'] ?? array() ) as $t ) {
			$row = $this->templates->byKey( (string) ( $t['template_key'] ?? '' ) );
			if ( $row ) {
				$this->templates->update(
					(int) $row['id'],
					array(
						'subject'   => (string) $t['subject'],
						'preview'   => (string) ( $t['preview'] ?? '' ),
						'body_html' => wp_kses_post( (string) $t['body_html'] ),
						'body_text' => (string) ( $t['body_text'] ?? '' ),
						'enabled'   => ! empty( $t['enabled'] ),
					)
				);
				++$counts['templates'];
			}
		}
		$this->audit->log( 'tools.imported', 'settings', null, null, $counts );
		return $counts;
	}

	/** @return array<string,mixed> */
	public function health(): array {
		global $wpdb;
		$checks = array();
		$add    = static function ( string $key, string $label, string $status, string $detail, ?string $action = null ) use ( &$checks ): void {
			$checks[] = compact( 'key', 'label', 'status', 'detail', 'action' );
		};

		$add( 'woocommerce', 'WooCommerce', class_exists( 'WooCommerce' ) ? 'ok' : 'error', class_exists( 'WooCommerce' ) ? 'Version ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ) : 'WooCommerce is not active.' );
		$add( 'action_scheduler', 'Background jobs', function_exists( 'as_schedule_single_action' ) ? 'ok' : 'error', function_exists( 'as_schedule_single_action' ) ? $this->scheduler->pendingCount() . ' pending jobs' : 'Action Scheduler unavailable.' );
		$failed = $this->scheduler->failedCount();
		$add( 'failed_jobs', 'Failed jobs (24h)', $failed > 10 ? 'error' : ( $failed > 0 ? 'warning' : 'ok' ), $failed . ' failed', admin_url( 'admin.php?page=wc-status&tab=action-scheduler&status=failed&s=wvm_' ) );

		$missing = array();
		foreach ( Schema::tables() as $t ) {
			if ( Db::table( $t ) !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Db::table( $t ) ) ) ) { // phpcs:ignore WordPress.DB
				$missing[] = $t;
			}
		}
		$add( 'schema', 'Database tables', $missing ? 'error' : 'ok', $missing ? 'Missing: ' . implode( ', ', $missing ) : count( Schema::tables() ) . ' tables present (v' . get_option( 'wvm_db_version' ) . ')' );

		$last_hook = $wpdb->get_var( 'SELECT MAX(created_at) FROM ' . Db::table( 'hook_inbox' ) ); // phpcs:ignore WordPress.DB
		$pending   = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::table( 'orders' ) . " WHERE status = 'pending' AND woo_order_id IS NOT NULL" ); // phpcs:ignore WordPress.DB
		$silent    = $last_hook ? ( time() - (int) strtotime( $last_hook . ' UTC' ) ) / HOUR_IN_SECONDS : null;
		$add( 'webhooks', 'Payment events', ( null !== $silent && $silent > 48 && $pending > 0 ) ? 'warning' : 'ok', $last_hook ? sprintf( 'Last event %s ago', human_time_diff( (int) strtotime( $last_hook . ' UTC' ) ) ) : 'No payment events yet' );

		$kit_errors = wvm( KitSync::class )->errorCount();
		$kit_cfg    = '' !== (string) $this->settings->get( 'kit', 'api_key', '' );
		$add( 'kit', 'Kit sync', ! $kit_cfg ? 'warning' : ( $kit_errors > 5 ? 'error' : 'ok' ), ! $kit_cfg ? 'Kit API key not set.' : $kit_errors . ' errors in 24h', admin_url( 'admin.php?page=wvm-settings#/settings/kit' ) );

		$sandbox = (bool) $this->settings->get( 'payments', 'sandbox_mode', false );
		$add( 'sandbox', 'Payment mode', $sandbox ? 'warning' : 'ok', $sandbox ? 'Sandbox mode is ON — real payments are not processed.' : 'Live', admin_url( 'admin.php?page=wvm-settings#/settings/payments' ) );

		$coming = 'yes' === get_option( 'woocommerce_coming_soon' );
		$add( 'coming_soon', 'WooCommerce visibility', $coming ? 'warning' : 'ok', $coming ? 'Store is in "Coming soon" mode. Membership checkout still works, but switch it to Live before launch.' : 'Live', admin_url( 'admin.php?page=wc-settings&tab=site-visibility' ) );

		$review = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::table( 'orders' ) . " WHERE status = 'needs_review'" ); // phpcs:ignore WordPress.DB
		$add( 'review_queue', 'Payments needing review', $review > 0 ? 'warning' : 'ok', $review . ' in queue', admin_url( 'admin.php?page=wvm-payments#/payments/review' ) );

		$mail_failed = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . Db::table( 'notifications' ) . " WHERE status = 'failed' AND created_at >= %s", Clock::mysql( Clock::now()->modify( '-1 day' ) ) ) ); // phpcs:ignore WordPress.DB
		$add( 'email', 'Email delivery (24h)', $mail_failed > 0 ? 'warning' : 'ok', $mail_failed . ' failed', admin_url( 'admin.php?page=wvm-settings#/settings/notifications' ) );

		$key = defined( 'WVM_ENCRYPTION_KEY' );
		$add( 'encryption', 'Encryption key', $key ? 'ok' : 'warning', $key ? 'WVM_ENCRYPTION_KEY defined' : 'Using a key derived from WordPress salts. Define WVM_ENCRYPTION_KEY in wp-config.php.' );

		$last_backup = $this->settings->get( 'tools', 'last_backup_at' );
		$add( 'backup', 'Daily backup', $last_backup && strtotime( (string) $last_backup . ' UTC' ) > time() - 2 * DAY_IN_SECONDS ? 'ok' : 'warning', $last_backup ? 'Last backup ' . human_time_diff( (int) strtotime( (string) $last_backup . ' UTC' ) ) . ' ago' : 'No backup yet' );

		$pages = array();
		foreach ( array(
			'dashboard_page_id'  => 'Dashboard',
			'pricing_page_id'    => 'Membership',
			'enterprise_page_id' => 'Enterprise',
		) as $k => $label ) {
			$id = (int) $this->settings->get( 'general', $k, 0 );
			if ( ! $id || 'publish' !== get_post_status( $id ) ) {
				$pages[] = $label;
			}
		}
		$add( 'pages', 'Plugin pages', $pages ? 'error' : 'ok', $pages ? 'Missing: ' . implode( ', ', $pages ) : 'Dashboard, Membership and Enterprise pages published', admin_url( 'admin.php?page=wvm-settings#/settings/general' ) );

		$worst = 'ok';
		foreach ( $checks as $c ) {
			if ( 'error' === $c['status'] ) {
				$worst = 'error';
			} elseif ( 'warning' === $c['status'] && 'ok' === $worst ) {
				$worst = 'warning';
			}
		}
		return array(
			'status' => $worst,
			'checks' => $checks,
		);
	}

	/** Daily health alerts: webhook silence > 48h, failed jobs > 10, Kit errors > 5. */
	public function alerts(): array {
		$health = $this->health();
		$sent   = (array) $this->settings->get( 'tools', 'alerts_last_sent', array() );
		$fire   = array();
		foreach ( $health['checks'] as $c ) {
			$trigger = ( 'webhooks' === $c['key'] && 'ok' !== $c['status'] ) || ( 'failed_jobs' === $c['key'] && 'error' === $c['status'] ) || ( 'kit' === $c['key'] && 'error' === $c['status'] );
			if ( $trigger && ( empty( $sent[ $c['key'] ] ) || (int) $sent[ $c['key'] ] < time() - DAY_IN_SECONDS + 300 ) ) {
				$fire[]            = $c;
				$sent[ $c['key'] ] = time();
			}
		}
		if ( $fire ) {
			$body = "WitsValley Members health alert:\n\n";
			foreach ( $fire as $c ) {
				$body .= sprintf( "• %s: %s\n", $c['label'], $c['detail'] );
			}
			$body .= "\nOpen the admin: " . admin_url( 'admin.php?page=wvm' );
			wvm( Notifier::class )->admin( 'Health alert', $body );
			$this->settings->set( 'tools', 'alerts_last_sent', $sent );
		}
		return $fire;
	}

	/** Export all plugin tables to a gzipped JSON file in the protected directory (+ optional S3 upload). */
	public function backup(): array {
		global $wpdb;
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( (string) $uploads['basedir'] ) . MediaGuard::DIR . '/backups';
		wp_mkdir_p( $dir );
		MediaGuard::protectDirectory( dirname( $dir ) );
		$data = array(
			'generated_at' => gmdate( 'c' ),
			'version'      => WVM_VERSION,
			'tables'       => array(),
		);
		foreach ( Schema::tables() as $t ) {
			$data['tables'][ $t ] = $wpdb->get_results( 'SELECT * FROM ' . Db::table( $t ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB
		}
		$file = $dir . '/wvm-backup-' . gmdate( 'Ymd-His' ) . '.json.gz';
		file_put_contents( $file, gzencode( (string) wp_json_encode( $data ), 6 ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$keep = (int) $this->settings->get( 'tools', 'backup_keep_days', 14 );
		foreach ( glob( $dir . '/wvm-backup-*.json.gz' ) ?: array() as $old ) {
			if ( filemtime( $old ) < time() - $keep * DAY_IN_SECONDS ) {
				wp_delete_file( $old );
			}
		}
		$s3 = $this->uploadS3( $file );
		$this->settings->set( 'tools', 'last_backup_at', Clock::mysql() );
		$this->audit->log(
			'tools.backup',
			'settings',
			null,
			null,
			array(
				'file'  => basename( $file ),
				'bytes' => filesize( $file ),
				's3'    => $s3,
			)
		);
		return array(
			'file'  => basename( $file ),
			'bytes' => (int) filesize( $file ),
			's3'    => $s3,
		);
	}

	/** Minimal AWS SigV4 PUT to an S3-compatible endpoint. */
	private function uploadS3( string $file ): ?string {
		$t = $this->settings->group( 'tools' );
		if ( empty( $t['s3_endpoint'] ) || empty( $t['s3_bucket'] ) || empty( $t['s3_key'] ) || empty( $t['s3_secret'] ) ) {
			return null;
		}
		$endpoint = rtrim( (string) $t['s3_endpoint'], '/' );
		$host     = (string) wp_parse_url( $endpoint, PHP_URL_HOST );
		$region   = (string) ( $t['s3_region'] ?: 'us-east-1' );
		$key      = 'wv-members/' . basename( $file );
		$path     = '/' . rawurlencode( (string) $t['s3_bucket'] ) . '/' . str_replace( '%2F', '/', rawurlencode( $key ) );
		$body     = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$hash     = hash( 'sha256', $body );
		$amz      = gmdate( 'Ymd\THis\Z' );
		$date     = gmdate( 'Ymd' );
		$headers  = "host:{$host}\nx-amz-content-sha256:{$hash}\nx-amz-date:{$amz}\n";
		$signed   = 'host;x-amz-content-sha256;x-amz-date';
		$canon    = "PUT\n{$path}\n\n{$headers}\n{$signed}\n{$hash}";
		$scope    = "{$date}/{$region}/s3/aws4_request";
		$sts      = "AWS4-HMAC-SHA256\n{$amz}\n{$scope}\n" . hash( 'sha256', $canon );
		$k        = hash_hmac( 'sha256', 's3', hash_hmac( 'sha256', $region, hash_hmac( 'sha256', $date, 'AWS4' . $t['s3_secret'], true ), true ), true );
		$k        = hash_hmac( 'sha256', 'aws4_request', $k, true );
		$sig      = hash_hmac( 'sha256', $sts, $k );
		$res      = wp_remote_request(
			$endpoint . $path,
			array(
				'method'  => 'PUT',
				'timeout' => 60,
				'body'    => $body,
				'headers' => array(
					'x-amz-content-sha256' => $hash,
					'x-amz-date'           => $amz,
					'Authorization'        => "AWS4-HMAC-SHA256 Credential={$t['s3_key']}/{$scope}, SignedHeaders={$signed}, Signature={$sig}",
					'Content-Type'         => 'application/gzip',
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return 'error: ' . $res->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		return $code >= 200 && $code < 300 ? 'uploaded' : 'error: HTTP ' . $code;
	}
}
