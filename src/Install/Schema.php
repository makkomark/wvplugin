<?php
declare( strict_types=1 );

namespace WitsValley\Members\Install;

use WitsValley\Members\Support\Db;

/**
 * Custom table schema (dbDelta). See docs/SCHEMA.md for column notes.
 *
 * Reserved-word columns from the brief are renamed: plans.interval → interval_unit,
 * email_templates.key → template_key, settings.key → setting_key,
 * audit_log.before/after → before_data/after_data.
 */
final class Schema {

	/** @return list<string> Table names without prefix. */
	public static function tables(): array {
		return array(
			'plans',
			'plan_prices',
			'members',
			'member_notes',
			'subscriptions',
			'subscription_events',
			'orders',
			'payments',
			'payment_methods',
			'workshops',
			'workshop_registrations',
			'consultation_requests',
			'applications',
			'organisations',
			'organisation_members',
			'organisation_content',
			'email_templates',
			'notifications',
			'in_app_notifications',
			'kit_sync_log',
			'audit_log',
			'settings',
			'hook_inbox',
			'popup_stats',
		);
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		$t = static fn( string $n ): string => Db::table( $n );

		$sql = array();

		$sql[] = "CREATE TABLE {$t('plans')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(50) NOT NULL,
  name varchar(100) NOT NULL,
  tagline varchar(200) NULL,
  description text NULL,
  level tinyint(3) unsigned NOT NULL DEFAULT 0,
  interval_unit varchar(20) NOT NULL DEFAULT 'monthly',
  interval_count smallint(5) unsigned NOT NULL DEFAULT 1,
  trial_days smallint(5) unsigned NOT NULL DEFAULT 0,
  kit_tag varchar(100) NULL,
  visibility varchar(20) NOT NULL DEFAULT 'public',
  benefits longtext NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  woo_product_id bigint(20) unsigned NULL,
  is_free tinyint(1) NOT NULL DEFAULT 0,
  is_quoted tinyint(1) NOT NULL DEFAULT 0,
  highlight tinyint(1) NOT NULL DEFAULT 0,
  archived_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug)
) $c;";

		$sql[] = "CREATE TABLE {$t('plan_prices')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  plan_id bigint(20) unsigned NOT NULL,
  currency char(3) NOT NULL,
  amount_minor bigint(20) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY plan_currency (plan_id,currency)
) $c;";

		$sql[] = "CREATE TABLE {$t('members')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  public_id varchar(12) NULL,
  phone varchar(30) NULL,
  country char(2) NULL,
  timezone varchar(64) NOT NULL DEFAULT 'Africa/Kampala',
  marketing_consent tinyint(1) NOT NULL DEFAULT 0,
  marketing_consent_at datetime NULL,
  notification_prefs longtext NULL,
  kit_subscriber_id varchar(50) NULL,
  kit_last_synced_at datetime NULL,
  kit_sync_error text NULL,
  kit_unsubscribed_at datetime NULL,
  kit_unsubscribe_reason varchar(20) NULL,
  notes_count int(11) NOT NULL DEFAULT 0,
  anonymised_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_id (user_id),
  UNIQUE KEY public_id (public_id),
  KEY country (country)
) $c;";

		$sql[] = "CREATE TABLE {$t('member_notes')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  member_id bigint(20) unsigned NOT NULL,
  author_id bigint(20) unsigned NULL,
  body text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY member_id (member_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('subscriptions')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  member_id bigint(20) unsigned NOT NULL,
  plan_id bigint(20) unsigned NOT NULL,
  pending_plan_id bigint(20) unsigned NULL,
  organisation_id bigint(20) unsigned NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  currency char(3) NOT NULL DEFAULT 'UGX',
  amount_minor bigint(20) NOT NULL DEFAULT 0,
  started_at datetime NULL,
  current_period_start datetime NULL,
  current_period_end datetime NULL,
  grace_ends_at datetime NULL,
  suspended_at datetime NULL,
  expires_at datetime NULL,
  cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
  cancelled_at datetime NULL,
  cancel_reason varchar(100) NULL,
  previous_subscription_id bigint(20) unsigned NULL,
  payment_method_id bigint(20) unsigned NULL,
  autocharge_attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY member_status (member_id,status),
  KEY current_period_end (current_period_end),
  KEY status (status),
  KEY organisation_id (organisation_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('subscription_events')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  subscription_id bigint(20) unsigned NOT NULL,
  from_status varchar(20) NULL,
  to_status varchar(20) NOT NULL,
  reason varchar(255) NULL,
  actor_type varchar(10) NOT NULL DEFAULT 'system',
  actor_id bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY subscription_id (subscription_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('orders')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  woo_order_id bigint(20) unsigned NULL,
  member_id bigint(20) unsigned NOT NULL,
  plan_id bigint(20) unsigned NULL,
  subscription_id bigint(20) unsigned NULL,
  organisation_id bigint(20) unsigned NULL,
  type varchar(20) NOT NULL DEFAULT 'new',
  currency char(3) NOT NULL,
  amount_minor bigint(20) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'pending',
  description varchar(255) NULL,
  period_start datetime NULL,
  period_end datetime NULL,
  pay_link_token varchar(64) NULL,
  pay_link_used_at datetime NULL,
  receipt_number varchar(40) NULL,
  review_reason varchar(255) NULL,
  reviewed_by bigint(20) unsigned NULL,
  reviewed_at datetime NULL,
  paid_at datetime NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY woo_order_id (woo_order_id),
  UNIQUE KEY pay_link_token (pay_link_token),
  KEY member_id (member_id),
  KEY status (status),
  KEY subscription_id (subscription_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('payments')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  order_id bigint(20) unsigned NOT NULL,
  gateway varchar(50) NOT NULL,
  provider_ref varchar(191) NOT NULL,
  amount_minor bigint(20) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  raw_payload longtext NULL,
  confirmed_at datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY provider_ref (provider_ref),
  KEY order_id (order_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('payment_methods')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  member_id bigint(20) unsigned NOT NULL,
  gateway varchar(50) NOT NULL,
  token text NOT NULL,
  woo_token_id bigint(20) unsigned NULL,
  label varchar(60) NULL,
  expires_at date NULL,
  is_default tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY member_id (member_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('workshops')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(200) NOT NULL,
  description text NULL,
  starts_at datetime NOT NULL,
  ends_at datetime NOT NULL,
  host varchar(100) NULL,
  required_level tinyint(3) unsigned NOT NULL DEFAULT 1,
  organisation_id bigint(20) unsigned NULL,
  join_url text NULL,
  recording_url text NULL,
  capacity int(11) NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY starts_at (starts_at),
  KEY status (status)
) $c;";

		$sql[] = "CREATE TABLE {$t('workshop_registrations')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  workshop_id bigint(20) unsigned NOT NULL,
  member_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY workshop_member (workshop_id,member_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('consultation_requests')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  member_id bigint(20) unsigned NOT NULL,
  topic varchar(200) NOT NULL,
  preferred_dates text NULL,
  notes text NULL,
  status varchar(20) NOT NULL DEFAULT 'new',
  scheduled_at datetime NULL,
  meeting_url text NULL,
  admin_notes text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY member_id (member_id),
  KEY status (status)
) $c;";

		$sql[] = "CREATE TABLE {$t('applications')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  org_name varchar(200) NOT NULL,
  contact_name varchar(120) NOT NULL,
  email varchar(190) NOT NULL,
  phone varchar(30) NULL,
  country char(2) NULL,
  org_size varchar(30) NULL,
  programme_type varchar(100) NULL,
  participants int(11) NULL,
  duration varchar(60) NULL,
  start_preference varchar(60) NULL,
  budget_range varchar(60) NULL,
  message text NULL,
  status varchar(20) NOT NULL DEFAULT 'new',
  admin_notes text NULL,
  organisation_id bigint(20) unsigned NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $c;";

		$sql[] = "CREATE TABLE {$t('organisations')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(200) NOT NULL,
  contact_member_id bigint(20) unsigned NULL,
  seats int(11) NOT NULL DEFAULT 1,
  level tinyint(3) unsigned NOT NULL DEFAULT 3,
  term_start date NULL,
  term_end date NULL,
  contract_ref varchar(100) NULL,
  programme_title varchar(200) NULL,
  programme_description text NULL,
  notes text NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY status (status)
) $c;";

		$sql[] = "CREATE TABLE {$t('organisation_members')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  organisation_id bigint(20) unsigned NOT NULL,
  member_id bigint(20) unsigned NULL,
  subscription_id bigint(20) unsigned NULL,
  email varchar(190) NOT NULL,
  role varchar(10) NOT NULL DEFAULT 'member',
  invite_token varchar(64) NULL,
  invited_at datetime NOT NULL,
  joined_at datetime NULL,
  revoked_at datetime NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY org_email (organisation_id,email),
  KEY invite_token (invite_token),
  KEY member_id (member_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('organisation_content')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  organisation_id bigint(20) unsigned NOT NULL,
  post_id bigint(20) unsigned NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY org_post (organisation_id,post_id)
) $c;";

		$sql[] = "CREATE TABLE {$t('email_templates')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  template_key varchar(60) NOT NULL,
  name varchar(100) NOT NULL,
  subject varchar(200) NOT NULL,
  preview varchar(200) NULL,
  body_html mediumtext NULL,
  body_text text NULL,
  enabled tinyint(1) NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY template_key (template_key)
) $c;";

		$sql[] = "CREATE TABLE {$t('notifications')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  member_id bigint(20) unsigned NULL,
  to_email varchar(190) NOT NULL,
  template_key varchar(60) NOT NULL,
  subject varchar(200) NULL,
  payload longtext NULL,
  status varchar(10) NOT NULL DEFAULT 'queued',
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  error text NULL,
  sent_at datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY member_id (member_id),
  KEY status (status)
) $c;";

		$sql[] = "CREATE TABLE {$t('in_app_notifications')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  member_id bigint(20) unsigned NOT NULL,
  type varchar(50) NOT NULL,
  title varchar(200) NOT NULL,
  body text NULL,
  url text NULL,
  read_at datetime NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY member_read (member_id,read_at)
) $c;";

		$sql[] = "CREATE TABLE {$t('kit_sync_log')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  member_id bigint(20) unsigned NULL,
  action varchar(20) NOT NULL,
  tag varchar(100) NULL,
  status varchar(10) NOT NULL,
  response text NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY member_id (member_id),
  KEY status_created (status,created_at)
) $c;";

		$sql[] = "CREATE TABLE {$t('audit_log')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  actor_type varchar(10) NOT NULL DEFAULT 'system',
  actor_id bigint(20) unsigned NULL,
  action varchar(80) NOT NULL,
  entity_type varchar(50) NOT NULL,
  entity_id bigint(20) unsigned NULL,
  before_data longtext NULL,
  after_data longtext NULL,
  reason varchar(255) NULL,
  ip varchar(45) NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY entity (entity_type,entity_id),
  KEY created_at (created_at)
) $c;";

		$sql[] = "CREATE TABLE {$t('settings')} (
  setting_key varchar(100) NOT NULL,
  value longtext NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (setting_key)
) $c;";

		$sql[] = "CREATE TABLE {$t('popup_stats')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  day date NOT NULL,
  variant varchar(10) NOT NULL,
  event varchar(20) NOT NULL,
  count int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY day_variant_event (day,variant,event)
) $c;";

		$sql[] = "CREATE TABLE {$t('hook_inbox')} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  woo_order_id bigint(20) unsigned NOT NULL,
  event varchar(60) NOT NULL,
  payload longtext NULL,
  processed_at datetime NULL,
  result varchar(50) NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY woo_order_id (woo_order_id),
  KEY created_at (created_at)
) $c;";

		dbDelta( $sql );
	}

	public static function drop(): void {
		global $wpdb;
		foreach ( self::tables() as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . Db::table( $table ) ); // phpcs:ignore WordPress.DB
		}
	}
}
