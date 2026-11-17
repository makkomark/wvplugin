<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Audit;

use WitsValley\Members\Support\Repository;

/**
 * Append-only audit log. No update or delete methods are exposed.
 */
final class AuditRepository extends Repository {

	protected string $table = 'audit_log';

	protected bool $hasUpdatedAt = false;

	protected array $casts = array(
		'before_data' => 'json',
		'after_data'  => 'json',
	);

	/**
	 * @param array<string,mixed>|null $before
	 * @param array<string,mixed>|null $after
	 */
	public function record( string $actorType, ?int $actorId, string $action, string $entityType, ?int $entityId, ?array $before = null, ?array $after = null, ?string $reason = null ): int {
		return $this->insert(
			array(
				'actor_type'  => $actorType,
				'actor_id'    => $actorId,
				'action'      => $action,
				'entity_type' => $entityType,
				'entity_id'   => $entityId,
				'before_data' => $before,
				'after_data'  => $after,
				'reason'      => $reason ? mb_substr( $reason, 0, 255 ) : null,
				'ip'          => self::ip(),
			)
		);
	}

	public function update( int $id, array $data ): void {
		throw new \LogicException( 'Audit log is append-only.' );
	}

	public function delete( int $id ): void {
		throw new \LogicException( 'Audit log is append-only.' );
	}

	private static function ip(): ?string {
		if ( defined( 'WP_CLI' ) || ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return null;
		}
		$ip = sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : null;
	}
}
