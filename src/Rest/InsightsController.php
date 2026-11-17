<?php
declare( strict_types=1 );

namespace WitsValley\Members\Rest;

use WitsValley\Members\Domain\Insights\InsightsService;

final class InsightsController extends Controller {

	public function __construct( private InsightsService $insights ) {}

	public function routes(): void {
		$cap = 'wvm_view_insights';
		$this->route( 'GET', '/insights/summary', fn( $r ) => $this->insights->summary( (string) $r->get_param( 'currency' ) ?: null ), $cap );
		$this->route( 'GET', '/insights/(?P<metric>[a-z_]+)/export\.csv', fn( $r ) => $this->download( $this->insights->csv( (string) $r['metric'], $this->query( $r ) ), 'witsvalley-' . $r['metric'] . '-' . gmdate( 'Y-m-d' ) . '.csv', 'text/csv' ), $cap );
		$this->route( 'GET', '/insights/(?P<metric>[a-z_]+)', fn( $r ) => $this->insights->metric( (string) $r['metric'], $this->query( $r ) ), $cap );
	}
}
