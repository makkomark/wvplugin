<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Enterprise;

use WitsValley\Members\Domain\Access\LevelResolver;
use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Member\MemberRepository;
use WitsValley\Members\Domain\Member\MemberService;
use WitsValley\Members\Domain\Notification\Mailer;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Plan\PlanRepository;
use WitsValley\Members\Domain\Plan\PlanService;
use WitsValley\Members\Domain\Subscription\SubscriptionRepository;
use WitsValley\Members\Domain\Subscription\SubscriptionService;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Encryption;
use WitsValley\Members\Support\Pagination;
use WitsValley\Members\Support\Validation;

/**
 * Enterprise organisations: seats, invites, content assignment, term, invoices.
 */
final class OrganisationService {

	public const STATUSES = array( 'active', 'expired', 'suspended' );

	private const RULES = array(
		'name'                  => 'maxlen:200',
		'seats'                 => 'int|min:1|max:100000',
		'level'                 => 'int|min:0|max:9',
		'term_start'            => 'date',
		'term_end'              => 'date',
		'contract_ref'          => 'maxlen:100',
		'notes'                 => 'text',
		'programme_title'       => 'maxlen:200',
		'programme_description' => 'text',
		'contact_member_id'     => 'int',
	);

	public function __construct(
		private OrganisationRepository $orgs,
		private OrgMemberRepository $seats,
		private OrgContentRepository $content,
		private MemberRepository $members,
		private SubscriptionRepository $subs,
		private PlanRepository $plans,
		private Audit $audit
	) {}

	/**
	 * @param array<string,mixed> $dto incl. optional contact_email + contact_name (invited as org admin)
	 * @return array<string,mixed>
	 */
	public function create( array $dto ): array {
		$data              = Validation::validate(
			$dto,
			array_merge(
				self::RULES,
				array(
					'name'  => 'required|maxlen:200',
					'seats' => 'required|int|min:1|max:100000',
				)
			)
		);
		$row               = $this->normalise( $data );
		$row['status']     = 'active';
		$row['level']      = $row['level'] ?? 3;
		$row['term_start'] = $row['term_start'] ?? gmdate( 'Y-m-d' );
		$row['term_end']   = $row['term_end'] ?? gmdate( 'Y-m-d', strtotime( '+1 year' ) );
		$id                = $this->orgs->insert( $row );
		$org               = $this->get( $id );
		$this->audit->log( 'organisation.created', 'organisation', $id, null, $org );
		if ( ! empty( $dto['contact_email'] ) && is_email( (string) $dto['contact_email'] ) ) {
			$this->invite( $id, array( (string) $dto['contact_email'] ), 'admin' );
		}
		return $this->get( $id );
	}

	/** @return array<string,mixed> */
	public function get( int $id ): array {
		return $this->present( $this->orgs->findOrFail( $id, 'organisation' ) );
	}

	/** @return array<string,mixed> Detail incl. seats, content, subscriptions. */
	public function detail( int $id ): array {
		$org               = $this->get( $id );
		$org['seats_list'] = $this->seatList( $id );
		$org['content']    = $this->contentList( $id );
		$org['orders']     = wvm( OrderService::class )->list( array( 'organisation' => $id ), new Pagination( 1, 50 ) )['items'];
		return $org;
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
		if ( ! empty( $f['q'] ) ) {
			global $wpdb;
			$cond[] = 'name LIKE %s';
			$args[] = '%' . $wpdb->esc_like( (string) $f['q'] ) . '%';
		}
		$res          = $this->orgs->paginate( $cond, $args, $p, 'name ASC' );
		$res['items'] = array_map( array( $this, 'present' ), $res['items'] );
		return $res;
	}

	/**
	 * @param array<string,mixed> $dto
	 * @return array<string,mixed>
	 */
	public function update( int $id, array $dto ): array {
		$before = $this->get( $id );
		$data   = Validation::validate( $dto, self::RULES );
		$row    = $this->normalise( $data );
		if ( isset( $row['seats'] ) && $row['seats'] < $before['seats_used'] ) {
			throw DomainError::validation( array( 'seats' => sprintf( '%d seats are in use — revoke some first.', $before['seats_used'] ) ) );
		}
		$this->orgs->update( $id, $row );
		if ( isset( $row['term_end'] ) && $row['term_end'] !== $before['term_end'] ) {
			$this->syncSeatPeriods( $id );
		}
		$after = $this->get( $id );
		$this->audit->log( 'organisation.updated', 'organisation', $id, $before, $after );
		return $after;
	}

