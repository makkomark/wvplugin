<?php
declare( strict_types=1 );

namespace WitsValley\Members\Rest;

use WitsValley\Members\Domain\Workshop\ConsultationService;
use WitsValley\Members\Domain\Workshop\WorkshopService;

final class WorkshopsController extends Controller {

	public function __construct( private WorkshopService $workshops, private ConsultationService $consultations ) {}

	public function routes(): void {
		$cap = 'wvm_manage_workshops';
		$this->route( 'GET', '/workshops', fn( $r ) => $this->paged( $this->workshops->list( $this->query( $r ), $this->pagination( $r, 50 ) ) ), $cap );
		$this->route( 'GET', '/workshops/(?P<id>\d+)', fn( $r ) => $this->workshops->get( $this->id( $r ) ), $cap );
		$this->route( 'POST', '/workshops', fn( $r ) => $this->ok( $this->workshops->create( $this->body( $r ) ), null, 201 ), $cap );
		$this->route( 'PATCH', '/workshops/(?P<id>\d+)', fn( $r ) => $this->workshops->update( $this->id( $r ), $this->body( $r ) ), $cap );
		$this->route( 'DELETE', '/workshops/(?P<id>\d+)', fn( $r ) => $this->workshops->delete( $this->id( $r ) ), $cap );
		$this->route( 'POST', '/workshops/(?P<id>\d+)/publish', fn( $r ) => $this->workshops->publish( $this->id( $r ) ), $cap );
		$this->route( 'POST', '/workshops/(?P<id>\d+)/cancel', fn( $r ) => $this->workshops->cancel( $this->id( $r ), (string) ( $this->body( $r )['reason'] ?? '' ) ), $cap );
		$this->route( 'POST', '/workshops/(?P<id>\d+)/recording', fn( $r ) => $this->workshops->attachRecording( $this->id( $r ), (string) ( $this->body( $r )['url'] ?? '' ) ), $cap );
		$this->route( 'POST', '/workshops/(?P<id>\d+)/notify', fn( $r ) => array( 'recipients' => $this->workshops->notifyEligible( $this->id( $r ), sanitize_key( (string) ( $this->body( $r )['template_key'] ?? 'workshop_24h' ) ) ) ), $cap );
		$this->route( 'GET', '/consultations', fn( $r ) => $this->paged( $this->consultations->list( $this->query( $r ), $this->pagination( $r, 50 ) ) ), $cap );
		$this->route( 'GET', '/consultations/(?P<id>\d+)', fn( $r ) => $this->consultations->get( $this->id( $r ) ), $cap );
		$this->route( 'PATCH', '/consultations/(?P<id>\d+)', fn( $r ) => $this->consultations->update( $this->id( $r ), $this->body( $r ) ), $cap );
	}
}
