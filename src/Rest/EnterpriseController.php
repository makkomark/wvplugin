<?php
declare( strict_types=1 );

namespace WitsValley\Members\Rest;

use WitsValley\Members\Domain\Enterprise\ApplicationService;
use WitsValley\Members\Domain\Enterprise\OrganisationService;

final class EnterpriseController extends Controller {

	public function __construct( private ApplicationService $apps, private OrganisationService $orgs ) {}

	public function routes(): void {
		$cap = 'wvm_manage_enterprise';
		$this->route( 'GET', '/applications', fn( $r ) => $this->paged( $this->apps->list( $this->query( $r ), $this->pagination( $r, 50 ) ) ), $cap );
		$this->route( 'GET', '/applications/(?P<id>\d+)', fn( $r ) => $this->apps->get( $this->id( $r ) ), $cap );
		$this->route( 'PATCH', '/applications/(?P<id>\d+)', fn( $r ) => $this->apps->update( $this->id( $r ), $this->body( $r ) ), $cap );
		$this->route( 'POST', '/applications/(?P<id>\d+)/convert', fn( $r ) => $this->ok( $this->apps->convertToOrganisation( $this->id( $r ), $this->body( $r ) ), null, 201 ), $cap );

		$this->route( 'GET', '/organisations', fn( $r ) => $this->paged( $this->orgs->list( $this->query( $r ), $this->pagination( $r, 50 ) ) ), $cap );
		$this->route( 'GET', '/organisations/(?P<id>\d+)', fn( $r ) => $this->orgs->detail( $this->id( $r ) ), $cap );
		$this->route( 'POST', '/organisations', fn( $r ) => $this->ok( $this->orgs->create( $this->body( $r ) ), null, 201 ), $cap );
		$this->route( 'PATCH', '/organisations/(?P<id>\d+)', fn( $r ) => $this->orgs->update( $this->id( $r ), $this->body( $r ) ), $cap );
		$this->route( 'POST', '/organisations/(?P<id>\d+)/suspend', fn( $r ) => $this->orgs->suspend( $this->id( $r ), (string) ( $this->body( $r )['reason'] ?? '' ) ), $cap );
		$this->route( 'POST', '/organisations/(?P<id>\d+)/reactivate', fn( $r ) => $this->orgs->reactivate( $this->id( $r ), (string) ( $this->body( $r )['reason'] ?? '' ) ), $cap );
		$this->route( 'POST', '/organisations/(?P<id>\d+)/renew', fn( $r ) => $this->orgs->renewTerm( $this->id( $r ), (string) ( $this->body( $r )['term_end'] ?? '' ), (string) ( $this->body( $r )['reason'] ?? '' ) ), $cap );
		$this->route(
			'POST',
			'/organisations/(?P<id>\d+)/invites',
			function ( $r ) {
				$b      = $this->body( $r );
				$emails = isset( $b['csv'] ) ? MeController::emailsFromCsv( (string) $b['csv'] ) : array_map( 'strval', (array) ( $b['emails'] ?? array() ) );
				return $this->orgs->invite( $this->id( $r ), $emails, (string) ( $b['role'] ?? 'member' ) );
			},
			$cap
		);
		$this->route(
			'POST',
			'/organisations/(?P<id>\d+)/invites/(?P<inviteId>\d+)/resend',
			function ( $r ) {
				$this->orgs->resendInvite( $this->id( $r ), $this->id( $r, 'inviteId' ) );
				return $this->orgs->seatList( $this->id( $r ) );
			},
			$cap
		);
		$this->route(
			'DELETE',
			'/organisations/(?P<id>\d+)/seats/(?P<memberId>\d+)',
			function ( $r ) {
				$this->orgs->revoke( $this->id( $r ), $this->id( $r, 'memberId' ), (string) ( $r->get_param( 'reason' ) ?: 'Seat revoked by admin' ) );
				return $this->orgs->seatList( $this->id( $r ) );
			},
			$cap
		);
		$this->route( 'PUT', '/organisations/(?P<id>\d+)/content', fn( $r ) => $this->orgs->assignContent( $this->id( $r ), array_map( 'intval', (array) ( $this->body( $r )['post_ids'] ?? array() ) ) ), $cap );
		$this->route(
			'POST',
			'/organisations/(?P<id>\d+)/invoice-order',
			function ( $r ) {
				$b = $this->body( $r );
				return $this->ok( $this->orgs->invoiceOrder( $this->id( $r ), (int) ( $b['amount_minor'] ?? 0 ), (string) ( $b['currency'] ?? 'UGX' ), sanitize_text_field( (string) ( $b['description'] ?? '' ) ) ), null, 201 );
			},
			array( 'any' => array( $cap ) )
		);
	}
}
