<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Member;

use WitsValley\Members\Support\Repository;

final class MemberRepository extends Repository {

	protected string $table = 'members';

	protected array $casts = array(
		'public_id'          => 'string',
		'kit_subscriber_id'  => 'string',
		'marketing_consent'  => 'bool',
		'notification_prefs' => 'json',
		'notes_count'        => 'int',
	);

	/** @return array<string,mixed>|null */
	public function byUser( int $userId ): ?array {
		return $this->findBy( 'user_id', $userId );
	}
}
