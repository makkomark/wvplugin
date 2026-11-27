<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Popup;

use WitsValley\Members\Domain\Kit\KitClient;
use WitsValley\Members\Domain\Kit\KitRateLimited;
use WitsValley\Members\Domain\Kit\TagMap;
use WitsValley\Members\Domain\Member\MemberRepository;
use WitsValley\Members\Domain\Notification\Urls;
use WitsValley\Members\Domain\Plan\PlanRepository;
use WitsValley\Members\Domain\Plan\PlanService;
use WitsValley\Members\Domain\Subscription\SubscriptionRepository;
use WitsValley\Members\Jobs\Scheduler;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Settings;

/**
 * Membership popup: who sees it (and which version), the free-book offer tags, email-only leads and the stats.
 *
 * Versions: "a" / "b" = the join (or email-only) offer, A/B tested when a second headline is set;
 * "upgrade" = the Premium nudge shown to logged-in free members.
 */
final class PopupService {

	public const EVENTS = array( 'view', 'close', 'signup', 'lead', 'upgrade_click' );

	public const VARIANTS = array( 'a', 'b', 'upgrade' );

	/** User meta: owned Kit tags this member should always carry (e.g. the free-book offer). */
	public const EXTRA_TAGS_META = 'wvm_kit_extra_tags';

	public function __construct(
		private Settings $settings,
		private MemberRepository $members,
		private SubscriptionRepository $subs,
		private PlanService $plans,
		private PlanRepository $planRepo,
		private Scheduler $scheduler
	) {}

	/** @return array<string,mixed> */
	private function s(): array {
		return $this->settings->group( 'popup' );
	}

