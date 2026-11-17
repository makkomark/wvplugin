<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Notification;

use WitsValley\Members\Domain\Member\MemberRepository;
use WitsValley\Members\Support\Settings;

/**
 * One call for "tell the member": email (respecting preferences) + in-app bell.
 */
final class Notifier {

	public function __construct(
		private Mailer $mailer,
		private InAppNotifier $inApp,
		private MemberRepository $members,
		private Settings $settings
	) {}

	/** @param array<string,mixed> $payload */
	public function member( int $memberId, string $templateKey, array $payload = array(), ?string $inAppType = null, ?string $title = null, ?string $body = null, ?string $url = null ): void {
		$member = $this->members->find( $memberId );
		if ( ! $member ) {
			return;
		}
		$prefs = is_array( $member['notification_prefs'] ) ? $member['notification_prefs'] : array();
		$pref  = TemplateDefaults::preference( $templateKey );
		if ( null === $pref || ( $prefs[ $pref ] ?? true ) ) {
			$this->mailer->send( $templateKey, $memberId, $payload );
		}
		if ( $inAppType && $title && ( $prefs['in_app'] ?? true ) ) {
			$this->inApp->push( $memberId, $inAppType, $title, (string) $body, $url ?? Urls::dashboard() );
		}
	}

	public function admin( string $subject, string $body ): void {
		$to = (string) $this->settings->get( 'emails', 'admin_alerts', '' ) ?: (string) get_option( 'admin_email' );
		wp_mail( $to, '[WitsValley] ' . $subject, $body );
	}
}
