# Adding a payment gateway

WitsValley uses provider-maintained WooCommerce gateway plugins (Flutterwave, Pesapal, DPO, PayPal, JPesa…). To add one:

1. Install and configure the gateway plugin in **WooCommerce › Settings › Payments**. Test it in its sandbox mode.
2. **WitsValley › Settings › Payments › Gateways by country**: add the gateway id (e.g. `flutterwave`) to the countries that should see it
   (`default` applies to every other country). The order-pay page only shows the gateways listed for the member's billing country
   (if none match, all enabled gateways are shown so nobody is stuck).
3. **Transaction id meta keys**: tell the plugin where the gateway stores its transaction reference on the WooCommerce order, in priority order.
   `_transaction_id` means WooCommerce's own `get_transaction_id()`. Defaults:

   | Gateway id | Meta keys |
   | --- | --- |
   | `flutterwave` | `_flutterwave_transaction_id`, `_transaction_id`, `flw_ref` |
   | `pesapal` | `_pesapal_tracking_id`, `pesapal_transaction_tracking_id`, `_transaction_id` |
   | `dpo` | `_dpo_transaction_token`, `dpo_trans_ref`, `_transaction_id` |
   | `paypal` / `ppcp-gateway` | `_transaction_id`, `_ppcp_paypal_order_id` |
   | `jpesa` | `_jpesa_tid`, `_transaction_id` |

   The reference is stored as `payments.provider_ref = "{gateway}:{reference}"` (unique — a duplicate reference on another order goes to review).
   Developers can also use the `wvm_transaction_meta_keys` filter.
4. Run a sandbox payment, open the order in **WitsValley › Payments** and confirm the payment row shows the right gateway and reference.

## Automatic renewals (saved cards)
Most mobile-money gateways cannot charge without the member present, so renewals default to a pay link. If a gateway exposes saved tokens:

```php
add_filter( 'wvm_autocharge_adapters', function ( array $adapters ) {
    $adapters['my_gateway'] = new class implements \WitsValley\Members\Domain\Payment\AutoChargeAdapter {
        public function charge( \WC_Order $order, string $token ): array {
            // Call the provider API with $token for $order->get_total() in $order->get_currency().
            // On success: $order->payment_complete( $transactionId ); return [ 'ok' => true, 'message' => 'Charged' ];
            return [ 'ok' => false, 'message' => 'Declined' ];
        }
    };
    return $adapters;
} );
```
Tokens saved by the gateway as WooCommerce payment tokens are mirrored into `wp_wvm_payment_methods` automatically. Failed auto-charges retry on the
days set in Settings › Lifecycle and fall back to a pay link. The built-in `wvm_test` gateway shows the full flow in sandbox mode.
