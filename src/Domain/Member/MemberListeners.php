<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Member;

use WitsValley\Members\Domain\Notification\Mailer;
use WitsValley\Members\Domain\Notification\Notifier;
use WitsValley\Members\Domain\Notification\Urls;

/**
 * Side effects of member self-service actions (verification and privacy emails).
 */
final class MemberListeners {

	public function register(): void {
		add_action( 'wvm/member/email_change_requested', array( $this, 'sendEmailVerification' ), 10, 3 );
		add_action( 'wvm/member/data_export_requested', array( $this, 'notifyExportRequest' ), 10, 2 );
	}

	/**
	 * Email the NEW address a one-click link; the dashboard confirms it via POST /me/email-change/confirm.
	 *
	 * @param array<string,mixed> $member
	 */
	public function sendEmailVerification( array $member, string $newEmail, string $token ): void {
		wvm( Mailer::class )->send(
			'email_change_verify',
			$newEmail,
			array(
				'first_name' => (string) $member['first_name'],
				'member_id'  => (string) $member['public_id'],
				'verify_url' => add_query_arg( 'email_token', rawurlencode( $token ), Urls::dashboard() ),
			)
		);
	}

	/**
	 * Personal data exports use the core WordPress tool; tell the team a request is waiting.
	 *
	 * @param array<string,mixed> $member
	 */
	public function notifyExportRequest( array $member, int $requestId ): void {
		wvm( Notifier::class )->admin(
			'Personal data export requested',
			sprintf(
				"%s (%s, %s) asked for a copy of their data (request #%d).\n\nGenerate and send it from Tools › Export Personal Data: %s",
				$member['name'],
				$member['public_id'],
				$member['email'],
				$requestId,
				admin_url( 'export-personal-data.php' )
			)
		);
	}
}
