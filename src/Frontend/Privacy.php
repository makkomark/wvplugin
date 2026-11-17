<?php
declare( strict_types=1 );

namespace WitsValley\Members\Frontend;

use WitsValley\Members\Domain\Member\MemberService;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Subscription\SubscriptionService;
use WitsValley\Members\Support\Pagination;

/**
 * Core WordPress privacy tools integration (personal data export + erasure).
 */
final class Privacy {

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'erasers' ) );
		add_action( 'admin_init', array( $this, 'policy' ) );
	}

	/** @param array<string,mixed> $exporters */
	public function exporters( array $exporters ): array {
		$exporters['wv-members'] = array(
			'exporter_friendly_name' => __( 'WitsValley membership', 'wv-members' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/** @param array<string,mixed> $erasers */
	public function erasers( array $erasers ): array {
		$erasers['wv-members'] = array(
			'eraser_friendly_name' => __( 'WitsValley membership', 'wv-members' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/** @return array{data:list<array<string,mixed>>, done:bool} */
	public function export( string $email, int $page = 1 ): array {
		unset( $page );
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( $user ) {
			$member = wvm( MemberService::class )->byUser( (int) $user->ID );
			if ( $member ) {
				$fields = array();
				foreach ( array(
					'public_id'  => 'Member ID',
					'phone'      => 'Phone',
					'country'    => 'Country',
					'timezone'   => 'Timezone',
					'created_at' => 'Joined',
				) as $k => $label ) {
					$fields[] = array(
						'name'  => $label,
						'value' => (string) $member[ $k ],
					);
				}
				$fields[] = array(
					'name'  => 'Marketing consent',
					'value' => $member['marketing_consent'] ? 'Yes (' . $member['marketing_consent_at'] . ')' : 'No',
				);
				$data[]   = array(
					'group_id'    => 'wvm-member',
					'group_label' => 'WitsValley membership',
					'item_id'     => 'member-' . $member['id'],
					'data'        => $fields,
				);
				foreach ( wvm( SubscriptionService::class )->forMember( (int) $member['id'] ) as $sub ) {
					$data[] = array(
						'group_id'    => 'wvm-subscriptions',
						'group_label' => 'WitsValley subscriptions',
						'item_id'     => 'sub-' . $sub['id'],
						'data'        => array(
							array(
								'name'  => 'Plan',
								'value' => $sub['plan']['name'],
							),
							array(
								'name'  => 'Status',
								'value' => $sub['status'],
							),
							array(
								'name'  => 'Started',
								'value' => (string) $sub['started_at'],
							),
							array(
								'name'  => 'Period end',
								'value' => (string) $sub['current_period_end'],
							),
						),
					);
				}
				foreach ( wvm( OrderService::class )->list( array( 'member' => (int) $member['id'] ), new Pagination( 1, 200 ) )['items'] as $order ) {
					$data[] = array(
						'group_id'    => 'wvm-orders',
						'group_label' => 'WitsValley payments',
						'item_id'     => 'order-' . $order['id'],
						'data'        => array(
							array(
								'name'  => 'Description',
								'value' => $order['description'],
							),
							array(
								'name'  => 'Amount',
								'value' => $order['amount_display'],
							),
							array(
								'name'  => 'Status',
								'value' => $order['status'],
							),
							array(
								'name'  => 'Receipt',
								'value' => (string) $order['receipt_number'],
							),
							array(
								'name'  => 'Date',
								'value' => (string) $order['created_at'],
							),
						),
					);
				}
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/** @return array<string,mixed> */
	public function erase( string $email, int $page = 1 ): array {
		unset( $page );
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$member = wvm( MemberService::class )->byUser( (int) $user->ID );
			if ( $member && ! $member['anonymised_at'] ) {
				wvm( MemberService::class )->anonymise( (int) $member['id'], 'Privacy erasure request' );
				return array(
					'items_removed'  => true,
					'items_retained' => true,
					'messages'       => array( 'Personal data pseudonymised; payment records retained for accounting.' ),
					'done'           => true,
				);
			}
		}
		return array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	public function policy(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( 'WitsValley Members', wp_kses_post( '<p>We store your name, email, phone, country and membership/payment history to provide your membership. Payments are processed by our payment providers; we keep transaction references, never card numbers. With your consent we share your email and membership status with our email platform (Kit) to deliver content.</p>' ) );
		}
	}
}
