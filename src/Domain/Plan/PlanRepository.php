<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Plan;

use WitsValley\Members\Support\Repository;

final class PlanRepository extends Repository {

	protected string $table = 'plans';

	protected array $casts = array(
		'level'          => 'int',
		'interval_count' => 'int',
		'trial_days'     => 'int',
		'sort_order'     => 'int',
		'is_free'        => 'bool',
		'is_quoted'      => 'bool',
		'highlight'      => 'bool',
		'benefits'       => 'json',
	);

	/** @return list<array<string,mixed>> */
	public function all( bool $includeArchived = false ): array {
		return $includeArchived ? $this->where( array(), 'sort_order ASC, id ASC' ) : $this->where( array( 'archived_at' => null ), 'sort_order ASC, id ASC' );
	}

	/** @return array<string,mixed>|null */
	public function free(): ?array {
		return $this->first( array( 'is_free' => 1, 'archived_at' => null ), 'id ASC' );
	}
}
