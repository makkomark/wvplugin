<?php
declare( strict_types=1 );

namespace WitsValley\Members\Rest;

use WitsValley\Members\Domain\Member\MemberService;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Pagination;

/**
 * Base REST controller. Every response is { data, meta?, error? }.
 *
 * Permission specs: 'public' | 'member' | '<capability>' | ['any' => [caps...]].
 */
abstract class Controller {

	abstract public function routes(): void;

	/**
	 * @param string|array<string,list<string>> $permission
	 * @param callable(\WP_REST_Request):mixed  $callback
	 */
	protected function route( string $method, string $path, callable $callback, string|array $permission ): void {
		register_rest_route(
			WVM_REST_NS,
			$path,
			array(
				'methods'             => $method,
				'callback'            => fn( \WP_REST_Request $r ) => $this->handle( $callback, $r ),
				'permission_callback' => fn() => $this->allowed( $permission ),
			)
		);
	}

	/** @param string|array<string,list<string>> $permission */
	protected function allowed( string|array $permission ): bool|\WP_Error {
		if ( 'public' === $permission ) {
			return true;
		}
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'wvm_not_logged_in', __( 'Please log in.', 'wv-members' ), array( 'status' => 401 ) );
		}
		if ( 'member' === $permission ) {
			return true;
		}
		$caps = is_array( $permission ) ? (array) ( $permission['any'] ?? array() ) : array( $permission );
		foreach ( $caps as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}
		return new \WP_Error( 'wvm_forbidden', __( 'You do not have permission to do that.', 'wv-members' ), array( 'status' => 403 ) );
	}

	/** @param callable(\WP_REST_Request):mixed $callback */
	protected function handle( callable $callback, \WP_REST_Request $request ): \WP_REST_Response {
		try {
			$result = $callback( $request );
			if ( $result instanceof \WP_REST_Response ) {
				return $result;
			}
			return $this->ok( $result );
		} catch ( DomainError $e ) {
			return $this->error( $e->getMessage(), $e->errorCode, $e->status, $e->fields );
		} catch ( \Throwable $e ) {
			error_log( 'WVM REST error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			$message = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? $e->getMessage() : __( 'Something went wrong. Please try again.', 'wv-members' );
			return $this->error( $message, 'wvm_server_error', 500 );
		}
	}

	/** @param array<string,mixed>|null $meta */
	protected function ok( mixed $data, ?array $meta = null, int $status = 200 ): \WP_REST_Response {
		$body = array( 'data' => $data );
		if ( null !== $meta ) {
			$body['meta'] = $meta;
		}
		$res = new \WP_REST_Response( $body, $status );
		$res->header( 'Cache-Control', 'private, no-store' );
		return $res;
	}

	/** @param array{items:list<mixed>, meta:array<string,mixed>} $page */
	protected function paged( array $page ): \WP_REST_Response {
		$meta = $page['meta'];
		foreach ( $page as $k => $v ) {
			if ( 'items' !== $k && 'meta' !== $k ) {
				$meta[ $k ] = $v;
			}
		}
		return $this->ok( $page['items'], $meta );
	}

	/** @param array<string,string> $fields */
	protected function error( string $message, string $code, int $status, array $fields = array() ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'data'  => null,
				'error' => array(
					'code'    => $code,
					'message' => $message,
					'fields'  => (object) $fields,
				),
			),
			$status
		);
	}

	/** @return array<string,mixed> JSON or form body. */
	protected function body( \WP_REST_Request $r ): array {
		$json = $r->get_json_params();
		return is_array( $json ) ? $json : (array) $r->get_body_params();
	}

	/** @return array<string,mixed> */
	protected function query( \WP_REST_Request $r ): array {
		return (array) $r->get_query_params();
	}

	protected function pagination( \WP_REST_Request $r, int $default = 20 ): Pagination {
		return Pagination::fromParams( $this->query( $r ), $default );
	}

	protected function id( \WP_REST_Request $r, string $key = 'id' ): int {
		return (int) $r->get_param( $key );
	}

	protected function reason( \WP_REST_Request $r, bool $required = true ): string {
		$reason = trim( sanitize_text_field( (string) ( $this->body( $r )['reason'] ?? '' ) ) );
		if ( $required && '' === $reason ) {
			throw DomainError::validation( array( 'reason' => 'A reason is required for this action.' ) );
		}
		return $reason;
	}

	/** @return array<string,mixed> Current member (created lazily). */
	protected function me(): array {
		return wvm( MemberService::class )->current();
	}

	/** Stream a file download and stop. */
	protected function download( string $content, string $filename, string $type ): never {
		nocache_headers();
		header( 'Content-Type: ' . $type . '; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $content ) );
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	protected static function ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/** Simple per-IP rate limit. */
	protected function rateLimit( string $bucket, int $max, int $window = HOUR_IN_SECONDS ): void {
		/** Filter: raise or disable (0) a rate limit, e.g. for E2E runs on staging. */
		$max = (int) apply_filters( 'wvm_rate_limit', $max, $bucket, self::ip() );
		if ( $max <= 0 ) {
			return;
		}
		$key   = 'wvm_rl_' . $bucket . '_' . md5( self::ip() );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			throw new DomainError( 'Too many attempts. Please wait a little and try again.', 'wvm_rate_limited', 429 );
		}
		set_transient( $key, $count + 1, $window );
	}
}
