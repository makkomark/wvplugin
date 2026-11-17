<?php
declare( strict_types=1 );

namespace WitsValley\Members\Rest;

use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Kit\KitClient;
use WitsValley\Members\Domain\Kit\KitSync;
use WitsValley\Members\Domain\Kit\TagMap;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Settings;

final class KitController extends Controller {

	public function __construct( private KitClient $client, private KitSync $sync, private TagMap $map, private Settings $settings ) {}

	public function routes(): void {
		$cap = 'wvm_manage_settings';
		$this->route( 'GET', '/kit/status', fn() => $this->sync->status(), array( 'any' => array( $cap, 'wvm_view_members' ) ) );
		$this->route(
			'POST',
			'/kit/test-connection',
			function () {
				delete_transient( 'wvm_kit_account' );
				if ( ! $this->client->configured() ) {
					throw DomainError::validation( array( 'api_key' => 'Add your Kit API key first.' ) );
				}
				$account = $this->client->account();
				if ( ! $account ) {
					throw new DomainError( 'Kit rejected the API key or could not be reached.', 'wvm_kit_unreachable', 502 );
				}
				return array( 'connected' => true, 'account' => $account );
			},
			$cap
		);
		$this->route( 'GET', '/kit/tags', fn() => $this->client->configured() ? array_map( static fn( $name, $id ) => array( 'id' => $id, 'name' => $name, 'owned' => TagMap::isOwned( (string) $name ) ), array_keys( $this->client->listTags( true ) ), $this->client->listTags() ) : array(), $cap );
		$this->route( 'GET', '/kit/tag-map', fn() => array( 'map' => $this->map->map(), 'defaults' => TagMap::defaults(), 'plan_tags' => $this->map->planTags(), 'states' => TagMap::STATES ), $cap );
		$this->route(
			'PUT',
			'/kit/tag-map',
			function ( $r ) {
				$in  = (array) ( $this->body( $r )['map'] ?? array() );
				$out = array();
				foreach ( TagMap::STATES as $state ) {
					if ( isset( $in[ $state ] ) ) {
						$out[ $state ] = array(
							'add'    => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $in[ $state ]['add'] ?? array() ) ) ) ),
							'remove' => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $in[ $state ]['remove'] ?? array() ) ) ) ),
						);
					}
				}
				$diff = $this->settings->update( 'kit', array( 'tag_map' => $out ) );
				wvm( Audit::class )->log( 'kit.tag_map_updated', 'settings', null, array( 'tag_map' => $diff['before']['tag_map'] ?? null ), array( 'tag_map' => $out ) );
				return array( 'map' => $this->map->map() );
			},
			$cap
		);
		$this->route( 'POST', '/kit/reconcile', fn() => array( 'queued' => $this->sync->reconcile() ), $cap );
		$this->route( 'GET', '/kit/log', fn( $r ) => $this->paged( $this->sync->logList( $this->query( $r ), $this->pagination( $r, 50 ) ) ), array( 'any' => array( $cap, 'wvm_view_members' ) ) );
	}
}
