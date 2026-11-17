<?php
declare( strict_types=1 );

namespace WitsValley\Members\Rest;

use WitsValley\Members\Domain\Audit\AuditService;

/**
 * Read-only: the audit log has no update or delete endpoints.
 */
final class AuditController extends Controller {

	public function __construct( private AuditService $audit ) {}

	public function routes(): void {
		$this->route( 'GET', '/audit', fn( $r ) => $this->paged( $this->audit->list( $this->query( $r ), $this->pagination( $r, 50 ) ) ), 'wvm_view_audit' );
		$this->route( 'GET', '/audit/export\.csv', fn( $r ) => $this->download( $this->audit->csv( $this->query( $r ) ), 'witsvalley-audit-' . gmdate( 'Y-m-d' ) . '.csv', 'text/csv' ), 'wvm_view_audit' );
	}
}
