<?php
declare( strict_types=1 );

namespace WitsValley\Members;

use WitsValley\Members\Admin\ContentMetaBox;
use WitsValley\Members\Admin\Menu;
use WitsValley\Members\Domain\Access\ContentGuard;
use WitsValley\Members\Domain\Access\MediaGuard;
use WitsValley\Members\Domain\Kit\KitSync;
use WitsValley\Members\Domain\Notification\Urls;
use WitsValley\Members\Domain\Order\OrderListener;
use WitsValley\Members\Domain\Payment\PaymentMethodService;
use WitsValley\Members\Frontend\Frontend;
use WitsValley\Members\Frontend\Privacy;
use WitsValley\Members\Install\Installer;
use WitsValley\Members\Jobs\Handlers;
use WitsValley\Members\Woo\CheckoutCustomiser;
use WitsValley\Members\Woo\EmailSuppressor;
use WitsValley\Members\Woo\GatewayFilter;
use WitsValley\Members\Woo\ProductSync;
use WitsValley\Members\Woo\Redirects;

/**
 * Plugin kernel: service container + hook registration.
 */
final class Plugin {

	private static ?self $instance = null;

	private Container $container;

	private bool $booted = false;

	/** REST controllers, registered on rest_api_init. */
	public const CONTROLLERS = array(
		Rest\PublicController::class,
		Rest\MeController::class,
		Rest\PlansController::class,
		Rest\MembersController::class,
		Rest\SubscriptionsController::class,
		Rest\OrdersController::class,
		Rest\AccessController::class,
		Rest\WorkshopsController::class,
		Rest\EnterpriseController::class,
		Rest\EmailsController::class,
		Rest\KitController::class,
		Rest\SettingsController::class,
		Rest\AuditController::class,
		Rest\InsightsController::class,
		Rest\HomeController::class,
	);

	/** Services with a register() method that attaches WordPress hooks. */
	public const HOOKED = array(
		OrderListener::class,
		ProductSync::class,
		GatewayFilter::class,
		\WitsValley\Members\Woo\CurrencyContext::class,
		CheckoutCustomiser::class,
		Redirects::class,
		EmailSuppressor::class,
		ContentGuard::class,
		MediaGuard::class,
		KitSync::class,
		PaymentMethodService::class,
		Frontend::class,
		Privacy::class,
		Menu::class,
		ContentMetaBox::class,
		Handlers::class,
		\WitsValley\Members\Support\Branding::class,
		\WitsValley\Members\Domain\Member\MemberListeners::class,
	);

	private function __construct() {
		$this->container = new Container();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function container(): Container {
		return $this->container;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;
		add_action( 'init', static fn() => load_plugin_textdomain( 'wv-members', false, dirname( plugin_basename( WVM_FILE ) ) . '/languages' ), 1 );
		// Upgrades seed Woo products and pages, so they must wait for WordPress + WooCommerce to be fully initialised.
		add_action( 'init', array( Installer::class, 'maybeUpgrade' ), 20 );
		foreach ( self::HOOKED as $service ) {
			$this->container->get( $service )->register();
		}
		add_action(
			'rest_api_init',
			function (): void {
				foreach ( self::CONTROLLERS as $controller ) {
					$this->container->get( $controller )->routes();
				}
			}
		);
		add_action( 'admin_init', array( Installer::class, 'ensureRecurringJobs' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		do_action( 'wvm/booted', $this );
	}

	public function notices(): void {
		if ( ! current_user_can( 'wvm_manage_settings' ) ) {
			return;
		}
		$settings = $this->container->get( \WitsValley\Members\Support\Settings::class );
		if ( $settings->get( 'payments', 'sandbox_mode', false ) ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && str_contains( (string) $screen->id, 'wvm' ) ) {
				return;
			}
			echo '<div class="notice notice-warning"><p><strong>WitsValley Members</strong> is in <strong>sandbox mode</strong> — payments use the Test gateway. <a href="' . esc_url( admin_url( 'admin.php?page=wvm-settings#/settings/payments' ) ) . '">Payment settings</a></p></div>';
		}
		unset( $settings );
	}

	/** Reset for tests. */
	public function reset(): void {
		$this->container->reset();
	}

	public static function dashboardUrl(): string {
		return Urls::dashboard();
	}
}
