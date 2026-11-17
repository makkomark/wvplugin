<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Access;

use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Support\Encryption;

/**
 * Protected media: attachments with _wvm_level > 0 live under uploads/wvm-protected/ (web access denied)
 * and are served through GET /public/media/{signedToken} (HMAC, 15-minute expiry, member-bound).
 */
final class MediaGuard {

	public const DIR = 'wvm-protected';
	public const TTL = 900;

	public function __construct( private LevelResolver $levels, private Audit $audit ) {}

	public function register(): void {
		add_filter( 'wp_get_attachment_url', array( $this, 'filterUrl' ), 99, 2 );
		add_filter( 'wp_get_attachment_image_src', array( $this, 'filterImageSrc' ), 99, 2 );
		add_filter( 'wp_calculate_image_srcset', array( $this, 'filterSrcset' ), 99, 5 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'fieldsToEdit' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( $this, 'fieldsToSave' ), 10, 2 );
	}

	public function requiredLevel( int $attachmentId ): int {
		return (int) get_post_meta( $attachmentId, ContentGuard::META_LEVEL, true );
	}

	public function setLevel( int $attachmentId, int $level ): void {
		$before = $this->requiredLevel( $attachmentId );
		update_post_meta( $attachmentId, ContentGuard::META_LEVEL, $level );
		if ( $level > 0 ) {
			$this->moveToProtected( $attachmentId );
		} else {
			$this->moveToPublic( $attachmentId );
		}
		$this->audit->log( 'media.level_set', 'attachment', $attachmentId, array( 'level' => $before ), array( 'level' => $level ) );
	}

	public function moveToProtected( int $attachmentId ): bool {
		$rel = (string) get_post_meta( $attachmentId, '_wp_attached_file', true );
		if ( '' === $rel || str_starts_with( $rel, self::DIR . '/' ) ) {
			return false;
		}
		return $this->relocate( $attachmentId, $rel, self::DIR . '/' . $rel );
	}

	public function moveToPublic( int $attachmentId ): bool {
		$rel = (string) get_post_meta( $attachmentId, '_wp_attached_file', true );
		if ( ! str_starts_with( $rel, self::DIR . '/' ) ) {
			return false;
		}
		return $this->relocate( $attachmentId, $rel, substr( $rel, strlen( self::DIR ) + 1 ) );
	}

