<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Subscription;

use WitsValley\Members\Support\DomainError;

/**
 * Allowed subscription status transitions.
 *
 *   pending   → active | cancelled
 *   active    → past_due | suspended (admin) | cancelled | expired (fixed term end)
 *   past_due  → active | suspended | cancelled
 *   suspended → active | expired | cancelled
 *   expired   → active (within rejoin window)
 *   cancelled → (terminal)
 */
final class StateMachine {

	public const STATUSES = array( 'pending', 'active', 'past_due', 'suspended', 'expired', 'cancelled' );

	/** @var array<string,list<string>> */
	public const TRANSITIONS = array(
		'pending'   => array( 'active', 'cancelled' ),
		'active'    => array( 'past_due', 'suspended', 'cancelled', 'expired' ),
		'past_due'  => array( 'active', 'suspended', 'cancelled' ),
		'suspended' => array( 'active', 'expired', 'cancelled' ),
		'expired'   => array( 'active' ),
		'cancelled' => array(),
	);

	public static function canTransition( string $from, string $to ): bool {
		return in_array( $to, self::TRANSITIONS[ $from ] ?? array(), true );
	}

	public static function assert( string $from, string $to ): void {
		if ( ! self::canTransition( $from, $to ) ) {
			throw new DomainError( sprintf( 'Illegal subscription transition %s → %s.', $from, $to ), 'wvm_illegal_transition', 409 );
		}
	}

	/** Statuses that grant access. */
	public static function grantsAccess( string $status ): bool {
		return in_array( $status, array( 'active', 'past_due' ), true );
	}
}
