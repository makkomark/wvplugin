<?php
namespace WitsValley\Members\Tests\Integration;

use WitsValley\Members\Domain\Access\ContentGuard;
use WitsValley\Members\Domain\Access\LevelResolver;
use WitsValley\Members\Domain\Access\MediaGuard;
use WitsValley\Members\Tests\TestCase;

/** Acceptance #5: protected body absent from REST, RSS, search; media 403 without a valid token. */
final class AccessTest extends TestCase {

	private int $postId;

	private string $secret = 'SECRET-PREMIUM-PARAGRAPH';

	public function set_up(): void {
		parent::set_up();
		$this->postId = self::factory()->post->create(
			array(
				'post_title'   => 'Premium investing guide',
				'post_content' => "<p>Intro teaser paragraph.</p>\n\n<p>Second paragraph.</p>\n\n<p>{$this->secret} deep dive.</p>",
				'post_status'  => 'publish',
			)
		);
		update_post_meta( $this->postId, ContentGuard::META_LEVEL, 2 );
		wvm( ContentGuard::class )->flush();
	}

	public function test_rest_strips_protected_body_for_anonymous_and_basic(): void {
		wp_set_current_user( 0 );
		$res  = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->postId ) );
		$data = $res->get_data();
		$this->assertStringNotContainsString( $this->secret, wp_json_encode( $data ) );
		$this->assertTrue( $data['wvm_protected'] );

		$basic = $this->paidMember( 'basic' );
		wp_set_current_user( (int) $basic['member']['user_id'] );
		wvm( ContentGuard::class )->flush();
		$res = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->postId ) );
		$this->assertStringNotContainsString( $this->secret, wp_json_encode( $res->get_data() ) );
	}

	public function test_premium_member_sees_full_content(): void {
		$premium = $this->paidMember( 'premium' );
		wp_set_current_user( (int) $premium['member']['user_id'] );
		wvm( LevelResolver::class )->flush();
		wvm( ContentGuard::class )->flush();
		$res = rest_do_request( new \WP_REST_Request( 'GET', '/wp/v2/posts/' . $this->postId ) );
		$this->assertStringContainsString( $this->secret, $res->get_data()['content']['rendered'] );
	}

	public function test_rss_and_search_hide_protected_body(): void {
		wp_set_current_user( 0 );
		global $post;
		$post = get_post( $this->postId ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );
		$feed = apply_filters( 'the_content_feed', $post->post_content, 'rss2' );
		$this->assertStringNotContainsString( $this->secret, $feed );
		wp_reset_postdata();

		$this->go_to( '/?s=SECRET' );
		$ids = wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' );
		$this->assertNotContains( $this->postId, $ids );
		$req = new \WP_REST_Request( 'GET', '/wp/v2/posts' );
		$req->set_query_params( array( 'search' => 'Premium investing' ) );
		$res = rest_do_request( $req );
		$this->assertSame( 200, $res->get_status() );
		$this->assertNotContains( $this->postId, wp_list_pluck( $res->get_data(), 'id' ) );
	}

	public function test_the_content_shows_teaser_and_paywall(): void {
		wp_set_current_user( 0 );
		$this->go_to( get_permalink( $this->postId ) );
		global $post;
		$post = get_post( $this->postId ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		setup_postdata( $post );
		$html = apply_filters( 'the_content', $post->post_content );
		$this->assertStringContainsString( 'Intro teaser paragraph', $html );
		$this->assertStringContainsString( 'wvm-paywall', $html );
		$this->assertStringNotContainsString( $this->secret, $html );
		wp_reset_postdata();
	}

	public function test_term_default_level_applies(): void {
		$cat  = self::factory()->category->create( array( 'name' => 'Premium only' ) );
		update_term_meta( $cat, ContentGuard::TERM_LEVEL, 1 );
		$post = self::factory()->post->create( array( 'post_category' => array( $cat ) ) );
		wvm( ContentGuard::class )->flush();
		$this->assertSame( 1, wvm( ContentGuard::class )->ruleFor( $post )['level'] );
		$this->assertFalse( wvm( ContentGuard::class )->canView( $post, 0 ) );
	}

	public function test_media_token_validation(): void {
		$media = wvm( MediaGuard::class );
		$att   = self::factory()->attachment->create( array( 'post_mime_type' => 'image/png' ) );
		update_post_meta( $att, ContentGuard::META_LEVEL, 2 );
		$premium = $this->paidMember( 'premium' );
		$uid     = (int) $premium['member']['user_id'];
		wvm( LevelResolver::class )->flush();

		$token = $media->token( $att, $uid );
		$this->assertSame( array( $att, $uid ), $media->verify( $token ) );
		$this->assertTrue( $media->userMayAccess( $att, $uid ) );
		$this->assertNull( $media->verify( $token . 'x' ), 'Tampered token rejected.' );
		$this->assertNull( $media->verify( $media->token( $att, $uid, time() - 10 ) ), 'Expired token rejected.' );
		$this->assertFalse( $media->userMayAccess( $att, 0 ), 'Anonymous cannot access level-2 media.' );

	}
}
