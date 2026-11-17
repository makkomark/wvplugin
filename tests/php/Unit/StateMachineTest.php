<?php
namespace WitsValley\Members\Tests\Unit;

use WitsValley\Members\Domain\Subscription\StateMachine;
use WitsValley\Members\Support\DomainError;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/** Acceptance #4: legal transitions allowed, illegal ones throw. */
final class StateMachineTest extends TestCase {

	/** @return array<string,array{0:string,1:string}> */
	public static function legal(): array {
		return array(
			'pending → active'     => array( 'pending', 'active' ),
			'active → past_due'    => array( 'active', 'past_due' ),
			'past_due → suspended' => array( 'past_due', 'suspended' ),
			'suspended → expired'  => array( 'suspended', 'expired' ),
			'past_due → active'    => array( 'past_due', 'active' ),
			'suspended → active'   => array( 'suspended', 'active' ),
			'expired → active'     => array( 'expired', 'active' ),
			'active → cancelled'   => array( 'active', 'cancelled' ),
		);
	}

	/** @return array<string,array{0:string,1:string}> */
	public static function illegal(): array {
		return array(
			'cancelled → active'  => array( 'cancelled', 'active' ),
			'expired → past_due'  => array( 'expired', 'past_due' ),
			'pending → suspended' => array( 'pending', 'suspended' ),
			'pending → past_due'  => array( 'pending', 'past_due' ),
			'expired → cancelled' => array( 'expired', 'cancelled' ),
		);
	}

	/** @dataProvider legal */
	public function test_legal_transitions( string $from, string $to ): void {
		$this->assertTrue( StateMachine::canTransition( $from, $to ) );
		StateMachine::assert( $from, $to );
	}

	/** @dataProvider illegal */
	public function test_illegal_transitions_throw( string $from, string $to ): void {
		$this->assertFalse( StateMachine::canTransition( $from, $to ) );
		$this->expectException( DomainError::class );
		StateMachine::assert( $from, $to );
	}

	public function test_access_granting_statuses(): void {
		$this->assertTrue( StateMachine::grantsAccess( 'active' ) );
		$this->assertTrue( StateMachine::grantsAccess( 'past_due' ) );
		$this->assertFalse( StateMachine::grantsAccess( 'suspended' ) );
		$this->assertFalse( StateMachine::grantsAccess( 'expired' ) );
	}
}