	private function relocate( int $attachmentId, string $fromRel, string $toRel ): bool {
		$uploads = wp_upload_dir();
		$base    = trailingslashit( (string) $uploads['basedir'] );
		$from    = $base . $fromRel;
		$to      = $base . $toRel;
		if ( ! file_exists( $from ) ) {
			return false;
		}
		wp_mkdir_p( dirname( $to ) );
		self::protectDirectory( $base . self::DIR );
		if ( ! @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}
		$meta = wp_get_attachment_metadata( $attachmentId );
		if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size ) {
				$sf = dirname( $from ) . '/' . $size['file'];
				if ( file_exists( $sf ) ) {
					@rename( $sf, dirname( $to ) . '/' . $size['file'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
		}
		update_post_meta( $attachmentId, '_wp_attached_file', $toRel );
		if ( is_array( $meta ) ) {
			$meta['file'] = $toRel;
			wp_update_attachment_metadata( $attachmentId, $meta );
		}
		return true;
	}

	public static function protectDirectory( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		$ht = $dir . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			file_put_contents( $ht, "# WitsValley Members: protected files\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	public function isProtectedFile( int $attachmentId ): bool {
		return str_starts_with( (string) get_post_meta( $attachmentId, '_wp_attached_file', true ), self::DIR . '/' );
	}

	public function filterUrl( string $url, int $attachmentId ): string {
		if ( ! $this->isProtectedFile( $attachmentId ) ) {
			return $url;
		}
		return $this->signedUrl( $attachmentId, get_current_user_id() );
	}

	/**
	 * @param array<int,mixed>|false $image
	 * @return array<int,mixed>|false
	 */
	public function filterImageSrc( $image, int $attachmentId ) {
		if ( is_array( $image ) && $this->isProtectedFile( $attachmentId ) ) {
			$image[0] = $this->signedUrl( $attachmentId, get_current_user_id() );
		}
		return $image;
	}

	/** @param array<int,mixed> $sources */
	public function filterSrcset( $sources, $size, $src, $meta, int $attachmentId ) {
		return $this->isProtectedFile( $attachmentId ) ? false : $sources;
	}

	public function signedUrl( int $attachmentId, int $userId ): string {
		return rest_url( WVM_REST_NS . '/public/media/' . $this->token( $attachmentId, $userId ) );
	}

	public function token( int $attachmentId, int $userId, ?int $expires = null ): string {
		$expires = $expires ?? time() + self::TTL;
		$data    = $attachmentId . '.' . $userId . '.' . $expires;
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ) . '.' . substr( Encryption::sign( 'media|' . $data ), 0, 32 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * Validate a token. Returns [attachmentId, userId] or null.
	 *
	 * @return array{0:int,1:int}|null
	 */
	public function verify( string $token ): ?array {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$data = base64_decode( strtr( $parts[0], '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
		if ( false === $data || ! hash_equals( substr( Encryption::sign( 'media|' . $data ), 0, 32 ), $parts[1] ) ) {
			return null;
		}
		$bits = explode( '.', $data );
		if ( 3 !== count( $bits ) || (int) $bits[2] < time() ) {
			return null;
		}
		return array( (int) $bits[0], (int) $bits[1] );
	}

	public function userMayAccess( int $attachmentId, int $userId ): bool {
		if ( $userId && ( user_can( $userId, 'edit_post', $attachmentId ) || user_can( $userId, 'wvm_manage_content_access' ) ) ) {
			return true;
		}
		$parent = (int) wp_get_post_parent_id( $attachmentId );
		if ( $parent && ! wvm( ContentGuard::class )->canView( $parent, $userId ) ) {
			return false;
		}
		return $this->levels->levelFor( $userId ) >= $this->requiredLevel( $attachmentId );
	}

	/** Stream the file for a token or emit 403. */
	public function serve( string $token ): void {
		$res = $this->verify( $token );
		if ( ! $res || ! $this->userMayAccess( $res[0], $res[1] ) ) {
			status_header( 403 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Forbidden';
			exit;
		}
		$file = get_attached_file( $res[0] );
		if ( ! $file || ! is_readable( $file ) ) {
			status_header( 404 );
			exit;
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store' );
		header( 'Content-Type: ' . ( get_post_mime_type( $res[0] ) ?: 'application/octet-stream' ) );
		header( 'Content-Length: ' . (string) filesize( $file ) );
		header( 'Content-Disposition: inline; filename="' . rawurlencode( basename( $file ) ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/**
	 * @param array<string,mixed> $fields
	 * @return array<string,mixed>
	 */
	public function fieldsToEdit( array $fields, \WP_Post $post ): array {
		if ( ! current_user_can( 'wvm_manage_content_access' ) ) {
			return $fields;
		}
		$current = $this->requiredLevel( (int) $post->ID );
		$options = '';
		foreach ( LevelResolver::LEVELS as $lvl => $label ) {
			$options .= '<option value="' . (int) $lvl . '"' . selected( $current, $lvl, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$fields['wvm_level'] = array(
			'label' => __( 'Members access', 'wv-members' ),
			'input' => 'html',
			'html'  => '<select name="attachments[' . (int) $post->ID . '][wvm_level]">' . $options . '</select>',
			'helps' => __( 'Protected files are moved out of the public uploads folder and served with expiring links.', 'wv-members' ),
		);
		return $fields;
	}

	/**
	 * @param array<string,mixed> $post
	 * @param array<string,mixed> $attachment
	 * @return array<string,mixed>
	 */
	public function fieldsToSave( array $post, array $attachment ): array {
		if ( isset( $attachment['wvm_level'] ) && current_user_can( 'wvm_manage_content_access' ) ) {
			$level = max( 0, min( 9, (int) $attachment['wvm_level'] ) );
			if ( $level !== $this->requiredLevel( (int) $post['ID'] ) ) {
				$this->setLevel( (int) $post['ID'], $level );
			}
		}
		return $post;
	}
}
