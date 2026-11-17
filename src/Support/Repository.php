<?php
declare( strict_types=1 );

namespace WitsValley\Members\Support;

/**
 * Generic table repository. Rows are associative arrays with typed casts applied.
 *
 * Subclasses declare the table name and column casts.
 */
abstract class Repository {

	/** Table name without prefix (e.g. 'plans'). */
	protected string $table = '';

	/**
	 * Column casts: int|bool|json|float|encrypted. Unlisted columns are strings.
	 *
	 * @var array<string,string>
	 */
	protected array $casts = array();

	/** Whether the table has created_at/updated_at columns. */
	protected bool $timestamps = true;

	protected bool $hasUpdatedAt = true;

	public function tableName(): string {
		return Db::table( $this->table );
	}

	/** @return array<string,mixed>|null */
	public function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tableName()} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $this->hydrate( $row ) : null;
	}

	/** @return array<string,mixed> */
	public function findOrFail( int $id, string $entity = '' ): array {
		$row = $this->find( $id );
		if ( ! $row ) {
			throw DomainError::notFound( $entity ?: rtrim( $this->table, 's' ) );
		}
		return $row;
	}

	/** @return array<string,mixed>|null */
	public function findBy( string $column, mixed $value ): ?array {
		global $wpdb;
		$this->assertColumn( $column );
		$format = is_int( $value ) ? '%d' : '%s';
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tableName()} WHERE `{$column}` = {$format} LIMIT 1", $value ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Simple equality filter query.
	 *
	 * @param array<string,mixed> $where
	 * @return list<array<string,mixed>>
	 */
	public function where( array $where, string $orderBy = 'id DESC', int $limit = 0 ): array {
		global $wpdb;
		[ $sql, $args ] = $this->buildWhere( $where );
		$order          = $this->safeOrder( $orderBy );
		$limit_sql      = $limit > 0 ? ' LIMIT ' . $limit : '';
		$query          = "SELECT * FROM {$this->tableName()} {$sql} ORDER BY {$order}{$limit_sql}";
		$rows           = $wpdb->get_results( $args ? $wpdb->prepare( $query, $args ) : $query, ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( array( $this, 'hydrate' ), $rows ?: array() );
	}

	/** @param array<string,mixed> $where */
	public function first( array $where, string $orderBy = 'id DESC' ): ?array {
		$rows = $this->where( $where, $orderBy, 1 );
		return $rows[0] ?? null;
	}

	/** @param array<string,mixed> $where */
	public function count( array $where = array() ): int {
		global $wpdb;
		[ $sql, $args ] = $this->buildWhere( $where );
		$query          = "SELECT COUNT(*) FROM {$this->tableName()} {$sql}";
		return (int) $wpdb->get_var( $args ? $wpdb->prepare( $query, $args ) : $query ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Paginated query with raw SQL conditions.
	 *
	 * @param list<string>     $conditions SQL fragments with placeholders.
	 * @param list<int|string> $args
	 * @return array{items:list<array<string,mixed>>, meta:array<string,int>}
	 */
	public function paginate( array $conditions, array $args, Pagination $p, string $orderBy = 'id DESC', string $select = '*', string $join = '' ): array {
		global $wpdb;
		$t     = $this->tableName();
		$where = $conditions ? 'WHERE ' . implode( ' AND ', $conditions ) : '';
		$order = $this->safeOrder( $orderBy );
		$count = "SELECT COUNT(*) FROM {$t} {$join} {$where}";
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( $count, $args ) : $count ); // phpcs:ignore WordPress.DB
		$query = "SELECT {$select} FROM {$t} {$join} {$where} ORDER BY {$order} LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $query, array_merge( $args, array( $p->perPage, $p->offset() ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array(
			'items' => array_map( array( $this, 'hydrate' ), $rows ?: array() ),
			'meta'  => $p->meta( $total ),
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function insert( array $data ): int {
		global $wpdb;
		if ( $this->timestamps ) {
			$now                = Clock::mysql();
			$data['created_at'] = $data['created_at'] ?? $now;
			if ( $this->hasUpdatedAt ) {
				$data['updated_at'] = $data['updated_at'] ?? $now;
			}
		}
		$ok = $wpdb->insert( $this->tableName(), $this->dehydrate( $data ) ); // phpcs:ignore WordPress.DB
		if ( false === $ok ) {
			throw new DomainError( 'Database insert failed: ' . $wpdb->last_error, 'wvm_db_error', 500 );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function update( int $id, array $data ): void {
		global $wpdb;
		if ( $this->timestamps && $this->hasUpdatedAt ) {
			$data['updated_at'] = Clock::mysql();
		}
		$ok = $wpdb->update( $this->tableName(), $this->dehydrate( $data ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
		if ( false === $ok ) {
			throw new DomainError( 'Database update failed: ' . $wpdb->last_error, 'wvm_db_error', 500 );
		}
	}

	public function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( $this->tableName(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Lock a row for update inside a transaction.
	 *
	 * @return array<string,mixed>|null
	 */
	public function lock( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->tableName()} WHERE id = %d FOR UPDATE", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public function hydrate( array $row ): array {
		foreach ( $row as $key => $value ) {
			$cast = $this->casts[ $key ] ?? ( 'id' === $key || str_ends_with( (string) $key, '_id' ) ? 'int' : null );
			if ( null === $value || null === $cast ) {
				continue;
			}
			$row[ $key ] = match ( $cast ) {
				'int'       => (int) $value,
				'bool'      => (bool) (int) $value,
				'float'     => (float) $value,
				'json'      => json_decode( (string) $value, true ),
				'encrypted' => Encryption::decrypt( (string) $value ),
				default     => $value,
			};
		}
		return $row;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	protected function dehydrate( array $data ): array {
		foreach ( $data as $key => $value ) {
			$cast = $this->casts[ $key ] ?? null;
			if ( null === $value ) {
				continue;
			}
			if ( 'json' === $cast ) {
				$data[ $key ] = wp_json_encode( $value );
			} elseif ( 'bool' === $cast ) {
				$data[ $key ] = $value ? 1 : 0;
			} elseif ( 'encrypted' === $cast && is_string( $value ) && ! Encryption::isEncrypted( $value ) ) {
				$data[ $key ] = Encryption::encrypt( $value );
			}
		}
		return $data;
	}

	/**
	 * @param array<string,mixed> $where
	 * @return array{0:string,1:list<mixed>}
	 */
	protected function buildWhere( array $where ): array {
		$parts = array();
		$args  = array();
		foreach ( $where as $column => $value ) {
			$this->assertColumn( (string) $column );
			if ( null === $value ) {
				$parts[] = "`{$column}` IS NULL";
			} elseif ( is_array( $value ) ) {
				if ( ! $value ) {
					$parts[] = '1=0';
					continue;
				}
				$type    = is_int( reset( $value ) ) ? '%d' : '%s';
				$parts[] = "`{$column}` IN (" . Db::in( $value, $type ) . ')';
				array_push( $args, ...array_values( $value ) );
			} else {
				$parts[] = is_int( $value ) ? "`{$column}` = %d" : "`{$column}` = %s";
				$args[]  = is_bool( $value ) ? (int) $value : $value;
			}
		}
		return array( $parts ? 'WHERE ' . implode( ' AND ', $parts ) : '', $args );
	}

	protected function assertColumn( string $column ): void {
		if ( 1 !== preg_match( '/^[a-z_][a-z0-9_]*$/', $column ) ) {
			throw new \InvalidArgumentException( 'Invalid column name.' );
		}
	}

	protected function safeOrder( string $orderBy ): string {
		if ( 1 !== preg_match( '/^[a-z0-9_.]+( (ASC|DESC))?(, ?[a-z0-9_.]+( (ASC|DESC))?)*$/i', $orderBy ) ) {
			return 'id DESC';
		}
		return $orderBy;
	}
}
