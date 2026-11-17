<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Workshop;

use WitsValley\Members\Domain\Access\LevelResolver;
use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Member\MemberRepository;
use WitsValley\Members\Domain\Notification\Notifier;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Pagination;
use WitsValley\Members\Support\Validation;

/**
 * 1:1 consultation requests (Premium and above).
 */
final class ConsultationService {

	public const MIN_LEVEL = 2;

	public const STATUSES = array( 'new', 'scheduled', 'done', 'declined' );

	public function __construct(
		private ConsultationRepository $repo,
		private MemberRepository $members,
		private LevelResolver $levels,
		private Audit $audit
	) {}

	/**
	 * @param array<string,mixed> $dto topic, preferred_dates, notes
	 * @return array<string,mixed>
	 */
	public function request( int $memberId, array $dto ): array {
		$member = $this->members->findOrFail( $memberId, 'member' );
		if ( $this->levels->levelFor( (int) $member['user_id'] ) < self::MIN_LEVEL ) {
			throw DomainError::forbidden( 'Consultations are included with Premium. Upgrade to request one.' );
		}
		$open = $this->repo->count(
			array(
				'member_id' => $memberId,
				'status'    => 'new',
			)
		);
		if ( $open >= 3 ) {
			throw DomainError::conflict( 'You already have open requests — we will be in touch soon.' );
		}
		$data = Validation::validate(
			$dto,
			array(
				'topic'           => 'required|maxlen:200',
				'preferred_dates' => 'text',
				'notes'           => 'text',
			)
		);
		$id   = $this->repo->insert(
			array(
				'member_id'       => $memberId,
				'topic'           => $data['topic'],
				'preferred_dates' => $data['preferred_dates'] ?? '',
				'notes'           => $data['notes'] ?? '',
				'status'          => 'new',
			)
		);
		$out  = $this->get( $id );
		$this->audit->log( 'consultation.requested', 'consultation', $id, null, $out );
		wvm( Notifier::class )->admin( 'New consultation request', sprintf( "%s requested a consultation: %s\n\n%s", $out['member_name'], $out['topic'], admin_url( 'admin.php?page=wvm-workshops#/consultations' ) ) );
		return $out;
	}

	/** @return array<string,mixed> */
	public function get( int $id ): array {
		return $this->present( $this->repo->findOrFail( $id, 'consultation' ) );
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
		$res          = $this->repo->paginate( $cond, $args, $p );
		$res['items'] = array_map( array( $this, 'present' ), $res['items'] );
		return $res;
	}

	/** @return list<array<string,mixed>> */
	public function forMember( int $memberId ): array {
		return array_map(
			function ( array $r ): array {
				$out = $this->present( $r );
				unset( $out['admin_notes'] );
				return $out;
			},
			$this->repo->where( array( 'member_id' => $memberId ), 'id DESC', 20 )
		);
	}

	/**
	 * @param array<string,mixed> $dto status, scheduled_at, meeting_url, admin_notes
	 * @return array<string,mixed>
	 */
	public function update( int $id, array $dto ): array {
		$before = $this->get( $id );
		$data   = Validation::validate(
			$dto,
			array(
				'status'       => 'in:new,scheduled,done,declined',
				'scheduled_at' => 'date',
				'meeting_url'  => 'url',
				'admin_notes'  => 'text',
			)
		);
		$row    = array();
		foreach ( array( 'status', 'meeting_url', 'admin_notes' ) as $k ) {
			if ( array_key_exists( $k, $data ) ) {
				$row[ $k ] = $data[ $k ];
			}
		}
		if ( ! empty( $data['scheduled_at'] ) ) {
			$row['scheduled_at'] = gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $data['scheduled_at'] ) );
		}
		if ( 'scheduled' === ( $row['status'] ?? '' ) && empty( $row['scheduled_at'] ) && ! $before['scheduled_at'] ) {
			throw DomainError::validation( array( 'scheduled_at' => 'Pick a date and time.' ) );
		}
		$this->repo->update( $id, $row );
		$after = $this->get( $id );
		$this->audit->log( 'consultation.updated', 'consultation', $id, $before, $after );
		if ( 'scheduled' === $after['status'] && ( 'scheduled' !== $before['status'] || $after['scheduled_at'] !== $before['scheduled_at'] ) ) {
			$member = $this->members->find( (int) $after['member_id'] );
			wvm( Notifier::class )->member(
				(int) $after['member_id'],
				'consultation_confirmed',
				array(
					'consultation_time' => Clock::inZone( (string) $this->repo->find( $id )['scheduled_at'], (string) ( $member['timezone'] ?? 'Africa/Kampala' ), 'D j M Y, H:i T' ),
					'meeting_url'       => $after['meeting_url'] ?: \WitsValley\Members\Domain\Notification\Urls::dashboard( 'workshops' ),
				),
				'consultation',
				'Consultation confirmed',
				$after['topic']
			);
		}
		return $after;
	}

	/** @return array<string,mixed> */
	public function schedule( int $id, string $datetime, string $url ): array {
		return $this->update(
			$id,
			array(
				'status'       => 'scheduled',
				'scheduled_at' => $datetime,
				'meeting_url'  => $url,
			)
		);
	}

	/** @return array<string,mixed> */
	public function decline( int $id, string $note ): array {
		return $this->update(
			$id,
			array(
				'status'      => 'declined',
				'admin_notes' => $note,
			)
		);
	}

	/** @return array<string,mixed> */
	public function complete( int $id ): array {
		return $this->update( $id, array( 'status' => 'done' ) );
	}

	/**
	 * @param array<string,mixed> $r
	 * @return array<string,mixed>
	 */
	private function present( array $r ): array {
		$m    = $this->members->find( (int) $r['member_id'] );
		$user = $m ? get_userdata( (int) $m['user_id'] ) : null;
		return array(
			'id'               => (int) $r['id'],
			'member_id'        => (int) $r['member_id'],
			'member_public_id' => $m['public_id'] ?? '',
			'member_name'      => $user ? $user->display_name : '—',
			'member_email'     => $user ? $user->user_email : '',
			'topic'            => (string) $r['topic'],
			'preferred_dates'  => (string) $r['preferred_dates'],
			'notes'            => (string) $r['notes'],
			'status'           => (string) $r['status'],
			'scheduled_at'     => Clock::iso( $r['scheduled_at'] ),
			'meeting_url'      => $r['meeting_url'],
			'admin_notes'      => $r['admin_notes'],
			'created_at'       => Clock::iso( $r['created_at'] ),
		);
	}
}