	/** @return array<string,mixed> */
	public function suspend( int $id, string $reason = '' ): array {
		return $this->setStatus( $id, 'suspended', $reason ?: 'Organisation suspended' );
	}

	/** @return array<string,mixed> */
	public function reactivate( int $id, string $reason = '' ): array {
		$org = $this->get( $id );
		if ( $org['term_end'] && strtotime( $org['term_end'] . ' 23:59:59 UTC' ) < time() ) {
			throw DomainError::conflict( 'The contract term has ended — renew the term instead.' );
		}
		return $this->setStatus( $id, 'active', $reason ?: 'Organisation reactivated' );
	}

	/** @return array<string,mixed> */
	public function expire( int $id ): array {
		return $this->setStatus( $id, 'expired', 'Contract term ended' );
	}

	/** @return array<string,mixed> */
	public function renewTerm( int $id, string $newEnd, string $reason = '' ): array {
		$ts = strtotime( $newEnd );
		if ( ! $ts || $ts < time() ) {
			throw DomainError::validation( array( 'term_end' => 'The new end date must be in the future.' ) );
		}
		$before = $this->get( $id );
		$this->orgs->update( $id, array( 'term_end' => gmdate( 'Y-m-d', $ts ) ) );
		if ( 'active' !== $before['status'] ) {
			$this->setStatus( $id, 'active', 'Term renewed' );
		}
		$this->syncSeatPeriods( $id );
		$after = $this->get( $id );
		$this->audit->log( 'organisation.term_renewed', 'organisation', $id, $before, $after, $reason ?: null );
		return $after;
	}

	/**
	 * Invite people. Seat limit = seats; counts joined + pending invites.
	 *
	 * @param list<string> $emails
	 * @return array{invited:list<string>, skipped:array<string,string>}
	 */
	public function invite( int $orgId, array $emails, string $role = 'member' ): array {
		$org     = $this->get( $orgId );
		$role    = 'admin' === $role ? 'admin' : 'member';
		$invited = array();
		$skipped = array();
		if ( 'active' !== $org['status'] ) {
			throw DomainError::conflict( 'Invites can only be sent for active organisations.' );
		}
		foreach ( array_unique( array_map( 'strtolower', array_map( 'trim', $emails ) ) ) as $email ) {
			if ( ! is_email( $email ) ) {
				$skipped[ $email ] = 'invalid email';
				continue;
			}
			$existing = $this->seats->first(
				array(
					'organisation_id' => $orgId,
					'email'           => $email,
				)
			);
			if ( $existing && ! $existing['revoked_at'] ) {
				$skipped[ $email ] = $existing['joined_at'] ? 'already a member' : 'already invited';
				continue;
			}
			if ( $this->seatsTaken( $orgId ) >= (int) $org['seats'] ) {
				$skipped[ $email ] = 'no seats left';
				continue;
			}
			$token = Encryption::token( 20 );
			if ( $existing ) {
				$this->seats->update(
					(int) $existing['id'],
					array(
						'invite_token'    => $token,
						'invited_at'      => Clock::mysql(),
						'revoked_at'      => null,
						'joined_at'       => null,
						'member_id'       => null,
						'subscription_id' => null,
						'role'            => $role,
					)
				);
				$seat_id = (int) $existing['id'];
			} else {
				$seat_id = $this->seats->insert(
					array(
						'organisation_id' => $orgId,
						'email'           => $email,
						'role'            => $role,
						'invite_token'    => $token,
						'invited_at'      => Clock::mysql(),
					)
				);
			}
			$this->sendInvite( $seat_id );
			$invited[] = $email;
		}
		$this->audit->log(
			'organisation.invited',
			'organisation',
			$orgId,
			null,
			array(
				'invited' => $invited,
				'skipped' => $skipped,
				'role'    => $role,
			)
		);
		return array(
			'invited' => $invited,
			'skipped' => $skipped,
		);
	}

