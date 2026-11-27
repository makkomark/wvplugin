<?php
declare( strict_types=1 );

namespace WitsValley\Members\Frontend;

use WitsValley\Members\Domain\Popup\PopupService;

/**
 * Loads the small popup script (no React) on pages where someone should see the membership popup.
 */
final class Popup {

	public function __construct( private PopupService $popup ) {}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 20 );
	}

	public function enqueue(): void {
		$config = $this->popup->forRequest();
		if ( ! $config || ! file_exists( WVM_DIR . 'build/popup.js' ) ) {
			return;
		}
		$asset_file = WVM_DIR . 'build/popup.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => WVM_VERSION,
		);
		wp_enqueue_script(
			'wvm-popup',
			WVM_URL . 'build/popup.js',
			(array) $asset['dependencies'],
			(string) $asset['version'],
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script( 'wvm-popup', 'window.wvmPopup = ' . wp_json_encode( $config ) . ';', 'before' );
		if ( file_exists( WVM_DIR . 'build/popup.css' ) ) {
			wp_enqueue_style( 'wvm-popup', WVM_URL . 'build/popup.css', array( 'wvm-public' ), (string) $asset['version'] );
		}
	}
}
