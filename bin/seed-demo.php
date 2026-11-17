<?php
/**
 * Demo data for staging / local UI work. Run: wp eval-file bin/seed-demo.php
 * Creates members in every lifecycle state, workshops, a consultation, an application and an organisation.
 * Never run on production.
 *
 * @package WitsValley\Members
 */

use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Enterprise\ApplicationService;
use WitsValley\Members\Domain\Enterprise\OrganisationService;
use WitsValley\Members\Domain\Member\MemberService;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Plan\PlanService;
use WitsValley\Members\Domain\Subscription\SubscriptionRepository;
use WitsValley\Members\Domain\Subscription\SubscriptionService;
use WitsValley\Members\Domain\Workshop\ConsultationRepository;
use WitsValley\Members\Domain\Workshop\WorkshopService;
use WitsValley\Members\Support\Clock;

if ( ! defined( 'WP_CLI' ) ) {
	exit;
}

Audit::asSystem(
	static function (): void {
		$members = wvm( MemberService::class );
		$subs    = wvm( SubscriptionService::class );
		$orders  = wvm( OrderService::class );
		$plans   = wvm( PlanService::class );
		$basic   = $plans->findBySlug( 'basic' );
		$premium = $plans->findBySlug( 'premium' );

		$people = array(
			array( 'Amina Nakato', 'UG', 'premium', 'active' ),
			array( 'Brian Okello', 'UG', 'basic', 'active' ),
			array( 'Grace Achieng', 'KE', 'basic', 'past_due' ),
			array( 'Daniel Mugisha', 'UG', 'premium', 'suspended' ),
			array( 'Esther Namuli', 'UG', 'basic', 'cancelling' ),
			array( 'Felix Ssempala', 'US', 'premium', 'active' ),
			array( 'Hope Atuhaire', 'UG', 'free', 'free' ),
			array( 'Isaac Kato', 'RW', 'basic', 'expired' ),
		);
		$demo = array();
		foreach ( $people as $i => [ $name, $country, $planSlug, $state ] ) {
			$email = strtolower( str_replace( ' ', '.', $name ) ) . '@demo.witsvalley.test';
			if ( email_exists( $email ) ) {
				continue;
			}
			$m = $members->createFromSignup( array( 'name' => $name, 'email' => $email, 'password' => 'demo1234pass', 'country' => $country, 'phone' => '+2567000000' . $i, 'marketing_consent' => 0 === $i % 2 ) );
			if ( 'free' === $planSlug ) {
				$subs->startFree( (int) $m['id'] );
				continue;
			}
			$plan     = 'premium' === $planSlug ? $premium : $basic;
			$currency = 'US' === $country ? 'USD' : 'UGX';
			$order    = $orders->createForPlan( (int) $m['id'], (int) $plan['id'], 'new', $currency );
			$wc       = wc_get_order( (int) $order['woo_order_id'] );
			$wc->set_payment_method( 'wvm_test' );
			$wc->set_payment_method_title( 'Test payment (sandbox)' );
			$wc->save();
			$wc->payment_complete( 'DEMO-' . strtoupper( wp_generate_password( 8, false ) ) );
			$sub = wvm( SubscriptionRepository::class )->current( (int) $m['id'] );
			if ( 'past_due' === $state ) {
				wvm( SubscriptionRepository::class )->update( (int) $sub['id'], array( 'current_period_end' => Clock::mysql( Clock::now()->modify( '-2 days' ) ) ) );
				$subs->markPastDue( wvm( SubscriptionRepository::class )->find( (int) $sub['id'] ) );
			} elseif ( 'suspended' === $state ) {
				wvm( SubscriptionRepository::class )->update( (int) $sub['id'], array( 'current_period_end' => Clock::mysql( Clock::now()->modify( '-10 days' ) ) ) );
				$subs->markPastDue( wvm( SubscriptionRepository::class )->find( (int) $sub['id'] ) );
				$subs->suspend( wvm( SubscriptionRepository::class )->find( (int) $sub['id'] ) );
			} elseif ( 'expired' === $state ) {
				wvm( SubscriptionRepository::class )->update( (int) $sub['id'], array( 'current_period_end' => Clock::mysql( Clock::now()->modify( '-45 days' ) ) ) );
				$fresh = wvm( SubscriptionRepository::class )->find( (int) $sub['id'] );
				$subs->markPastDue( $fresh );
				$subs->suspend( wvm( SubscriptionRepository::class )->find( (int) $sub['id'] ) );
				$subs->expire( wvm( SubscriptionRepository::class )->find( (int) $sub['id'] ) );
			} elseif ( 'cancelling' === $state ) {
				$subs->cancel( $sub, 'Too expensive right now' );
			}
			$demo[] = $m;
		}

		$ws = wvm( WorkshopService::class );
		if ( ! $ws->list( array(), new \WitsValley\Members\Support\Pagination( 1, 1 ) )['items'] ) {
			$ws->create( array( 'title' => 'Personal Finance Masterclass', 'description' => 'Budgeting, saving and investing basics for young professionals.', 'starts_at' => gmdate( 'c', time() + 2 * DAY_IN_SECONDS ), 'ends_at' => gmdate( 'c', time() + 2 * DAY_IN_SECONDS + 90 * MINUTE_IN_SECONDS ), 'host' => 'WitsValley Team', 'required_level' => 1, 'join_url' => 'https://meet.google.com/demo-wits-valley', 'capacity' => 100, 'publish' => true ) );
			$ws->create( array( 'title' => 'Investing in Uganda: Premium deep dive', 'description' => 'Treasury bonds, unit trusts and the USE.', 'starts_at' => gmdate( 'c', time() + 9 * DAY_IN_SECONDS ), 'ends_at' => gmdate( 'c', time() + 9 * DAY_IN_SECONDS + HOUR_IN_SECONDS ), 'host' => 'Guest expert', 'required_level' => 2, 'join_url' => 'https://zoom.us/j/000000', 'publish' => true ) );
			$past = $ws->create( array( 'title' => 'Getting started with WitsValley', 'description' => 'Orientation session.', 'starts_at' => gmdate( 'c', time() - 7 * DAY_IN_SECONDS ), 'ends_at' => gmdate( 'c', time() - 7 * DAY_IN_SECONDS + HOUR_IN_SECONDS ), 'host' => 'WitsValley Team', 'required_level' => 0, 'publish' => true ) );
			$ws->attachRecording( (int) $past['id'], 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' );
			$ws->create( array( 'title' => 'Draft: Career clinic', 'starts_at' => gmdate( 'c', time() + 20 * DAY_IN_SECONDS ), 'ends_at' => gmdate( 'c', time() + 20 * DAY_IN_SECONDS + HOUR_IN_SECONDS ), 'required_level' => 1 ) );
		}
		if ( $demo && 0 === wvm( ConsultationRepository::class )->count() ) {
			wvm( ConsultationRepository::class )->insert( array( 'member_id' => (int) $demo[0]['id'], 'topic' => 'Planning my first investment portfolio', 'preferred_dates' => 'Weekday evenings', 'notes' => 'I have about UGX 5M to start.', 'status' => 'new' ) );
		}
		$apps = wvm( ApplicationService::class );
		if ( 0 === $apps->counts()['new'] ) {
			$apps->submit( array( 'org_name' => 'Kampala Tech Hub', 'contact_name' => 'Ruth Nabirye', 'email' => 'ruth@kampalatech.demo', 'phone' => '+256701111111', 'country' => 'UG', 'org_size' => '51-200', 'programme_type' => 'Financial literacy for staff', 'participants' => 40, 'duration' => '3 months', 'start_preference' => 'Next month', 'budget_range' => 'UGX 5–10M', 'message' => 'We would like a programme for our new graduates.' ), '127.0.0.' . wp_rand( 2, 250 ) );
		}
		$orgs = wvm( OrganisationService::class );
		if ( ! $orgs->list( array(), new \WitsValley\Members\Support\Pagination( 1, 1 ) )['items'] ) {
			$org = $orgs->create( array( 'name' => 'Acme Uganda Ltd', 'seats' => 25, 'term_start' => gmdate( 'Y-m-d' ), 'term_end' => gmdate( 'Y-m-d', strtotime( '+6 months' ) ), 'contract_ref' => 'ACME-2026-01', 'programme_title' => 'Money Matters for Teams', 'programme_description' => 'Six-module financial wellbeing programme with monthly live sessions.' ) );
			$orgs->invite( (int) $org['id'], array( 'hr@acme.demo', 'staff1@acme.demo', 'staff2@acme.demo' ) );
		}
		WP_CLI::success( 'Demo data ready. Demo member password: demo1234pass' );
	}
);
