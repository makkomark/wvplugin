<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Workshop;

use WitsValley\Members\Domain\Access\LevelResolver;
use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Member\MemberRepository;
use WitsValley\Members\Domain\Notification\Notifier;
use WitsValley\Members\Jobs\Scheduler;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Pagination;
use WitsValley\Members\Support\Validation;

/**
 * Live workshops: scheduling, eligibility, registration, join gating, reminders, recordings, ICS.
 */
final class WorkshopService {

	public const STATUSES = array( 'draft', 'scheduled', 'live', 'ended', 'cancelled' );

	public const JOIN_WINDOW = 15 * MINUTE_IN_SECONDS;

	private const RULES = array(
		'title'           => 'maxlen:200',
		'description'     => 'text',
		'starts_at'       => 'date',
		'ends_at'         => 'date',
		'host'            => 'maxlen:100',
		'required_level'  => 'int|min:0|max:9',
		'organisation_id' => 'int',
		'join_url'        => 'url',
		'recording_url'   => 'url',
		'capacity'        => 'int|min:0',
	);

	public function __construct(
		private WorkshopRepository $workshops,
		private RegistrationRepository $registrations,
		private MemberRepository $members,
		private LevelResolver $levels,
		private Audit $audit,
		private Scheduler $scheduler
	) {}

	/** @return array<string,mixed> */
	public function get( int $id ): array {
		return $this->present( $this->workshops->findOrFail( $id, 'workshop' ), true );
	}

	/**
	 * @param array<string,mixed> $f status, from, to, level, org, when
	 * @return array{items:list<array<string,mixed>>, meta:array<string,int>}
	 */
	public function list( array $f, Pagination $p ): array {
		$cond = array();
		$args = array();
		if ( ! empty( $f['status'] ) ) {
			$cond[] = 'status = %s';
			$args[] = (string) $f['status'];
		}
		if ( ! empty( $f['from'] ) ) {
			$cond[] = 'starts_at >= %s';
			$args[] = gmdate( 'Y-m-d 00:00:00', (int) strtotime( (string) $f['from'] ) );
		}
		if ( ! empty( $f['to'] ) ) {
			$cond[] = 'starts_at <= %s';
			$args[] = gmdate( 'Y-m-d 23:59:59', (int) strtotime( (string) $f['to'] ) );
		}
		if ( isset( $f['level'] ) && '' !== $f['level'] ) {
			$cond[] = 'required_level = %d';
			$args[] = (int) $f['level'];
		}
		if ( ! empty( $f['org'] ) ) {
			$cond[] = 'organisation_id = %d';
			$args[] = (int) $f['org'];
		}
		$order        = ! empty( $f['order'] ) && 'desc' === $f['order'] ? 'starts_at DESC' : 'starts_at ASC';
		$res          = $this->workshops->paginate( $cond, $args, $p, $order );
		$res['items'] = array_map( fn( $w ) => $this->present( $w ), $res['items'] );
		return $res;
	}

	/**
	 * @param array<string,mixed> $dto
	 * @return array<string,mixed>
	 */
	public function create( array $dto ): array {
		$data = Validation::validate(
			$dto,
			array_merge(
				self::RULES,
				array(
					'title'     => 'required|maxlen:200',
					'starts_at' => 'required|date',
					'ends_at'   => 'required|date',
				)
			)
		);
		$row  = $this->normalise( $data );
		$this->assertTimes( $row );
		$row['status'] = 'draft';
		$id            = $this->workshops->insert( $row );
		$after         = $this->get( $id );
		$this->audit->log( 'workshop.created', 'workshop', $id, null, $after );
		if ( ! empty( $dto['publish'] ) ) {
			return $this->publish( $id );
		}
		return $after;
	}

	/**
	 * @param array<string,mixed> $dto
	 * @return array<string,mixed>
	 */
	public function update( int $id, array $dto ): array {
		$before = $this->get( $id );
		$data   = Validation::validate( $dto, self::RULES );
		$row    = $this->normalise( $data );
		$merged = array_merge(
			array(
				'starts_at' => $this->workshops->find( $id )['starts_at'],
				'ends_at'   => $this->workshops->find( $id )['ends_at'],
			),
			$row
		);
		$this->assertTimes( $merged );
		if ( isset( $dto['status'] ) && in_array( $dto['status'], array( 'live', 'ended' ), true ) ) {
			$row['status'] = $dto['status'];
		}
		$this->workshops->update( $id, $row );
		$after = $this->get( $id );
		$this->audit->log( 'workshop.updated', 'workshop', $id, $before, $after );
		if ( 'scheduled' === $after['status'] ) {
			$this->scheduleReminders( $id );
		}
		return $after;
	}

