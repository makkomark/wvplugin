<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Notification;

use WitsValley\Members\Domain\Member\MemberRepository;
use WitsValley\Members\Domain\Member\MemberService;
use WitsValley\Members\Domain\Order\OrderRepository;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Subscription\SubscriptionRepository;
use WitsValley\Members\Jobs\Scheduler;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Http;
use WitsValley\Members\Support\Pagination;
use WitsValley\Members\Support\Settings;

/**
 * Transactional email: queue → Action Scheduler → driver (wp_mail | postmark | sendgrid), 3 retries with backoff.
 */
final class Mailer {

	public const MAX_ATTEMPTS = 3;

	public function __construct(
		private EmailTemplateRepository $templates,
		private NotificationRepository $notifications,
		private MemberRepository $members,
		private Renderer $renderer,
		private Settings $settings,
		private Scheduler $scheduler
	) {}

	/**
	 * Queue an email. $to is a member id or an email address.
	 *
	 * @param array<string,mixed> $payload
	 */
	public function send( string $templateKey, int|string $to, array $payload = array() ): ?int {
		$template = $this->templates->byKey( $templateKey );
		if ( ! $template || ! $template['enabled'] ) {
			return null;
		}
		$member_id = is_int( $to ) ? $to : null;
		$member    = $member_id ? $this->members->find( $member_id ) : null;
		if ( $member_id && ( ! $member || $member['anonymised_at'] ) ) {
			return null;
		}
		$email = $member ? (string) get_userdata( (int) $member['user_id'] )->user_email : sanitize_email( (string) $to );
		if ( ! is_email( $email ) ) {
			return null;
		}
		$vars = $this->vars( $template, $member, $payload );
		$id   = $this->notifications->insert(
			array(
				'member_id'    => $member_id,
				'to_email'     => $email,
				'template_key' => $templateKey,
				'subject'      => mb_substr( $this->renderer->render( $template, $vars )['subject'], 0, 200 ),
				'payload'      => $vars,
				'status'       => 'queued',
				'attempts'     => 0,
			)
		);
		$this->scheduler->async( Scheduler::SEND_EMAIL, array( $id ) );
		return $id;
	}

	/** Job handler: deliver one queued notification. */
	public function deliver( int $notificationId ): void {
		$n = $this->notifications->find( $notificationId );
		if ( ! $n || 'sent' === $n['status'] ) {
			return;
		}
		$template = $this->templates->byKey( (string) $n['template_key'] );
		if ( ! $template ) {
			$this->notifications->update(
				$notificationId,
				array(
					'status' => 'failed',
					'error'  => 'Template missing',
				)
			);
			return;
		}
		$vars     = is_array( $n['payload'] ) ? $n['payload'] : array();
		$rendered = $this->renderer->render( $template, $vars );
		$attach   = array();
		if ( 'receipt' === $n['template_key'] && ! empty( $vars['order_id'] ) ) {
			try {
				$path = wvm( Receipt::class )->pdfPath( (int) $vars['order_id'] );
				if ( $path ) {
					$attach[] = $path;
				}
			} catch ( \Throwable $e ) {
				$attach = array();
			}
		}
		$error    = $this->transport( (string) $n['to_email'], $rendered, $attach, (string) $n['template_key'] );
		$attempts = (int) $n['attempts'] + 1;
		if ( null === $error ) {
			$this->notifications->update(
				$notificationId,
				array(
					'status'   => 'sent',
					'attempts' => $attempts,
					'sent_at'  => Clock::mysql(),
					'error'    => null,
				)
			);
			return;
		}
		$final = $attempts >= self::MAX_ATTEMPTS;
		$this->notifications->update(
			$notificationId,
			array(
				'status'   => $final ? 'failed' : 'queued',
				'attempts' => $attempts,
				'error'    => mb_substr( $error, 0, 1000 ),
			)
		);
		if ( ! $final ) {
			$this->scheduler->at( time() + 60 * ( 5 ** ( $attempts - 1 ) ), Scheduler::SEND_EMAIL, array( $notificationId ) );
		}
	}

	public function retry( int $notificationId ): void {
		$n = $this->notifications->findOrFail( $notificationId, 'notification' );
		$this->notifications->update(
			$notificationId,
			array(
				'status'   => 'queued',
				'attempts' => 0,
				'error'    => null,
			)
		);
		$this->scheduler->async( Scheduler::SEND_EMAIL, array( (int) $n['id'] ) );
	}

	/** Send a template immediately with sample data. */
	public function test( string $templateKey, string $to ): void {
		$template = $this->templates->byKey( $templateKey );
		if ( ! $template ) {
			throw DomainError::notFound( 'template' );
		}
		if ( ! is_email( $to ) ) {
			throw DomainError::validation( array( 'to' => 'Enter a valid email address.' ) );
		}
		$rendered            = $this->renderer->render( $template, $this->sampleVars() );
		$rendered['subject'] = '[Test] ' . $rendered['subject'];
		$error               = $this->transport( $to, $rendered, array(), $templateKey );
		if ( null !== $error ) {
			throw new DomainError( 'Test send failed: ' . $error, 'wvm_mail_failed', 502 );
		}
	}

