/* Shared API types (mirror the PHP presenters). */

export type Currency = string;
export type Interval = 'none' | 'monthly' | 'quarterly' | 'annual' | 'one_off' | 'custom' | 'contract';
export type SubStatus = 'pending' | 'active' | 'past_due' | 'suspended' | 'expired' | 'cancelled';
export type OrderStatus = 'pending' | 'paid' | 'failed' | 'cancelled' | 'refunded' | 'needs_review';
export type OrderType = 'new' | 'renewal' | 'upgrade' | 'downgrade' | 'reactivation' | 'enterprise' | 'manual_adjust';

export interface ApiEnvelope< T > {
	data: T;
	meta?: PageMeta & Record< string, unknown >;
	error?: ApiErrorBody;
}
export interface PageMeta { total: number; pages: number; page: number; per_page: number }
export interface ApiErrorBody { code: string; message: string; fields: Record< string, string > }

export interface Plan {
	id: number;
	slug: string;
	name: string;
	description: string;
	level: number;
	interval: Interval;
	interval_count: number;
	trial_days: number;
	kit_tag: string;
	visibility: 'public' | 'hidden' | 'invite';
	benefits: string[];
	sort_order: number;
	woo_product_id: number | null;
	woo_product_url: string | null;
	is_free: boolean;
	is_quoted: boolean;
	highlight: boolean;
	archived_at: string | null;
	prices: Record< Currency, number > | [];
	prices_display: string[];
	member_count?: number;
	created_at: string;
	updated_at: string;
}

export interface PublicPlan {
	id: number;
	slug: string;
	name: string;
	description: string;
	level: number;
	interval: Interval;
	interval_count: number;
	trial_days: number;
	benefits: string[];
	is_free: boolean;
	is_quoted: boolean;
	highlight: boolean;
	currency: Currency;
	amount_minor: number | null;
	amount_display: string | null;
	available: boolean;
	prices: Record< Currency, number > | [];
}

export interface Member {
	id: number;
	public_id: string;
	user_id: number;
	name: string;
	first_name: string;
	last_name: string;
	email: string;
	avatar_url: string;
	phone: string;
	country: string;
	timezone: string;
	marketing_consent: boolean;
	marketing_consent_at: string | null;
	notification_prefs: Record< 'renewal_reminders' | 'workshop_reminders' | 'product_updates' | 'in_app', boolean >;
	kit_subscriber_id: string | null;
	kit_last_synced_at: string | null;
	kit_sync_error: string | null;
	notes_count: number;
	anonymised_at: string | null;
	created_at: string;
}

export interface MemberRow {
	id: number;
	public_id: string;
	user_id: number;
	name: string;
	email: string;
	phone: string;
	country: string;
	plan_name: string;
	plan_slug: string;
	subscription_id: number | null;
	status: SubStatus | 'none';
	cancel_at_period_end: boolean;
	period_end: string | null;
	currency: string | null;
	amount_minor: number | null;
	marketing_consent: boolean;
	kit_sync_error: string | null;
	created_at: string;
}

export interface Subscription {
	id: number;
	member_id: number;
	plan_id: number;
	plan: { id: number; slug: string; name: string; level: number; interval: Interval; is_free: boolean; benefits: string[] };
	pending_plan: { id: number; name: string } | null;
	organisation_id: number | null;
	status: SubStatus;
	currency: Currency;
	amount_minor: number;
	amount_display: string;
	started_at: string | null;
	current_period_start: string | null;
	current_period_end: string | null;
	period_end_display: string;
	days_left: number | null;
	grace_ends_at: string | null;
	grace_ends_display: string;
	suspended_at: string | null;
	expires_at: string | null;
	cancel_at_period_end: boolean;
	cancelled_at: string | null;
	cancel_reason: string | null;
	payment_method_id: number | null;
	autocharge_attempts: number;
	can_rejoin: boolean;
	created_at: string;
	updated_at: string;
	events?: SubscriptionEvent[];
}

export interface SubscriptionEvent {
	id: number;
	from_status: string | null;
	to_status: string;
	reason: string | null;
	actor_type: 'system' | 'admin' | 'member';
	actor_name: string;
	created_at: string;
}

export interface Order {
	id: number;
	woo_order_id: number | null;
	woo_order_url?: string | null;
	member_id: number;
	member?: { id: number; public_id: string; name: string; email: string } | null;
	plan_id: number | null;
	plan_name: string;
	subscription_id: number | null;
	organisation_id: number | null;
	type: OrderType;
	type_label: string;
	currency: Currency;
	amount_minor: number;
	amount_display: string;
	status: OrderStatus;
	description: string;
	gateway: string | null;
	provider_ref: string | null;
	period_start: string | null;
	period_end: string | null;
	receipt_number: string | null;
	receipt_url: string | null;
	pay_link: string | null;
	pay_link_token: string | null;
	checkout_url: string | null;
	review_reason?: string | null;
	reviewed_at: string | null;
	paid_at: string | null;
	created_at: string;
	payments?: Payment[];
	hook_log?: { id: number; event: string; result: string | null; processed_at: string | null; created_at: string }[];
}

