<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Enterprise;

use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Notification\Mailer;
use WitsValley\Members\Domain\Notification\Notifier;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Pagination;
use WitsValley\Members\Support\Validation;

/**
 * Enterprise & Private programme applications (public form → inbox → organisation).
 */
final class ApplicationService {

	public const STATUSES = array( 'new', 'in_review', 'quoted', 'accepted', 'declined' );

	public const RATE_LIMIT = 5;

	public function __construct(
		private ApplicationRepository $repo,
		private Audit $audit
	) {}

	/**
	 * @param array<string,mixed> $dto
	 * @return array<string,mixed>
	 */
	public function submit( array $dto, string $ip = '' ): array {
		$key   = 'wvm_app_rl_' . md5( $ip ?: 'none' );
		$count = (int) get_transient( $key );
		if ( $count >= self::RATE_LIMIT ) {
			throw new DomainError( 'Too many applications from your network. Please try again later.', 'wvm_rate_limited', 429 );
		}
		$data = Validation::validate(
			$dto,
			array(
				'org_name'         => 'required|maxlen:200',
				'contact_name'     => 'required|maxlen:120',
				'email'            => 'required|email',
				'phone'            => 'phone',
				'country'          => 'country',
				'org_size'         => 'maxlen:30',
				'programme_type'   => 'maxlen:100',
				'participants'     => 'int|min:1|max:100000',
				'duration'         => 'maxlen:60',
				'start_preference' => 'maxlen:60',
				'budget_range'     => 'maxlen:60',
				'message'          => 'text|maxlen:5000',
			)
		);
		$data['status'] = 'new';
		$id             = $this->repo->insert( $data );
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		$app = $this->get( $id );
		$this->audit->log( 'application.submitted', 'application', $id, null, $app );
		$first = explode( ' ', (string) $data['contact_name'] )[0];
		wvm( Mailer::class )->send( 'enterprise_ack', (string) $data['email'], array( 'first_name' => $first, 'org_name' => $data['org_name'] ) );
		wvm( Notifier::class )->admin( 'New Enterprise application', sprintf( "%s (%s) applied for %s participants.\n\nReview: %s", $data['org_name'], $data['email'], $data['participants'] ?? '?', admin_url( 'admin.php?page=wvm-enterprise#/enterprise/applications' ) ) );
		return $app;
	}

	/** @return array<string,mixed> */
	public function get( int $id ): array {
		return $this->present( $this->repo->findOrFail( $id, 'application' ) );
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
		$res          = $this->repo->paginate( $cond, $args, $p );
		$res['items'] = array_map( array( $this, 'present' ), $res['items'] );
		$res['counts'] = $this->counts();
		return $res;
	}

	/** @return array<string,int> */
	public function counts(): array {
		$out = array();
		foreach ( self::STATUSES as $s ) {
			$out[ $s ] = $this->repo->count( array( 'status' => $s ) );
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $dto status, admin_notes
	 * @return array<string,mixed>
	 */
	public function update( int $id, array $dto ): array {
		$before = $this->get( $id );
		$data   = Validation::validate(
			$dto,
			array(
				'status'      => 'in:new,in_review,quoted,accepted,declined',
				'admin_notes' => 'text',
			)
		);
		$this->repo->update( $id, $data );
		$after = $this->get( $id );
		$this->audit->log( 'application.updated', 'application', $id, $before, $after );
		return $after;
	}

	/**
	 * @param array<string,mixed> $orgDto
	 * @return array<string,mixed> organisation
	 */
	public function convertToOrganisation( int $id, array $orgDto ): array {
		$app = $this->get( $id );
		if ( $app['organisation_id'] ) {
			throw DomainError::conflict( 'This application was already converted.' );
		}
		$dto = array_merge(
			array(
				'name'          => $app['org_name'],
				'seats'         => $app['participants'] ?: 10,
				'contact_email' => $app['email'],
				'contact_name'  => $app['contact_name'],
				'programme_title' => $app['programme_type'],
			),
			$orgDto
		);
		$org = wvm( OrganisationService::class )->create( $dto );
		$this->repo->update(
			$id,
			array(
				'status'          => 'accepted',
				'organisation_id' => (int) $org['id'],
			)
		);
		$this->audit->log( 'application.converted', 'application', $id, $app, $this->get( $id ) );
		return $org;
	}

	/**
	 * @param array<string,mixed> $r
	 * @return array<string,mixed>
	 */
	private function present( array $r ): array {
		$r['id']              = (int) $r['id'];
		$r['participants']    = $r['participants'] ? (int) $r['participants'] : null;
		$r['organisation_id'] = $r['organisation_id'] ? (int) $r['organisation_id'] : null;
		$r['created_at']      = Clock::iso( $r['created_at'] );
		$r['updated_at']      = Clock::iso( $r['updated_at'] );
		return $r;
	}
}