	/** @return array<string,mixed> */
	public function publish( int $id ): array {
		$w = $this->workshops->findOrFail( $id, 'workshop' );
		if ( 'draft' !== $w['status'] && 'cancelled' !== $w['status'] ) {
			return $this->get( $id );
		}
		$this->workshops->update( $id, array( 'status' => 'scheduled' ) );
		$this->scheduleReminders( $id );
		$after = $this->get( $id );
		$this->audit->log( 'workshop.published', 'workshop', $id, $this->present( $w ), $after );
		return $after;
	}

	/** @return array<string,mixed> */
	public function cancel( int $id, string $reason = '' ): array {
		$w = $this->workshops->findOrFail( $id, 'workshop' );
		$this->workshops->update( $id, array( 'status' => 'cancelled' ) );
		$after = $this->get( $id );
		$this->audit->log( 'workshop.cancelled', 'workshop', $id, $this->present( $w ), $after, $reason ?: null );
		foreach ( $this->registrations->where( array( 'workshop_id' => $id ) ) as $reg ) {
			wvm( \WitsValley\Members\Domain\Notification\InAppNotifier::class )->push( (int) $reg['member_id'], 'workshop', 'Workshop cancelled', $w['title'] . ' has been cancelled.' );
		}
		return $after;
	}

	/** Hard delete — drafts only. */
	public function delete( int $id ): array {
		$w = $this->workshops->findOrFail( $id, 'workshop' );
		if ( 'draft' !== $w['status'] ) {
			return $this->cancel( $id, 'Deleted after publishing (cancelled instead)' );
		}
		$this->workshops->delete( $id );
		$this->audit->log( 'workshop.deleted', 'workshop', $id, $this->present( $w ), null );
		return array(
			'deleted' => true,
			'id'      => $id,
		);
	}

	/** @return array<string,mixed> */
	public function attachRecording( int $id, string $url ): array {
		$url = esc_url_raw( $url );
		if ( ! $url ) {
			throw DomainError::validation( array( 'url' => 'Enter a valid URL.' ) );
		}
		$before = $this->get( $id );
		$this->workshops->update(
			$id,
			array(
				'recording_url' => $url,
				'status'        => 'ended',
			)
		);
		$after = $this->get( $id );
		$this->audit->log( 'workshop.recording_attached', 'workshop', $id, $before, $after );
		return $after;
	}

	/** @return array<string,mixed> */
	public function register( int $id, int $memberId ): array {
		$w = $this->workshops->findOrFail( $id, 'workshop' );
		if ( ! in_array( $w['status'], array( 'scheduled', 'live' ), true ) ) {
			throw DomainError::conflict( 'Registration is closed for this workshop.' );
		}
		if ( ! $this->eligible( $w, $memberId ) ) {
			throw DomainError::forbidden( 'Your plan does not include this workshop. Upgrade to join.' );
		}
		if ( $this->registrations->first(
			array(
				'workshop_id' => $id,
				'member_id'   => $memberId,
			)
		) ) {
			return $this->presentForMember( $w, $memberId );
		}
		if ( $w['capacity'] && $this->registrations->count( array( 'workshop_id' => $id ) ) >= (int) $w['capacity'] ) {
			throw DomainError::conflict( 'This workshop is full.' );
		}
		$this->registrations->insert(
			array(
				'workshop_id' => $id,
				'member_id'   => $memberId,
			)
		);
		$this->audit->log( 'workshop.registered', 'workshop', $id, null, array( 'member_id' => $memberId ) );
		return $this->presentForMember( $this->workshops->find( $id ), $memberId );
	}

