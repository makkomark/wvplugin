<?php
declare( strict_types=1 );

namespace WitsValley\Members\Support;

/**
 * Small rule-based validator. Rules: required, email, int, min:n, max:n, in:a,b, currency, country, url, slug, bool, array, date, maxlen:n
 */
final class Validation {

	/**
	 * @param array<string,mixed>  $data
	 * @param array<string,string> $rules
	 * @return array<string,mixed> Sanitised subset of $data containing only ruled keys that were present.
	 * @throws DomainError On failure.
	 */
	public static function validate( array $data, array $rules ): array {
		$errors = array();
		$out    = array();
		foreach ( $rules as $field => $spec ) {
			$parts    = explode( '|', $spec );
			$present  = array_key_exists( $field, $data ) && null !== $data[ $field ] && '' !== $data[ $field ];
			$required = in_array( 'required', $parts, true );
			if ( ! $present ) {
				if ( $required ) {
					$errors[ $field ] = 'This field is required.';
				} elseif ( array_key_exists( $field, $data ) ) {
					$out[ $field ] = null;
				}
				continue;
			}
			$value = $data[ $field ];
			foreach ( $parts as $rule ) {
				[ $name, $arg ] = array_pad( explode( ':', $rule, 2 ), 2, null );
				$error          = self::check( (string) $name, $arg, $value );
				if ( null !== $error ) {
					$errors[ $field ] = $error;
					break;
				}
			}
			if ( ! isset( $errors[ $field ] ) ) {
				$out[ $field ] = self::sanitize( $parts, $value );
			}
		}
		if ( $errors ) {
			throw DomainError::validation( $errors );
		}
		return $out;
	}

	private static function check( string $name, ?string $arg, mixed $value ): ?string {
		switch ( $name ) {
			case 'required':
				return null;
			case 'email':
				return is_email( (string) $value ) ? null : 'Enter a valid email address.';
			case 'int':
				return is_numeric( $value ) && (int) $value == $value ? null : 'Must be a whole number.'; // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			case 'min':
				return (float) $value >= (float) $arg ? null : sprintf( 'Must be at least %s.', $arg );
			case 'max':
				return (float) $value <= (float) $arg ? null : sprintf( 'Must be at most %s.', $arg );
			case 'maxlen':
				return mb_strlen( (string) $value ) <= (int) $arg ? null : sprintf( 'Must be %s characters or fewer.', $arg );
			case 'minlen':
				return mb_strlen( (string) $value ) >= (int) $arg ? null : sprintf( 'Must be at least %s characters.', $arg );
			case 'in':
				return in_array( (string) $value, explode( ',', (string) $arg ), true ) ? null : 'Invalid choice.';
			case 'currency':
				return 1 === preg_match( '/^[A-Z]{3}$/', strtoupper( (string) $value ) ) ? null : 'Invalid currency code.';
			case 'country':
				return 1 === preg_match( '/^[A-Z]{2}$/', strtoupper( (string) $value ) ) ? null : 'Invalid country code.';
			case 'url':
				return false !== wp_http_validate_url( (string) $value ) || 0 === strpos( (string) $value, 'http' ) ? null : 'Enter a valid URL.';
			case 'slug':
				return 1 === preg_match( '/^[a-z0-9][a-z0-9_-]{0,49}$/', (string) $value ) ? null : 'Use lowercase letters, numbers, dashes.';
			case 'bool':
				return is_bool( $value ) || in_array( $value, array( 0, 1, '0', '1', 'true', 'false' ), true ) ? null : 'Must be true or false.';
			case 'array':
				return is_array( $value ) ? null : 'Must be a list.';
			case 'date':
				return false !== strtotime( (string) $value ) ? null : 'Enter a valid date.';
			case 'phone':
				return 1 === preg_match( '/^\+?[0-9 ()-]{6,30}$/', (string) $value ) ? null : 'Enter a valid phone number.';
			case 'timezone':
				return in_array( (string) $value, timezone_identifiers_list(), true ) ? null : 'Invalid timezone.';
			default:
				return null;
		}
	}

	/** @param list<string> $parts */
	private static function sanitize( array $parts, mixed $value ): mixed {
		foreach ( $parts as $rule ) {
			$name = explode( ':', $rule, 2 )[0];
			switch ( $name ) {
				case 'int':
					return (int) $value;
				case 'bool':
					return in_array( $value, array( true, 1, '1', 'true' ), true );
				case 'email':
					return sanitize_email( (string) $value );
				case 'currency':
				case 'country':
					return strtoupper( sanitize_text_field( (string) $value ) );
				case 'url':
					return esc_url_raw( (string) $value );
				case 'array':
					return $value;
				case 'html':
					return wp_kses_post( (string) $value );
				case 'text':
					return sanitize_textarea_field( (string) $value );
				case 'raw':
					return (string) $value;
			}
		}
		return is_string( $value ) ? sanitize_text_field( $value ) : $value;
	}
}
