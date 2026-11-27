<?php
declare( strict_types=1 );

namespace WitsValley\Members\Rest;

use WitsValley\Members\Domain\Popup\PopupService;
use WitsValley\Members\Support\DomainError;

final class PopupController extends Controller {

	public function __construct( private PopupService $popup ) {}

	public function routes(): void {
		$this->route(
			'POST',
			'/public/popup/event',
			function ( $r ) {
				$b     = $this->body( $r );
				$event = (string) ( $b['event'] ?? '' );
				// Sign-ups and leads are counted server-side when they actually happen.
				if ( ! in_array( $event, array( 'view', 'close', 'upgrade_click' ), true ) ) {
					throw DomainError::validation( array( 'event' => 'Unknown event.' ) );
				}
				$this->rateLimit( 'popup_event', 120 );
				$this->popup->record( $event, (string) ( $b['variant'] ?? '' ) );
				return array( 'ok' => true );
			},
			'public'
		);
		$this->route(
			'POST',
			'/public/popup/lead',
			function ( $r ) {
				$b = $this->body( $r );
				if ( ! empty( $b['website'] ) ) {
					throw DomainError::validation( array( 'website' => 'Spam detected.' ) );
				}
				$this->rateLimit( 'popup_lead', 10 );
				$this->popup->lead( (string) ( $b['name'] ?? '' ), (string) ( $b['email'] ?? '' ), (string) ( $b['variant'] ?? 'a' ) );
				return array( 'ok' => true );
			},
			'public'
		);
		$this->route( 'GET', '/popup/stats', fn( $r ) => $this->popup->stats( (int) ( $r->get_param( 'days' ) ?: 30 ) ), 'wvm_manage_settings' );
	}
}