	public function resendInvite( int $orgId, int $seatId ): void {
		$seat = $this->seats->findOrFail( $seatId, 'invite' );
		if ( (int) $seat['organisation_id'] !== $orgId || $seat['joined_at'] || $seat['revoked_at'] ) {
			throw DomainError::conflict( 'This invite cannot be resent.' );
		}
		$this->seats->update(
			$seatId,
			array(
				'invite_token' => Encryption::token( 20 ),
				'invited_at'   => Clock::mysql(),
			)
		);
		$this->sendInvite( $seatId );
		$this->audit->log( 'organisation.invite_resent', 'organisation', $orgId, null, array( 'email' => $seat['email'] ) );
	}

	private function sendInvite( int $seatId ): void {
		$seat = $this->seats->findOrFail( $seatId );
		$org  = $this->orgs->findOrFail( (int) $seat['organisation_id'] );
		wvm( Mailer::class )->send(
			'enterprise_invite',
			(string) $seat['email'],
			array(
				'org_name'   => $org['name'],
				'accept_url' => self::acceptUrl( (string) $seat['invite_token'] ),
				'first_name' => 'there',
			)
		);
	}

	/** Withdraw a pending (not yet accepted) invite. */
	public function withdrawInvite( int $orgId, int $seatId ): void {
		$seat = $this->seats->find( $seatId );
		if ( ! $seat || (int) $seat['organisation_id'] !== $orgId || $seat['revoked_at'] ) {
			throw DomainError::notFound( 'invite' );
		}
		if ( $seat['joined_at'] ) {
			throw DomainError::conflict( 'This person has already joined — remove their seat instead.' );
		}
		$this->seats->update(
			$seatId,
			array(
				'revoked_at'   => Clock::mysql(),
				'invite_token' => null,
			)
		);
		$this->audit->log( 'organisation.invite_withdrawn', 'organisation', $orgId, array( 'email' => $seat['email'] ), null );
	}

