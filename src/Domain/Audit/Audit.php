<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Audit;

/**
 * Service-layer audit facade: resolves the current actor automatically.
 */
final class Audit {

	private static ?string $forcedActor = null;

	public function __construct( private AuditRepository $repo ) {}

	/**
	 * @param array<string,mixed>|null $before
	 * @param array<string,mixed>|null $after
	 */
	public function log( string $action, string $entityType, ?int $entityId, ?array $before = null, ?array $after = null, ?string $reason = null ): void {
		[ $type, $id ] = self::actor();
		$this->repo->record( $type, $id, $action, $entityType, $entityId, self::strip( $before ), self::strip( $after ), $reason );
	}

	/**
	 * Run $fn as the system actor (jobs, webhooks).
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public static function asSystem( callable $fn ): mixed {
		$prev              = self::$forcedActor;
		self::$forcedActor = 'system';
		try {
			return $fn();
		} finally {
			self::$forcedActor = $prev;
		}
	}

	/** @return array{0:string,1:?int} */
	public static function actor(): array {
		if ( 'system' === self::$forcedActor || doing_action( 'action_scheduler_run_queue' ) || wp_doing_cron() ) {
			return array( 'system', null );
		}
		$uid = get_current_user_id();
		if ( ! $uid ) {
			return array( 'system', null );
		}
		$is_admin = user_can( $uid, 'wvm_view_members' ) || user_can( $uid, 'manage_options' ) || user_can( $uid, 'wvm_manage_plans' ) || user_can( $uid, 'wvm_manage_workshops' ) || user_can( $uid, 'wvm_manage_content_access' );
		return array( $is_admin ? 'admin' : 'member', $uid );
	}

	/**
	 * @param array<string,mixed>|null $data
	 * @return array<string,mixed>|null
	 */
	private static function strip( ?array $data ): ?array {
		if ( null === $data ) {
			return null;
		}
		foreach ( array( 'token', 'raw_payload', 'password', 'api_key', 'pay_link_token', 'invite_token' ) as $secret ) {
			if ( array_key_exists( $secret, $data ) && null !== $data[ $secret ] ) {
				$data[ $secret ] = '[redacted]';
			}
		}
		return $data;
	}
}
