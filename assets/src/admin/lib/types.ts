/* Admin-only response shapes (complements shared/types.ts). */
import type { AuditRow, Entitlements, HealthCheck, Member, Order, PaymentMethod, Subscription, Workshop } from '../../shared/types';

export interface HomeData {
	attention: { key: string; label: string; count: number; route: string; cap: string; tone: 'danger' | 'warning' | 'info' }[];
	today: {
		new_members: number;
		payments: { currency: string; count: number; total_display: string }[];
		renewals_due: number;
		workshops: Workshop[];
	};
	health: { status: 'ok' | 'warning' | 'error'; checks: HealthCheck[] };
	stats: InsightsSummary | null;
	activity: AuditRow[];
}

export interface InsightsSummary {
	currency: string;
	mrr_minor: number;
	mrr_display: string;
	active_paid: number;
	active_free: number;
	revenue_month_minor: number;
	revenue_month_display: string;
	past_due: number;
	needs_review: number;
	new_members_month: number;
	churn_rate: number;
	renewal_success: number;
}

export interface Note { id: number; member_id: number; body: string; author_id: number | null; author_name: string; created_at: string }

export interface MemberDetailData {
	member: Member;
	subscription: Subscription | null;
	entitlements: Entitlements;
	organisation: { id: number; name: string; role?: string; programme_title?: string; term_end?: string | null; status?: string } | null;
	pending_order: Order | null;
	payment_methods: PaymentMethod[];
	notes: Note[];
	stats: { lifetime_value: { currency: string; total_minor: number; display: string; orders: number }[]; member_since: string; subscriptions: number };
	kit: { subscriber_id: string | null; last_synced: string | null; error: string | null; unsubscribed_at: string | null; unsubscribe_reason: 'unsubscribe' | 'bounce' | 'complain' | null; expected: { state: string; plan_tag: string | null; extra: string[]; paid: boolean } };
	wp_user_url: string;
}

export interface NotificationRow { id: number; member_id: number | null; to_email: string; template_key: string; subject: string; status: 'queued' | 'sent' | 'failed'; attempts: number; error: string | null; sent_at: string | null; created_at: string }

export interface EmailTemplateRow { key: string; name: string; subject: string; preview: string; enabled: boolean; customised: boolean; updated_at: string; body_html?: string; body_text?: string; placeholders?: string[] }

export interface AccessPost { id: number; title: string; post_type: string; status: string; level: number; level_label: string; plans: string[]; orgs: number[]; teaser_paragraphs: number | null; source: string; edit_url: string; view_url: string }

export interface AccessTerm { id: number; name: string; taxonomy: string; taxonomy_label: string; count: number; level: number; level_label: string }

export interface KitStatus { configured: boolean; connected: boolean; account_name: string | null; last_reconcile_at: string | null; error_count_24h: number; members_with_errors: number; synced_members: number; unsubscribed: number; webhooks_connected: boolean; webhooks_connected_at: string | null; marketing_tag: string | null }

export interface KitLogRow { id: number; member_id: number; action: string; tag: string | null; status: 'ok' | 'error'; response: string | null; created_at: string; member_name?: string; public_id?: string }

export type TagRule = { add: string[]; remove: string[] };
