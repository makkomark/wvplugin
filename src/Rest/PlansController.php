<?php
declare( strict_types=1 );

namespace WitsValley\Members\Rest;

use WitsValley\Members\Admin\Capabilities;
use WitsValley\Members\Domain\Plan\PlanService;

final class PlansController extends Controller {

	public function __construct( private PlanService $plans ) {}

	public function routes(): void {
		// Reading plans is needed by every staff picker (manual payment, change plan, grant, filters).
		$read = array( 'any' => array( 'wvm_manage_plans', 'wvm_view_members', 'wvm_view_payments', 'wvm_manage_workshops', 'wvm_manage_enterprise' ) );
		$this->route( 'GET', '/plans', fn( $r ) => $this->plans->list( ! empty( $r['include_archived'] ) ), $read );
		$this->route( 'GET', '/plans/(?P<id>\d+)', fn( $r ) => $this->plans->get( $this->id( $r ) ), $read );
		$this->route( 'POST', '/plans', fn( $r ) => $this->ok( $this->plans->create( $this->body( $r ) ), null, 201 ), Capabilities::PLANS_FULL );
		$this->route( 'PATCH', '/plans/(?P<id>\d+)', fn( $r ) => $this->plans->update( $this->id( $r ), $this->body( $r ), ! current_user_can( Capabilities::PLANS_FULL ) ), 'wvm_manage_plans' );
		$this->route( 'DELETE', '/plans/(?P<id>\d+)', fn( $r ) => $this->plans->archive( $this->id( $r ) ), Capabilities::PLANS_FULL );
		$this->route( 'POST', '/plans/(?P<id>\d+)/restore', fn( $r ) => $this->plans->restore( $this->id( $r ) ), Capabilities::PLANS_FULL );
		$this->route(
			'POST',
			'/plans/reorder',
			function ( $r ) {
				$this->plans->reorder( array_map( 'intval', (array) ( $this->body( $r )['ids'] ?? array() ) ) );
				return $this->plans->list( true );
			},
			Capabilities::PLANS_FULL
		);
		$this->route( 'PUT', '/plans/(?P<id>\d+)/prices/(?P<currency>[A-Za-z]{3})', fn( $r ) => $this->plans->setPrice( $this->id( $r ), strtoupper( (string) $r['currency'] ), (int) ( $this->body( $r )['amount_minor'] ?? -1 ) ), Capabilities::PLANS_FULL );
		$this->route( 'DELETE', '/plans/(?P<id>\d+)/prices/(?P<currency>[A-Za-z]{3})', fn( $r ) => $this->plans->removePrice( $this->id( $r ), strtoupper( (string) $r['currency'] ) ), Capabilities::PLANS_FULL );
	}
}