	/** @return array<string,mixed> */
	public function unregister( int $id, int $memberId ): array {
		$reg = $this->registrations->first(
			array(
				'workshop_id' => $id,
				'member_id'   => $memberId,
			)
		);
		if ( $reg ) {
			$this->registrations->delete( (int) $reg['id'] );
			$this->audit->log( 'workshop.unregistered', 'workshop', $id, array( 'member_id' => $memberId ), null );
		}
		return $this->presentForMember( $this->workshops->findOrFail( $id, 'workshop' ), $memberId );
	}

	/** @param array<string,mixed> $w */
	public function eligible( array $w, int $memberId ): bool {
		$member = $this->members->find( $memberId );
		if ( ! $member ) {
			return false;
		}
		$ent = $this->levels->entitlements( (int) $member['user_id'] );
		if ( $w['organisation_id'] && ! in_array( (int) $w['organisation_id'], $ent['org_ids'], true ) ) {
			return false;
		}
		return $ent['level'] >= (int) $w['required_level'];
	}

	/**
	 * Join URL for an eligible member, from 15 minutes before start until the end.
	 *
	 * @return array{url:string}
	 */
	public function joinUrl( int $id, int $memberId ): array {
		$w = $this->workshops->findOrFail( $id, 'workshop' );
		if ( ! $this->eligible( $w, $memberId ) ) {
			throw DomainError::forbidden( 'Your plan does not include this workshop.' );
		}
		if ( 'cancelled' === $w['status'] ) {
			throw DomainError::forbidden( 'This workshop was cancelled.' );
		}
		$start = (int) Clock::timestamp( $w['starts_at'] );
		$end   = (int) Clock::timestamp( $w['ends_at'] );
		$now   = Clock::now()->getTimestamp();
		if ( $now < $start - self::JOIN_WINDOW ) {
			throw new DomainError( 'The join link opens 15 minutes before the start.', 'wvm_too_early', 403 );
		}
		if ( $now > $end + HOUR_IN_SECONDS ) {
			throw new DomainError( 'This workshop has ended.', 'wvm_ended', 403 );
		}
		if ( ! $w['join_url'] ) {
			throw new DomainError( 'The host has not added the join link yet. Try again in a minute.', 'wvm_no_link', 403 );
		}
		return array( 'url' => (string) $w['join_url'] );
	}

	/**
	 * Member list: upcoming or past, with eligibility flags (never the join URL).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function forMember( int $memberId, string $when = 'upcoming' ): array {
		global $wpdb;
		$t      = $this->workshops->tableName();
		$now    = Clock::mysql( Clock::now()->modify( '-1 hour' ) );
		$sql    = 'past' === $when
			? $wpdb->prepare( "SELECT * FROM {$t} WHERE status IN ('scheduled','live','ended') AND ends_at < %s ORDER BY starts_at DESC LIMIT 50", $now ) // phpcs:ignore WordPress.DB
			: $wpdb->prepare( "SELECT * FROM {$t} WHERE status IN ('scheduled','live') AND ends_at >= %s ORDER BY starts_at ASC LIMIT 50", $now ); // phpcs:ignore WordPress.DB
		$rows   = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
		$member = $this->members->find( $memberId );
		$ent    = $member ? $this->levels->entitlements( (int) $member['user_id'] ) : array( 'org_ids' => array() );
		$out    = array();
		foreach ( $rows ?: array() as $row ) {
			$w = $this->workshops->hydrate( $row );
			if ( $w['organisation_id'] && ! in_array( (int) $w['organisation_id'], $ent['org_ids'], true ) ) {
				continue;
			}
			$out[] = $this->presentForMember( $w, $memberId );
		}
		return $out;
	}

	/** @return array<string,mixed>|null */
	public function nextFor( int $memberId ): ?array {
		foreach ( $this->forMember( $memberId ) as $w ) {
			if ( $w['eligible'] ) {
				return $w;
			}
		}
		return null;
	}

