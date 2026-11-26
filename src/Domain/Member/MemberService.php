<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Member;

use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Subscription\SubscriptionRepository;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Encryption;
use WitsValley\Members\Support\Pagination;
use WitsValley\Members\Support\Validation;

/**
 * Members: creation, profile, notes, search, export, privacy, impersonation.
 */
final class MemberService {

	public const DEFAULT_PREFS = array(
		'renewal_reminders'  => true,
		'workshop_reminders' => true,
		'product_updates'    => true,
		'in_app'             => true,
	);

	public function __construct(
		private MemberRepository $members,
		private NoteRepository $notes,
		private SubscriptionRepository $subscriptions,
		private Audit $audit
	) {}

	/**
	 * Create a WP user + member row (public signup or admin create).
	 *
	 * @param array<string,mixed> $dto name,email,phone,country,password,timezone,marketing_consent
	 * @return array<string,mixed> Presented member.
	 */
	public function createFromSignup( array $dto, bool $requirePassword = true ): array {
		$data = Validation::validate(
			$dto,
			array(
				'name'              => 'required|maxlen:120',
				'email'             => 'required|email',
				'phone'             => 'phone',
				'country'           => 'country',
				'password'          => $requirePassword ? 'required|raw|minlen:8' : 'raw',
				'timezone'          => 'timezone',
				'marketing_consent' => 'bool',
			)
		);
		if ( email_exists( $data['email'] ) ) {
			throw DomainError::validation( array( 'email' => 'An account with this email already exists. Please log in instead.' ), 'Email already registered.' );
		}
		if ( $requirePassword ) {
			$this->assertPasswordPolicy( (string) $data['password'] );
		}
		$name  = trim( (string) $data['name'] );
		$parts = preg_split( '/\s+/', $name, 2 ) ?: array( $name );
		$login = $this->uniqueLogin( (string) $data['email'] );
		$uid   = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_email'   => $data['email'],
				'user_pass'    => $data['password'] ?: wp_generate_password( 20 ),
				'display_name' => $name,
				'first_name'   => $parts[0],
				'last_name'    => $parts[1] ?? '',
				'role'         => get_role( 'customer' ) ? 'customer' : 'subscriber',
			)
		);
		if ( is_wp_error( $uid ) ) {
			throw DomainError::validation( array( 'email' => $uid->get_error_message() ) );
		}
		return $this->createForUser( (int) $uid, $data );
	}

	/**
	 * Ensure a member row exists for an existing WP user.
	 *
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public function createForUser( int $userId, array $data = array() ): array {
		$existing = $this->members->byUser( $userId );
		if ( $existing ) {
			return $this->present( $existing );
		}
		$consent = ! empty( $data['marketing_consent'] );
		$id      = Db::transaction(
			function () use ( $userId, $data, $consent ): int {
				$id = $this->members->insert(
					array(
						'user_id'              => $userId,
						'phone'                => $data['phone'] ?? null,
						'country'              => $data['country'] ?? null,
						'timezone'             => $data['timezone'] ?? 'Africa/Kampala',
						'marketing_consent'    => $consent,
						'marketing_consent_at' => $consent ? Clock::mysql() : null,
						'notification_prefs'   => self::DEFAULT_PREFS,
					)
				);
				$this->members->update( $id, array( 'public_id' => self::publicId( $id ) ) );
				return $id;
			}
		);
		if ( ! empty( $data['phone'] ) ) {
			update_user_meta( $userId, 'billing_phone', $data['phone'] );
		}
		if ( ! empty( $data['country'] ) ) {
			update_user_meta( $userId, 'billing_country', $data['country'] );
		}
		$member = $this->present( $this->members->findOrFail( $id ) );
		$this->audit->log( 'member.created', 'member', $id, null, $member );
		do_action( 'wvm/member/created', $member );
		return $member;
	}

	public static function publicId( int $id ): string {
		return 'WV-' . str_pad( (string) $id, 6, '0', STR_PAD_LEFT );
	}

	/** @return array<string,mixed> */
	public function get( int|string $idOrPublic ): array {
		$row = is_int( $idOrPublic ) ? $this->members->find( $idOrPublic ) : $this->members->findBy( 'public_id', strtoupper( $idOrPublic ) );
		if ( ! $row ) {
			throw DomainError::notFound( 'member' );
		}
		return $this->present( $row );
	}

	/** @return array<string,mixed>|null */
	public function byUser( int $userId ): ?array {
		$row = $this->members->byUser( $userId );
		return $row ? $this->present( $row ) : null;
	}

	/** @return array<string,mixed> Member for current user, created lazily for legacy users. */
	public function current(): array {
		$uid = get_current_user_id();
		if ( ! $uid ) {
			throw new DomainError( 'Please log in.', 'wvm_not_logged_in', 401 );
		}
		return $this->byUser( $uid ) ?? $this->createForUser( $uid );
	}

	/** @return array<string,mixed> */
	public function raw( int $id ): array {
		return $this->members->findOrFail( $id, 'member' );
	}

	/**
	 * @param array<string,mixed> $dto
	 * @return array<string,mixed>
	 */
	public function update( int $id, array $dto, ?string $reason = null ): array {
		$before = $this->get( $id );
		$data   = Validation::validate(
			$dto,
			array(
				'name'               => 'maxlen:120',
				'phone'              => 'phone',
				'country'            => 'country',
				'timezone'           => 'timezone',
				'marketing_consent'  => 'bool',
				'notification_prefs' => 'array',
			)
		);
		$row    = array();
		foreach ( array( 'phone', 'country', 'timezone' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$row[ $key ] = $data[ $key ];
			}
		}
		$consent_changed = false;
		if ( array_key_exists( 'marketing_consent', $data ) && (bool) $data['marketing_consent'] !== $before['marketing_consent'] ) {
			$row['marketing_consent']    = (bool) $data['marketing_consent'];
			$row['marketing_consent_at'] = Clock::mysql();
			$consent_changed             = true;
		}
		if ( array_key_exists( 'notification_prefs', $data ) ) {
			$prefs = array();
			foreach ( self::DEFAULT_PREFS as $key => $default ) {
				$prefs[ $key ] = isset( $data['notification_prefs'][ $key ] ) ? (bool) $data['notification_prefs'][ $key ] : (bool) ( $before['notification_prefs'][ $key ] ?? $default );
			}
			$row['notification_prefs'] = $prefs;
		}
		if ( $row ) {
			$this->members->update( $id, $row );
		}
		if ( ! empty( $data['name'] ) ) {
			$name  = trim( (string) $data['name'] );
			$parts = preg_split( '/\s+/', $name, 2 ) ?: array( $name );
			wp_update_user(
				array(
					'ID'           => $before['user_id'],
					'display_name' => $name,
					'first_name'   => $parts[0],
					'last_name'    => $parts[1] ?? '',
				)
			);
		}
		if ( isset( $row['phone'] ) ) {
			update_user_meta( (int) $before['user_id'], 'billing_phone', $row['phone'] );
		}
		if ( isset( $row['country'] ) ) {
			update_user_meta( (int) $before['user_id'], 'billing_country', $row['country'] );
		}
		$after = $this->get( $id );
		$this->audit->log( 'member.updated', 'member', $id, $before, $after, $reason );
		if ( $consent_changed ) {
			do_action( 'wvm/member/consent_changed', $after );
		}
		return $after;
	}

	/** @return array<string,mixed> */
	public function addNote( int $memberId, string $body ): array {
		$this->members->findOrFail( $memberId, 'member' );
		$body = trim( sanitize_textarea_field( $body ) );
		if ( '' === $body ) {
			throw DomainError::validation( array( 'body' => 'Note cannot be empty.' ) );
		}
		$id = $this->notes->insert(
			array(
				'member_id' => $memberId,
				'author_id' => get_current_user_id() ?: null,
				'body'      => $body,
			)
		);
		$this->refreshNotesCount( $memberId );
		$note = $this->presentNote( $this->notes->findOrFail( $id ) );
		$this->audit->log( 'member.note_added', 'member', $memberId, null, $note );
		return $note;
	}

	public function deleteNote( int $memberId, int $noteId ): void {
		$note = $this->notes->findOrFail( $noteId, 'note' );
		if ( (int) $note['member_id'] !== $memberId ) {
			throw DomainError::notFound( 'note' );
		}
		$this->notes->delete( $noteId );
		$this->refreshNotesCount( $memberId );
		$this->audit->log( 'member.note_deleted', 'member', $memberId, $this->presentNote( $note ), null );
	}

	/** @return list<array<string,mixed>> */
	public function notes( int $memberId ): array {
		return array_map( array( $this, 'presentNote' ), $this->notes->where( array( 'member_id' => $memberId ), 'id DESC' ) );
	}

	/**
	 * Filterable member list.
	 *
	 * @param array<string,mixed> $f
	 * @return array{items:list<array<string,mixed>>, meta:array<string,int>}
	 */
	public function list( array $f, Pagination $p ): array {
		global $wpdb;
		[ $where, $args ] = $this->filterSql( $f );
		$m                = Db::table( 'members' );
		$s                = Db::table( 'subscriptions' );
		$pl               = Db::table( 'plans' );
		$join             = $this->joinSql();
		$sort             = match ( (string) ( $f['sort'] ?? '' ) ) {
			'name'          => 'u.display_name ASC',
			'-name'         => 'u.display_name DESC',
			'joined'        => 'm.created_at ASC',
			'renewal'       => 'cs.current_period_end ASC',
			'-renewal'      => 'cs.current_period_end DESC',
			default         => 'm.id DESC',
		};
		$where_sql = $where ? 'WHERE ' . implode( ' AND ', $where ) : '';
		$total     = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$m} m {$join} {$where_sql}", $args ) : "SELECT COUNT(*) FROM {$m} m {$join} {$where_sql}" ); // phpcs:ignore WordPress.DB
		$sql       = "SELECT m.*, u.user_email, u.display_name, cs.id AS sub_id, cs.status AS sub_status, cs.current_period_end AS sub_period_end, cs.cancel_at_period_end AS sub_cancel, cs.currency AS sub_currency, cs.amount_minor AS sub_amount, cp.name AS plan_name, cp.slug AS plan_slug
			FROM {$m} m {$join} {$where_sql} ORDER BY {$sort} LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $p->perPage, $p->offset() ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		unset( $s, $pl );
		$items = array();
		foreach ( $rows ?: array() as $row ) {
			$items[] = $this->presentListRow( $row );
		}
		return array(
			'items' => $items,
			'meta'  => $p->meta( $total ),
		);
	}

	/** @return list<array<string,mixed>> */
	public function search( string $q, int $limit = 10 ): array {
		return $this->list( array( 'q' => $q ), new Pagination( 1, $limit ) )['items'];
	}

	/**
	 * CSV export of the filtered list.
	 *
	 * @param array<string,mixed> $f
	 */
	public function exportCsv( array $f ): string {
		$fh = fopen( 'php://temp', 'w+' );
		if ( ! $fh ) {
			return '';
		}
		fputcsv( $fh, array( 'Member ID', 'Name', 'Email', 'Phone', 'Country', 'Plan', 'Status', 'Renews / ends', 'Joined', 'Marketing consent' ) );
		$page = 1;
		do {
			$res = $this->list( $f, new Pagination( $page, 200 ) );
			foreach ( $res['items'] as $r ) {
				fputcsv( $fh, array( $r['public_id'], $r['name'], $r['email'], $r['phone'], $r['country'], $r['plan_name'], $r['status'], $r['period_end'], $r['created_at'], $r['marketing_consent'] ? 'yes' : 'no' ) );
			}
			++$page;
		} while ( $page <= $res['meta']['pages'] );
		rewind( $fh );
		$csv = (string) stream_get_contents( $fh );
		fclose( $fh );
		$this->audit->log( 'member.exported', 'member', null, null, array( 'filters' => $f ) );
		return $csv;
	}

	/** Uses the core WordPress privacy request flow (Tools › Export Personal Data). */
	public function requestDataExport( int $id ): int {
		$member = $this->get( $id );
		$req    = wp_create_user_request( (string) $member['email'], 'export_personal_data', array(), 'confirmed' );
		if ( is_wp_error( $req ) ) {
			throw DomainError::conflict( $req->get_error_message() );
		}
		$this->audit->log( 'member.data_export_requested', 'member', $id );
		do_action( 'wvm/member/data_export_requested', $member, (int) $req );
		return (int) $req;
	}

	public function requestDeletion( int $id ): int {
		$member = $this->get( $id );
		$req    = wp_create_user_request( (string) $member['email'], 'remove_personal_data' );
		if ( is_wp_error( $req ) ) {
			throw DomainError::conflict( $req->get_error_message() );
		}
		wp_send_user_request( (int) $req );
		$this->audit->log( 'member.deletion_requested', 'member', $id );
		return (int) $req;
	}

	/**
	 * GDPR anonymise: pseudonymise PII, keep financial rows.
	 *
	 * @return array<string,mixed>
	 */
	public function anonymise( int $id, string $reason ): array {
		if ( '' === trim( $reason ) ) {
			throw DomainError::validation( array( 'reason' => 'A reason is required.' ) );
		}
		$before = $this->get( $id );
		$pseudo = 'anon-' . strtolower( (string) $before['public_id'] ) . '@anonymised.invalid';
		wp_update_user(
			array(
				'ID'           => $before['user_id'],
				'user_email'   => $pseudo,
				'display_name' => 'Anonymised member',
				'first_name'   => 'Anonymised',
				'last_name'    => '',
				'user_url'     => '',
				'user_pass'    => wp_generate_password( 32 ),
			)
		);
		foreach ( array( 'billing_phone', 'billing_first_name', 'billing_last_name', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_email', 'description', 'nickname' ) as $meta ) {
			delete_user_meta( (int) $before['user_id'], $meta );
		}
		$this->members->update(
			$id,
			array(
				'phone'             => null,
				'marketing_consent' => false,
				'kit_subscriber_id' => null,
				'anonymised_at'     => Clock::mysql(),
			)
		);
		\WP_Session_Tokens::get_instance( (int) $before['user_id'] )->destroy_all();
		$after = $this->get( $id );
		$this->audit->log( 'member.anonymised', 'member', $id, $before, $after, $reason );
		return $after;
	}

	/**
	 * One-time impersonation URL (valid 5 minutes).
	 */
	public function impersonate( int $id ): string {
		$member = $this->get( $id );
		if ( user_can( (int) $member['user_id'], 'manage_options' ) || user_can( (int) $member['user_id'], 'wvm_access' ) ) {
			throw DomainError::forbidden( 'Staff accounts cannot be impersonated.' );
		}
		$token = Encryption::token( 20 );
		set_transient(
			'wvm_imp_' . hash( 'sha256', $token ),
			array(
				'admin_id'  => get_current_user_id(),
				'member_id' => $id,
				'user_id'   => (int) $member['user_id'],
			),
			5 * MINUTE_IN_SECONDS
		);
		$this->audit->log( 'member.impersonation_started', 'member', $id );
		return add_query_arg( 'wvm_impersonate', $token, home_url( '/' ) );
	}

	/**
	 * Bulk add a Kit tag to members (queued via KitSync).
	 *
	 * @param list<int> $ids
	 */
	public function bulkTag( array $ids, string $tag ): int {
		$tag = sanitize_text_field( $tag );
		if ( '' === $tag ) {
			throw DomainError::validation( array( 'value' => 'Tag is required.' ) );
		}
		foreach ( $ids as $id ) {
			do_action( 'wvm/member/bulk_tag', (int) $id, $tag );
		}
		$this->audit->log(
			'member.bulk_tag',
			'member',
			null,
			null,
			array(
				'ids' => $ids,
				'tag' => $tag,
			)
		);
		return count( $ids );
	}

	/**
	 * Email change with verification link.
	 */
	public function requestEmailChange( int $id, string $email ): void {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			throw DomainError::validation( array( 'email' => 'Enter a valid email address.' ) );
		}
		if ( email_exists( $email ) ) {
			throw DomainError::validation( array( 'email' => 'That email is already in use.' ) );
		}
		$member = $this->get( $id );
		$token  = Encryption::token( 20 );
		set_transient(
			'wvm_email_' . hash( 'sha256', $token ),
			array(
				'member_id' => $id,
				'email'     => $email,
			),
			DAY_IN_SECONDS
		);
		$this->audit->log( 'member.email_change_requested', 'member', $id, null, array( 'new_email' => $email ) );
		do_action( 'wvm/member/email_change_requested', $member, $email, $token );
	}

	/** @return array<string,mixed> */
	public function confirmEmailChange( string $token, ?int $expectMemberId = null ): array {
		$key  = 'wvm_email_' . hash( 'sha256', $token );
		$data = get_transient( $key );
		if ( ! is_array( $data ) || ( $expectMemberId && (int) $data['member_id'] !== $expectMemberId ) ) {
			throw new DomainError( 'This verification link is invalid or has expired.', 'wvm_invalid_token', 400 );
		}
		delete_transient( $key );
		$before = $this->get( (int) $data['member_id'] );
		$res    = wp_update_user(
			array(
				'ID'         => $before['user_id'],
				'user_email' => $data['email'],
			)
		);
		if ( is_wp_error( $res ) ) {
			throw DomainError::validation( array( 'email' => $res->get_error_message() ) );
		}
		update_user_meta( (int) $before['user_id'], 'billing_email', $data['email'] );
		$after = $this->get( (int) $data['member_id'] );
		$this->audit->log( 'member.email_changed', 'member', (int) $data['member_id'], array( 'email' => $before['email'] ), array( 'email' => $after['email'] ) );
		do_action( 'wvm/member/email_changed', $after, $before['email'] );
		return $after;
	}

	/** @return list<array<string,mixed>> */
	public function sessions( int $userId ): array {
		$manager = \WP_Session_Tokens::get_instance( $userId );
		$current = wp_get_session_token();
		$out     = array();
		foreach ( $manager->get_all() as $verifier => $session ) {
			$out[] = array(
				'id'         => substr( (string) $verifier, 0, 12 ),
				'ip'         => $session['ip'] ?? '',
				'user_agent' => $session['ua'] ?? '',
				'login_at'   => isset( $session['login'] ) ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $session['login'] ) : null,
				'expires_at' => isset( $session['expiration'] ) ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $session['expiration'] ) : null,
				'current'    => $current && hash_equals( (string) $verifier, hash( 'sha256', $current ) ),
			);
		}
		return $out;
	}

	public function destroyOtherSessions( int $userId ): void {
		$token = wp_get_session_token();
		$mgr   = \WP_Session_Tokens::get_instance( $userId );
		if ( $token ) {
			$mgr->destroy_others( $token );
		} else {
			$mgr->destroy_all();
		}
	}

	/** @param array<string,mixed> $row */
	public function present( array $row ): array {
		$user = get_userdata( (int) $row['user_id'] );
		return array(
			'id'                     => (int) $row['id'],
			'public_id'              => (string) $row['public_id'],
			'user_id'                => (int) $row['user_id'],
			'name'                   => $user ? (string) $user->display_name : '',
			'first_name'             => $user ? (string) ( $user->first_name ?: $user->display_name ) : '',
			'last_name'              => $user ? (string) $user->last_name : '',
			'email'                  => $user ? (string) $user->user_email : '',
			'avatar_url'             => $user ? (string) get_avatar_url( $user->ID, array( 'size' => 96 ) ) : '',
			'phone'                  => (string) ( $row['phone'] ?? '' ),
			'country'                => (string) ( $row['country'] ?? '' ),
			'timezone'               => (string) ( $row['timezone'] ?: 'Africa/Kampala' ),
			'marketing_consent'      => (bool) $row['marketing_consent'],
			'marketing_consent_at'   => Clock::iso( $row['marketing_consent_at'] ?? null ),
			'notification_prefs'     => array_merge( self::DEFAULT_PREFS, is_array( $row['notification_prefs'] ?? null ) ? $row['notification_prefs'] : array() ),
			'kit_subscriber_id'      => $row['kit_subscriber_id'] ?? null,
			'kit_last_synced_at'     => Clock::iso( $row['kit_last_synced_at'] ?? null ),
			'kit_sync_error'         => $row['kit_sync_error'] ?? null,
			'kit_unsubscribed_at'    => Clock::iso( $row['kit_unsubscribed_at'] ?? null ),
			'kit_unsubscribe_reason' => $row['kit_unsubscribe_reason'] ?? null,
			'notes_count'            => (int) $row['notes_count'],
			'anonymised_at'          => Clock::iso( $row['anonymised_at'] ?? null ),
			'created_at'             => Clock::iso( $row['created_at'] ?? null ),
		);
	}

	/** @param array<string,mixed> $row */
	private function presentListRow( array $row ): array {
		$status = $row['sub_status'] ?? null;
		return array(
			'id'                   => (int) $row['id'],
			'public_id'            => (string) $row['public_id'],
			'user_id'              => (int) $row['user_id'],
			'name'                 => (string) $row['display_name'],
			'email'                => (string) $row['user_email'],
			'phone'                => (string) ( $row['phone'] ?? '' ),
			'country'              => (string) ( $row['country'] ?? '' ),
			'plan_name'            => (string) ( $row['plan_name'] ?? '—' ),
			'plan_slug'            => (string) ( $row['plan_slug'] ?? '' ),
			'subscription_id'      => $row['sub_id'] ? (int) $row['sub_id'] : null,
			'status'               => $status ?: 'none',
			'cancel_at_period_end' => (bool) ( $row['sub_cancel'] ?? false ),
			'period_end'           => Clock::iso( $row['sub_period_end'] ?? null ),
			'currency'             => $row['sub_currency'] ?? null,
			'amount_minor'         => isset( $row['sub_amount'] ) ? (int) $row['sub_amount'] : null,
			'marketing_consent'    => (bool) $row['marketing_consent'],
			'kit_sync_error'       => $row['kit_sync_error'] ?? null,
			'created_at'           => Clock::iso( $row['created_at'] ),
		);
	}

	/** @param array<string,mixed> $row */
	private function presentNote( array $row ): array {
		$author = $row['author_id'] ? get_userdata( (int) $row['author_id'] ) : null;
		return array(
			'id'          => (int) $row['id'],
			'member_id'   => (int) $row['member_id'],
			'body'        => (string) $row['body'],
			'author_id'   => $row['author_id'] ? (int) $row['author_id'] : null,
			'author_name' => $author ? $author->display_name : 'System',
			'created_at'  => Clock::iso( $row['created_at'] ),
		);
	}

	private function joinSql(): string {
		global $wpdb;
		$s  = Db::table( 'subscriptions' );
		$pl = Db::table( 'plans' );
		return "INNER JOIN {$wpdb->users} u ON u.ID = m.user_id
			LEFT JOIN {$s} cs ON cs.id = (SELECT s2.id FROM {$s} s2 WHERE s2.member_id = m.id AND s2.organisation_id IS NULL ORDER BY (s2.status IN ('active','past_due','suspended')) DESC, s2.id DESC LIMIT 1)
			LEFT JOIN {$pl} cp ON cp.id = cs.plan_id";
	}

	/**
	 * @param array<string,mixed> $f
	 * @return array{0:list<string>,1:list<mixed>}
	 */
	private function filterSql( array $f ): array {
		global $wpdb;
		$where = array();
		$args  = array();
		if ( ! empty( $f['q'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $f['q'] ) . '%';
			$where[] = '(u.display_name LIKE %s OR u.user_email LIKE %s OR m.public_id LIKE %s OR m.phone LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}
		if ( ! empty( $f['plan'] ) ) {
			if ( is_numeric( $f['plan'] ) ) {
				$where[] = 'cp.id = %d';
				$args[]  = (int) $f['plan'];
			} else {
				$where[] = 'cp.slug = %s';
				$args[]  = (string) $f['plan'];
			}
		}
		if ( ! empty( $f['status'] ) ) {
			if ( 'none' === $f['status'] ) {
				$where[] = 'cs.id IS NULL';
			} elseif ( 'cancelling' === $f['status'] ) {
				$where[] = "cs.status = 'active' AND cs.cancel_at_period_end = 1";
			} else {
				$where[] = 'cs.status = %s';
				$args[]  = (string) $f['status'];
			}
		}
		if ( ! empty( $f['country'] ) ) {
			$where[] = 'm.country = %s';
			$args[]  = strtoupper( (string) $f['country'] );
		}
		if ( ! empty( $f['method'] ) ) {
			$o       = Db::table( 'orders' );
			$py      = Db::table( 'payments' );
			$where[] = "EXISTS (SELECT 1 FROM {$py} py INNER JOIN {$o} o ON o.id = py.order_id WHERE o.member_id = m.id AND py.gateway = %s)";
			$args[]  = (string) $f['method'];
		}
		if ( ! empty( $f['joined_from'] ) ) {
			$where[] = 'm.created_at >= %s';
			$args[]  = gmdate( 'Y-m-d 00:00:00', (int) strtotime( (string) $f['joined_from'] ) );
		}
		if ( ! empty( $f['joined_to'] ) ) {
			$where[] = 'm.created_at <= %s';
			$args[]  = gmdate( 'Y-m-d 23:59:59', (int) strtotime( (string) $f['joined_to'] ) );
		}
		if ( ! empty( $f['renewal_within_days'] ) ) {
			$where[] = "cs.status IN ('active','past_due') AND cs.current_period_end IS NOT NULL AND cs.current_period_end <= %s";
			$args[]  = Clock::mysql( Clock::now()->modify( '+' . (int) $f['renewal_within_days'] . ' days' ) );
		}
		if ( ! empty( $f['consent'] ) ) {
			$where[] = 'm.marketing_consent = %d';
			$args[]  = 'yes' === $f['consent'] ? 1 : 0;
		}
		if ( ! empty( $f['kit_error'] ) ) {
			$where[] = 'm.kit_sync_error IS NOT NULL';
		}
		if ( ! empty( $f['ids'] ) && is_array( $f['ids'] ) ) {
			$where[] = 'm.id IN (' . Db::in( $f['ids'] ) . ')';
			array_push( $args, ...array_map( 'intval', $f['ids'] ) );
		}
		return array( $where, $args );
	}

	private function refreshNotesCount( int $memberId ): void {
		$this->members->update( $memberId, array( 'notes_count' => $this->notes->count( array( 'member_id' => $memberId ) ) ) );
	}

	private function uniqueLogin( string $email ): string {
		$base  = sanitize_user( strtolower( (string) strstr( $email, '@', true ) ), true ) ?: 'member';
		$login = $base;
		$i     = 1;
		while ( username_exists( $login ) ) {
			$login = $base . ( ++$i );
		}
		return $login;
	}

	private function assertPasswordPolicy( string $password ): void {
		if ( strlen( $password ) < 8 || ! preg_match( '/[A-Za-z]/', $password ) || ! preg_match( '/[0-9]/', $password ) ) {
			throw DomainError::validation( array( 'password' => 'Use at least 8 characters with letters and numbers.' ) );
		}
	}
}