	/** End of a contract day in the site's timezone, as UTC (so the term never shows as the next day). */
	public static function endOfDayUtc( string $date ): \DateTimeImmutable {
		return ( new \DateTimeImmutable( $date . ' 23:59:59', wp_timezone() ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
	}

	public static function acceptUrl( string $token ): string {
		return home_url( '/enterprise/accept/' . $token . '/' );
	}

	/** @return array<string,mixed>|null Seat row for a token. */
	public function seatByToken( string $token ): ?array {
		if ( ! preg_match( '/^[a-f0-9]{20,64}$/', $token ) ) {
			return null;
		}
		$seat = $this->seats->findBy( 'invite_token', $token );
		return $seat && ! $seat['revoked_at'] ? $seat : null;
	}

	/**
	 * Accept an invite for an existing user: create member if needed + active enterprise subscription.
	 *
	 * @return array<string,mixed> organisation
	 */
	public function accept( string $token, int $userId ): array {
		$seat = $this->seatByToken( $token );
		if ( ! $seat ) {
			throw new DomainError( 'This invitation is no longer valid.', 'wvm_invalid_invite', 404 );
		}
		$org = $this->get( (int) $seat['organisation_id'] );
		if ( 'active' !== $org['status'] ) {
			throw DomainError::conflict( 'This programme is not active.' );
		}
		$member = wvm( MemberService::class )->byUser( $userId ) ?? wvm( MemberService::class )->createForUser( $userId );
		if ( $seat['joined_at'] && (int) $seat['member_id'] === (int) $member['id'] ) {
			return $org;
		}
		$other = $this->seats->first(
			array(
				'organisation_id' => (int) $org['id'],
				'member_id'       => (int) $member['id'],
				'revoked_at'      => null,
			)
		);
		if ( $other && (int) $other['id'] !== (int) $seat['id'] && $other['joined_at'] ) {
			throw DomainError::conflict( 'You already hold a seat in this programme.' );
		}
		$plan = $this->enterprisePlan();
		$end  = $org['term_end'] ? self::endOfDayUtc( (string) $org['term_end'] ) : null;
		$sub  = wvm( SubscriptionService::class )->start( (int) $member['id'], (int) $plan['id'], null, null, 'Joined organisation ' . $org['name'], (int) $org['id'], $end );
		$this->seats->update(
			(int) $seat['id'],
			array(
				'member_id'       => (int) $member['id'],
				'subscription_id' => (int) $sub['id'],
				'joined_at'       => Clock::mysql(),
				'invite_token'    => null,
			)
		);
		if ( 'admin' === $seat['role'] && ! $org['contact_member_id'] ) {
			$this->orgs->update( (int) $org['id'], array( 'contact_member_id' => (int) $member['id'] ) );
		}
		wvm( LevelResolver::class )->flush();
		$this->audit->log(
			'organisation.seat_joined',
			'organisation',
			(int) $org['id'],
			null,
			array(
				'member_id' => (int) $member['id'],
				'email'     => $seat['email'],
			)
		);
		return $this->get( (int) $org['id'] );
	}

	public function revoke( int $orgId, int $memberId, string $reason = 'Seat revoked' ): void {
		$seat = $this->seats->first(
			array(
				'organisation_id' => $orgId,
				'member_id'       => $memberId,
				'revoked_at'      => null,
			)
		);
		if ( ! $seat ) {
			// Allow revoking a pending invite by seat id.
			$seat = $this->seats->find( $memberId );
			if ( ! $seat || (int) $seat['organisation_id'] !== $orgId || $seat['revoked_at'] ) {
				throw DomainError::notFound( 'seat' );
			}
		}
		$this->seats->update(
			(int) $seat['id'],
			array(
				'revoked_at'   => Clock::mysql(),
				'invite_token' => null,
			)
		);
		if ( $seat['subscription_id'] ) {
			$sub = $this->subs->find( (int) $seat['subscription_id'] );
			if ( $sub && in_array( $sub['status'], array( 'active', 'past_due', 'suspended', 'pending' ), true ) ) {
				wvm( SubscriptionService::class )->cancel( $sub, $reason, true );
			}
		}
		wvm( LevelResolver::class )->flush();
		$this->audit->log(
			'organisation.seat_revoked',
			'organisation',
			$orgId,
			array(
				'email'     => $seat['email'],
				'member_id' => $seat['member_id'],
			),
			null,
			$reason
		);
	}

	/**
	 * Replace the set of posts assigned to an organisation.
	 *
	 * @param list<int> $postIds
	 * @return list<array<string,mixed>>
	 */
	public function assignContent( int $orgId, array $postIds ): array {
		$this->orgs->findOrFail( $orgId, 'organisation' );
		$before = array_map( static fn( $c ) => $c['post_id'], $this->contentList( $orgId ) );
		global $wpdb;
		$t = Db::table( 'organisation_content' );
		$wpdb->delete( $t, array( 'organisation_id' => $orgId ) ); // phpcs:ignore WordPress.DB
		foreach ( array_unique( array_map( 'intval', $postIds ) ) as $pid ) {
			if ( $pid > 0 && get_post( $pid ) ) {
				$this->content->insert(
					array(
						'organisation_id' => $orgId,
						'post_id'         => $pid,
					)
				);
			}
		}
		$after = $this->contentList( $orgId );
		$this->audit->log( 'organisation.content_assigned', 'organisation', $orgId, array( 'post_ids' => $before ), array( 'post_ids' => array_map( static fn( $c ) => $c['post_id'], $after ) ) );
		return $after;
	}

	public function removeContent( int $orgId, int $postId ): void {
		global $wpdb;
		$wpdb->delete(
			Db::table( 'organisation_content' ),
			array(
				'organisation_id' => $orgId,
				'post_id'         => $postId,
			)
		); // phpcs:ignore WordPress.DB
		$this->audit->log( 'organisation.content_removed', 'organisation', $orgId, array( 'post_id' => $postId ), null );
	}

	/** @return array<string,mixed> Plugin order with pay link (sent to the org contact). */
	public function invoiceOrder( int $orgId, int $amountMinor, string $currency, string $description ): array {
		$org = $this->get( $orgId );
		if ( ! $org['contact_member_id'] ) {
			throw DomainError::conflict( 'Set an organisation contact (an org admin who has accepted their invite) first.' );
		}
		if ( $amountMinor <= 0 ) {
			throw DomainError::validation( array( 'amount_minor' => 'Amount must be greater than zero.' ) );
		}
		$plan  = $this->enterprisePlan();
		$order = wvm( OrderService::class )->createInvoice( (int) $org['contact_member_id'], $orgId, $amountMinor, $currency, $description ?: ( 'WitsValley Enterprise programme — ' . $org['name'] ), (int) $plan['id'] );
		wvm( Mailer::class )->send(
			'pay_link',
			(int) $org['contact_member_id'],
			array(
				'pay_link' => $order['pay_link'],
				'amount'   => $order['amount_display'],
				'plan'     => 'Enterprise — ' . $org['name'],
			)
		);
		return $order;
	}

	/** Daily: expire organisations whose term has ended. */
	public function expireEnded(): int {
		global $wpdb;
		$t   = Db::table( 'organisations' );
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'active' AND term_end IS NOT NULL AND term_end < %s", gmdate( 'Y-m-d' ) ) ); // phpcs:ignore WordPress.DB
		foreach ( $ids as $id ) {
			$this->expire( (int) $id );
		}
		return count( $ids );
	}

	/** @return list<array<string,mixed>> */
	public function seatList( int $orgId ): array {
		return array_map(
			function ( array $s ): array {
				$m    = $s['member_id'] ? $this->members->find( (int) $s['member_id'] ) : null;
				$user = $m ? get_userdata( (int) $m['user_id'] ) : null;
				return array(
					'id'         => (int) $s['id'],
					'member_id'  => $s['member_id'] ? (int) $s['member_id'] : null,
					'public_id'  => $m['public_id'] ?? null,
					'name'       => $user ? $user->display_name : null,
					'email'      => $s['email'],
					'role'       => $s['role'],
					'status'     => $s['revoked_at'] ? 'revoked' : ( $s['joined_at'] ? 'joined' : 'invited' ),
					'invited_at' => Clock::iso( $s['invited_at'] ),
					'joined_at'  => Clock::iso( $s['joined_at'] ),
					'revoked_at' => Clock::iso( $s['revoked_at'] ),
				);
			},
			$this->seats->where( array( 'organisation_id' => $orgId ), 'id ASC' )
		);
	}

	/** @return list<array<string,mixed>> */
	public function contentList( int $orgId ): array {
		return array_values(
			array_filter(
				array_map(
					static function ( array $c ): ?array {
						$post = get_post( (int) $c['post_id'] );
						if ( ! $post ) {
							return null;
						}
						return array(
							'post_id'   => (int) $c['post_id'],
							'title'     => get_the_title( $post ),
							'post_type' => $post->post_type,
							'url'       => (string) get_permalink( $post ),
							'excerpt'   => wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 24 ),
						);
					},
					$this->content->where( array( 'organisation_id' => $orgId ), 'id ASC' )
				)
			)
		);
	}

	/**
	 * The member's organisation (seat holder view).
	 *
	 * @return array<string,mixed>|null
	 */
	public function forMember( int $memberId ): ?array {
		$seat = $this->seats->first(
			array(
				'member_id'  => $memberId,
				'revoked_at' => null,
			),
			'id DESC'
		);
		if ( ! $seat || ! $seat['joined_at'] ) {
			return null;
		}
		$org            = $this->get( (int) $seat['organisation_id'] );
		$org['role']    = $seat['role'];
		$org['content'] = $this->contentList( (int) $seat['organisation_id'] );
		unset( $org['notes'], $org['contract_ref'] );
		return $org;
	}

	/** @return array<string,mixed> */
	public function seatFor( int $orgId, int $memberId ): ?array {
		return $this->seats->first(
			array(
				'organisation_id' => $orgId,
				'member_id'       => $memberId,
				'revoked_at'      => null,
			)
		);
	}

	private function seatsTaken( int $orgId ): int {
		global $wpdb;
		$t = Db::table( 'organisation_members' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE organisation_id = %d AND revoked_at IS NULL", $orgId ) ); // phpcs:ignore WordPress.DB
	}

	/** @return array<string,mixed> */
	private function setStatus( int $id, string $status, string $reason ): array {
		$before = $this->get( $id );
		if ( $before['status'] === $status ) {
			return $before;
		}
		$this->orgs->update( $id, array( 'status' => $status ) );
		$service = wvm( SubscriptionService::class );
		foreach ( $this->subs->where( array( 'organisation_id' => $id ) ) as $sub ) {
			try {
				if ( 'suspended' === $status && in_array( $sub['status'], array( 'active', 'past_due' ), true ) ) {
					$service->suspend( $sub, $reason, false );
				} elseif ( 'expired' === $status && in_array( $sub['status'], array( 'active', 'past_due', 'suspended' ), true ) ) {
					if ( 'active' === $sub['status'] || 'past_due' === $sub['status'] ) {
						$service->suspend( $sub, $reason, false );
						$sub = $this->subs->find( (int) $sub['id'] );
					}
					$service->expire( $sub, $reason );
				} elseif ( 'active' === $status && in_array( $sub['status'], array( 'suspended', 'expired' ), true ) ) {
					$service->transition(
						$sub,
						'active',
						$reason,
						array(
							'suspended_at' => null,
							'expires_at'   => null,
						)
					);
				}
			} catch ( \Throwable $e ) {
				continue;
			}
		}
		wvm( LevelResolver::class )->flush();
		$after = $this->get( $id );
		$this->audit->log( 'organisation.' . $status, 'organisation', $id, $before, $after, $reason );
		return $after;
	}

	private function syncSeatPeriods( int $orgId ): void {
		$org = $this->orgs->findOrFail( $orgId );
		if ( ! $org['term_end'] ) {
			return;
		}
		foreach ( $this->subs->where(
			array(
				'organisation_id' => $orgId,
				'status'          => array( 'active', 'past_due', 'suspended' ),
			)
		) as $sub ) {
			$this->subs->update( (int) $sub['id'], array( 'current_period_end' => Clock::mysql( self::endOfDayUtc( (string) $org['term_end'] ) ) ) );
		}
	}

	/** @return array<string,mixed> */
	private function enterprisePlan(): array {
		$plan = $this->plans->findBy( 'slug', 'enterprise' );
		if ( ! $plan ) {
			$candidates = array_filter( $this->plans->all( true ), static fn( $p ) => (int) $p['level'] >= 3 );
			$plan       = $candidates ? reset( $candidates ) : null;
		}
		if ( ! $plan ) {
			throw DomainError::notFound( 'enterprise plan' );
		}
		return wvm( PlanService::class )->get( (int) $plan['id'] );
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function normalise( array $data ): array {
		$row = array();
		foreach ( array( 'name', 'seats', 'level', 'contract_ref', 'notes', 'programme_title', 'programme_description', 'contact_member_id' ) as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$row[ $k ] = $data[ $k ];
			}
		}
		foreach ( array( 'term_start', 'term_end' ) as $k ) {
			if ( ! empty( $data[ $k ] ) ) {
				$row[ $k ] = gmdate( 'Y-m-d', (int) strtotime( (string) $data[ $k ] ) );
			}
		}
		return $row;
	}

	/**
	 * @param array<string,mixed> $o
	 * @return array<string,mixed>
	 */
	private function present( array $o ): array {
		global $wpdb;
		$t       = Db::table( 'organisation_members' );
		$joined  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE organisation_id = %d AND revoked_at IS NULL AND joined_at IS NOT NULL", (int) $o['id'] ) ); // phpcs:ignore WordPress.DB
		$pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE organisation_id = %d AND revoked_at IS NULL AND joined_at IS NULL", (int) $o['id'] ) ); // phpcs:ignore WordPress.DB
		$contact = $o['contact_member_id'] ? $this->members->find( (int) $o['contact_member_id'] ) : null;
		$cuser   = $contact ? get_userdata( (int) $contact['user_id'] ) : null;
		return array(
			'id'                    => (int) $o['id'],
			'name'                  => (string) $o['name'],
			'contact_member_id'     => $o['contact_member_id'] ? (int) $o['contact_member_id'] : null,
			'contact_name'          => $cuser ? $cuser->display_name : null,
			'contact_email'         => $cuser ? $cuser->user_email : null,
			'seats'                 => (int) $o['seats'],
			'seats_used'            => $joined,
			'seats_pending'         => $pending,
			'seats_available'       => max( 0, (int) $o['seats'] - $joined - $pending ),
			'level'                 => (int) $o['level'],
			'level_label'           => LevelResolver::label( (int) $o['level'] ),
			'term_start'            => $o['term_start'],
			'term_end'              => $o['term_end'],
			'contract_ref'          => (string) ( $o['contract_ref'] ?? '' ),
			'programme_title'       => (string) ( $o['programme_title'] ?? '' ),
			'programme_description' => (string) ( $o['programme_description'] ?? '' ),
			'notes'                 => (string) ( $o['notes'] ?? '' ),
			'status'                => (string) $o['status'],
			'created_at'            => Clock::iso( $o['created_at'] ),
		);
	}
}
