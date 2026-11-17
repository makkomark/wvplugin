<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Notification;

/**
 * Seeded email templates. Placeholders: {first_name} {plan} {amount} {renewal_date} {pay_link}
 * {dashboard_url} {member_id} plus template-specific ones listed in PLACEHOLDERS.
 * [button url="..."]Label[/button] renders a branded call-to-action button.
 */
final class TemplateDefaults {

	/** @var list<string> */
	public const PLACEHOLDERS = array(
		'first_name', 'plan', 'amount', 'renewal_date', 'pay_link', 'dashboard_url', 'member_id',
		'grace_end_date', 'receipt_number', 'receipt_url', 'days', 'reason', 'reference', 'workshop_title',
		'workshop_time', 'join_url', 'meeting_url', 'consultation_time', 'verify_url', 'device', 'ip',
		'org_name', 'accept_url', 'business_name', 'support_email',
	);

	/** @return array<string,array{name:string,subject:string,preview:string,html:string,text:string}> */
	public static function all(): array {
		$t = array();

		$t['payment_pending'] = self::make(
			'Payment pending',
			'Finish joining WitsValley',
			'Your {plan} membership is one step away.',
			"<p>Hi {first_name},</p><p>You're one step away from your <strong>{plan}</strong> membership ({amount}). Complete your payment to unlock everything.</p>[button url=\"{pay_link}\"]Complete payment[/button]<p>If you've already paid, you can ignore this email — we'll confirm as soon as your payment provider does.</p>"
		);
		$t['welcome'] = self::make(
			'Welcome',
			'Welcome to WitsValley {plan} 🎉',
			"You're in. Here's how to get started.",
			"<p>Hi {first_name},</p><p>Welcome to <strong>WitsValley {plan}</strong>! Your member ID is <strong>{member_id}</strong>.</p><p>Your content arrives by email from our learning team — look out for it in your inbox. Your dashboard shows your membership, upcoming workshops and receipts.</p>[button url=\"{dashboard_url}\"]Open my dashboard[/button]"
		);
		$t['receipt'] = self::make(
			'Payment receipt',
			'Receipt {receipt_number} — {amount}',
			'Thanks for your payment.',
			"<p>Hi {first_name},</p><p>We received your payment of <strong>{amount}</strong> for <strong>{plan}</strong>.</p><table role=\"presentation\" class=\"kv\"><tr><td>Receipt</td><td>{receipt_number}</td></tr><tr><td>Amount</td><td>{amount}</td></tr><tr><td>Member ID</td><td>{member_id}</td></tr></table>[button url=\"{receipt_url}\"]Download receipt (PDF)[/button]"
		);
		$t['renewal_7d'] = self::make(
			'Renewal reminder (7 days)',
			'Your {plan} membership renews on {renewal_date}',
			'A quick heads-up about your renewal.',
			"<p>Hi {first_name},</p><p>Your <strong>{plan}</strong> membership renews on <strong>{renewal_date}</strong> ({amount}).</p><p>Pay early to stay uninterrupted — your new period always starts from your renewal date, so you never lose days.</p>[button url=\"{pay_link}\"]Renew now[/button]"
		);
		$t['renewal_3d'] = self::make(
			'Renewal reminder (3 days)',
			'3 days left on your {plan} membership',
			'Renew now to keep access.',
			"<p>Hi {first_name},</p><p>Just 3 days until your <strong>{plan}</strong> membership renews on <strong>{renewal_date}</strong>.</p>[button url=\"{pay_link}\"]Renew {amount}[/button]"
		);
		$t['renewal_due_manual'] = self::make(
			'Renewal due (manual payment)',
			'Your {plan} renewal is due today',
			'Pay now to keep your access.',
			"<p>Hi {first_name},</p><p>Your <strong>{plan}</strong> membership renewal ({amount}) is due today. You have until <strong>{grace_end_date}</strong> before access is paused.</p>[button url=\"{pay_link}\"]Pay now[/button]"
		);
		$t['autocharge_failed'] = self::make(
			'Automatic payment failed',
			"We couldn't charge your saved payment method",
			'Update your payment to keep access.',
			"<p>Hi {first_name},</p><p>We tried to renew your <strong>{plan}</strong> membership ({amount}) with your saved payment method, but it didn't go through. We'll retry automatically, or you can pay now.</p>[button url=\"{pay_link}\"]Pay now[/button]"
		);
		$t['grace_started'] = self::make(
			'Grace period started',
			'Action needed: your {plan} payment is overdue',
			'Pay by {grace_end_date} to keep access.',
			"<p>Hi {first_name},</p><p>Your <strong>{plan}</strong> renewal is overdue. Your access continues until <strong>{grace_end_date}</strong> — pay before then to avoid interruption.</p>[button url=\"{pay_link}\"]Pay {amount}[/button]"
		);
		$t['suspended'] = self::make(
			'Membership suspended',
			'Your {plan} membership is paused',
			'Reactivate any time.',
			"<p>Hi {first_name},</p><p>We didn't receive your renewal, so your <strong>{plan}</strong> membership is now paused. Your history and progress are safe — reactivate any time and you'll pick up where you left off.</p>[button url=\"{pay_link}\"]Reactivate[/button]"
		);
		$t['restored'] = self::make(
			'Membership restored',
			"You're back! {plan} is active again",
			'Thanks for renewing.',
			"<p>Hi {first_name},</p><p>Thanks — your <strong>{plan}</strong> membership is active again. Next renewal: <strong>{renewal_date}</strong>.</p>[button url=\"{dashboard_url}\"]Go to my dashboard[/button]"
		);
		$t['plan_changed'] = self::make(
			'Plan changed',
			'Your plan is now {plan}',
			'Here are the details of your plan change.',
			"<p>Hi {first_name},</p><p>Your membership plan has been updated to <strong>{plan}</strong>. Next renewal: {renewal_date} ({amount}).</p>[button url=\"{dashboard_url}\"]See my membership[/button]"
		);
		$t['cancel_scheduled'] = self::make(
			'Cancellation scheduled',
			'Your {plan} membership will end on {renewal_date}',
			'Changed your mind? You can resume any time before then.',
			"<p>Hi {first_name},</p><p>We've scheduled your <strong>{plan}</strong> membership to end on <strong>{renewal_date}</strong>. You keep full access until then.</p><p>Changed your mind? Resume with one click.</p>[button url=\"{dashboard_url}\"]Resume membership[/button]"
		);
		$t['expired'] = self::make(
			'Membership expired',
			'Your WitsValley membership has ended',
			'Rejoin any time.',
			"<p>Hi {first_name},</p><p>Your <strong>{plan}</strong> membership has ended. We'd love to have you back — rejoin any time from your dashboard.</p>[button url=\"{dashboard_url}\"]Rejoin WitsValley[/button]"
		);
		$t['workshop_24h'] = self::make(
			'Workshop in 24 hours',
			'Tomorrow: {workshop_title}',
			'Add it to your calendar.',
			"<p>Hi {first_name},</p><p><strong>{workshop_title}</strong> starts {workshop_time}. The join button appears on your dashboard 15 minutes before start.</p>[button url=\"{dashboard_url}\"]View workshop[/button]"
		);
		$t['workshop_1h'] = self::make(
			'Workshop in 1 hour',
			'Starting soon: {workshop_title}',
			'See you in an hour.',
			"<p>Hi {first_name},</p><p><strong>{workshop_title}</strong> starts in about an hour ({workshop_time}).</p>[button url=\"{dashboard_url}\"]Open my workshops[/button]"
		);
		$t['consultation_confirmed'] = self::make(
			'Consultation confirmed',
			'Your consultation is booked for {consultation_time}',
			'Here are your meeting details.',
			"<p>Hi {first_name},</p><p>Your consultation is confirmed for <strong>{consultation_time}</strong>.</p>[button url=\"{meeting_url}\"]Join meeting[/button]"
		);
		$t['admin_extension'] = self::make(
			'Membership extended by admin',
			"We've extended your membership",
			'{days} extra days added.',
			"<p>Hi {first_name},</p><p>Good news — we've added <strong>{days} days</strong> to your <strong>{plan}</strong> membership. Your new renewal date is <strong>{renewal_date}</strong>.</p>"
		);
		$t['admin_manual_payment'] = self::make(
			'Manual payment recorded',
			'We recorded your payment of {amount}',
			'Thanks — your membership is updated.',
			"<p>Hi {first_name},</p><p>We've recorded your payment of <strong>{amount}</strong> for <strong>{plan}</strong> (reference {reference}). Your receipt follows separately.</p>"
		);
		$t['admin_refund'] = self::make(
			'Refund issued',
			'Refund of {amount} issued',
			'Your refund is on its way.',
			"<p>Hi {first_name},</p><p>We've issued a refund of <strong>{amount}</strong>. Depending on your payment provider it can take a few days to appear.</p><p>Reason: {reason}</p>"
		);
		$t['email_change_verify'] = self::make(
			'Verify new email',
			'Confirm your new email address',
			'One click to confirm.',
			"<p>Hi {first_name},</p><p>Please confirm this is your new email address for WitsValley. The link is valid for 24 hours.</p>[button url=\"{verify_url}\"]Confirm email[/button]<p>If you didn't request this, ignore this email.</p>"
		);
		$t['new_device_login'] = self::make(
			'New device login',
			'New sign-in to your WitsValley account',
			'Was this you?',
			"<p>Hi {first_name},</p><p>Your account was just used to sign in from a new device: <strong>{device}</strong> (IP {ip}).</p><p>If this wasn't you, sign out other sessions from your profile and reset your password.</p>[button url=\"{dashboard_url}\"]Review sessions[/button]"
		);
		$t['enterprise_ack'] = self::make(
			'Enterprise application received',
			"We've received your application, {first_name}",
			'Our team will be in touch within 2 business days.',
			"<p>Hi {first_name},</p><p>Thanks for your interest in WitsValley Enterprise & Private programmes for <strong>{org_name}</strong>. Our team will review your request and reply within 2 business days with next steps.</p>"
		);
		$t['enterprise_invite'] = self::make(
			'Enterprise invite',
			"You're invited to {org_name}'s WitsValley programme",
			'Accept your seat.',
			"<p>Hello,</p><p><strong>{org_name}</strong> has invited you to join their WitsValley programme.</p>[button url=\"{accept_url}\"]Accept invitation[/button]"
		);
		$t['pay_link'] = self::make(
			'Payment link',
			'Your WitsValley payment link',
			'Pay securely in one click.',
			"<p>Hi {first_name},</p><p>Here's your secure payment link for <strong>{plan}</strong> ({amount}). It's valid for 7 days.</p>[button url=\"{pay_link}\"]Pay now[/button]"
		);
		return $t;
	}

	/** @return array{name:string,subject:string,preview:string,html:string,text:string} */
	private static function make( string $name, string $subject, string $preview, string $html ): array {
		return array(
			'name'    => $name,
			'subject' => $subject,
			'preview' => $preview,
			'html'    => $html,
			'text'    => self::toText( $html ),
		);
	}

	public static function toText( string $html ): string {
		$html = (string) preg_replace( '#\[button url="([^"]*)"\](.*?)\[/button\]#s', "$2: $1\n", $html );
		$html = str_replace( array( '</p>', '<br>', '<br/>', '</tr>' ), "\n\n", $html );
		$html = str_replace( '</td><td>', ': ', $html );
		return trim( (string) preg_replace( "/\n{3,}/", "\n\n", wp_strip_all_tags( $html, false ) ) );
	}

	/** Which notification preference (if any) gates a template. */
	public static function preference( string $key ): ?string {
		return match ( $key ) {
			'renewal_7d', 'renewal_3d' => 'renewal_reminders',
			'workshop_24h', 'workshop_1h' => 'workshop_reminders',
			default => null,
		};
	}
}
