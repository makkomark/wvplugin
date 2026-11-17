<?php
namespace WitsValley\Members\Tests\Integration;

use WitsValley\Members\Support\Db;
use WitsValley\Members\Tests\TestCase;

/** Acceptance #7 and #8. */
final class RolesAndAuditTest extends TestCase {

	private function call( string $method, string $route, array $body = array() ): \WP_REST_Response {
		$req = new \WP_REST_Request( $method, '/wv-members/v1' . $route );
		if ( $body ) {
			$req->set_header( 'Content-Type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
		}
		return rest_do_request( $req );
	}

	public function test_finance_cannot_edit_plans(): void {
		wp_set_current_user( $this->staff( 'wvm_finance' ) );
		$this->assertSame( 403, $this->call( 'PATCH', '/plans/' . $this->plan( 'basic' )['id'], array( 'name' => 'Hacked' ) )->get_status() );
		$this->assertSame( 403, $this->call( 'POST', '/plans', array( 'slug' => 'x', 'name' => 'X', 'level' => 1 ) )->get_status() );
		$this->assertSame( 200, $this->call( 'GET', '/orders' )->get_status(), 'Finance can see payments.' );
	}

	public function test_support_cannot_refund(): void {
		$p = $this->paidMember( 'basic' );
		wp_set_current_user( $this->staff( 'wvm_support' ) );
		$this->assertSame( 403, $this->call( 'POST', '/orders/' . $p['order']['id'] . '/refund', array( 'amount_minor' => 1000, 'reason' => 'x' ) )->get_status() );
		$this->assertSame( 200, $this->call( 'GET', '/members' )->get_status(), 'Support can view members.' );
	}

	public function test_content_cannot_see_payments_and_only_edits_benefits(): void {
		wp_set_current_user( $this->staff( 'wvm_content' ) );
		$this->assertSame( 403, $this->call( 'GET', '/orders' )->get_status() );
		$this->assertSame( 403, $this->call( 'GET', '/members' )->get_status() );
		$plan = $this->plan( 'basic' );
		$res  = $this->call( 'PATCH', '/plans/' . $plan['id'], array( 'benefits' => array( 'New benefit' ), 'name' => 'Renamed', 'prices' => array( array( 'currency' => 'UGX', 'amount_minor' => 1 ) ) ) );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data()['data'];
		$this->assertSame( array( 'New benefit' ), $data['benefits'] );
		$this->assertSame( 'Basic', $data['name'], 'Content role cannot rename plans.' );
		$this->assertSame( 20000, $data['prices']['UGX'], 'Content role cannot change prices.' );
	}

	public function test_anonymous_gets_401_and_member_routes_are_self_only(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->call( 'GET', '/me' )->get_status() );
		$this->assertSame( 401, $this->call( 'GET', '/members' )->get_status() );
		$a = $this->paidMember( 'basic' );
		$b = $this->paidMember( 'basic' );
		wp_set_current_user( (int) $a['member']['user_id'] );
		$this->assertSame( 403, $this->call( 'GET', '/members/' . $b['member']['id'] )->get_status() );
		$me = $this->call( 'GET', '/me' )->get_data()['data'];
		$this->assertSame( $a['member']['id'], $me['member']['id'] );
		$this->assertSame( 404, $this->call( 'GET', '/me/orders/' . $b['order']['id'] . '/receipt.pdf' )->get_status() );
	}

	public function test_admin_actions_write_audit_rows_with_reason(): void {
		wp_set_current_user( $this->staff( 'administrator' ) );
		$p = $this->paidMember( 'basic' );
		$this->call( 'PATCH', '/plans/' . $this->plan( 'premium' )['id'], array( 'prices' => array( array( 'currency' => 'UGX', 'amount_minor' => 55000 ), array( 'currency' => 'USD', 'amount_minor' => 1500 ) ) ) );
		$this->call( 'POST', '/subscriptions/' . $p['sub']['id'] . '/extend', array( 'days' => 7, 'reason' => 'Support goodwill' ) );
		$this->assertSame( 422, $this->call( 'POST', '/subscriptions/' . $p['sub']['id'] . '/suspend', array( 'reason' => '' ) )->get_status() );
		$m = $this->member();
		$this->call( 'POST', '/orders/manual', array( 'member_id' => $m['id'], 'plan_id' => $this->plan( 'basic' )['id'], 'amount_minor' => 20000, 'currency' => 'UGX', 'method' => 'Bank transfer', 'reference' => 'BANK-123' ) );
		$this->call( 'PATCH', '/settings/lifecycle', array( 'grace_days' => 10 ) );

		global $wpdb;
		$t = Db::table( 'audit_log' );
		foreach ( array( 'plan.updated', 'subscription.extended', 'order.manual_recorded', 'settings.updated' ) as $action ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE action = %s ORDER BY id DESC LIMIT 1", $action ), ARRAY_A );
			$this->assertNotNull( $row, "Audit row for {$action}" );
			$this->assertSame( 'admin', $row['actor_type'] );
		}
		$ext = $wpdb->get_row( "SELECT * FROM {$t} WHERE action = 'subscription.extended' ORDER BY id DESC LIMIT 1", ARRAY_A );
		$this->assertSame( 'Support goodwill', $ext['reason'] );
		$this->assertSame( 'active', wvm( \WitsValley\Members\Domain\Subscription\SubscriptionRepository::class )->current( (int) $m['id'] )['status'], 'Manual payment activates immediately.' );
		$audit = $this->call( 'GET', '/audit' );
		$this->assertSame( 200, $audit->get_status() );
		$this->assertArrayNotHasKey( 'error', $audit->get_data() );
	}
}