	/**
	 * @param array<string,mixed> $template
	 * @return array{subject:string,html:string,text:string}
	 */
	public function preview( array $template ): array {
		return $this->renderer->render( $template, $this->sampleVars() );
	}

	/**
	 * @param array<string,mixed> $f
	 * @return array{items:list<array<string,mixed>>, meta:array<string,int>}
	 */
	public function list( array $f, Pagination $p ): array {
		$cond = array();
		$args = array();
		if ( ! empty( $f['status'] ) ) {
			$cond[] = 'status = %s';
			$args[] = (string) $f['status'];
		}
		if ( ! empty( $f['member'] ) ) {
			$cond[] = 'member_id = %d';
			$args[] = (int) $f['member'];
		}
		if ( ! empty( $f['template'] ) ) {
			$cond[] = 'template_key = %s';
			$args[] = (string) $f['template'];
		}
		$res          = $this->notifications->paginate( $cond, $args, $p );
		$res['items'] = array_map(
			static fn( array $n ): array => array(
				'id'           => (int) $n['id'],
				'member_id'    => $n['member_id'] ? (int) $n['member_id'] : null,
				'to_email'     => $n['to_email'],
				'template_key' => $n['template_key'],
				'subject'      => $n['subject'],
				'status'       => $n['status'],
				'attempts'     => (int) $n['attempts'],
				'error'        => $n['error'],
				'sent_at'      => Clock::iso( $n['sent_at'] ),
				'created_at'   => Clock::iso( $n['created_at'] ),
			),
			$res['items']
		);
		return $res;
	}