	public function ics( int $id ): string {
		$w     = $this->workshops->findOrFail( $id, 'workshop' );
		$fmt   = static fn( string $mysql ) => gmdate( 'Ymd\THis\Z', (int) strtotime( $mysql . ' UTC' ) );
		$esc   = static fn( string $s ) => addcslashes( str_replace( array( "\r\n", "\n" ), '\n', $s ), ',;' );
		$host  = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//WitsValley//Members//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:wvm-workshop-' . $id . '@' . $host,
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART:' . $fmt( (string) $w['starts_at'] ),
			'DTEND:' . $fmt( (string) $w['ends_at'] ),
			'SUMMARY:' . $esc( (string) $w['title'] ),
			'DESCRIPTION:' . $esc( wp_strip_all_tags( (string) $w['description'] ) . "\n\nJoin from your dashboard: " . \WitsValley\Members\Domain\Notification\Urls::dashboard( 'workshops' ) ),
			'URL:' . \WitsValley\Members\Domain\Notification\Urls::dashboard( 'workshops' ),
			'BEGIN:VALARM',
			'TRIGGER:-PT15M',
			'ACTION:DISPLAY',
			'DESCRIPTION:' . $esc( (string) $w['title'] ),
			'END:VALARM',
			'END:VEVENT',
			'END:VCALENDAR',
		);
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/** Email a template to every eligible member (e.g. announcement). */
	public function notifyEligible( int $id, string $templateKey ): int {
		$w = $this->workshops->findOrFail( $id, 'workshop' );
		global $wpdb;
		$m     = Db::table( 'members' );
		$ids   = $wpdb->get_col( "SELECT id FROM {$m} WHERE anonymised_at IS NULL" ); // phpcs:ignore WordPress.DB
		$count = 0;
		foreach ( $ids as $mid ) {
			if ( $this->eligible( $w, (int) $mid ) ) {
				wvm( Notifier::class )->member( (int) $mid, $templateKey, $this->payload( $w, (int) $mid ), 'workshop', $w['title'], 'Workshop on ' . $this->displayTime( $w, (int) $mid ) );
				++$count;
			}
		}
		$this->audit->log(
			'workshop.notified',
			'workshop',
			$id,
			null,
			array(
				'template'   => $templateKey,
				'recipients' => $count,
			)
		);
		return $count;
	}

