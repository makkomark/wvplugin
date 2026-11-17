<?php
declare( strict_types=1 );

namespace WitsValley\Members\Support;

/**
 * Page/per_page normalisation and meta building.
 */
final class Pagination {

	public function __construct( public readonly int $page = 1, public readonly int $perPage = 20 ) {}

	/** @param array<string,mixed> $params */
	public static function fromParams( array $params, int $default = 20, int $max = 200 ): self {
		$page     = max( 1, (int) ( $params['page'] ?? 1 ) );
		$per_page = (int) ( $params['per_page'] ?? $default );
		$per_page = max( 1, min( $max, $per_page ) );
		return new self( $page, $per_page );
	}

	public function offset(): int {
		return ( $this->page - 1 ) * $this->perPage;
	}

	/** @return array{total:int,pages:int,page:int,per_page:int} */
	public function meta( int $total ): array {
		return array(
			'total'    => $total,
			'pages'    => (int) max( 1, ceil( $total / $this->perPage ) ),
			'page'     => $this->page,
			'per_page' => $this->perPage,
		);
	}
}