	/**
	 * Front-end config for the current request, or null when nobody should see a popup here.
	 *
	 * @return array<string,mixed>|null
	 */
	public function forRequest(): ?array {
		$s       = $this->s();
		$preview = isset( $_GET['wvm_popup'] ) && 'preview' === $_GET['wvm_popup'] && current_user_can( 'wvm_manage_settings' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $preview && ( empty( $s['enabled'] ) || ! $this->pageAllowed( $s ) ) ) {
			return null;
		}
		$kind = $preview ? ( isset( $_GET['wvm_popup_kind'] ) && 'upgrade' === $_GET['wvm_popup_kind'] ? 'upgrade' : 'join' ) : $this->audience(); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $kind ) {
			return null;
		}
		$free = $this->freePlan();
		if ( 'join' === $kind && ! $free && 'join' === $s['mode'] ) {
			return null;
		}
		return array(
			'kind'       => $kind,
			'mode'       => 'email' === $s['mode'] ? 'email' : 'join',
			'preview'    => $preview,
			'restUrl'    => esc_url_raw( rest_url( WVM_REST_NS . '/' ) ),
			'freePlanId' => $free ? (int) $free['id'] : 0,
			'copy'       => array(
				'eyebrow'      => (string) $s['eyebrow'],
				'headline'     => (string) $s['headline'],
				'headlineB'    => (string) $s['headline_b'],
				'body'         => (string) $s['body'],
				'bullets'      => array_values( array_filter( array_map( 'strval', (array) $s['bullets'] ) ) ),
				'button'       => (string) $s['button'],
				'emailButton'  => (string) $s['email_button'],
				'note'         => (string) $s['note'],
				'imageUrl'     => (string) $s['image_url'],
				'bookUrl'      => (string) $s['book_url'],
				'successTitle' => (string) $s['success_title'],
				'successBody'  => (string) $s['success_body'],
				'emailSuccess' => (string) $s['email_success'],
				'upEyebrow'    => (string) $s['upgrade_eyebrow'],
				'upHeadline'   => (string) $s['upgrade_headline'],
				'upBody'       => (string) $s['upgrade_body'],
				'upButton'     => (string) $s['upgrade_button'],
			),
			'triggers'   => array(
				'delay'        => max( 0, (int) $s['delay_seconds'] ),
				'scroll'       => min( 100, max( 0, (int) $s['scroll_percent'] ) ),
				'exitIntent'   => (bool) $s['exit_intent'],
				'paywall'      => (bool) $s['on_paywall'],
				'minPageviews' => max( 1, (int) $s['min_pageviews'] ),
				'dismissDays'  => max( 0, (int) $s['dismiss_days'] ),
			),
			'urls'       => array(
				'terms'     => (string) $this->settings->get( 'general', 'terms_url', '' ),
				'privacy'   => (string) ( $this->settings->get( 'general', 'privacy_url', '' ) ?: get_privacy_policy_url() ),
				'login'     => wp_login_url( $this->currentUrl() ),
				'dashboard' => add_query_arg( 'welcome', '1', Urls::dashboard() ),
				'upgrade'   => add_query_arg( 'plan', sanitize_key( (string) $s['upgrade_plan'] ), Urls::pricing() ),
			),
			'siteName'   => (string) $this->settings->get( 'general', 'business_name', get_bloginfo( 'name' ) ),
		);
	}

	/** "join" for visitors, "upgrade" for free members, null for paying members and non-member staff. */
	public function audience(): ?string {
		if ( ! is_user_logged_in() ) {
			return 'join';
		}
		if ( ! $this->s()['upgrade_enabled'] ) {
			return null;
		}
		$member = $this->members->byUser( get_current_user_id() );
		if ( ! $member ) {
			return null;
		}
		$sub = $this->subs->current( (int) $member['id'] );
		if ( ! $sub ) {
			return 'upgrade';
		}
		$plan = $this->plans->get( (int) $sub['plan_id'] );
		$paid = ! $plan['is_free'] && in_array( $sub['status'], array( 'active', 'past_due' ), true );
		return $paid ? null : 'upgrade';
	}

	/** @param array<string,mixed> $s */
	public function pageAllowed( array $s ): bool {
		if ( is_admin() || wp_doing_ajax() || is_feed() || is_embed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( get_query_var( 'wvm_pay' ) || get_query_var( 'wvm_accept' ) ) {
			return false;
		}
		if ( function_exists( 'is_woocommerce' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return false;
		}
		$own = array_filter(
			array(
				(int) $this->settings->get( 'general', 'dashboard_page_id', 0 ),
				(int) $this->settings->get( 'general', 'pricing_page_id', 0 ),
				(int) $this->settings->get( 'general', 'enterprise_page_id', 0 ),
			)
		);
		if ( $own && is_page( $own ) ) {
			return false;
		}
		$types = array_map( 'strval', (array) $s['post_types'] );
		if ( is_singular() ) {
			$post = get_post();
			if ( ! $post || ! in_array( $post->post_type, $types, true ) ) {
				return false;
			}
			foreach ( array( 'wvm_dashboard', 'wvm_pricing', 'wvm_enterprise_form' ) as $code ) {
				if ( has_shortcode( (string) $post->post_content, $code ) ) {
					return false;
				}
			}
		} elseif ( ! in_array( 'post', $types, true ) ) {
			return false;
		}
		$path = (string) wp_parse_url( $this->currentUrl(), PHP_URL_PATH );
		foreach ( (array) $s['exclude_paths'] as $prefix ) {
			$prefix = '/' . ltrim( trim( (string) $prefix ), '/' );
			if ( '/' !== $prefix && str_starts_with( $path, $prefix ) ) {
				return false;
			}
		}
		return true;
	}

	/** @return array<string,mixed>|null */
	public function freePlan(): ?array {
		$plan = $this->plans->findBySlug( 'free' );
		if ( $plan && $plan['is_free'] && ! $plan['archived_at'] && 'hidden' !== $plan['visibility'] ) {
			return $plan;
		}
		foreach ( $this->planRepo->all( true ) as $p ) {
			if ( ! empty( $p['is_free'] ) && empty( $p['archived_at'] ) ) {
				return $this->plans->get( (int) $p['id'] );
			}
		}
		return null;
	}

	private function currentUrl(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		return home_url( $uri );
	}

	/** The offer tag (e.g. WV-FREE-BOOK), only when it's a tag the plugin may manage. */
	public function offerTag(): ?string {
		$tag = strtoupper( trim( (string) $this->s()['offer_tag'] ) );
		return '' !== $tag && TagMap::isOwned( $tag ) ? $tag : null;
	}

	/** Someone joined through the popup: remember the source and give them the offer tag in Kit. */
	public function joined( int $userId, string $variant ): void {
		$variant = in_array( $variant, array( 'a', 'b' ), true ) ? $variant : 'a';
		update_user_meta( $userId, 'wvm_signup_source', 'popup' );
		update_user_meta( $userId, 'wvm_popup_variant', $variant );
		$tag = $this->offerTag();
		if ( $tag ) {
			$tags   = (array) get_user_meta( $userId, self::EXTRA_TAGS_META, true );
			$tags[] = $tag;
			update_user_meta( $userId, self::EXTRA_TAGS_META, array_values( array_unique( array_filter( array_map( 'strval', $tags ) ) ) ) );
		}
		$this->record( 'signup', $variant );
	}

	/** Email-only mode: queue a Kit subscriber with the lead + offer tags. */
	public function lead( string $name, string $email, string $variant ): void {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			throw DomainError::validation( array( 'email' => 'Enter a valid email address.' ) );
		}
		if ( ! wvm( KitClient::class )->configured() ) {
			throw new DomainError( 'Sign-ups are not available right now. Please try again later.', 'wvm_unavailable', 503 );
		}
		$this->scheduler->async( Scheduler::KIT_LEAD, array( strtolower( $email ), mb_substr( sanitize_text_field( $name ), 0, 80 ) ) );
		$this->record( 'lead', in_array( $variant, array( 'a', 'b' ), true ) ? $variant : 'a' );
	}

	/** Job: create (or reuse) the Kit subscriber and tag them. */
	public function deliverLead( string $email, string $name ): void {
		$kit = wvm( KitClient::class );
		if ( ! $kit->configured() ) {
			return;
		}
		try {
			$sub = $kit->findSubscriberByEmail( $email );
			if ( ! $sub ) {
				$sub = $kit->createSubscriber( $email, $name, 'active' );
			}
			if ( ! $sub || empty( $sub['id'] ) ) {
				return;
			}
			$tags = array_filter( array( strtoupper( trim( (string) $this->s()['lead_tag'] ) ), $this->offerTag() ) );
			foreach ( array_unique( $tags ) as $tag ) {
				if ( ! TagMap::isOwned( $tag ) ) {
					continue;
				}
				$tid = $kit->tagId( $tag );
				if ( $tid ) {
					$kit->addTag( (string) $sub['id'], $tid, $tag );
				}
			}
		} catch ( KitRateLimited $e ) {
			$this->scheduler->at( time() + $e->retryAfter + wp_rand( 0, 30 ), Scheduler::KIT_LEAD, array( $email, $name ) );
		}
	}

	public function record( string $event, string $variant ): void {
		if ( ! in_array( $event, self::EVENTS, true ) || ! in_array( $variant, self::VARIANTS, true ) ) {
			throw DomainError::validation( array( 'event' => 'Unknown event.' ) );
		}
		global $wpdb;
		$t = Db::table( 'popup_stats' );
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$t} (day, variant, event, count) VALUES (%s, %s, %s, 1) ON DUPLICATE KEY UPDATE count = count + 1", Clock::now()->format( 'Y-m-d' ), $variant, $event ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Totals per version for the last $days days, plus how many popup sign-ups have since paid.
	 *
	 * @return array{days:int, variants:array<string,array<string,int|float>>}
	 */
	public function stats( int $days = 30 ): array {
		global $wpdb;
		$days  = max( 1, min( 365, $days ) );
		$since = Clock::now()->modify( '-' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' );
		$t     = Db::table( 'popup_stats' );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT variant, event, SUM(count) AS n FROM {$t} WHERE day >= %s GROUP BY variant, event", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$out   = array();
		foreach ( self::VARIANTS as $v ) {
			$out[ $v ] = array_fill_keys( self::EVENTS, 0 ) + array( 'paid' => 0 );
		}
		foreach ( $rows as $r ) {
			if ( isset( $out[ $r['variant'] ] ) ) {
				$out[ $r['variant'] ][ $r['event'] ] = (int) $r['n'];
			}
		}
		$o    = Db::table( 'orders' );
		$m    = Db::table( 'members' );
		$paid = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT v.meta_value AS variant, COUNT(DISTINCT o.member_id) AS n FROM {$o} o
				JOIN {$m} m ON m.id = o.member_id
				JOIN {$wpdb->usermeta} s ON s.user_id = m.user_id AND s.meta_key = 'wvm_signup_source' AND s.meta_value = 'popup'
				JOIN {$wpdb->usermeta} v ON v.user_id = m.user_id AND v.meta_key = 'wvm_popup_variant'
				WHERE o.status = 'paid' AND o.amount_minor > 0 AND m.created_at >= %s GROUP BY v.meta_value", // phpcs:ignore WordPress.DB
				$since . ' 00:00:00'
			),
			ARRAY_A
		);
		foreach ( $paid as $r ) {
			if ( isset( $out[ $r['variant'] ] ) ) {
				$out[ $r['variant'] ]['paid'] = (int) $r['n'];
			}
		}
		foreach ( $out as $v => $row ) {
			$won               = 'upgrade' === $v ? $row['upgrade_click'] : $row['signup'] + $row['lead'];
			$out[ $v ]['rate'] = $row['view'] ? round( 100 * $won / $row['view'], 1 ) : 0.0;
		}
		return array(
			'days'     => $days,
			'variants' => $out,
		);
	}
}
