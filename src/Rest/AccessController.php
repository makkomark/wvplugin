<?php
declare( strict_types=1 );

namespace WitsValley\Members\Rest;

use WitsValley\Members\Domain\Access\AccessService;
use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Support\Settings;

final class AccessController extends Controller {

	public function __construct( private AccessService $access, private Settings $settings ) {}

	public function routes(): void {
		$cap = 'wvm_manage_content_access';
		$this->route( 'GET', '/access/rules', fn( $r ) => $this->paged( $this->access->rules( $this->query( $r ), $this->pagination( $r ) ) ), $cap );
		$this->route( 'GET', '/access/posts', fn( $r ) => $this->access->searchPosts( (string) $r->get_param( 'q' ) ), array( 'any' => array( $cap, 'wvm_manage_enterprise' ) ) );
		$this->route( 'PATCH', '/access/posts/(?P<postId>\d+)', fn( $r ) => $this->access->updatePost( $this->id( $r, 'postId' ), $this->body( $r ) ), $cap );
		$this->route( 'GET', '/access/terms', fn() => $this->access->terms(), $cap );
		$this->route( 'PATCH', '/access/terms/(?P<termId>\d+)', fn( $r ) => $this->access->updateTerm( $this->id( $r, 'termId' ), (int) ( $this->body( $r )['level'] ?? 0 ) ), $cap );
		$this->route( 'GET', '/access/settings', fn() => $this->settings->publicGroup( 'access' ), $cap );
		$this->route(
			'PATCH',
			'/access/settings',
			function ( $r ) {
				$b     = array_intersect_key( $this->body( $r ), array_flip( array( 'default_level', 'teaser_paragraphs', 'paywall_message', 'mode', 'post_types' ) ) );
				if ( isset( $b['mode'] ) && ! in_array( $b['mode'], array( 'teaser', 'redirect', 'login' ), true ) ) {
					unset( $b['mode'] );
				}
				$diff = $this->settings->update( 'access', $b );
				wvm( Audit::class )->log( 'settings.updated', 'settings', null, $diff['before'], $diff['after'], 'access' );
				return $this->settings->publicGroup( 'access' );
			},
			$cap
		);
		$this->route( 'GET', '/access/check', fn( $r ) => $this->access->check( (int) $r->get_param( 'post_id' ), (int) $r->get_param( 'user_id' ) ), $cap );
	}
}
