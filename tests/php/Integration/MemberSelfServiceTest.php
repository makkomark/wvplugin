<?php
namespace WitsValley\Members\Tests\Integration;

use WitsValley\Members\Domain\Enterprise\OrganisationService;
use WitsValley\Members\Tests\TestCase;

final class MemberSelfServiceTest extends TestCase {

	private function call( string $method, string $route, array $body = array() ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/wv-members/v1' . $route );
		if ( $body ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
		}
		return rest_do_request( $req );
	}

	public function test_email_change_sends_verification_and_confirms(): void {
		$member = $this->member();
		wp_set_current_user( (int) $member['user_id'] );
		$this->assertSame( 200, $this->call( 'POST', '/me/email-change', array( 'email' => 'new-address@example.org' ) )->get_status() );
		global $wpdb;
		$row = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}wvm_notifications WHERE template_key = 'email_change_verify' ORDER BY id DESC LIMIT 1", ARRAY_A );
		$this->assertSame( 'new-address@example.org', $row['to_email'] );
		$payload = json_decode( $row['payload'], true );
		parse_str( (string) wp_parse_url( $payload['verify_url'], PHP_URL_QUERY ), $q );
		$this->assertNotEmpty( $q['email_token'] );
		$res = $this->call( 'POST', '/me/email-change/confirm', array( 'token' => $q['email_token'] ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( 'new-address@example.org', get_userdata( (int) $member['user_id'] )->user_email );
		$this->assertSame( 400, $this->call( 'POST', '/me/email-change/confirm', array( 'token' => $q['email_token'] ) )->get_status(), 'Token is single-use.' );
	}

	public function test_org_admin_can_withdraw_pending_invite_and_seat_holder_sees_org_subscription(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$orgs  = wvm( OrganisationService::class );
		$org   = $orgs->create(
			array(
				'name'  => 'Test Org',
				'seats' => 5,
			)
		);
		$admin = $this->member();
		$orgs->invite( (int) $org['id'], array( $admin['email'] ), 'admin' );
		$seat = current( array_filter( $orgs->seatList( (int) $org['id'] ), static fn( $s ) => $s['email'] === strtolower( $admin['email'] ) ) );
		global $wpdb;
		$token = $wpdb->get_var( $wpdb->prepare( "SELECT invite_token FROM {$wpdb->prefix}wvm_organisation_members WHERE id = %d", $seat['id'] ) );
		$orgs->accept( (string) $token, (int) $admin['user_id'] );

		wp_set_current_user( (int) $admin['user_id'] );
		$me = $this->call( 'GET', '/me' )->get_data()['data'];
		$this->assertSame( (int) $org['id'], $me['org_subscription']['organisation_id'] );
		$this->assertSame( 3, $me['entitlements']['level'] );

		$this->call( 'POST', '/me/organisation/invites', array( 'emails' => array( 'pending@example.org' ) ) );
		$pending = current( array_filter( $this->call( 'GET', '/me/organisation/seats' )->get_data()['data'], static fn( $s ) => 'pending@example.org' === $s['email'] ) );
		$this->assertSame( 'invited', $pending['status'] );
		$this->assertSame( 200, $this->call( 'POST', '/me/organisation/invites/' . $pending['id'] . '/resend' )->get_status() );
		$after = current( array_filter( $this->call( 'DELETE', '/me/organisation/invites/' . $pending['id'] )->get_data()['data'], static fn( $s ) => 'pending@example.org' === $s['email'] ) );
		$this->assertSame( 'revoked', $after['status'] );
	}
}
