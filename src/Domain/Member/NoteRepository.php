<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Member;

use WitsValley\Members\Support\Repository;

final class NoteRepository extends Repository {

	protected string $table = 'member_notes';

	protected bool $hasUpdatedAt = false;
}