	/**
	 * @param array{subject:string,html:string,text:string} $msg
	 * @param list<string> $attachments
	 * @return string|null Error message or null on success.
	 */
	private function transport( string $to, array $msg, array $attachments, string $templateKey ): ?string {
		$from_name  = (string) $this->settings->get( 'emails', 'from_name', 'WitsValley' );
		$from_email = (string) $this->settings->get( 'emails', 'from_email', '' ) ?: (string) get_option( 'admin_email' );
		$driver     = (string) $this->settings->get( 'emails', 'driver', 'wp_mail' );
		$key        = (string) $this->settings->get( 'emails', 'provider_key', '' );
		$bcc        = in_array( $templateKey, (array) $this->settings->get( 'emails', 'bcc_admin_on', array() ), true ) ? ( (string) $this->settings->get( 'emails', 'admin_alerts', '' ) ?: (string) get_option( 'admin_email' ) ) : '';

		if ( 'postmark' === $driver && $key ) {
			$body = array(
				'From'          => sprintf( '%s <%s>', $from_name, $from_email ),
				'To'            => $to,
				'Subject'       => $msg['subject'],
				'HtmlBody'      => $msg['html'],
				'TextBody'      => $msg['text'],
				'MessageStream' => 'outbound',
				'Tag'           => $templateKey,
			);
			if ( $bcc ) {
				$body['Bcc'] = $bcc;
			}
			if ( $attachments ) {
				$body['Attachments'] = array_map( static fn( $p ) => array( 'Name' => basename( $p ), 'Content' => base64_encode( (string) file_get_contents( $p ) ), 'ContentType' => 'application/pdf' ), $attachments ); // phpcs:ignore
			}
			$res = Http::json( 'POST', 'https://api.postmarkapp.com/email', array( 'X-Postmark-Server-Token' => $key ), $body );
			return $res['status'] >= 200 && $res['status'] < 300 ? null : ( $res['error'] ?? 'Postmark error' ) . ' ' . mb_substr( $res['raw'], 0, 300 );
		}
		if ( 'sendgrid' === $driver && $key ) {
			$personal = array( 'to' => array( array( 'email' => $to ) ) );
			if ( $bcc ) {
				$personal['bcc'] = array( array( 'email' => $bcc ) );
			}
			$body = array(
				'personalizations' => array( $personal ),
				'from'             => array(
					'email' => $from_email,
					'name'  => $from_name,
				),
				'subject'          => $msg['subject'],
				'content'          => array(
					array(
						'type'  => 'text/plain',
						'value' => $msg['text'],
					),
					array(
						'type'  => 'text/html',
						'value' => $msg['html'],
					),
				),
				'categories'       => array( $templateKey ),
			);
			if ( $attachments ) {
				$body['attachments'] = array_map( static fn( $p ) => array( 'filename' => basename( $p ), 'content' => base64_encode( (string) file_get_contents( $p ) ), 'type' => 'application/pdf' ), $attachments ); // phpcs:ignore
			}
			$res = Http::json( 'POST', 'https://api.sendgrid.com/v3/mail/send', array( 'Authorization' => 'Bearer ' . $key ), $body );
			return $res['status'] >= 200 && $res['status'] < 300 ? null : ( $res['error'] ?? 'SendGrid error' ) . ' ' . mb_substr( $res['raw'], 0, 300 );
		}

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			sprintf( 'From: %s <%s>', $from_name, $from_email ),
		);
		if ( $bcc ) {
			$headers[] = 'Bcc: ' . $bcc;
		}
		$alt     = static function ( $phpmailer ) use ( $msg ): void {
			$phpmailer->AltBody = $msg['text']; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		};
		$error   = null;
		$capture = static function ( \WP_Error $e ) use ( &$error ): void {
			$error = $e->get_error_message();
		};
		add_action( 'phpmailer_init', $alt );
		add_action( 'wp_mail_failed', $capture );
		$ok = wp_mail( $to, $msg['subject'], $msg['html'], $headers, $attachments );
		remove_action( 'phpmailer_init', $alt );
		remove_action( 'wp_mail_failed', $capture );
		return $ok ? null : ( $error ?? 'wp_mail returned false' );
	}

	/**
	 * @param array<string,mixed>      $template
	 * @param array<string,mixed>|null $member
	 * @param array<string,mixed>      $payload
	 * @return array<string,mixed>
	 */
	private function vars( array $template, ?array $member, array $payload ): array {
		$user = $member ? get_userdata( (int) $member['user_id'] ) : null;
		$vars = array(
			'first_name'    => $user ? ( $user->first_name ?: $user->display_name ) : 'there',
			'member_id'     => $member['public_id'] ?? '',
			'dashboard_url' => Urls::dashboard(),
			'business_name' => (string) $this->settings->get( 'general', 'business_name', 'WitsValley' ),
			'support_email' => (string) $this->settings->get( 'general', 'support_email', '' ) ?: (string) get_option( 'admin_email' ),
		);
		$vars = array_merge( $vars, $payload );
		$uses = str_contains( (string) $template['body_html'] . (string) $template['subject'], '{pay_link}' );
		if ( $uses && empty( $vars['pay_link'] ) && $member ) {
			$vars['pay_link'] = $this->resolvePayLink( (int) $member['id'], isset( $payload['subscription_id'] ) ? (int) $payload['subscription_id'] : null ) ?? Urls::dashboard();
		}
		return $vars;
	}

	private function resolvePayLink( int $memberId, ?int $subId ): ?string {
		$orders = wvm( OrderRepository::class );
		if ( $subId ) {
			$sub = wvm( SubscriptionRepository::class )->find( $subId );
			if ( $sub && in_array( $sub['status'], array( 'active', 'past_due', 'suspended', 'expired' ), true ) && ! $sub['organisation_id'] ) {
				try {
					$order = wvm( OrderService::class )->ensureRenewalOrder( $sub );
					return $order['pay_link'];
				} catch ( \Throwable $e ) {
					return null;
				}
			}
		}
		$pending = $orders->first(
			array(
				'member_id' => $memberId,
				'status'    => 'pending',
			)
		);
		return $pending ? wvm( OrderService::class )->payLinkUrl( $pending ) : null;
	}

	/** @return array<string,string> */
	private function sampleVars(): array {
		return array(
			'first_name'        => 'Amina',
			'plan'              => 'Premium',
			'amount'            => 'UGX 50,000',
			'renewal_date'      => wp_date( 'j M Y', time() + 7 * DAY_IN_SECONDS ),
			'grace_end_date'    => wp_date( 'j M Y', time() + 14 * DAY_IN_SECONDS ),
			'pay_link'          => home_url( '/pay/sample-token/' ),
			'dashboard_url'     => Urls::dashboard(),
			'member_id'         => MemberService::publicId( 123 ),
			'receipt_number'    => 'WV-R-1001',
			'receipt_url'       => Urls::dashboard( 'billing' ),
			'days'              => '14',
			'reason'            => 'Goodwill credit',
			'reference'         => 'MM-889123',
			'workshop_title'    => 'Personal Finance Masterclass',
			'workshop_time'     => wp_date( 'D j M, H:i', time() + DAY_IN_SECONDS ),
			'join_url'          => Urls::dashboard( 'workshops' ),
			'meeting_url'       => Urls::dashboard( 'workshops' ),
			'consultation_time' => wp_date( 'D j M, H:i', time() + 3 * DAY_IN_SECONDS ),
			'verify_url'        => Urls::dashboard( 'profile' ),
			'device'            => 'Chrome on Android',
			'ip'                => '102.85.0.1',
			'org_name'          => 'Acme Uganda Ltd',
			'accept_url'        => home_url( '/enterprise/accept/sample/' ),
			'business_name'     => (string) $this->settings->get( 'general', 'business_name', 'WitsValley' ),
			'support_email'     => (string) $this->settings->get( 'general', 'support_email', '' ) ?: (string) get_option( 'admin_email' ),
		);
	}
}
