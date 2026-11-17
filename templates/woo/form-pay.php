<?php
/**
 * WitsValley branded pay-for-order form (overrides checkout/form-pay.php for plugin orders only).
 *
 * Keeps WooCommerce's #order_review form, #payment block, nonce and #place_order button so every
 * gateway's own JavaScript keeps working.
 *
 * @package WitsValley\Members
 * @var WC_Order $order
 * @var array    $available_gateways
 * @var string   $order_button_text
 */

defined( 'ABSPATH' ) || exit;

$wvm   = wvm( \WitsValley\Members\Woo\CheckoutCustomiser::class )->summary( $order );
$plan  = $wvm['plan'];
$wvo   = $wvm['order'];
$label = $wvo ? $wvo['type_label'] : '';
?>
<div class="wvm-pay">
	<header class="wvm-pay__header">
		<a class="wvm-pay__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php if ( $wvm['logo'] ) : ?>
				<img src="<?php echo esc_url( $wvm['logo'] ); ?>" alt="<?php echo esc_attr( $wvm['business'] ); ?>" height="32">
			<?php else : ?>
				<span class="wvm-pay__logo-mark" aria-hidden="true">W</span><span><?php echo esc_html( $wvm['business'] ); ?></span>
			<?php endif; ?>
		</a>
		<ol class="wvm-steps" aria-label="<?php esc_attr_e( 'Progress', 'wv-members' ); ?>">
			<li class="is-done"><span>1</span> <?php esc_html_e( 'Account', 'wv-members' ); ?></li>
			<li class="is-current" aria-current="step"><span>2</span> <?php esc_html_e( 'Pay', 'wv-members' ); ?></li>
			<li><span>3</span> <?php esc_html_e( 'Done', 'wv-members' ); ?></li>
		</ol>
	</header>

	<?php if ( $wvm['sandbox'] ) : ?>
		<div class="wvm-pay__sandbox" role="status"><?php esc_html_e( 'Sandbox mode — no real money will be charged.', 'wv-members' ); ?></div>
	<?php endif; ?>

	<div class="wvm-pay__grid">
		<aside class="wvm-pay__summary" aria-label="<?php esc_attr_e( 'Order summary', 'wv-members' ); ?>">
			<div class="wvm-pay__plan">
				<span class="wvm-chip"><?php echo esc_html( $label ); ?></span>
				<h2><?php echo esc_html( $plan ? $plan['name'] : ( $wvo ? $wvo['description'] : '' ) ); ?></h2>
				<p class="wvm-pay__amount"><?php echo esc_html( $wvo ? $wvo['amount_display'] : wp_strip_all_tags( $order->get_formatted_order_total() ) ); ?>
					<?php if ( $plan && in_array( $plan['interval'], array( 'monthly', 'quarterly', 'annual' ), true ) ) : ?>
						<small>/ 
						<?php
						echo esc_html(
							array(
								'monthly'   => __( 'month', 'wv-members' ),
								'quarterly' => __( 'quarter', 'wv-members' ),
								'annual'    => __( 'year', 'wv-members' ),
							)[ $plan['interval'] ]
						);
						?>
									</small>
					<?php endif; ?>
				</p>
				<?php if ( $plan && $plan['benefits'] ) : ?>
					<ul class="wvm-pay__benefits">
						<?php foreach ( array_slice( $plan['benefits'], 0, 6 ) as $benefit ) : ?>
							<li><?php echo esc_html( $benefit ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
			<p class="wvm-pay__secure"><svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3Zm-1 14-4-4 1.4-1.4L11 13.2l4.6-4.6L17 10l-6 6Z"/></svg><?php esc_html_e( 'Secure payment. Cancel any time from your dashboard.', 'wv-members' ); ?></p>
		</aside>

		<form id="order_review" method="post" class="wvm-pay__form">
			<h1 class="wvm-pay__title"><?php esc_html_e( 'Choose how to pay', 'wv-members' ); ?></h1>
			<?php do_action( 'woocommerce_pay_order_before_payment' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks ?>
			<div id="payment">
				<?php if ( $order->needs_payment() ) : ?>
					<ul class="wc_payment_methods payment_methods methods" aria-label="<?php esc_attr_e( 'Payment methods', 'wv-members' ); ?>">
						<?php
						if ( ! empty( $available_gateways ) ) {
							foreach ( $available_gateways as $gateway ) {
								wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
							}
						} else {
							echo '<li>';
							wc_print_notice( esc_html__( 'No payment methods are available for your country yet. Please contact us and we will help you pay.', 'wv-members' ), 'notice' );
							echo '</li>';
						}
						?>
					</ul>
				<?php else : ?>
					<p class="wvm-pay__paid"><?php esc_html_e( 'This order is already paid. You can go to your dashboard.', 'wv-members' ); ?></p>
				<?php endif; ?>
				<div class="form-row">
					<input type="hidden" name="woocommerce_pay" value="1" />
					<?php do_action( 'woocommerce_pay_order_before_submit' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks ?>
					<?php echo apply_filters( 'woocommerce_pay_order_button_html', '<button type="submit" class="button alt wvm-btn wvm-btn--primary" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">' . esc_html( $order_button_text ) . '</button>' ); // phpcs:ignore ?>
					<?php do_action( 'woocommerce_pay_order_after_submit' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks ?>
					<?php wp_nonce_field( 'woocommerce-pay', 'woocommerce-pay-nonce' ); ?>
				</div>
			</div>
			<p class="wvm-pay__legal">
				<?php
				if ( $wvm['terms'] ) {
					printf(
						/* translators: %s: terms link */
						esc_html__( 'By paying you agree to our %s.', 'wv-members' ),
						'<a href="' . esc_url( $wvm['terms'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'terms', 'wv-members' ) . '</a>'
					);
				}
				?>
				<a href="<?php echo esc_url( \WitsValley\Members\Domain\Notification\Urls::dashboard() ); ?>"><?php esc_html_e( 'Back to my dashboard', 'wv-members' ); ?></a>
			</p>
			<div class="wvm-pay__waiting" hidden aria-live="polite"><span class="wvm-spinner" aria-hidden="true"></span> <?php esc_html_e( 'Waiting for your payment provider to confirm…', 'wv-members' ); ?></div>
		</form>
	</div>
</div>
