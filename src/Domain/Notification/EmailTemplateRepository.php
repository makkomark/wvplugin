<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Notification;

use WitsValley\Members\Support\Repository;

final class EmailTemplateRepository extends Repository {

	protected string $table = 'email_templates';

	protected array $casts = array( 'enabled' => 'bool' );

	/** @return array<string,mixed>|null */
	public function byKey( string $key ): ?array {
		return $this->findBy( 'template_key', $key );
	}
}
