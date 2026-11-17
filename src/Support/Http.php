<?php
declare( strict_types=1 );

namespace WitsValley\Members\Support;

/**
 * Thin JSON HTTP client over wp_remote_request.
 */
final class Http {

	/**
	 * @param array<string,string>     $headers
	 * @param array<string,mixed>|null $body
	 * @return array{status:int, body:mixed, raw:string, error:?string}
	 */
	public static function json( string $method, string $url, array $headers = array(), ?array $body = null, int $timeout = 15 ): array {
		$args = array(
			'method'  => strtoupper( $method ),
			'timeout' => $timeout,
			'headers' => array_merge(
				array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'status' => 0,
				'body'   => null,
				'raw'    => '',
				'error'  => $response->get_error_message(),
			);
		}
		$raw    = (string) wp_remote_retrieve_body( $response );
		$status = (int) wp_remote_retrieve_response_code( $response );
		return array(
			'status' => $status,
			'body'   => json_decode( $raw, true ),
			'raw'    => $raw,
			'error'  => $status >= 400 ? sprintf( 'HTTP %d', $status ) : null,
		);
	}
}
