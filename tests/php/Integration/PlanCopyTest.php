<?php
namespace WitsValley\Members\Tests\Integration;

use WitsValley\Members\Domain\Plan\PlanRepository;
use WitsValley\Members\Domain\Plan\PlanService;
use WitsValley\Members\Install\Migrations;
use WitsValley\Members\Tests\TestCase;

final class PlanCopyTest extends TestCase {

	public function test_launch_plans_carry_witsvalley_copy(): void {
		$public = array();
		foreach ( wvm( PlanService::class )->getPublic( 'UGX' ) as $p ) {
			$public[ $p['slug'] ] = $p;
		}
		$this->assertSame( 'Begin your journey of awareness and personal transformation.', $public['free']['tagline'] );
		$this->assertSame( 'Build the foundation for personal transformation.', $public['basic']['tagline'] );
		$this->assertSame( 'Go deeper. Experience a greater level of transformation.', $public['premium']['tagline'] );
		$this->assertCount( 4, $public['free']['benefits'] );
		$this->assertCount( 5, $public['basic']['benefits'] );
		$this->assertCount( 7, $public['premium']['benefits'] );
		$this->assertSame( 20000, $public['basic']['amount_minor'] );
		$this->assertSame( 50000, $public['premium']['amount_minor'] );
	}

	public function test_migration_updates_untouched_plans_but_never_admin_edits(): void {
		$repo  = wvm( PlanRepository::class );
		$basic = $repo->findBy( 'slug', 'basic' );
		$prem  = $repo->findBy( 'slug', 'premium' );
		$repo->update( (int) $basic['id'], array( 'tagline' => '', 'description' => 'Core lessons and monthly live sessions.', 'benefits' => array( 'Old' ) ) );
		$repo->update( (int) $prem['id'], array( 'tagline' => '', 'description' => 'Our own premium wording.', 'benefits' => array( 'Custom' ) ) );

		Migrations::planCopy2026();

		$basic = $repo->findBy( 'slug', 'basic' );
		$prem  = $repo->findBy( 'slug', 'premium' );
		$this->assertSame( 'Build the foundation for personal transformation.', $basic['tagline'] );
		$this->assertContains( 'Monthly live learning sessions.', $basic['benefits'] );
		$this->assertSame( 'Our own premium wording.', $prem['description'], 'Admin-edited copy is left alone.' );
		$this->assertSame( array( 'Custom' ), $prem['benefits'] );
	}

	public function test_content_role_can_edit_tagline_only_copy_fields(): void {
		wp_set_current_user( $this->staff( 'wvm_content' ) );
		$plan = $this->plan( 'basic' );
		$req  = new \WP_REST_Request( 'PATCH', '/wv-members/v1/plans/' . $plan['id'] );
		$req->set_header( 'Content-Type', 'application/json' );
		$req->set_body( wp_json_encode( array( 'tagline' => 'New tagline', 'name' => 'Renamed' ) ) );
		$data = rest_do_request( $req )->get_data()['data'];
		$this->assertSame( 'New tagline', $data['tagline'] );
		$this->assertSame( 'Basic', $data['name'] );
	}
}
