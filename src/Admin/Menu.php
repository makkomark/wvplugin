<?php
declare( strict_types=1 );

namespace WitsValley\Members\Admin;

use WitsValley\Members\Domain\Access\LevelResolver;
use WitsValley\Members\Domain\Notification\Urls;
use WitsValley\Members\Domain\Notification\TemplateDefaults;
use WitsValley\Members\Support\Money;
use WitsValley\Members\Support\Settings;

/**
 * "WitsValley" admin menu. Every page mounts the same React app; each submenu opens its own route.
 */
final class Menu {

	/** slug => [title, capability, route] */
	public const PAGES = array(
		'wvm'            => array( 'Home', 'wvm_access', '/' ),
		'wvm-members'    => array( 'Members', 'wvm_view_members', '/members' ),
		'wvm-payments'   => array( 'Payments', 'wvm_view_payments', '/payments' ),
		'wvm-plans'      => array( 'Plans', 'wvm_manage_plans', '/plans' ),
		'wvm-workshops'  => array( 'Workshops', 'wvm_manage_workshops', '/workshops' ),
		'wvm-enterprise' => array( 'Enterprise', 'wvm_manage_enterprise', '/enterprise/applications' ),
		'wvm-access'     => array( 'Content access', 'wvm_manage_content_access', '/access' ),
		'wvm-insights'   => array( 'Insights', 'wvm_view_insights', '/insights' ),
		'wvm-audit'      => array( 'Audit log', 'wvm_view_audit', '/audit' ),
		'wvm-settings'   => array( 'Settings', 'wvm_manage_settings', '/settings/general' ),
	);

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'admin_body_class', array( $this, 'bodyClass' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WVM_FILE ), array( $this, 'actionLinks' ) );
	}

	public function menu(): void {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="black" d="M3 5h3.2l2.3 9.2L11 5h2l2.5 9.2L17.8 5H21l-4.2 14h-2.6L12 10.7 9.8 19H7.2z"/></svg>' ); // phpcs:ignore
		add_menu_page( 'WitsValley', 'WitsValley', 'wvm_access', 'wvm', array( $this, 'render' ), $icon, 3 );
		foreach ( self::PAGES as $slug => [ $title, $cap ] ) {
			add_submenu_page( 'wvm', $title . ' — WitsValley', $title, $cap, $slug, array( $this, 'render' ) );
		}
	}

	public function render(): void {
		$page  = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : 'wvm'; // phpcs:ignore WordPress.Security.NonceVerification
		$route = self::PAGES[ $page ][2] ?? '/';
		echo '<div class="wrap wvm-admin-wrap"><div id="wvm-admin-root" data-page="' . esc_attr( $page ) . '" data-route="' . esc_attr( $route ) . '"><div class="wvm-admin-boot">' . esc_html__( 'Loading WitsValley…', 'wv-members' ) . '</div></div></div>';
	}

	public function isOurScreen(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return isset( self::PAGES[ $page ] );
	}

	/** @param string $classes */
	public function bodyClass( $classes ): string {
		return $this->isOurScreen() ? $classes . ' wvm-admin-screen' : $classes;
	}

	public function assets(): void {
		if ( ! $this->isOurScreen() ) {
			return;
		}
		$asset_file = WVM_DIR . 'build/admin.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array( 'react', 'react-dom', 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n' ),
			'version'      => WVM_VERSION,
		);
		wp_enqueue_script( 'wvm-admin', WVM_URL . 'build/admin.js', (array) $asset['dependencies'], (string) $asset['version'], true );
		wp_enqueue_style( 'wp-components' );
		if ( file_exists( WVM_DIR . 'build/admin.css' ) ) {
			wp_enqueue_style( 'wvm-admin', WVM_URL . 'build/admin.css', array( 'wp-components' ), (string) $asset['version'] );
		}
		wp_enqueue_media();
		$user  = wp_get_current_user();
		$pages = array();
		foreach ( self::PAGES as $slug => [ $title, $cap, $route ] ) {
			$pages[] = array(
				'slug'    => $slug,
				'title'   => $title,
				'route'   => $route,
				'url'     => admin_url( 'admin.php?page=' . $slug ),
				'allowed' => current_user_can( $cap ) || current_user_can( 'manage_options' ),
			);
		}
		wp_localize_script(
			'wvm-admin',
			'wvmAdmin',
			array(
				'restUrl'            => esc_url_raw( rest_url( WVM_REST_NS . '/' ) ),
				'restRoot'           => esc_url_raw( rest_url() ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'adminUrl'           => admin_url(),
				'pages'              => $pages,
				'caps'               => Capabilities::forCurrentUser(),
				'user'               => array(
					'id'     => $user->ID,
					'name'   => $user->display_name,
					'email'  => $user->user_email,
					'avatar' => get_avatar_url( $user->ID, array( 'size' => 64 ) ),
				),
				'levels'             => LevelResolver::LEVELS,
				'currencies'         => array_values( (array) $this->settings->get( 'payments', 'currencies_enabled', array( 'UGX', 'USD' ) ) ),
				'allCurrencies'      => Money::known(),
				'decimals'           => array_combine( Money::known(), array_map( array( Money::class, 'decimals' ), Money::known() ) ),
				'countries'          => function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array(),
				'timezones'          => timezone_identifiers_list(),
				'placeholders'       => TemplateDefaults::PLACEHOLDERS,
				'dashboardUrl'       => Urls::dashboard(),
				'pricingUrl'         => Urls::pricing(),
				'enterpriseUrl'      => Urls::enterprise(),
				'wooOrdersUrl'       => admin_url( 'admin.php?page=wc-orders' ),
				'wooGatewaysUrl'     => admin_url( 'admin.php?page=wc-settings&tab=checkout' ),
				'actionSchedulerUrl' => admin_url( 'admin.php?page=wc-status&tab=action-scheduler&s=wvm_' ),
				'gateways'           => $this->gateways(),
				'postTypes'          => array_values(
					array_map(
						static fn( $t ) => array(
							'name'  => $t->name,
							'label' => $t->labels->singular_name,
						),
						get_post_types( array( 'public' => true ), 'objects' )
					)
				),
				'sandbox'            => (bool) $this->settings->get( 'payments', 'sandbox_mode', false ),
				'siteName'           => (string) $this->settings->get( 'general', 'business_name', 'WitsValley' ),
				'version'            => WVM_VERSION,
			)
		);
	}

	/** @return list<array{id:string,title:string,enabled:bool}> */
	private function gateways(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return array();
		}
		$out = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gw ) {
			$out[] = array(
				'id'      => (string) $id,
				'title'   => wp_strip_all_tags( (string) ( $gw->get_method_title() ?: $gw->get_title() ) ),
				'enabled' => 'yes' === $gw->enabled,
			);
		}
		return $out;
	}

	/** @param list<string> $links */
	public function actionLinks( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=wvm' ) ) . '">' . esc_html__( 'Open', 'wv-members' ) . '</a>', '<a href="' . esc_url( admin_url( 'admin.php?page=wvm-settings' ) ) . '">' . esc_html__( 'Settings', 'wv-members' ) . '</a>' );
		return $links;
	}
}