export interface Payment {
	id: number;
	gateway: string;
	provider_ref: string;
	amount_minor: number;
	amount_display: string;
	currency: Currency;
	status: 'pending' | 'confirmed' | 'failed' | 'refunded';
	confirmed_at: string | null;
	created_at: string;
}

export interface PaymentMethod { id: number; gateway: string; label: string; expires_at: string | null; is_default: boolean; created_at: string }

export interface Workshop {
	id: number;
	title: string;
	description: string;
	starts_at: string;
	ends_at: string;
	host: string;
	required_level: number;
	required_level_label: string;
	organisation_id: number | null;
	join_url?: string | null;
	recording_url: string | null;
	capacity: number | null;
	status: 'draft' | 'scheduled' | 'live' | 'ended' | 'cancelled';
	registration_count: number;
	created_at: string;
	registrations?: { member_id: number; public_id: string; name: string; email: string; created_at: string }[];
	/* Member view only */
	eligible?: boolean;
	registered?: boolean;
	can_join_now?: boolean;
	join_opens_at?: string;
	is_full?: boolean;
	calendar_url?: string;
}

export interface Consultation {
	id: number;
	member_id: number;
	member_public_id: string;
	member_name: string;
	member_email: string;
	topic: string;
	preferred_dates: string;
	notes: string;
	status: 'new' | 'scheduled' | 'done' | 'declined';
	scheduled_at: string | null;
	meeting_url: string | null;
	admin_notes?: string | null;
	created_at: string;
}

export interface Organisation {
	id: number;
	name: string;
	contact_member_id: number | null;
	contact_name: string | null;
	contact_email: string | null;
	seats: number;
	seats_used: number;
	seats_pending: number;
	seats_available: number;
	level: number;
	level_label: string;
	term_start: string | null;
	term_end: string | null;
	contract_ref: string;
	programme_title: string;
	programme_description: string;
	notes: string;
	status: 'active' | 'expired' | 'suspended';
	created_at: string;
	role?: 'admin' | 'member';
	content?: OrgContent[];
	seats_list?: Seat[];
	orders?: Order[];
}
export interface OrgContent { post_id: number; title: string; post_type: string; url: string; excerpt: string }
export interface Seat {
	id: number;
	member_id: number | null;
	public_id: string | null;
	name: string | null;
	email: string;
	role: 'admin' | 'member';
	status: 'invited' | 'joined' | 'revoked';
	invited_at: string | null;
	joined_at: string | null;
	revoked_at: string | null;
}

export interface Application {
	id: number;
	org_name: string;
	contact_name: string;
	email: string;
	phone: string | null;
	country: string | null;
	org_size: string | null;
	programme_type: string | null;
	participants: number | null;
	duration: string | null;
	start_preference: string | null;
	budget_range: string | null;
	message: string | null;
	status: 'new' | 'in_review' | 'quoted' | 'accepted' | 'declined';
	admin_notes: string | null;
	organisation_id: number | null;
	created_at: string;
	updated_at: string;
}

export interface InAppNotification { id: number; type: string; title: string; body: string; url: string | null; read: boolean; created_at: string }

export interface AuditRow {
	id: number;
	actor_type: 'system' | 'admin' | 'member';
	actor_id: number | null;
	actor_name: string;
	action: string;
	entity_type: string;
	entity_id: number | null;
	before: Record< string, unknown > | null;
	after: Record< string, unknown > | null;
	reason: string | null;
	ip: string | null;
	created_at: string;
}

export interface Entitlements {
	level: number;
	plan_slug: string | null;
	plan_name: string | null;
	plan_slugs: string[];
	org_id: number | null;
	org_ids: number[];
	grants: string[];
	member_id: number | null;
}

export type DashboardState = 'none' | 'free' | 'pending' | 'active' | 'cancelling' | 'past_due' | 'suspended' | 'expired' | 'ended' | 'cancelled';

export interface MeOverview {
	member: Member;
	subscription: Subscription | null;
	/** Live enterprise-seat subscription (seat holders may have no personal plan). */
	org_subscription?: Subscription | null;
	state: DashboardState;
	entitlements: Entitlements;
	level_label: string;
	pending_order: Order | null;
	organisation: { id: number; name: string; role: 'admin' | 'member'; programme_title: string; term_end: string | null } | null;
	next_workshop: Workshop | null;
	unread: number;
	plans: PublicPlan[];
	currency: Currency;
	payment_methods: PaymentMethod[];
	impersonating: boolean;
	nonce: string;
}

export interface UpgradePreview {
	mode: 'new' | 'prorated';
	plan: Plan;
	currency: Currency;
	amount_minor: number;
	amount_display: string;
	new_price?: number;
	old_price?: number;
	days_remaining?: number;
	days_in_period?: number;
	is_upgrade: boolean;
	note: string;
}

export interface HealthCheck { key: string; label: string; status: 'ok' | 'warning' | 'error'; detail: string; action: string | null }