	/** Reminder job: 24h / 1h before start → registered members. */
	public function sendReminder( int $id, string $templateKey ): int {
		$w = $this->workshops->find( $id );
		if ( ! $w || ! in_array( $w['status'], array( 'scheduled', 'live' ), true ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $this->registrations->where( array( 'workshop_id' => $id ) ) as $reg ) {
			wvm( Notifier::class )->member( (int) $reg['member_id'], $templateKey, $this->payload( $w, (int) $reg['member_id'] ), 'workshop', ( 'workshop_1h' === $templateKey ? 'Starting soon: ' : 'Tomorrow: ' ) . $w['title'], $this->displayTime( $w, (int) $reg['member_id'] ) );
			++$count;
		}
		return $count;
	}

	private function scheduleReminders( int $id ): void {
		$w = $this->workshops->findOrFail( $id );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Scheduler::WORKSHOP_REMINDER, array( $id, 'workshop_24h' ), Scheduler::GROUP );
			as_unschedule_all_actions( Scheduler::WORKSHOP_REMINDER, array( $id, 'workshop_1h' ), Scheduler::GROUP );
		}
		$start = (int) Clock::timestamp( $w['starts_at'] );
		foreach ( array(
			'workshop_24h' => DAY_IN_SECONDS,
			'workshop_1h'  => HOUR_IN_SECONDS,
		) as $key => $offset ) {
			if ( $start - $offset > time() ) {
				$this->scheduler->at( $start - $offset, Scheduler::WORKSHOP_REMINDER, array( $id, $key ) );
			}
		}
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function normalise( array $data ): array {
		$row = array();
		foreach ( array( 'title', 'description', 'host', 'required_level', 'join_url', 'recording_url', 'capacity', 'organisation_id' ) as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$row[ $k ] = $data[ $k ];
			}
		}
		foreach ( array( 'starts_at', 'ends_at' ) as $k ) {
			if ( ! empty( $data[ $k ] ) ) {
				$row[ $k ] = gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $data[ $k ] ) );
			}
		}
		if ( array_key_exists( 'capacity', $row ) && ! $row['capacity'] ) {
			$row['capacity'] = null;
		}
		if ( array_key_exists( 'organisation_id', $row ) && ! $row['organisation_id'] ) {
			$row['organisation_id'] = null;
		}
		return $row;
	}

	/** @param array<string,mixed> $row */
	private function assertTimes( array $row ): void {
		if ( ! empty( $row['starts_at'] ) && ! empty( $row['ends_at'] ) && strtotime( (string) $row['ends_at'] ) <= strtotime( (string) $row['starts_at'] ) ) {
			throw DomainError::validation( array( 'ends_at' => 'End time must be after the start.' ) );
		}
	}

	/** @param array<string,mixed> $w */
	private function displayTime( array $w, int $memberId ): string {
		$member = $this->members->find( $memberId );
		return Clock::inZone( (string) $w['starts_at'], (string) ( $member['timezone'] ?? 'Africa/Kampala' ), 'D j M, H:i T' );
	}

	/**
	 * @param array<string,mixed> $w
	 * @return array<string,mixed>
	 */
	private function payload( array $w, int $memberId ): array {
		return array(
			'workshop_title' => (string) $w['title'],
			'workshop_time'  => $this->displayTime( $w, $memberId ),
			'join_url'       => \WitsValley\Members\Domain\Notification\Urls::dashboard( 'workshops' ),
		);
	}

	/**
	 * @param array<string,mixed> $w
	 * @return array<string,mixed>
	 */
	public function present( array $w, bool $withRegistrations = false ): array {
		$out = array(
			'id'                   => (int) $w['id'],
			'title'                => (string) $w['title'],
			'description'          => (string) $w['description'],
			'starts_at'            => Clock::iso( $w['starts_at'] ),
			'ends_at'              => Clock::iso( $w['ends_at'] ),
			'host'                 => (string) $w['host'],
			'required_level'       => (int) $w['required_level'],
			'required_level_label' => LevelResolver::label( (int) $w['required_level'] ),
			'organisation_id'      => $w['organisation_id'] ? (int) $w['organisation_id'] : null,
			'join_url'             => $w['join_url'],
			'recording_url'        => $w['recording_url'],
			'capacity'             => $w['capacity'] ? (int) $w['capacity'] : null,
			'status'               => (string) $w['status'],
			'registration_count'   => $this->registrations->count( array( 'workshop_id' => (int) $w['id'] ) ),
			'created_at'           => Clock::iso( $w['created_at'] ),
		);
		if ( $withRegistrations ) {
			$out['registrations'] = array_map(
				function ( array $r ): array {
					$m    = $this->members->find( (int) $r['member_id'] );
					$user = $m ? get_userdata( (int) $m['user_id'] ) : null;
					return array(
						'member_id'  => (int) $r['member_id'],
						'public_id'  => $m['public_id'] ?? '',
						'name'       => $user ? $user->display_name : '—',
						'email'      => $user ? $user->user_email : '',
						'created_at' => Clock::iso( $r['created_at'] ),
					);
				},
				$this->registrations->where( array( 'workshop_id' => (int) $w['id'] ), 'id ASC' )
			);
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $w
	 * @return array<string,mixed>
	 */
	public function presentForMember( array $w, int $memberId ): array {
		$start = (int) Clock::timestamp( $w['starts_at'] );
		$end   = (int) Clock::timestamp( $w['ends_at'] );
		$now   = Clock::now()->getTimestamp();
		$elig  = $this->eligible( $w, $memberId );
		$out   = $this->present( $w );
		unset( $out['join_url'] );
		$out['eligible']      = $elig;
		$out['registered']    = (bool) $this->registrations->first(
			array(
				'workshop_id' => (int) $w['id'],
				'member_id'   => $memberId,
			)
		);
		$out['can_join_now']  = $elig && 'cancelled' !== $w['status'] && $now >= $start - self::JOIN_WINDOW && $now <= $end + HOUR_IN_SECONDS;
		$out['join_opens_at'] = gmdate( 'Y-m-d\TH:i:s\Z', $start - self::JOIN_WINDOW );
		$out['is_full']       = $w['capacity'] && $out['registration_count'] >= (int) $w['capacity'];
		$out['recording_url'] = $elig ? $w['recording_url'] : null;
		$out['calendar_url']  = rest_url( WVM_REST_NS . '/me/workshops/' . (int) $w['id'] . '/calendar.ics' );
		return $out;
	}
}
