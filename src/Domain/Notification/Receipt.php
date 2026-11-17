<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Notification;

use WitsValley\Members\Domain\Member\MemberService;
use WitsValley\Members\Domain\Order\OrderService;
use WitsValley\Members\Domain\Payment\PaymentRepository;
use WitsValley\Members\Support\Clock;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Settings;

/**
 * Receipt rendering: HTML, and PDF via Dompdf (cached in the protected uploads directory).
 */
final class Receipt {

	public function __construct(
		private OrderService $orders,
		private MemberService $members,
		private PaymentRepository $payments,
		private Settings $settings
	) {}

	public function html( int $orderId ): string {
		$order = $this->orders->get( $orderId );
		if ( ! in_array( $order['status'], array( 'paid', 'refunded' ), true ) ) {
			throw DomainError::conflict( 'Receipts are only available for paid orders.' );
		}
		$member   = $this->members->get( (int) $order['member_id'] );
		$payment  = $this->payments->first( array( 'order_id' => $orderId ) );
		$g        = $this->settings->group( 'general' );
		$brand    = esc_attr( (string) $this->settings->get( 'emails', 'brand_color', '#5B3DF5' ) );
		$paid     = Clock::inZone( (string) ( $this->orders->raw( $orderId )['paid_at'] ?? '' ), (string) $member['timezone'], 'j F Y, H:i' );
		$period   = $order['period_start'] && $order['period_end'] ? esc_html( gmdate( 'j M Y', (int) strtotime( (string) $order['period_start'] ) ) . ' – ' . gmdate( 'j M Y', (int) strtotime( (string) $order['period_end'] ) ) ) : '';
		$method   = $payment ? esc_html( (string) $payment['gateway'] ) : '—';
		$ref      = $payment ? esc_html( (string) $payment['provider_ref'] ) : '—';
		$refunded = 'refunded' === $order['status'] ? '<p style="color:#B42318;font-weight:700">REFUNDED</p>' : '';

		return '<!doctype html><html><head><meta charset="utf-8"><title>Receipt ' . esc_html( (string) $order['receipt_number'] ) . '</title><style>
			body{font-family:DejaVu Sans,Helvetica,Arial,sans-serif;color:#1E1B3A;font-size:12px;margin:40px}
			h1{font-size:22px;margin:0 0 4px}.muted{color:#7A7699}.brand{color:' . $brand . ';font-weight:800;font-size:20px}
			table{width:100%;border-collapse:collapse;margin-top:24px}th{text-align:left;font-size:11px;text-transform:uppercase;color:#7A7699;border-bottom:2px solid #ECEAF5;padding:8px 0}
			td{padding:10px 0;border-bottom:1px solid #ECEAF5}.right{text-align:right}.total td{font-weight:700;font-size:14px;border-bottom:0}
			.grid{width:100%;margin-top:28px}.grid td{border:0;vertical-align:top;padding:0}</style></head><body>
			<table class="grid"><tr><td><div class="brand">' . esc_html( (string) $g['business_name'] ) . '</div><div class="muted">' . esc_html( (string) $g['address'] ) . '</div></td>
			<td class="right"><h1>Receipt</h1><div class="muted">' . esc_html( (string) $order['receipt_number'] ) . '</div><div class="muted">' . esc_html( $paid ) . '</div></td></tr></table>
			<table class="grid"><tr><td><strong>Billed to</strong><br>' . esc_html( (string) $member['name'] ) . '<br>' . esc_html( (string) $member['email'] ) . '<br>Member ID ' . esc_html( (string) $member['public_id'] ) . '</td>
			<td class="right"><strong>Payment</strong><br>Method: ' . $method . '<br>Reference: ' . $ref . '</td></tr></table>
			<table><thead><tr><th>Description</th><th class="right">Amount</th></tr></thead><tbody>
			<tr><td>' . esc_html( (string) $order['description'] ) . ( $period ? '<br><span class="muted">Period ' . $period . '</span>' : '' ) . '</td><td class="right">' . esc_html( (string) $order['amount_display'] ) . '</td></tr>
			<tr class="total"><td>Total paid</td><td class="right">' . esc_html( (string) $order['amount_display'] ) . '</td></tr></tbody></table>' . $refunded . '
			<p class="muted" style="margin-top:40px">Thank you for being part of WitsValley. Questions? ' . esc_html( (string) ( $g['support_email'] ?: get_option( 'admin_email' ) ) ) . '</p>
			</body></html>';
	}

	/** Absolute path to a cached PDF, or null if Dompdf is unavailable. */
	public function pdfPath( int $orderId ): ?string {
		if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
			return null;
		}
		$order = $this->orders->get( $orderId );
		$dir   = self::dir();
		$file  = $dir . '/' . sanitize_file_name( (string) ( $order['receipt_number'] ?: 'order-' . $orderId ) ) . ( 'refunded' === $order['status'] ? '-refunded' : '' ) . '.pdf';
		if ( is_readable( $file ) ) {
			return $file;
		}
		$pdf = new \Dompdf\Dompdf( array( 'isRemoteEnabled' => false ) );
		$pdf->loadHtml( $this->html( $orderId ) );
		$pdf->setPaper( 'A4' );
		$pdf->render();
		file_put_contents( $file, $pdf->output() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $file;
	}

	public static function dir(): string {
		$uploads = wp_upload_dir();
		$dir     = trailingslashit( (string) $uploads['basedir'] ) . 'wvm-protected/receipts';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		\WitsValley\Members\Domain\Access\MediaGuard::protectDirectory( dirname( $dir ) );
		return $dir;
	}
}
