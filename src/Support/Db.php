<?php
declare( strict_types=1 );

namespace WitsValley\Members\Support;

/**
 * Database helpers: table names, transactions.
 */
final class Db {

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'wvm_' . $name;
	}

	/**
	 * Run $fn inside a transaction. Nested calls join the outer transaction.
	 *
	 * @template T
	 * @param callable():T $fn
	 * @return T
	 */
	public static function transaction( callable $fn ): mixed {
		global $wpdb;
		static $depth = 0;
		// Under the WP test suite every test already runs inside a transaction; a nested
		// START TRANSACTION would implicitly commit it, so use savepoints there instead.
		$nested = defined( 'WVM_TESTING' );
		if ( 0 === $depth ) {
			$wpdb->query( $nested ? 'SAVEPOINT wvm_tx' : 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		++$depth;
		try {
			$result = $fn();
			--$depth;
			if ( 0 === $depth ) {
				$wpdb->query( $nested ? 'RELEASE SAVEPOINT wvm_tx' : 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			return $result;
		} catch ( \Throwable $e ) {
			--$depth;
			if ( 0 === $depth ) {
				$wpdb->query( $nested ? 'ROLLBACK TO SAVEPOINT wvm_tx' : 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			throw $e;
		}
	}

	/**
	 * Build "IN (%d,%d)" placeholders.
	 *
	 * @param array<int|string> $values
	 */
	public static function in( array $values, string $type = '%d' ): string {
		return implode( ',', array_fill( 0, max( 1, count( $values ) ), $type ) );
	}
}
