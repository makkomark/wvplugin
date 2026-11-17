<?php
declare( strict_types=1 );

namespace WitsValley\Members\Support;

/**
 * Business-rule violation. Mapped to a REST error response by the controllers.
 */
class DomainError extends \RuntimeException {

	/**
	 * @param array<string,string> $fields Field-level messages.
	 */
	public function __construct(
		string $message,
		public readonly string $errorCode = 'wvm_error',
		public readonly int $status = 400,
		public readonly array $fields = array()
	) {
		parent::__construct( $message );
	}

	public static function notFound( string $entity ): self {
		return new self( sprintf( '%s not found.', ucfirst( $entity ) ), 'wvm_not_found', 404 );
	}

	public static function forbidden( string $message = 'You are not allowed to do that.' ): self {
		return new self( $message, 'wvm_forbidden', 403 );
	}

	/** @param array<string,string> $fields */
	public static function validation( array $fields, string $message = 'Please check the highlighted fields.' ): self {
		return new self( $message, 'wvm_validation', 422, $fields );
	}

	public static function conflict( string $message, string $code = 'wvm_conflict' ): self {
		return new self( $message, $code, 409 );
	}
}
