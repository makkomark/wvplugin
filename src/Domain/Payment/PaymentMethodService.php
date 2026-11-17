<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Payment;

use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Member\MemberRepository;
use WitsValley\Members\Domain\Subscription\SubscriptionRepository;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\DomainError;

/**
 * Saved payment methods mirrored from WooCommerce payment tokens.
 */
final class PaymentMethodService {

	public function __construct(
		private PaymentMethodRepository $methods,
		private MemberRepository $members,
		private SubscriptionRepository $subs,
		private Audit $audit
	) {}

	public function register(): void {
		add_action( 'woocommerce_new_payment_token', array( $this, 'mirrorToken' ), 10, 2 );
	}

	/** @param mixed $token */
	public function mirrorToken( int $tokenId, $token = null ): void {
		$token = $token instanceof \WC_Payment_Token ? $token : \WC_Payment_Tokens::get( $tokenId );
		if ( ! $token ) {
			return;
		}
		$member = $this->members->byUser( (int) $token->get_user_id() );
		if ( ! $member ) {
			return;
		}
		$this->add( (int) $member['id'], $token->get_gateway_id(), $token->get_token(), $token->get_display_name(), $tokenId, method_exists( $token, 'get_expiry_year' ) && $token->get_expiry_year() ? sprintf( '%04d-%02d-28', (int) $token->get_expiry_year(), (int) $token->get_expiry_month() ) : null );
	}

	public function add( int $memberId, string $gateway, string $token, string $label, ?int $wooTokenId = null, ?string $expires = null ): int {
		$is_first = 0 === $this->methods->count( array( 'member_id' => $memberId ) );
		$id       = $this->methods->insert(
			array(
				'member_id'    => $memberId,
				'gateway'      => $gateway,
				'token'        => $token,
				'woo_token_id' => $wooTokenId,
				'label'        => mb_substr( $label, 0, 60 ),
				'expires_at'   => $expires,
				'is_default'   => $is_first,
			)
		);
		if ( $is_first ) {
			$this->attachToSubscriptions( $memberId, $id );
		}
		$this->audit->log(
			'payment_method.added',
			'member',
			$memberId,
			null,
			array(
				'payment_method_id' => $id,
				'gateway'           => $gateway,
				'label'             => $label,
			)
		);
		return $id;
	}

	/** @return list<array<string,mixed>> */
	public function forMember( int $memberId ): array {
		return array_map(
			static fn( array $m ): array => array(
				'id'         => (int) $m['id'],
				'gateway'    => $m['gateway'],
				'label'      => $m['label'],
				'expires_at' => $m['expires_at'],
				'is_default' => (bool) $m['is_default'],
				'created_at' => Clock::iso( $m['created_at'] ),
			),
			$this->methods->where( array( 'member_id' => $memberId ), 'is_default DESC, id DESC' )
		);
	}

	public function delete( int $memberId, int $id ): void {
		$m = $this->methods->find( $id );
		if ( ! $m || (int) $m['member_id'] !== $memberId ) {
			throw DomainError::notFound( 'payment method' );
		}
		$this->methods->delete( $id );
		if ( $m['woo_token_id'] ) {
			\WC_Payment_Tokens::delete( (int) $m['woo_token_id'] );
		}
		foreach ( $this->subs->where(
			array(
				'member_id'         => $memberId,
				'payment_method_id' => $id,
			)
		) as $sub ) {
			$this->subs->update( (int) $sub['id'], array( 'payment_method_id' => null ) );
		}
		if ( $m['is_default'] ) {
			$next = $this->methods->first( array( 'member_id' => $memberId ) );
			if ( $next ) {
				$this->setDefault( $memberId, (int) $next['id'] );
			}
		}
		$this->audit->log(
			'payment_method.removed',
			'member',
			$memberId,
			array(
				'payment_method_id' => $id,
				'label'             => $m['label'],
			),
			null
		);
	}

	public function setDefault( int $memberId, int $id ): void {
		$m = $this->methods->find( $id );
		if ( ! $m || (int) $m['member_id'] !== $memberId ) {
			throw DomainError::notFound( 'payment method' );
		}
		foreach ( $this->methods->where( array( 'member_id' => $memberId ) ) as $row ) {
			$this->methods->update( (int) $row['id'], array( 'is_default' => (int) $row['id'] === $id ) );
		}
		$this->attachToSubscriptions( $memberId, $id );
		$this->audit->log( 'payment_method.default_set', 'member', $memberId, null, array( 'payment_method_id' => $id ) );
	}

	private function attachToSubscriptions( int $memberId, int $methodId ): void {
		foreach ( $this->subs->where(
			array(
				'member_id'       => $memberId,
				'status'          => array( 'active', 'past_due' ),
				'organisation_id' => null,
			)
		) as $sub ) {
			$this->subs->update( (int) $sub['id'], array( 'payment_method_id' => $methodId ) );
		}
	}
}
