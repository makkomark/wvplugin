<?php
declare( strict_types=1 );

namespace WitsValley\Members\Rest;

use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Tools\ToolsService;
use WitsValley\Members\Install\Seeder;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Settings;

final class SettingsController extends Controller {

	public function __construct( private Settings $settings, private ToolsService $tools ) {}

	public function routes(): void {
		$cap = 'wvm_manage_settings';
		$this->route( 'GET', '/settings', fn() => array_combine( Settings::groups(), array_map( array( $this->settings, 'publicGroup' ), Settings::groups() ) ), $cap );
		$this->route( 'GET', '/settings/(?P<group>[a-z]+)', fn( $r ) => $this->settings->publicGroup( $this->group( $r ) ), $cap );
		$this->route(
			'PATCH',
			'/settings/(?P<group>[a-z]+)',
			function ( $r ) {
				$group = $this->group( $r );
				$diff  = $this->settings->update( $group, $this->body( $r ) );
				wvm( Audit::class )->log( 'settings.updated', 'settings', null, $diff['before'], $diff['after'], $group );
				if ( 'kit' === $group ) {
					delete_transient( 'wvm_kit_account' );
					delete_transient( 'wvm_kit_tags' );
				}
				if ( 'general' === $group ) {
					flush_rewrite_rules( false );
				}
				return $this->settings->publicGroup( $group );
			},
			$cap
		);
		$this->route( 'POST', '/tools/export', fn() => $this->tools->export(), $cap );
		$this->route( 'POST', '/tools/import', fn( $r ) => $this->tools->import( (array) ( $this->body( $r )['data'] ?? $this->body( $r ) ) ), $cap );
		$this->route( 'GET', '/tools/health', fn() => $this->tools->health(), array( 'any' => array( $cap, 'wvm_access' ) ) );
		$this->route( 'POST', '/tools/backup-now', fn() => $this->tools->backup(), $cap );
		$this->route(
			'POST',
			'/tools/create-pages',
			function () {
				Seeder::pages( true );
				wvm( Audit::class )->log( 'tools.pages_created', 'settings', null );
				return $this->settings->publicGroup( 'general' );
			},
			$cap
		);
	}

	private function group( \WP_REST_Request $r ): string {
		$g = (string) $r['group'];
		if ( ! in_array( $g, Settings::groups(), true ) || 'tools' === $g && false ) {
			throw DomainError::notFound( 'settings group' );
		}
		return $g;
	}
}
