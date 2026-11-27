<?php
declare( strict_types=1 );

namespace WitsValley\Members\Jobs;

use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Enterprise\OrganisationService;
use WitsValley\Members\Domain\Kit\KitSync;
use WitsValley\Members\Domain\Notification\Mailer;
use WitsValley\Members\Domain\Order\OrderRepository;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Subscription\Dunning;
use WitsValley\Members\Domain\Tools\ToolsService;
use WitsValley\Members\Domain\Workshop\WorkshopService;
use WitsValley\Members\Support\Settings;
use WitsValley\Members\Woo\TestGateway;

/**
 * Action Scheduler job handlers. All run as the "system" actor and are idempotent.
 */
final class Handlers {

	public function register(): void {
		$map = array(
			Scheduler::REMINDER          => array( 2, fn( $id, $days ) => wvm( Dunning::class )->reminder( (int) $id, (int) $days ) ),
			Scheduler::RENEWAL_DUE       => array( 1, fn( $id ) => wvm( Dunning::class )->renewalDue( (int) $id ) ),
			Scheduler::END_GRACE         => array( 1, fn( $id ) => wvm( Dunning::class )->endGrace( (int) $id ) ),
			Scheduler::EXPIRE            => array( 1, fn( $id ) => wvm( Dunning::class )->expire( (int) $id ) ),
			Scheduler::RETRY_AUTOCHARGE  => array( 2, fn( $id, $attempt ) => wvm( Dunning::class )->retryAutocharge( (int) $id, (int) $attempt ) ),
			Scheduler::EXPIRE_CANCELLED  => array( 1, fn( $id ) => wvm( Dunning::class )->expireCancelled( (int) $id ) ),
			Scheduler::CANCEL_STALE      => array( 0, fn() => wvm( OrderService::class )->cancelStale() ),
			Scheduler::DAILY_RECONCILE   => array( 0, fn() => wvm( Dunning::class )->reconcile() ),
			Scheduler::SEND_EMAIL        => array( 1, fn( $id ) => wvm( Mailer::class )->deliver( (int) $id ) ),
			Scheduler::KIT_SYNC          => array( 1, fn( $id ) => wvm( KitSync::class )->syncMember( (int) $id ) ),
			Scheduler::KIT_TAG           => array( 2, fn( $id, $tag ) => wvm( KitSync::class )->addTag( (int) $id, (string) $tag ) ),
			Scheduler::KIT_RECONCILE     => array( 0, fn() => wvm( KitSync::class )->reconcile() ),
			Scheduler::WORKSHOP_REMINDER => array( 2, fn( $id, $key ) => wvm( WorkshopService::class )->sendReminder( (int) $id, (string) $key ) ),
			Scheduler::HEALTH            => array( 0, fn() => wvm( ToolsService::class )->alerts() ),
			Scheduler::BACKUP            => array( 0, fn() => wvm( Settings::class )->get( 'tools', 'backup_enabled', true ) ? wvm( ToolsService::class )->backup() : null ),
			Scheduler::ORG_EXPIRE        => array( 0, fn() => wvm( OrganisationService::class )->expireEnded() ),
			Scheduler::TEST_GATEWAY      => array( 1, fn( $id ) => TestGateway::completeAsync( (int) $id ) ),
			Scheduler::PENDING_NUDGE     => array( 1, fn( $id ) => $this->pendingNudge( (int) $id ) ),
			Scheduler::KIT_LEAD          => array( 2, fn( $email, $name ) => wvm( \WitsValley\Members\Domain\Popup\PopupService::class )->deliverLead( (string) $email, (string) $name ) ),
		);
		foreach ( $map as $hook => [ $args, $fn ] ) {
			add_action(
				$hook,
				static function ( ...$a ) use ( $fn ): void {
					Audit::asSystem( static fn() => $fn( ...$a ) );
				},
				10,
				max( 1, $args )
			);
		}
	}

	/** 45 minutes after signup: if still unpaid, send the "finish joining" email with the pay link. */
	private function pendingNudge( int $orderId ): void {
		$order = wvm( OrderRepository::class )->find( $orderId );
		if ( ! $order || 'pending' !== $order['status'] ) {
			return;
		}
		$o = wvm( OrderService::class )->get( $orderId );
		wvm( Mailer::class )->send(
			'payment_pending',
			(int) $order['member_id'],
			array(
				'pay_link' => $o['pay_link'],
				'amount'   => $o['amount_display'],
				'plan'     => $o['plan_name'],
			)
		);
	}
}
