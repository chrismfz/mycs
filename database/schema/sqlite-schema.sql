CREATE TABLE IF NOT EXISTS "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE IF NOT EXISTS "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE IF NOT EXISTS "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE IF NOT EXISTS "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" text not null,
  "queue" text not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE IF NOT EXISTS "roles"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "permissions" text,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "roles_name_unique" on "roles"("name");
CREATE TABLE IF NOT EXISTS "users"(
  "id" integer primary key autoincrement not null,
  "first_name" varchar,
  "last_name" varchar,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar not null,
  "tfa_secret" text,
  "created_at" datetime,
  "updated_at" datetime,
  "tfa_recovery_codes" text,
  "language" varchar
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE TABLE IF NOT EXISTS "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE IF NOT EXISTS "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE IF NOT EXISTS "settings"(
  "id" integer primary key autoincrement not null,
  "key" varchar not null,
  "value" text,
  "type" varchar not null default 'string',
  "encrypted" tinyint(1) not null default '0',
  "settingable_type" varchar,
  "settingable_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "scope" varchar as(COALESCE(settingable_type, '') || '#' || COALESCE(settingable_id, 0))
);
CREATE INDEX "settings_settingable_type_settingable_id_index" on "settings"(
  "settingable_type",
  "settingable_id"
);
CREATE UNIQUE INDEX "settings_key_settingable_id_settingable_type_unique" on "settings"(
  "key",
  "settingable_id",
  "settingable_type"
);
CREATE TABLE IF NOT EXISTS "extensions"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "extension" varchar not null,
  "type" varchar not null,
  "enabled" tinyint(1) not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  "deleted_at" datetime,
  "max_accounts" integer,
  "whmcs_id" integer
);
CREATE TABLE IF NOT EXISTS "categories"(
  "id" integer primary key autoincrement not null,
  "slug" varchar not null,
  "name" varchar not null,
  "description" text,
  "image" varchar,
  "parent_id" integer,
  "full_slug" text,
  "sort" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "whmcs_id" integer,
  "show_in_menu" tinyint(1) not null default '1',
  foreign key("parent_id") references "categories"("id")
);
CREATE UNIQUE INDEX "categories_slug_unique" on "categories"("slug");
CREATE TABLE IF NOT EXISTS "plans"(
  "id" integer primary key autoincrement not null,
  "name" varchar,
  "priceable_type" varchar not null,
  "priceable_id" integer not null,
  "type" varchar check("type" in('free', 'one-time', 'recurring')) not null,
  "billing_period" integer,
  "billing_unit" varchar check("billing_unit" in('hour', 'day', 'week', 'month', 'year')),
  "sort" integer
);
CREATE INDEX "plans_priceable_type_priceable_id_index" on "plans"(
  "priceable_type",
  "priceable_id"
);
CREATE TABLE IF NOT EXISTS "prices"(
  "id" integer primary key autoincrement not null,
  "price" numeric,
  "setup_fee" numeric,
  "currency_code" varchar not null,
  "plan_id" integer
);
CREATE TABLE IF NOT EXISTS "audit_logs"(
  "id" integer primary key autoincrement not null,
  "action" varchar not null,
  "description" varchar,
  "model_type" varchar not null,
  "model_id" integer not null,
  "user_id" integer,
  "changes" text,
  "ip_address" varchar,
  "user_agent" varchar,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "audit_logs_model_type_model_id_index" on "audit_logs"(
  "model_type",
  "model_id"
);
CREATE TABLE IF NOT EXISTS "config_options"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "env_variable" varchar,
  "type" varchar,
  "sort" integer,
  "hidden" tinyint(1) not null default('0'),
  "created_at" datetime,
  "updated_at" datetime,
  "parent_id" integer,
  "upgradable" tinyint(1) not null default '0',
  "description" text,
  "whmcs_ref" varchar,
  "staff_task" tinyint(1) not null default '0',
  foreign key("parent_id") references "config_options"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "config_option_products"(
  "id" integer primary key autoincrement not null,
  "config_option_id" integer not null,
  "product_id" integer not null,
  foreign key("config_option_id") references "config_options"("id") on delete cascade,
  foreign key("product_id") references "products"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "tax_rates"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "rate" numeric not null,
  "country" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "tax_rates_country_unique" on "tax_rates"("country");
CREATE TABLE IF NOT EXISTS "coupons"(
  "id" integer primary key autoincrement not null,
  "type" varchar not null,
  "recurring" integer,
  "code" varchar not null,
  "value" numeric,
  "max_uses" integer,
  "starts_at" datetime,
  "expires_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "max_uses_per_user" integer,
  "applies_to" varchar not null default 'all',
  "customers" varchar not null default 'all',
  "once_per_client" tinyint(1) not null default '0',
  "requires_existing" tinyint(1) not null default '0',
  "billing_cycles" text
);
CREATE TABLE IF NOT EXISTS "coupon_products"(
  "id" integer primary key autoincrement not null,
  "coupon_id" integer not null,
  "product_id" integer not null,
  foreign key("coupon_id") references "coupons"("id") on delete cascade,
  foreign key("product_id") references "products"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "custom_properties"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "description" varchar,
  "key" varchar not null,
  "type" varchar not null,
  "model" varchar not null,
  "validation" varchar,
  "allowed_values" text,
  "non_editable" tinyint(1) not null,
  "required" tinyint(1) not null,
  "show_on_invoice" tinyint(1) not null
);
CREATE UNIQUE INDEX "custom_properties_key_unique" on "custom_properties"(
  "key"
);
CREATE TABLE IF NOT EXISTS "properties"(
  "id" integer primary key autoincrement not null,
  "custom_property_id" integer,
  "name" varchar,
  "key" varchar not null,
  "value" text not null,
  "model_type" varchar not null,
  "model_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("custom_property_id") references "custom_properties"("id") on delete cascade
);
CREATE UNIQUE INDEX "properties_key_model_id_model_type_unique" on "properties"(
  "key",
  "model_id",
  "model_type"
);
CREATE INDEX "properties_model_type_model_id_index" on "properties"(
  "model_type",
  "model_id"
);
CREATE TABLE IF NOT EXISTS "notification_templates"(
  "id" integer primary key autoincrement not null,
  "key" varchar not null,
  "subject" varchar not null,
  "enabled" tinyint(1) not null default '1',
  "body" text not null,
  "cc" text,
  "bcc" text,
  "created_at" datetime,
  "updated_at" datetime,
  "mail_enabled" varchar check("mail_enabled" in('force', 'choice_on', 'choice_off', 'never')) not null default 'choice_on',
  "in_app_enabled" varchar check("in_app_enabled" in('force', 'choice_on', 'choice_off', 'never')) not null default 'choice_on',
  "in_app_title" varchar,
  "in_app_body" text,
  "in_app_url" varchar,
  "edit_preference_message" varchar,
  "translations" text
);
CREATE UNIQUE INDEX "email_templates_key_unique" on "notification_templates"(
  "key"
);
CREATE TABLE IF NOT EXISTS "service_cancellations"(
  "id" integer primary key autoincrement not null,
  "service_id" integer not null,
  "reason" varchar,
  "type" varchar check("type" in('immediate', 'end_of_period')) not null default 'immediate',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("service_id") references "services"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "product_upgrades"(
  "id" integer primary key autoincrement not null,
  "product_id" integer not null,
  "upgrade_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("product_id") references "products"("id") on delete cascade,
  foreign key("upgrade_id") references "products"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "debug_logs"(
  "id" integer primary key autoincrement not null,
  "type" varchar not null,
  "context" text not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE TABLE IF NOT EXISTS "ticket_message_attachments"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "filename" varchar not null,
  "path" varchar not null,
  "filesize" integer not null,
  "mime_type" varchar not null,
  "ticket_message_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("ticket_message_id") references "ticket_messages"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "audits"(
  "id" integer primary key autoincrement not null,
  "user_type" varchar,
  "user_id" integer,
  "event" varchar not null,
  "auditable_type" varchar not null,
  "auditable_id" integer not null,
  "old_values" text,
  "new_values" text,
  "url" text,
  "ip_address" varchar,
  "user_agent" varchar,
  "tags" varchar,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "audits_auditable_type_auditable_id_index" on "audits"(
  "auditable_type",
  "auditable_id"
);
CREATE INDEX "audits_user_id_user_type_index" on "audits"(
  "user_id",
  "user_type"
);
CREATE TABLE IF NOT EXISTS "currencies"(
  "code" varchar not null,
  "prefix" varchar,
  "suffix" varchar,
  "format" varchar not null default('1.000,00'),
  "name" varchar not null,
  primary key("code")
);
CREATE TABLE IF NOT EXISTS "invoice_snapshots"(
  "id" integer primary key autoincrement not null,
  "name" varchar,
  "properties" text,
  "tax_name" varchar,
  "tax_rate" numeric,
  "tax_country" varchar,
  "bill_to" text,
  "invoice_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("invoice_id") references "invoices"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "cron_stats"(
  "id" integer primary key autoincrement not null,
  "key" varchar not null,
  "value" integer not null default '0',
  "date" date not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "cron_stats_date_index" on "cron_stats"("date");
CREATE TABLE IF NOT EXISTS "cart_items"(
  "id" integer primary key autoincrement not null,
  "cart_id" integer not null,
  "product_id" integer,
  "plan_id" integer,
  "config_options" text,
  "checkout_config" text,
  "quantity" integer not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  "domain" text,
  "epp_code" text,
  foreign key("cart_id") references "carts"("id") on delete cascade,
  foreign key("product_id") references "products"("id") on delete cascade,
  foreign key("plan_id") references "plans"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "notification_preferences"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "notification_template_id" integer not null,
  "mail_enabled" tinyint(1) not null default '1',
  "in_app_enabled" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("notification_template_id") references "notification_templates"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "notification_subscriptions"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "endpoint" varchar not null,
  "p256dh_key" text not null,
  "auth_key" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "notification_subscriptions_endpoint_unique" on "notification_subscriptions"(
  "endpoint"
);
CREATE TABLE IF NOT EXISTS "notifications"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "title" varchar not null,
  "body" text not null,
  "url" varchar,
  "read_at" datetime,
  "show_in_app" tinyint(1) not null default '1',
  "show_as_push" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "user_authentication_logs"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "ip_address" varchar not null,
  "last_used_at" datetime not null default CURRENT_TIMESTAMP,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "user_authentication_logs_user_id_ip_address_index" on "user_authentication_logs"(
  "user_id",
  "ip_address"
);
CREATE TABLE IF NOT EXISTS "user_sessions"(
  "id" integer primary key autoincrement not null,
  "ulid" varchar not null,
  "user_id" integer not null,
  "ip_address" varchar,
  "user_agent" varchar,
  "last_activity" datetime not null,
  "expires_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "user_sessions_ulid_unique" on "user_sessions"("ulid");
CREATE TABLE IF NOT EXISTS "adjustment_notes"(
  "id" integer primary key autoincrement not null,
  "invoice_id" integer not null,
  "type" varchar not null,
  "number" varchar,
  "amount" numeric not null,
  "description" text,
  "status" varchar not null default 'active',
  "created_at" datetime,
  "updated_at" datetime,
  "is_cancellation" tinyint(1) not null default '0',
  "is_refund" tinyint(1) not null default '0',
  foreign key("invoice_id") references "invoices"("id") on delete cascade
);
CREATE UNIQUE INDEX "adjustment_notes_number_unique" on "adjustment_notes"(
  "number"
);
CREATE TABLE IF NOT EXISTS "invoice_transactions"(
  "id" integer primary key autoincrement not null,
  "invoice_id" integer not null,
  "gateway_id" integer,
  "amount" numeric not null,
  "fee" numeric,
  "transaction_id" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "status" varchar not null default('succeeded'),
  "is_credit_transaction" tinyint(1) not null default('0'),
  "refunded_amount" numeric not null default('0'),
  "credited_amount" numeric not null default('0'),
  "source_transaction_id" integer,
  foreign key("gateway_id") references "extensions"("id") on delete set null on update no action,
  foreign key("invoice_id") references "invoices"("id") on delete cascade on update no action,
  foreign key("source_transaction_id") references "invoice_transactions"("id") on delete set null
);
CREATE INDEX "invoice_transactions_transaction_gateway_index" on "invoice_transactions"(
  "transaction_id",
  "gateway_id"
);
CREATE TABLE IF NOT EXISTS "money_event_notes"(
  "id" integer primary key autoincrement not null,
  "money_event_id" integer not null,
  "note" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("money_event_id") references "money_events"("id") on delete restrict
);
CREATE UNIQUE INDEX "money_event_notes_money_event_id_unique" on "money_event_notes"(
  "money_event_id"
);
CREATE TABLE IF NOT EXISTS "payment_gateway_events"(
  "id" integer primary key autoincrement not null,
  "gateway" varchar not null,
  "endpoint" varchar not null,
  "method" varchar not null,
  "event_type" varchar,
  "fields" text,
  "signature_verified" tinyint(1),
  "outcome" varchar not null default 'received',
  "reason" varchar,
  "amount" numeric,
  "currency_code" varchar,
  "external_id" varchar,
  "invoice_id" integer,
  "invoice_transaction_id" integer,
  "http_status" integer,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "payment_gateway_events_gateway_index" on "payment_gateway_events"(
  "gateway"
);
CREATE INDEX "payment_gateway_events_outcome_index" on "payment_gateway_events"(
  "outcome"
);
CREATE INDEX "payment_gateway_events_external_id_index" on "payment_gateway_events"(
  "external_id"
);
CREATE INDEX "payment_gateway_events_invoice_id_index" on "payment_gateway_events"(
  "invoice_id"
);
CREATE INDEX "payment_gateway_events_invoice_transaction_id_index" on "payment_gateway_events"(
  "invoice_transaction_id"
);
CREATE TABLE IF NOT EXISTS "service_upgrades"(
  "id" integer primary key autoincrement not null,
  "service_id" integer not null,
  "plan_id" integer not null,
  "product_id" integer not null,
  "invoice_id" integer,
  "status" varchar not null default('pending'),
  "type" varchar not null default('product'),
  "created_at" datetime,
  "updated_at" datetime,
  "credit_cents" integer,
  "credit_until" date,
  foreign key("service_id") references "services"("id") on delete cascade on update no action,
  foreign key("invoice_id") references "invoices"("id") on delete cascade on update no action,
  foreign key("plan_id") references "plans"("id") on delete restrict,
  foreign key("product_id") references "products"("id") on delete restrict
);
CREATE UNIQUE INDEX "coupons_code_unique" on "coupons"("code");
CREATE UNIQUE INDEX "service_cancellations_service_id_unique" on "service_cancellations"(
  "service_id"
);
CREATE UNIQUE INDEX "invoice_snapshots_invoice_id_unique" on "invoice_snapshots"(
  "invoice_id"
);
CREATE UNIQUE INDEX "settings_key_scope_unique" on "settings"("key", "scope");
CREATE TABLE IF NOT EXISTS "invoice_items"(
  "id" integer primary key autoincrement not null,
  "invoice_id" integer not null,
  "price" numeric not null,
  "quantity" integer not null default('1'),
  "description" text,
  "reference_type" varchar,
  "reference_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "period_start" date,
  "period_end" date,
  "applied_at" datetime,
  "taxed" tinyint(1) not null default '1',
  "tax_rate" numeric not null default '0',
  "tax_amount" numeric not null default '0',
  "line_total" numeric not null default '0',
  "forgone_at" datetime,
  "tax_reason" varchar,
  "kind" varchar,
  foreign key("invoice_id") references "invoices"("id") on delete cascade on update no action
);
CREATE INDEX "invoice_items_reference_period_index" on "invoice_items"(
  "reference_type",
  "reference_id",
  "period_start"
);
CREATE TABLE IF NOT EXISTS "service_configs"(
  "id" integer primary key autoincrement not null,
  "configurable_type" varchar not null,
  "configurable_id" integer not null,
  "config_option_id" integer not null,
  "config_value_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("config_option_id") references "config_options"("id") on delete cascade on update no action,
  foreign key("config_value_id") references "config_options"("id") on delete restrict
);
CREATE INDEX "service_configs_configurable_type_configurable_id_index" on "service_configs"(
  "configurable_type",
  "configurable_id"
);
CREATE TABLE IF NOT EXISTS "gateway_refunds"(
  "id" integer primary key autoincrement not null,
  "invoice_transaction_id" integer,
  "gateway" varchar not null,
  "reference" varchar not null,
  "payment_reference" varchar,
  "amount" numeric not null,
  "currency_code" varchar not null,
  "recorded_amount" numeric not null,
  "origin" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "money_event_id" integer,
  foreign key("invoice_transaction_id") references "invoice_transactions"("id") on delete restrict on update no action,
  foreign key("money_event_id") references "money_events"("id") on delete restrict
);
CREATE INDEX "gateway_refunds_gateway_payment_reference_index" on "gateway_refunds"(
  "gateway",
  "payment_reference"
);
CREATE UNIQUE INDEX "gateway_refunds_gateway_reference_unique" on "gateway_refunds"(
  "gateway",
  "reference"
);
CREATE TABLE IF NOT EXISTS "staff"(
  "id" integer primary key autoincrement not null,
  "first_name" varchar not null,
  "last_name" varchar not null,
  "email" varchar not null,
  "password" varchar not null,
  "role_id" integer,
  "active" tinyint(1) not null default '1',
  "app_authentication_secret" text,
  "app_authentication_recovery_codes" text,
  "remember_token" varchar,
  "last_login_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "presence_status" varchar not null default 'available',
  "last_seen_at" datetime,
  foreign key("role_id") references "roles"("id") on delete set null
);
CREATE UNIQUE INDEX "staff_email_unique" on "staff"("email");
CREATE TABLE IF NOT EXISTS "staff_password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE IF NOT EXISTS "api_keys"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "permissions" text,
  "token" varchar not null,
  "type" varchar,
  "ip_addresses" text,
  "last_used_at" datetime,
  "enabled" tinyint(1) not null default('1'),
  "created_at" datetime,
  "updated_at" datetime,
  "staff_id" integer,
  foreign key("staff_id") references "staff"("id") on delete cascade on update no action
);
CREATE UNIQUE INDEX "api_keys_token_unique" on "api_keys"("token");
CREATE TABLE IF NOT EXISTS "invoice_reminders"(
  "id" integer primary key autoincrement not null,
  "invoice_id" integer not null,
  "kind" varchar not null,
  "days" integer not null default '0',
  "due_on" date not null,
  "sent_at" datetime not null,
  foreign key("invoice_id") references "invoices"("id") on delete cascade
);
CREATE UNIQUE INDEX "invoice_reminders_invoice_id_kind_days_due_on_unique" on "invoice_reminders"(
  "invoice_id",
  "kind",
  "days",
  "due_on"
);
CREATE TABLE IF NOT EXISTS "clients"(
  "id" integer primary key autoincrement not null,
  "credit_auto_apply" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime,
  "tax_exempt" tinyint(1) not null default '0',
  "separate_invoices" tinyint(1) not null default '0',
  "default_billing_agreement_id" integer,
  "auto_charge" tinyint(1) not null default '1',
  "client_group_id" integer,
  "language" varchar,
  "ignore_invoicing" tinyint(1) not null default '0',
  "immediate_invoicing" tinyint(1) not null default '0',
  "dunning_exempt" tinyint(1) not null default '0',
  "no_overdue_reminders" tinyint(1) not null default '0'
);
CREATE TABLE IF NOT EXISTS "client_user"(
  "id" integer primary key autoincrement not null,
  "client_id" integer not null,
  "user_id" integer not null,
  "owner" tinyint(1) not null default '0',
  "owner_of_client_id" integer,
  "permissions" text,
  "granted_by" integer,
  "granted_at" datetime,
  "revoked_at" datetime,
  "last_login_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("client_id") references "clients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("granted_by") references "staff"("id") on delete set null
);
CREATE UNIQUE INDEX "client_user_client_id_user_id_unique" on "client_user"(
  "client_id",
  "user_id"
);
CREATE UNIQUE INDEX "client_user_owner_of_client_id_unique" on "client_user"(
  "owner_of_client_id"
);
CREATE TABLE IF NOT EXISTS "credits"(
  "id" integer primary key autoincrement not null,
  "client_id" integer not null,
  "currency_code" varchar not null,
  "amount" numeric not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE UNIQUE INDEX "credits_user_id_currency_code_unique" on "credits"(
  "client_id",
  "currency_code"
);
CREATE TABLE IF NOT EXISTS "money_events"(
  "id" integer primary key autoincrement not null,
  "kind" varchar not null,
  "amount" numeric not null,
  "currency_code" varchar not null,
  "client_id" integer,
  "invoice_id" integer,
  "invoice_transaction_id" integer,
  "credit_balance_after" numeric,
  "source" varchar not null,
  "cause" varchar,
  "external_reference" varchar,
  "group_reference" varchar,
  "actor_type" varchar,
  "actor_id" integer,
  "reason" text,
  "reverses_id" integer,
  "occurred_at" datetime not null default(CURRENT_TIMESTAMP),
  foreign key("reverses_id") references "money_events"("id") on delete restrict on update no action,
  foreign key("client_id") references "clients"("id") on delete restrict
);
CREATE INDEX "money_events_actor_type_actor_id_index" on "money_events"(
  "actor_type",
  "actor_id"
);
CREATE INDEX "money_events_group_reference_index" on "money_events"(
  "group_reference"
);
CREATE INDEX "money_events_invoice_id_index" on "money_events"("invoice_id");
CREATE INDEX "money_events_invoice_transaction_id_index" on "money_events"(
  "invoice_transaction_id"
);
CREATE UNIQUE INDEX "money_events_reverses_id_unique" on "money_events"(
  "reverses_id"
);
CREATE INDEX "money_events_user_id_currency_code_id_index" on "money_events"(
  "client_id",
  "currency_code",
  "id"
);
CREATE TABLE IF NOT EXISTS "carts"(
  "id" integer primary key autoincrement not null,
  "ulid" varchar not null,
  "client_id" integer,
  "coupon_id" integer,
  "currency_code" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("coupon_id") references "coupons"("id") on delete set null on update no action,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE UNIQUE INDEX "carts_ulid_unique" on "carts"("ulid");
CREATE TABLE IF NOT EXISTS "billing_agreements"(
  "id" integer primary key autoincrement not null,
  "ulid" varchar not null,
  "client_id" integer not null,
  "gateway_id" integer not null,
  "name" varchar not null,
  "type" varchar,
  "expiry" date,
  "external_reference" varchar not null,
  "deleted_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "failed_count" integer not null default '0',
  "last_failed_at" datetime,
  "expiry_notified_at" datetime,
  foreign key("gateway_id") references "extensions"("id") on delete cascade on update no action,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE UNIQUE INDEX "billing_agreements_external_reference_unique" on "billing_agreements"(
  "external_reference"
);
CREATE UNIQUE INDEX "billing_agreements_ulid_unique" on "billing_agreements"(
  "ulid"
);
CREATE TABLE IF NOT EXISTS "client_invites"(
  "id" integer primary key autoincrement not null,
  "client_id" integer not null,
  "email" varchar not null,
  "token_hash" varchar not null,
  "permissions" text,
  "invited_by_user_id" integer,
  "invited_by_staff_id" integer,
  "expires_at" datetime not null,
  "accepted_at" datetime,
  "accepted_by_user_id" integer,
  "revoked_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("client_id") references "clients"("id") on delete cascade,
  foreign key("invited_by_user_id") references "users"("id") on delete set null,
  foreign key("invited_by_staff_id") references "staff"("id") on delete set null,
  foreign key("accepted_by_user_id") references "users"("id") on delete set null
);
CREATE INDEX "client_invites_client_id_email_index" on "client_invites"(
  "client_id",
  "email"
);
CREATE UNIQUE INDEX "client_invites_token_hash_unique" on "client_invites"(
  "token_hash"
);
CREATE TABLE IF NOT EXISTS "contacts"(
  "id" integer primary key autoincrement not null,
  "client_id" integer not null,
  "primary_of_client_id" integer,
  "type" varchar not null default 'individual',
  "first_name" varchar,
  "last_name" varchar,
  "company_name" varchar,
  "tax_id" varchar,
  "vat_number" varchar,
  "tax_office" varchar,
  "activity" varchar,
  "address1" varchar,
  "address2" varchar,
  "city" varchar,
  "state" varchar,
  "postcode" varchar,
  "country" varchar,
  "email" varchar,
  "phone" varchar,
  "notes" text,
  "document_type" varchar,
  "email_preferences" text,
  "archived_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "vat_status" varchar,
  "vat_checked_number" varchar,
  "vat_checked_at" datetime,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE INDEX "contacts_client_id_archived_at_index" on "contacts"(
  "client_id",
  "archived_at"
);
CREATE UNIQUE INDEX "contacts_primary_of_client_id_unique" on "contacts"(
  "primary_of_client_id"
);
CREATE TABLE IF NOT EXISTS "licenses"(
  "id" integer primary key autoincrement not null,
  "service_id" integer not null,
  "license_key" varchar not null,
  "status" varchar not null,
  "valid_domain" text,
  "valid_ip" text,
  "valid_directory" text,
  "reissues" integer not null default '0',
  "last_access" datetime,
  "whmcs_id" integer,
  "last_ok_at" datetime,
  "last_seen_ip" varchar,
  "last_seen_client" varchar,
  "last_failure_at" datetime,
  "last_failure" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("service_id") references "services"("id") on delete cascade
);
CREATE UNIQUE INDEX "licenses_service_id_unique" on "licenses"("service_id");
CREATE UNIQUE INDEX "licenses_license_key_unique" on "licenses"("license_key");
CREATE UNIQUE INDEX "licenses_whmcs_id_unique" on "licenses"("whmcs_id");
CREATE INDEX "licenses_last_ok_at_index" on "licenses"("last_ok_at");
CREATE TABLE IF NOT EXISTS "license_check_days"(
  "id" integer primary key autoincrement not null,
  "license_id" integer not null,
  "day" date not null,
  "ok" integer not null default '0',
  "failed" integer not null default '0',
  foreign key("license_id") references "licenses"("id") on delete cascade
);
CREATE UNIQUE INDEX "license_check_days_license_id_day_unique" on "license_check_days"(
  "license_id",
  "day"
);
CREATE TABLE IF NOT EXISTS "license_access_logs"(
  "id" integer primary key autoincrement not null,
  "license_id" integer,
  "domain" varchar not null default '',
  "ip" varchar not null default '',
  "directory" varchar not null default '',
  "message" varchar not null,
  "created_at" datetime not null,
  "whmcs_id" integer,
  foreign key("license_id") references "licenses"("id") on delete set null
);
CREATE INDEX "license_access_logs_created_at_index" on "license_access_logs"(
  "created_at"
);
CREATE TABLE IF NOT EXISTS "server_groups"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "fill_type" varchar not null default 'least_full',
  "created_at" datetime,
  "updated_at" datetime,
  "whmcs_id" integer
);
CREATE TABLE IF NOT EXISTS "server_group_server"(
  "id" integer primary key autoincrement not null,
  "server_group_id" integer not null,
  "server_id" integer not null,
  "position" integer not null default '0',
  foreign key("server_group_id") references "server_groups"("id") on delete cascade,
  foreign key("server_id") references "extensions"("id") on delete cascade
);
CREATE UNIQUE INDEX "server_group_server_server_group_id_server_id_unique" on "server_group_server"(
  "server_group_id",
  "server_id"
);
CREATE TABLE IF NOT EXISTS "products"(
  "id" integer primary key autoincrement not null,
  "category_id" integer not null,
  "name" varchar not null,
  "image" varchar,
  "slug" varchar,
  "description" text,
  "stock" integer,
  "per_user_limit" integer,
  "sort" integer,
  "allow_quantity" varchar not null default('disabled'),
  "server_id" integer,
  "email_template" text,
  "created_at" datetime,
  "updated_at" datetime,
  "hidden" tinyint(1) not null default('0'),
  "taxable" tinyint(1) not null default('1'),
  "server_group_id" integer,
  "autosetup" varchar not null default 'payment',
  "prorata_day" integer,
  "prorata_next_after_day" integer,
  "whmcs_id" integer,
  "retired" tinyint(1) not null default '0',
  foreign key("category_id") references "categories"("id") on delete no action on update no action,
  foreign key("server_group_id") references "server_groups"("id") on delete set null
);
CREATE TABLE IF NOT EXISTS "services"(
  "id" integer primary key autoincrement not null,
  "status" varchar not null default('pending'),
  "order_id" integer,
  "product_id" integer,
  "client_id" integer not null,
  "currency_code" varchar not null,
  "quantity" integer not null default('1'),
  "price" numeric not null,
  "plan_id" integer,
  "coupon_id" integer,
  "expires_at" datetime,
  "subscription_id" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "billing_agreement_id" integer,
  "label" varchar,
  "suspend_hold_until" date,
  "billing_day" integer,
  "coupon_started_at" date,
  "coupon_expired_at" datetime,
  "dunning_suspended_at" datetime,
  "domain" varchar,
  "username" varchar,
  "password" text,
  "dedicated_ip" varchar,
  "assigned_ips" text,
  "notes" text,
  "suspend_reason" varchar,
  "server_id" integer,
  "next_plan_id" integer,
  "contact_id" integer,
  "activated_at" datetime,
  "started_unpaid_at" datetime,
  "group_percent" numeric not null default '0',
  "price_locked" tinyint(1) not null default '0',
  foreign key("next_plan_id") references "plans"("id") on delete set null on update no action,
  foreign key("client_id") references "clients"("id") on delete cascade on update no action,
  foreign key("order_id") references "orders"("id") on delete restrict on update no action,
  foreign key("product_id") references "products"("id") on delete restrict on update no action,
  foreign key("plan_id") references "plans"("id") on delete restrict on update no action,
  foreign key("coupon_id") references "coupons"("id") on delete set null on update no action,
  foreign key("billing_agreement_id") references "billing_agreements"("id") on delete set null on update no action,
  foreign key("server_id") references "extensions"("id") on delete restrict on update no action,
  foreign key("contact_id") references "contacts"("id") on delete set null
);
CREATE INDEX "services_dedicated_ip_index" on "services"("dedicated_ip");
CREATE INDEX "services_domain_index" on "services"("domain");
CREATE TABLE IF NOT EXISTS "orders"(
  "id" integer primary key autoincrement not null,
  "client_id" integer not null,
  "currency_code" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "notes" text,
  "status" varchar not null default 'pending',
  "accepted_at" datetime,
  "accepted_by_staff_id" integer,
  "invoice_id" integer,
  "ip_address" varchar,
  foreign key("client_id") references "clients"("id") on delete cascade on update no action,
  foreign key("accepted_by_staff_id") references "staff"("id") on delete set null
);
CREATE INDEX "orders_status_index" on "orders"("status");
CREATE INDEX "orders_invoice_id_index" on "orders"("invoice_id");
CREATE UNIQUE INDEX "license_access_logs_whmcs_id_unique" on "license_access_logs"(
  "whmcs_id"
);
CREATE TABLE IF NOT EXISTS "email_logs"(
  "id" integer primary key autoincrement not null,
  "client_id" integer,
  "subject" varchar not null,
  "to" varchar not null,
  "body" text not null,
  "sent_at" datetime,
  "status" varchar not null default('pending'),
  "error" text,
  "job_uuid" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "from_address" varchar,
  "cc" text,
  "bcc" text,
  "template_key" varchar,
  "related_type" varchar,
  "related_id" integer,
  "trigger" varchar not null default 'auto',
  "staff_id" integer,
  "resent_from_id" integer,
  "attempts" integer not null default '0',
  "sending_at" datetime,
  "failed_at" datetime,
  "message_id" varchar,
  foreign key("client_id") references "clients"("id") on delete set null on update no action,
  foreign key("staff_id") references "staff"("id") on delete set null
);
CREATE INDEX "email_logs_related_type_related_id_index" on "email_logs"(
  "related_type",
  "related_id"
);
CREATE INDEX "email_logs_status_created_at_index" on "email_logs"(
  "status",
  "created_at"
);
CREATE INDEX "email_logs_template_key_index" on "email_logs"("template_key");
CREATE INDEX "email_logs_resent_from_id_index" on "email_logs"(
  "resent_from_id"
);
CREATE TABLE IF NOT EXISTS "mcp_calls"(
  "id" integer primary key autoincrement not null,
  "staff_id" integer,
  "api_key_id" integer,
  "tool" varchar not null,
  "arguments" text,
  "outcome" varchar not null,
  "error" varchar,
  "duration_ms" integer not null default '0',
  "ip" varchar,
  "created_at" datetime not null,
  foreign key("staff_id") references "staff"("id") on delete set null,
  foreign key("api_key_id") references "api_keys"("id") on delete set null
);
CREATE INDEX "mcp_calls_created_at_index" on "mcp_calls"("created_at");
CREATE TABLE IF NOT EXISTS "payment_groups"(
  "id" integer primary key autoincrement not null,
  "ulid" varchar not null,
  "client_id" integer not null,
  "user_id" integer,
  "currency_code" varchar not null,
  "invoice_id" integer,
  "total" numeric not null,
  "status" varchar not null default 'open',
  "invoice_transaction_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "whmcs_invoice_id" integer,
  foreign key("client_id") references "clients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete set null,
  foreign key("invoice_id") references "invoices"("id") on delete set null,
  foreign key("invoice_transaction_id") references "invoice_transactions"("id") on delete set null
);
CREATE INDEX "payment_groups_client_id_status_index" on "payment_groups"(
  "client_id",
  "status"
);
CREATE UNIQUE INDEX "payment_groups_ulid_unique" on "payment_groups"("ulid");
CREATE INDEX "payment_groups_status_index" on "payment_groups"("status");
CREATE TABLE IF NOT EXISTS "payment_group_invoice"(
  "id" integer primary key autoincrement not null,
  "payment_group_id" integer not null,
  "invoice_id" integer not null,
  "position" integer not null,
  "amount" numeric not null,
  foreign key("payment_group_id") references "payment_groups"("id") on delete cascade,
  foreign key("invoice_id") references "invoices"("id") on delete cascade
);
CREATE UNIQUE INDEX "payment_group_invoice_payment_group_id_invoice_id_unique" on "payment_group_invoice"(
  "payment_group_id",
  "invoice_id"
);
CREATE UNIQUE INDEX "payment_groups_whmcs_invoice_id_unique" on "payment_groups"(
  "whmcs_invoice_id"
);
CREATE TABLE IF NOT EXISTS "mcp_oauth_clients"(
  "id" integer primary key autoincrement not null,
  "client_id" varchar not null,
  "name" varchar not null,
  "redirect_uris" text not null,
  "registered_ip" varchar,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "mcp_oauth_clients_client_id_unique" on "mcp_oauth_clients"(
  "client_id"
);
CREATE TABLE IF NOT EXISTS "mcp_oauth_grants"(
  "id" integer primary key autoincrement not null,
  "client_id" integer not null,
  "staff_id" integer not null,
  "api_key_id" integer,
  "approved_by" varchar not null,
  "access_hash" varchar,
  "access_expires_at" datetime,
  "refresh_hash" varchar,
  "previous_refresh_hash" varchar,
  "refresh_expires_at" datetime,
  "last_used_at" datetime,
  "last_ip" varchar,
  "revoked_at" datetime,
  "revoked_reason" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "staff_session" varchar,
  foreign key("client_id") references "mcp_oauth_clients"("id") on delete cascade,
  foreign key("staff_id") references "staff"("id") on delete cascade,
  foreign key("api_key_id") references "api_keys"("id") on delete cascade
);
CREATE UNIQUE INDEX "mcp_oauth_grants_access_hash_unique" on "mcp_oauth_grants"(
  "access_hash"
);
CREATE UNIQUE INDEX "mcp_oauth_grants_refresh_hash_unique" on "mcp_oauth_grants"(
  "refresh_hash"
);
CREATE INDEX "mcp_oauth_grants_previous_refresh_hash_index" on "mcp_oauth_grants"(
  "previous_refresh_hash"
);
CREATE TABLE IF NOT EXISTS "mcp_oauth_used_refresh_tokens"(
  "id" integer primary key autoincrement not null,
  "grant_id" integer not null,
  "token_hash" varchar not null,
  "created_at" datetime not null,
  foreign key("grant_id") references "mcp_oauth_grants"("id") on delete cascade
);
CREATE UNIQUE INDEX "mcp_oauth_used_refresh_tokens_token_hash_unique" on "mcp_oauth_used_refresh_tokens"(
  "token_hash"
);
CREATE TABLE IF NOT EXISTS "mcp_oauth_codes"(
  "id" integer primary key autoincrement not null,
  "code_hash" varchar not null,
  "client_id" integer not null,
  "staff_id" integer not null,
  "api_key_id" integer,
  "redirect_uri" text not null,
  "code_challenge" varchar not null,
  "expires_at" datetime not null,
  "used_at" datetime,
  "created_at" datetime not null,
  "grant_id" integer,
  "staff_session" varchar,
  foreign key("api_key_id") references "api_keys"("id") on delete cascade on update no action,
  foreign key("staff_id") references "staff"("id") on delete cascade on update no action,
  foreign key("client_id") references "mcp_oauth_clients"("id") on delete cascade on update no action,
  foreign key("grant_id") references "mcp_oauth_grants"("id") on delete set null
);
CREATE UNIQUE INDEX "mcp_oauth_codes_code_hash_unique" on "mcp_oauth_codes"(
  "code_hash"
);
CREATE TABLE IF NOT EXISTS "mcp_proposals"(
  "id" integer primary key autoincrement not null,
  "type" varchar not null,
  "payload" text not null,
  "summary" varchar not null,
  "proposed_by" integer,
  "status" varchar not null default 'pending',
  "decided_by" integer,
  "decided_at" datetime,
  "result" text,
  "expires_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("proposed_by") references "staff"("id") on delete set null,
  foreign key("decided_by") references "staff"("id") on delete set null
);
CREATE INDEX "mcp_proposals_status_index" on "mcp_proposals"("status");
CREATE TABLE IF NOT EXISTS "vat_checks"(
  "id" integer primary key autoincrement not null,
  "contact_id" integer not null,
  "vat_number" varchar not null,
  "result" varchar not null,
  "source" varchar not null default 'vies',
  "staff_id" integer,
  "reason" varchar,
  "name" varchar,
  "address" text,
  "request_identifier" varchar,
  "error" varchar,
  "checked_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("contact_id") references "contacts"("id") on delete cascade,
  foreign key("staff_id") references "staff"("id") on delete set null
);
CREATE INDEX "vat_checks_contact_id_checked_at_index" on "vat_checks"(
  "contact_id",
  "checked_at"
);
CREATE TABLE IF NOT EXISTS "charge_attempts"(
  "id" integer primary key autoincrement not null,
  "key" varchar not null,
  "invoice_id" integer not null,
  "client_id" integer not null,
  "billing_agreement_id" integer,
  "payment_group_id" integer,
  "amount" numeric not null,
  "currency_code" varchar not null,
  "source" varchar not null,
  "status" varchar not null,
  "error" varchar,
  "started_at" datetime not null,
  "sent_at" datetime not null,
  "finished_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("invoice_id") references "invoices"("id") on delete cascade,
  foreign key("client_id") references "clients"("id") on delete cascade,
  foreign key("billing_agreement_id") references "billing_agreements"("id") on delete set null,
  foreign key("payment_group_id") references "payment_groups"("id") on delete set null
);
CREATE INDEX "charge_attempts_invoice_id_sent_at_index" on "charge_attempts"(
  "invoice_id",
  "sent_at"
);
CREATE UNIQUE INDEX "charge_attempts_key_unique" on "charge_attempts"("key");
CREATE TABLE IF NOT EXISTS "cardlink_orders"(
  "id" integer primary key autoincrement not null,
  "order_id" varchar not null,
  "invoice_id" integer,
  "client_id" integer,
  "payment_group" varchar,
  "amount" numeric not null,
  "currency_code" varchar not null,
  "locale" varchar,
  "status" varchar,
  "tx_id" varchar,
  "pay_method" varchar,
  "returned_at" datetime,
  "recorded_at" datetime,
  "invoice_transaction_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("invoice_id") references "invoices"("id") on delete set null,
  foreign key("client_id") references "clients"("id") on delete set null
);
CREATE UNIQUE INDEX "cardlink_orders_order_id_unique" on "cardlink_orders"(
  "order_id"
);
CREATE TABLE IF NOT EXISTS "domain_tlds"(
  "id" integer primary key autoincrement not null,
  "tld" varchar not null,
  "registrar_connection_id" integer,
  "epp_code_required" tinyint(1) not null default '1',
  "dns_management" tinyint(1) not null default '0',
  "email_forwarding" tinyint(1) not null default '0',
  "id_protection" tinyint(1) not null default '0',
  "allow_idn" tinyint(1) not null default '0',
  "grace_days" integer,
  "redemption_days" integer,
  "is_active" tinyint(1) not null default '1',
  "sort" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  "whmcs_id" integer,
  foreign key("registrar_connection_id") references "domain_registrar_connections"("id") on delete restrict
);
CREATE UNIQUE INDEX "domain_tlds_tld_unique" on "domain_tlds"("tld");
CREATE TABLE IF NOT EXISTS "domain_tld_prices"(
  "id" integer primary key autoincrement not null,
  "domain_tld_id" integer not null,
  "operation" varchar not null,
  "years" integer not null,
  "currency_code" varchar not null,
  "price" numeric,
  "cost" numeric,
  "cost_currency" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("domain_tld_id") references "domain_tlds"("id") on delete cascade,
  foreign key("currency_code") references "currencies"("code") on delete cascade
);
CREATE UNIQUE INDEX "domain_tld_prices_unique" on "domain_tld_prices"(
  "domain_tld_id",
  "operation",
  "years",
  "currency_code"
);
CREATE TABLE IF NOT EXISTS "domain_nameservers"(
  "id" integer primary key autoincrement not null,
  "domain_id" integer not null,
  "host" varchar not null,
  "sort" integer not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("domain_id") references "domains"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "domain_reminders"(
  "id" integer primary key autoincrement not null,
  "domain_id" integer not null,
  "days" integer not null,
  "expires_on" date not null,
  "sent_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("domain_id") references "domains"("id") on delete cascade
);
CREATE UNIQUE INDEX "domain_reminders_domain_id_days_expires_on_unique" on "domain_reminders"(
  "domain_id",
  "days",
  "expires_on"
);
CREATE UNIQUE INDEX "categories_whmcs_id_unique" on "categories"("whmcs_id");
CREATE UNIQUE INDEX "products_whmcs_id_unique" on "products"("whmcs_id");
CREATE UNIQUE INDEX "extensions_whmcs_id_unique" on "extensions"("whmcs_id");
CREATE UNIQUE INDEX "server_groups_whmcs_id_unique" on "server_groups"(
  "whmcs_id"
);
CREATE UNIQUE INDEX "domain_tlds_whmcs_id_unique" on "domain_tlds"("whmcs_id");
CREATE UNIQUE INDEX "config_options_whmcs_ref_unique" on "config_options"(
  "whmcs_ref"
);
CREATE TABLE IF NOT EXISTS "module_actions"(
  "id" integer primary key autoincrement not null,
  "service_id" integer,
  "action" varchar not null,
  "module" varchar,
  "status" varchar not null,
  "attempts" integer not null default('0'),
  "last_error" text,
  "last_attempt_at" datetime,
  "given_up_at" datetime,
  "resolved_at" datetime,
  "resolution" varchar,
  "resolved_by_staff_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "domain_id" integer,
  "invoice_item_id" integer,
  "subject" varchar,
  foreign key("resolved_by_staff_id") references "staff"("id") on delete set null on update no action,
  foreign key("service_id") references "services"("id") on delete cascade on update no action,
  foreign key("domain_id") references "domains"("id") on delete restrict,
  foreign key("invoice_item_id") references "invoice_items"("id") on delete set null
);
CREATE INDEX "module_actions_service_id_action_status_index" on "module_actions"(
  "service_id",
  "action",
  "status"
);
CREATE INDEX "module_actions_status_action_index" on "module_actions"(
  "status",
  "action"
);
CREATE INDEX "module_actions_domain_id_action_status_index" on "module_actions"(
  "domain_id",
  "action",
  "status"
);
CREATE TABLE IF NOT EXISTS "domain_registrar_logs"(
  "id" integer primary key autoincrement not null,
  "domain_id" integer,
  "registrar_connection_id" integer,
  "action" varchar not null,
  "status" varchar not null,
  "request" text,
  "response" text,
  "error" text,
  "invoice_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "staff_id" integer,
  "user_id" integer,
  "transactions" text,
  foreign key("staff_id") references "staff"("id") on delete set null on update no action,
  foreign key("domain_id") references "domains"("id") on delete restrict on update no action,
  foreign key("registrar_connection_id") references "domain_registrar_connections"("id") on delete restrict on update no action,
  foreign key("invoice_id") references "invoices"("id") on delete set null on update no action,
  foreign key("user_id") references "users"("id") on delete set null
);
CREATE INDEX "domain_registrar_logs_domain_id_action_status_index" on "domain_registrar_logs"(
  "domain_id",
  "action",
  "status"
);
CREATE TABLE IF NOT EXISTS "ticket_departments"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "sort" integer not null default '0',
  "hidden" tinyint(1) not null default '0',
  "clients_only" tinyint(1) not null default '0',
  "email" varchar,
  "whmcs_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "imap_host" varchar,
  "imap_port" integer,
  "imap_encryption" varchar not null default 'ssl',
  "imap_username" varchar,
  "imap_password" text,
  "imap_folder" varchar not null default 'INBOX',
  "authserv_id" varchar,
  "require_dmarc" tinyint(1) not null default '1',
  "polling" tinyint(1) not null default '0'
);
CREATE UNIQUE INDEX "ticket_departments_whmcs_id_unique" on "ticket_departments"(
  "whmcs_id"
);
CREATE TABLE IF NOT EXISTS "client_notes"(
  "id" integer primary key autoincrement not null,
  "client_id" integer not null,
  "staff_id" integer,
  "body" text not null,
  "sticky" tinyint(1) not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("client_id") references "clients"("id") on delete cascade,
  foreign key("staff_id") references "staff"("id") on delete set null
);
CREATE INDEX "client_notes_client_id_sticky_index" on "client_notes"(
  "client_id",
  "sticky"
);
CREATE TABLE IF NOT EXISTS "ticket_quarantine"(
  "id" integer primary key autoincrement not null,
  "department_id" integer,
  "reason" varchar not null,
  "message_id" varchar,
  "from_email" varchar,
  "from_name" varchar,
  "subject" varchar,
  "sent_at" datetime,
  "authenticated" tinyint(1) not null default '0',
  "auth_detail" varchar,
  "preview" text,
  "refused_files" text,
  "raw_path" varchar,
  "raw_size" integer not null default '0',
  "suggested_ticket_id" integer,
  "status" varchar not null default 'pending',
  "ticket_id" integer,
  "handled_by" integer,
  "handled_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("department_id") references "ticket_departments"("id") on delete set null,
  foreign key("suggested_ticket_id") references "tickets"("id") on delete set null,
  foreign key("ticket_id") references "tickets"("id") on delete set null,
  foreign key("handled_by") references "staff"("id") on delete set null
);
CREATE UNIQUE INDEX "ticket_quarantine_message_id_unique" on "ticket_quarantine"(
  "message_id"
);
CREATE INDEX "ticket_quarantine_from_email_index" on "ticket_quarantine"(
  "from_email"
);
CREATE INDEX "ticket_quarantine_status_index" on "ticket_quarantine"("status");
CREATE TABLE IF NOT EXISTS "ticket_blocked_senders"(
  "id" integer primary key autoincrement not null,
  "value" varchar not null,
  "note" varchar,
  "created_by" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("created_by") references "staff"("id") on delete set null
);
CREATE UNIQUE INDEX "ticket_blocked_senders_value_unique" on "ticket_blocked_senders"(
  "value"
);
CREATE TABLE IF NOT EXISTS "ticket_poll_runs"(
  "id" integer primary key autoincrement not null,
  "department_id" integer not null,
  "started_at" datetime not null,
  "finished_at" datetime,
  "connected" tinyint(1) not null default '0',
  "fetched" integer not null default '0',
  "threaded" integer not null default '0',
  "opened" integer not null default '0',
  "quarantined" integer not null default '0',
  "skipped" integer not null default '0',
  "errors" text,
  foreign key("department_id") references "ticket_departments"("id") on delete cascade
);
CREATE INDEX "ticket_poll_runs_department_id_started_at_index" on "ticket_poll_runs"(
  "department_id",
  "started_at"
);
CREATE TABLE IF NOT EXISTS "ticket_messages"(
  "id" integer primary key autoincrement not null,
  "ticket_id" integer not null,
  "user_id" integer,
  "message" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  "staff_id" integer,
  "email_message_id" varchar,
  "via" varchar,
  "is_internal" tinyint(1) not null default('0'),
  "body_original" text,
  "whmcs_ref" varchar,
  "sender_email" varchar,
  foreign key("user_id") references "users"("id") on delete set null on update no action,
  foreign key("staff_id") references "staff"("id") on delete restrict on update no action,
  foreign key("ticket_id") references "tickets"("id") on delete cascade on update no action
);
CREATE UNIQUE INDEX "ticket_messages_email_message_id_unique" on "ticket_messages"(
  "email_message_id"
);
CREATE TABLE IF NOT EXISTS "ticket_mail_ids"(
  "id" integer primary key autoincrement not null,
  "ticket_id" integer not null,
  "ticket_message_id" integer,
  "message_id" varchar not null,
  "kind" varchar not null,
  "recipient" varchar,
  "created_at" datetime,
  foreign key("ticket_id") references "tickets"("id") on delete cascade,
  foreign key("ticket_message_id") references "ticket_messages"("id") on delete cascade
);
CREATE UNIQUE INDEX "ticket_mail_ids_message_id_unique" on "ticket_mail_ids"(
  "message_id"
);
CREATE TABLE IF NOT EXISTS "ticket_department_staff"(
  "ticket_department_id" integer not null,
  "staff_id" integer not null,
  foreign key("ticket_department_id") references "ticket_departments"("id") on delete cascade,
  foreign key("staff_id") references "staff"("id") on delete cascade,
  primary key("ticket_department_id", "staff_id")
);
CREATE TABLE IF NOT EXISTS "zlic_limits"(
  "key" varchar not null,
  "hits" integer not null default '0',
  "expires_at" integer not null,
  primary key("key")
);
CREATE INDEX "zlic_limits_expires_at_index" on "zlic_limits"("expires_at");
CREATE TABLE IF NOT EXISTS "ticket_guest_links"(
  "id" integer primary key autoincrement not null,
  "ticket_id" integer not null,
  "token_hash" varchar not null,
  "expires_at" datetime not null,
  "revoked_at" datetime,
  "last_used_at" datetime,
  "created_at" datetime,
  foreign key("ticket_id") references "tickets"("id") on delete cascade
);
CREATE UNIQUE INDEX "ticket_guest_links_token_hash_unique" on "ticket_guest_links"(
  "token_hash"
);
CREATE TABLE IF NOT EXISTS "ticket_whmcs_tombstones"(
  "ref" varchar not null,
  "created_at" datetime,
  primary key("ref")
);
CREATE UNIQUE INDEX "ticket_messages_whmcs_ref_unique" on "ticket_messages"(
  "whmcs_ref"
);
CREATE TABLE IF NOT EXISTS "canned_reply_categories"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "sort" integer not null default '0',
  "whmcs_id" integer,
  "whmcs_hash" varchar,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "canned_reply_categories_whmcs_id_unique" on "canned_reply_categories"(
  "whmcs_id"
);
CREATE TABLE IF NOT EXISTS "canned_replies"(
  "id" integer primary key autoincrement not null,
  "canned_reply_category_id" integer,
  "title" varchar not null,
  "body" text not null,
  "sort" integer not null default '0',
  "is_active" tinyint(1) not null default '1',
  "whmcs_id" integer,
  "whmcs_hash" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("canned_reply_category_id") references "canned_reply_categories"("id") on delete set null
);
CREATE UNIQUE INDEX "canned_replies_whmcs_id_unique" on "canned_replies"(
  "whmcs_id"
);
CREATE TABLE IF NOT EXISTS "tickets"(
  "id" integer primary key autoincrement not null,
  "subject" varchar not null,
  "status" varchar not null default('open'),
  "priority" varchar not null default('normal'),
  "client_id" integer,
  "assigned_to" integer,
  "service_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "department_id" integer,
  "guest_name" varchar,
  "guest_email" varchar,
  "reference" varchar,
  "opened_via" varchar,
  "sender_vouched" tinyint(1),
  "whmcs_id" integer,
  "whmcs_hash" varchar,
  "merged_into_id" integer,
  "pinned_at" datetime,
  foreign key("client_id") references "clients"("id") on delete cascade on update no action,
  foreign key("assigned_to") references "staff"("id") on delete set null on update no action,
  foreign key("service_id") references "services"("id") on delete set null on update no action,
  foreign key("department_id") references "ticket_departments"("id") on delete set null on update no action,
  foreign key("merged_into_id") references "tickets"("id") on delete set null
);
CREATE UNIQUE INDEX "tickets_reference_unique" on "tickets"("reference");
CREATE UNIQUE INDEX "tickets_whmcs_id_unique" on "tickets"("whmcs_id");
CREATE TABLE IF NOT EXISTS "ticket_ccs"(
  "id" integer primary key autoincrement not null,
  "ticket_id" integer not null,
  "email" varchar not null,
  "added_by" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("ticket_id") references "tickets"("id") on delete cascade,
  foreign key("added_by") references "staff"("id") on delete set null
);
CREATE UNIQUE INDEX "ticket_ccs_ticket_id_email_unique" on "ticket_ccs"(
  "ticket_id",
  "email"
);
CREATE TABLE IF NOT EXISTS "client_groups"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "colour" varchar,
  "discount_percent" numeric not null default '0',
  "created_at" datetime,
  "updated_at" datetime,
  "separate_invoices" tinyint(1) not null default '0',
  "dunning_exempt" tinyint(1) not null default '0',
  "no_overdue_reminders" tinyint(1) not null default '0'
);
CREATE INDEX "clients_client_group_id_index" on "clients"("client_group_id");
CREATE TABLE IF NOT EXISTS "coupon_required_products"(
  "id" integer primary key autoincrement not null,
  "coupon_id" integer not null,
  "product_id" integer not null,
  foreign key("coupon_id") references "coupons"("id") on delete cascade,
  foreign key("product_id") references "products"("id") on delete cascade
);
CREATE UNIQUE INDEX "coupon_required_products_coupon_id_product_id_unique" on "coupon_required_products"(
  "coupon_id",
  "product_id"
);
CREATE TABLE IF NOT EXISTS "offers"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "slug" varchar not null,
  "description" text,
  "coupon_id" integer,
  "active" tinyint(1) not null default '1',
  "starts_at" datetime,
  "expires_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("coupon_id") references "coupons"("id") on delete set null
);
CREATE UNIQUE INDEX "offers_slug_unique" on "offers"("slug");
CREATE TABLE IF NOT EXISTS "offer_items"(
  "id" integer primary key autoincrement not null,
  "offer_id" integer not null,
  "product_id" integer not null,
  "plan_id" integer,
  "quantity" integer not null default '1',
  "sort" integer not null default '0',
  foreign key("offer_id") references "offers"("id") on delete cascade,
  foreign key("product_id") references "products"("id") on delete cascade,
  foreign key("plan_id") references "plans"("id") on delete set null
);
CREATE TABLE IF NOT EXISTS "bulk_mails"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "kind" varchar not null,
  "subject" varchar not null,
  "body" text not null,
  "subject_el" varchar,
  "body_el" text,
  "filters" text,
  "status" varchar not null default 'draft',
  "recipients_count" integer not null default '0',
  "staff_id" integer,
  "started_at" datetime,
  "batch_at" datetime,
  "batch_seq" integer not null default '0',
  "finished_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("staff_id") references "staff"("id") on delete set null
);
CREATE TABLE IF NOT EXISTS "bulk_mail_recipients"(
  "id" integer primary key autoincrement not null,
  "bulk_mail_id" integer not null,
  "client_id" integer not null,
  "user_id" integer,
  "status" varchar not null default 'pending',
  "error" varchar,
  "sent_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("bulk_mail_id") references "bulk_mails"("id") on delete cascade,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE UNIQUE INDEX "bulk_mail_recipients_bulk_mail_id_client_id_unique" on "bulk_mail_recipients"(
  "bulk_mail_id",
  "client_id"
);
CREATE INDEX "bulk_mail_recipients_bulk_mail_id_status_index" on "bulk_mail_recipients"(
  "bulk_mail_id",
  "status"
);
CREATE INDEX "bulk_mail_recipients_user_id_index" on "bulk_mail_recipients"(
  "user_id"
);
CREATE TABLE IF NOT EXISTS "domain_registrar_connections"(
  "id" integer primary key autoincrement not null,
  "registrar" varchar not null,
  "label" varchar not null,
  "is_active" tinyint(1) not null default('0'),
  "mode" varchar not null default('off'),
  "created_at" datetime,
  "updated_at" datetime,
  "debug_log" tinyint(1) not null default('0'),
  "balance" text,
  "low_balance" numeric,
  "renew_transfer_to_id" integer,
  foreign key("renew_transfer_to_id") references "domain_registrar_connections"("id") on delete set null
);
CREATE INDEX "domain_registrar_connections_registrar_is_active_index" on "domain_registrar_connections"(
  "registrar",
  "is_active"
);
CREATE TABLE IF NOT EXISTS "service_usages"(
  "id" integer primary key autoincrement not null,
  "service_id" integer not null,
  "disk_used_mb" integer,
  "disk_limit_mb" integer,
  "bandwidth_used_mb" integer,
  "bandwidth_limit_mb" integer,
  "accounts" integer,
  "accounts_limit" integer,
  "read_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  "accounts_kind" varchar not null default 'accounts',
  "server_ip" varchar,
  foreign key("service_id") references "services"("id") on delete cascade
);
CREATE UNIQUE INDEX "service_usages_service_id_unique" on "service_usages"(
  "service_id"
);
CREATE TABLE IF NOT EXISTS "service_hosted_domains"(
  "id" integer primary key autoincrement not null,
  "service_id" integer not null,
  "domain" varchar not null,
  "type" varchar not null,
  "account" varchar,
  "read_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("service_id") references "services"("id") on delete cascade
);
CREATE UNIQUE INDEX "service_hosted_domains_service_id_domain_unique" on "service_hosted_domains"(
  "service_id",
  "domain"
);
CREATE INDEX "service_hosted_domains_domain_index" on "service_hosted_domains"(
  "domain"
);
CREATE TABLE IF NOT EXISTS "paypal_orders"(
  "id" integer primary key autoincrement not null,
  "order_id" varchar not null,
  "invoice_id" integer not null,
  "payee_merchant_id" varchar,
  "payee_email" varchar,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "paypal_orders_order_id_unique" on "paypal_orders"(
  "order_id"
);
CREATE INDEX "paypal_orders_invoice_id_index" on "paypal_orders"("invoice_id");
CREATE TABLE IF NOT EXISTS "ssl_orders"(
  "id" integer primary key autoincrement not null,
  "service_id" integer not null,
  "connection_id" integer not null,
  "order_id" integer,
  "product_id" integer not null,
  "period" integer not null default '1',
  "common_name" varchar,
  "host_names" text,
  "csr" text,
  "validation_method" varchar,
  "approver_email" varchar,
  "organization_handle" varchar,
  "status" varchar,
  "requested_at" datetime,
  "expires_at" datetime,
  "reissue_at" datetime,
  "order_ends_at" datetime,
  "certificate" text,
  "intermediate" text,
  "validation" text,
  "synced_at" datetime,
  "sync_error" text,
  "created_at" datetime,
  "updated_at" datetime,
  "renew_requested_at" datetime,
  "renew_for" datetime,
  "renew_answered_at" datetime,
  "previous_order_ids" text,
  "notified_hash" varchar,
  foreign key("service_id") references "services"("id") on delete cascade,
  foreign key("connection_id") references "domain_registrar_connections"("id")
);
CREATE UNIQUE INDEX "ssl_orders_connection_id_order_id_unique" on "ssl_orders"(
  "connection_id",
  "order_id"
);
CREATE UNIQUE INDEX "ssl_orders_service_id_unique" on "ssl_orders"(
  "service_id"
);
CREATE TABLE IF NOT EXISTS "kb_categories"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "description" text,
  "sort" integer not null default '0',
  "translations" text,
  "whmcs_id" integer,
  "imported_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "kb_categories_whmcs_id_unique" on "kb_categories"(
  "whmcs_id"
);
CREATE TABLE IF NOT EXISTS "kb_articles"(
  "id" integer primary key autoincrement not null,
  "title" varchar not null,
  "slug" varchar not null,
  "body" text not null,
  "tags" text,
  "sort" integer not null default '0',
  "is_published" tinyint(1) not null default '1',
  "views" integer not null default '0',
  "translations" text,
  "whmcs_id" integer,
  "imported_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "kb_articles_whmcs_id_unique" on "kb_articles"("whmcs_id");
CREATE TABLE IF NOT EXISTS "kb_article_category"(
  "article_id" integer not null,
  "category_id" integer not null,
  foreign key("article_id") references "kb_articles"("id") on delete cascade,
  foreign key("category_id") references "kb_categories"("id") on delete cascade,
  primary key("article_id", "category_id")
);
CREATE TABLE IF NOT EXISTS "kb_whmcs_tombstones"(
  "ref" varchar not null,
  "created_at" datetime,
  primary key("ref")
);
CREATE TABLE IF NOT EXISTS "staff_threads"(
  "id" integer primary key autoincrement not null,
  "kind" varchar not null,
  "direct_key" varchar not null,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE UNIQUE INDEX "staff_threads_direct_key_unique" on "staff_threads"(
  "direct_key"
);
CREATE TABLE IF NOT EXISTS "staff_thread_members"(
  "thread_id" integer not null,
  "staff_id" integer not null,
  "last_read_message_id" integer not null default '0',
  foreign key("thread_id") references "staff_threads"("id") on delete cascade,
  foreign key("staff_id") references "staff"("id") on delete cascade,
  primary key("thread_id", "staff_id")
);
CREATE TABLE IF NOT EXISTS "staff_messages"(
  "id" integer primary key autoincrement not null,
  "thread_id" integer not null,
  "staff_id" integer not null,
  "body" text not null,
  "created_at" datetime,
  foreign key("thread_id") references "staff_threads"("id") on delete cascade,
  foreign key("staff_id") references "staff"("id") on delete restrict
);
CREATE INDEX "staff_messages_thread_id_id_index" on "staff_messages"(
  "thread_id",
  "id"
);
CREATE TABLE IF NOT EXISTS "staff_sessions"(
  "id" integer primary key autoincrement not null,
  "ulid" varchar not null,
  "staff_id" integer not null,
  "session_id" varchar,
  "ip_address" varchar,
  "user_agent" varchar,
  "remembered" tinyint(1) not null default '0',
  "created_at" datetime,
  "last_seen_at" datetime,
  "ended_at" datetime,
  "terminated" tinyint(1) not null default '0',
  "ended_by_staff_id" integer,
  foreign key("staff_id") references "staff"("id") on delete cascade,
  foreign key("ended_by_staff_id") references "staff"("id") on delete set null
);
CREATE INDEX "staff_sessions_staff_id_ended_at_index" on "staff_sessions"(
  "staff_id",
  "ended_at"
);
CREATE UNIQUE INDEX "staff_sessions_ulid_unique" on "staff_sessions"("ulid");
CREATE INDEX "staff_sessions_session_id_index" on "staff_sessions"(
  "session_id"
);
CREATE INDEX "mcp_oauth_codes_staff_session_index" on "mcp_oauth_codes"(
  "staff_session"
);
CREATE INDEX "mcp_oauth_grants_staff_session_index" on "mcp_oauth_grants"(
  "staff_session"
);
CREATE TABLE IF NOT EXISTS "domain_owner_changes"(
  "id" integer primary key autoincrement not null,
  "domain_id" integer not null,
  "kind" varchar not null,
  "contact_id" integer not null,
  "move_to_client_id" integer,
  "declaration_path" varchar,
  "declaration_name" varchar,
  "status" varchar not null,
  "free" tinyint(1) not null default '0',
  "staff_id" integer,
  "result" text,
  "sent_at" datetime,
  "done_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("domain_id") references "domains"("id") on delete cascade,
  foreign key("contact_id") references "contacts"("id") on delete restrict,
  foreign key("move_to_client_id") references "clients"("id") on delete set null,
  foreign key("staff_id") references "staff"("id") on delete set null
);
CREATE INDEX "domain_owner_changes_status_index" on "domain_owner_changes"(
  "status"
);
CREATE TABLE IF NOT EXISTS "ticket_mail_imports"(
  "id" integer primary key autoincrement not null,
  "department_id" integer,
  "from_email" varchar,
  "from_name" varchar,
  "subject" varchar,
  "outcome" varchar not null,
  "reason" varchar,
  "ticket_id" integer,
  "quarantine_id" integer,
  "created_at" datetime,
  foreign key("department_id") references "ticket_departments"("id") on delete set null,
  foreign key("ticket_id") references "tickets"("id") on delete set null,
  foreign key("quarantine_id") references "ticket_quarantine"("id") on delete set null
);
CREATE INDEX "ticket_mail_imports_created_at_index" on "ticket_mail_imports"(
  "created_at"
);
CREATE INDEX "tickets_status_id_index" on "tickets"("status", "id");
CREATE INDEX "tickets_created_at_index" on "tickets"("created_at");
CREATE INDEX "services_status_expires_at_index" on "services"(
  "status",
  "expires_at"
);
CREATE INDEX "ticket_messages_ticket_id_created_at_index" on "ticket_messages"(
  "ticket_id",
  "created_at"
);
CREATE INDEX "inv_tx_status_is_credit_transaction_created_at_index" on "invoice_transactions"(
  "status",
  "is_credit_transaction",
  "created_at"
);
CREATE INDEX "invoice_transactions_created_at_index" on "invoice_transactions"(
  "created_at"
);
CREATE INDEX "orders_created_at_index" on "orders"("created_at");
CREATE INDEX "email_logs_created_at_index" on "email_logs"("created_at");
CREATE INDEX "audits_created_at_index" on "audits"("created_at");
CREATE TABLE IF NOT EXISTS "login_attempts"(
  "id" integer primary key autoincrement not null,
  "area" varchar not null,
  "user_id" integer,
  "staff_id" integer,
  "email" varchar,
  "ip_address" varchar,
  "user_agent" varchar,
  "result" varchar not null,
  "created_at" datetime,
  foreign key("user_id") references "users"("id") on delete set null,
  foreign key("staff_id") references "staff"("id") on delete set null
);
CREATE INDEX "login_attempts_result_created_at_index" on "login_attempts"(
  "result",
  "created_at"
);
CREATE INDEX "login_attempts_created_at_index" on "login_attempts"(
  "created_at"
);
CREATE TABLE IF NOT EXISTS "ssl_versions"(
  "id" integer primary key autoincrement not null,
  "ssl_order_id" integer not null,
  "number" integer not null,
  "source" varchar not null,
  "key_type" varchar,
  "key_description" varchar,
  "private_key" text,
  "spki_sha256" varchar,
  "csr" text,
  "common_name" varchar,
  "host_names" text,
  "certificate" text,
  "chain" text,
  "serial" varchar,
  "expires_at" datetime,
  "ordered_at" datetime,
  "creator_type" varchar,
  "creator_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("ssl_order_id") references "ssl_orders"("id") on delete cascade
);
CREATE INDEX "ssl_versions_creator_type_creator_id_index" on "ssl_versions"(
  "creator_type",
  "creator_id"
);
CREATE UNIQUE INDEX "ssl_versions_ssl_order_id_number_unique" on "ssl_versions"(
  "ssl_order_id",
  "number"
);
CREATE INDEX "ssl_versions_spki_sha256_index" on "ssl_versions"("spki_sha256");
CREATE TABLE IF NOT EXISTS "proxmox_guests"(
  "id" integer primary key autoincrement not null,
  "server_id" integer not null,
  "vmid" integer not null,
  "type" varchar not null default 'qemu',
  "node" varchar,
  "name" varchar,
  "status" varchar,
  "template" tinyint(1) not null default '0',
  "cpus" integer not null default '0',
  "max_memory" integer not null default '0',
  "max_disk" integer not null default '0',
  "uptime" integer not null default '0',
  "macs" text,
  "ips" text,
  "tags" text,
  "last_seen_at" datetime,
  "gone_at" datetime,
  "service_id" integer,
  "internal" tinyint(1) not null default '0',
  "note" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "pool" varchar,
  "onboot_before_suspend" tinyint(1),
  foreign key("server_id") references "extensions"("id") on delete cascade,
  foreign key("service_id") references "services"("id") on delete set null
);
CREATE UNIQUE INDEX "proxmox_guests_server_id_vmid_unique" on "proxmox_guests"(
  "server_id",
  "vmid"
);
CREATE TABLE IF NOT EXISTS "chat_notification_rules"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "webhook_url" text not null,
  "events" text not null,
  "enabled" tinyint(1) not null default '1',
  "created_at" datetime,
  "updated_at" datetime
);
CREATE TABLE IF NOT EXISTS "bank_movements"(
  "id" integer primary key autoincrement not null,
  "bank" varchar not null,
  "direction" varchar not null,
  "amount" numeric not null,
  "balance" numeric,
  "reason" varchar not null default(''),
  "executed_at" datetime,
  "message_key" varchar not null,
  "message_id" varchar,
  "folder" varchar,
  "account" varchar,
  "authentic" tinyint(1) not null default('0'),
  "authenticity" varchar,
  "status" varchar not null default('pending'),
  "acknowledged_by" integer,
  "acknowledged_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "invoice_id" integer,
  "matched_at" datetime,
  "match_note" varchar,
  foreign key("acknowledged_by") references "staff"("id") on delete set null on update no action,
  foreign key("invoice_id") references "invoices"("id") on delete set null
);
CREATE INDEX "bank_movements_bank_executed_at_index" on "bank_movements"(
  "bank",
  "executed_at"
);
CREATE INDEX "bank_movements_direction_status_index" on "bank_movements"(
  "direction",
  "status"
);
CREATE UNIQUE INDEX "bank_movements_message_key_unique" on "bank_movements"(
  "message_key"
);
CREATE TABLE IF NOT EXISTS "ekdosi_documents"(
  "id" integer primary key autoincrement not null,
  "invoice_id" integer not null,
  "mark" varchar not null,
  "code" varchar,
  "state" varchar not null default 'active',
  "pdf_url" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("invoice_id") references "invoices"("id") on delete restrict
);
CREATE INDEX "ekdosi_documents_invoice_id_index" on "ekdosi_documents"(
  "invoice_id"
);
CREATE UNIQUE INDEX "ekdosi_documents_mark_unique" on "ekdosi_documents"(
  "mark"
);
CREATE TABLE IF NOT EXISTS "domains"(
  "id" integer primary key autoincrement not null,
  "client_id" integer not null,
  "domain_tld_id" integer,
  "registrar_connection_id" integer,
  "sld" varchar not null,
  "tld" varchar not null,
  "fqdn" varchar not null,
  "status" varchar not null,
  "live_fqdn" varchar as(CASE WHEN status IN('transferred_away','cancelled','deleted') THEN NULL ELSE fqdn END) stored,
  "registered_at" date,
  "transferred_at" date,
  "expires_at" date,
  "years" integer not null default('1'),
  "currency_code" varchar not null,
  "recurring_amount" numeric,
  "auto_renew" tinyint(1) not null default('1'),
  "transfer_lock" tinyint(1) not null default('0'),
  "id_protection" tinyint(1) not null default('0'),
  "dns_management" tinyint(1) not null default('0'),
  "email_forwarding" tinyint(1) not null default('0'),
  "dnssec_enabled" tinyint(1) not null default('0'),
  "registrant_contact_id" integer,
  "registrant_handle" varchar,
  "admin_contact_id" integer,
  "admin_handle" varchar,
  "tech_contact_id" integer,
  "tech_handle" varchar,
  "billing_contact_id" integer,
  "billing_handle" varchar,
  "registrar_domain_id" varchar,
  "idn_script" varchar,
  "last_synced_at" datetime,
  "sync_error" text,
  "grace_days_override" integer,
  "grace_fee_override" numeric,
  "redemption_days_override" integer,
  "redemption_fee_override" numeric,
  "notes" text,
  "meta" text,
  "created_at" datetime,
  "updated_at" datetime,
  "grace_ends_on" date,
  "redemption_ends_on" date,
  "order_id" integer,
  "epp_code" text,
  "contact_id" integer,
  foreign key("order_id") references "orders"("id") on delete set null on update no action,
  foreign key("client_id") references "clients"("id") on delete restrict on update no action,
  foreign key("domain_tld_id") references "domain_tlds"("id") on delete restrict on update no action,
  foreign key("registrar_connection_id") references "domain_registrar_connections"("id") on delete restrict on update no action,
  foreign key("currency_code") references "currencies"("code") on delete restrict on update no action,
  foreign key("registrant_contact_id") references "contacts"("id") on delete restrict on update no action,
  foreign key("admin_contact_id") references "contacts"("id") on delete restrict on update no action,
  foreign key("tech_contact_id") references "contacts"("id") on delete restrict on update no action,
  foreign key("billing_contact_id") references "contacts"("id") on delete restrict on update no action,
  foreign key("contact_id") references "contacts"("id") on delete set null
);
CREATE INDEX "domains_expires_at_index" on "domains"("expires_at");
CREATE INDEX "domains_fqdn_index" on "domains"("fqdn");
CREATE UNIQUE INDEX "domains_live_fqdn_unique" on "domains"("live_fqdn");
CREATE INDEX "domains_status_expires_at_index" on "domains"(
  "status",
  "expires_at"
);
CREATE TABLE IF NOT EXISTS "live_chats"(
  "id" integer primary key autoincrement not null,
  "client_id" integer,
  "user_id" integer,
  "department_id" integer,
  "status" varchar not null default 'waiting',
  "assigned_to" integer,
  "last_participant_message_at" datetime,
  "closed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("client_id") references "clients"("id") on delete set null,
  foreign key("user_id") references "users"("id") on delete set null,
  foreign key("department_id") references "ticket_departments"("id") on delete set null,
  foreign key("assigned_to") references "staff"("id") on delete set null
);
CREATE INDEX "live_chats_status_department_id_index" on "live_chats"(
  "status",
  "department_id"
);
CREATE INDEX "live_chats_client_id_status_index" on "live_chats"(
  "client_id",
  "status"
);
CREATE TABLE IF NOT EXISTS "live_chat_messages"(
  "id" integer primary key autoincrement not null,
  "chat_id" integer not null,
  "author_type" varchar not null,
  "user_id" integer,
  "staff_id" integer,
  "body" text not null,
  "created_at" datetime,
  foreign key("chat_id") references "live_chats"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete set null,
  foreign key("staff_id") references "staff"("id") on delete restrict
);
CREATE INDEX "live_chat_messages_chat_id_id_index" on "live_chat_messages"(
  "chat_id",
  "id"
);
CREATE TABLE IF NOT EXISTS "live_chat_department_staff"(
  "ticket_department_id" integer not null,
  "staff_id" integer not null,
  foreign key("ticket_department_id") references "ticket_departments"("id") on delete cascade,
  foreign key("staff_id") references "staff"("id") on delete cascade,
  primary key("ticket_department_id", "staff_id")
);
CREATE TABLE IF NOT EXISTS "invoices"(
  "id" integer primary key autoincrement not null,
  "status" varchar not null default('draft'),
  "due_at" date,
  "currency_code" varchar not null,
  "client_id" integer,
  "created_at" datetime,
  "updated_at" datetime,
  "number" varchar,
  "cancellation_reason" varchar,
  "apply_pending_since" datetime,
  "kept_as_debt_at" datetime,
  "finish_pending_at" datetime,
  "merged_into_id" integer,
  "split_from_id" integer,
  "no_auto_charge" tinyint(1) not null default('0'),
  "paid_at" datetime,
  "ekdosi_requested_at" datetime,
  "ekdosi_requested_by" integer,
  foreign key("client_id") references "clients"("id") on delete set null on update no action,
  foreign key("ekdosi_requested_by") references "staff"("id") on delete set null
);
CREATE INDEX "invoices_apply_pending_since_index" on "invoices"(
  "apply_pending_since"
);
CREATE UNIQUE INDEX "invoices_number_unique" on "invoices"("number");
CREATE INDEX "invoices_paid_at_index" on "invoices"("paid_at");
CREATE INDEX "invoices_status_due_at_index" on "invoices"("status", "due_at");
CREATE INDEX "invoices_ekdosi_requested_at_index" on "invoices"(
  "ekdosi_requested_at"
);
CREATE TABLE IF NOT EXISTS "mailcow_domains"(
  "id" integer primary key autoincrement not null,
  "server_id" integer not null,
  "domain" varchar not null,
  "active" tinyint(1) not null default '1',
  "mailboxes" integer not null default '0',
  "mailbox_limit" integer not null default '0',
  "aliases" integer not null default '0',
  "alias_limit" integer not null default '0',
  "quota_bytes" integer not null default '0',
  "used_bytes" integer not null default '0',
  "messages" integer not null default '0',
  "admins" text,
  "last_seen_at" datetime,
  "gone_at" datetime,
  "service_id" integer,
  "internal" tinyint(1) not null default '0',
  "note" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "dkim_selector" varchar,
  "dkim_txt" text,
  "dns_status" varchar,
  "dns_issues" varchar,
  "dns_checked_at" datetime,
  foreign key("server_id") references "extensions"("id") on delete cascade,
  foreign key("service_id") references "services"("id") on delete set null
);
CREATE UNIQUE INDEX "mailcow_domains_server_id_domain_unique" on "mailcow_domains"(
  "server_id",
  "domain"
);
CREATE TABLE IF NOT EXISTS "mailcow_mailboxes"(
  "id" integer primary key autoincrement not null,
  "mailcow_domain_id" integer not null,
  "address" varchar not null,
  "name" varchar,
  "active" tinyint(1) not null default '1',
  "quota_bytes" integer not null default '0',
  "used_bytes" integer not null default '0',
  "messages" integer not null default '0',
  "last_login_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("mailcow_domain_id") references "mailcow_domains"("id") on delete cascade
);
CREATE UNIQUE INDEX "mailcow_mailboxes_mailcow_domain_id_address_unique" on "mailcow_mailboxes"(
  "mailcow_domain_id",
  "address"
);
CREATE TABLE IF NOT EXISTS "cpanel_accounts"(
  "id" integer primary key autoincrement not null,
  "server_id" integer not null,
  "user" varchar not null,
  "domain" varchar,
  "owner" varchar,
  "plan" varchar,
  "ip" varchar,
  "reseller" tinyint(1),
  "disk_used_mb" integer,
  "disk_limit_mb" integer,
  "inodes_used" integer,
  "inodes_limit" integer,
  "bandwidth_used_mb" integer,
  "bandwidth_limit_mb" integer,
  "suspended" tinyint(1) not null default '0',
  "suspend_reason" varchar,
  "suspended_at" datetime,
  "mail_held" tinyint(1) not null default '0',
  "mail_suspended" tinyint(1) not null default '0',
  "max_email_per_hour" integer,
  "backup" tinyint(1) not null default '0',
  "created_on_server_at" datetime,
  "last_seen_at" datetime,
  "gone_at" datetime,
  "service_id" integer,
  "internal" tinyint(1) not null default '0',
  "note" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "lve_memory_limit_mb" integer,
  "lve_entry_processes_limit" integer,
  "lve_processes_limit" integer,
  "lve_io_limit_kbs" integer,
  "lve_iops_limit" integer,
  "lve_read_at" datetime,
  foreign key("server_id") references "extensions"("id") on delete cascade,
  foreign key("service_id") references "services"("id") on delete set null
);
CREATE UNIQUE INDEX "cpanel_accounts_server_id_user_unique" on "cpanel_accounts"(
  "server_id",
  "user"
);
CREATE INDEX "cpanel_accounts_owner_index" on "cpanel_accounts"("owner");
CREATE TABLE IF NOT EXISTS "cpanel_lve_samples"(
  "id" integer primary key autoincrement not null,
  "cpanel_account_id" integer not null,
  "sampled_at" datetime not null,
  "cpu" integer,
  "memory_mb" integer,
  "entry_processes" integer,
  "processes" integer,
  "io_kbs" integer,
  "iops" integer,
  foreign key("cpanel_account_id") references "cpanel_accounts"("id") on delete cascade
);
CREATE INDEX "cpanel_lve_samples_cpanel_account_id_sampled_at_index" on "cpanel_lve_samples"(
  "cpanel_account_id",
  "sampled_at"
);
CREATE INDEX "cpanel_lve_samples_sampled_at_index" on "cpanel_lve_samples"(
  "sampled_at"
);
CREATE TABLE IF NOT EXISTS "ip_pools"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "family" integer not null default '4',
  "network" varchar,
  "prefix" integer not null,
  "gateway" varchar,
  "nameservers" varchar,
  "server_id" integer,
  "whmcs_id" integer,
  "note" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("server_id") references "extensions"("id") on delete set null
);
CREATE UNIQUE INDEX "ip_pools_whmcs_id_unique" on "ip_pools"("whmcs_id");
CREATE TABLE IF NOT EXISTS "ip_addresses"(
  "id" integer primary key autoincrement not null,
  "ip_pool_id" integer not null,
  "address" varchar not null,
  "service_id" integer,
  "reserved" tinyint(1) not null default '0',
  "note" varchar,
  "assigned_at" datetime,
  "released_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("ip_pool_id") references "ip_pools"("id") on delete cascade,
  foreign key("service_id") references "services"("id") on delete restrict
);
CREATE INDEX "ip_addresses_ip_pool_id_service_id_index" on "ip_addresses"(
  "ip_pool_id",
  "service_id"
);
CREATE UNIQUE INDEX "ip_addresses_address_unique" on "ip_addresses"("address");
CREATE TABLE IF NOT EXISTS "whmcs_ip_pool_imports"(
  "whmcs_id" integer not null,
  "imported_at" datetime not null,
  primary key("whmcs_id")
);

INSERT INTO migrations VALUES(1,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(2,'2024_02_15_122223_create_roles_table',1);
INSERT INTO migrations VALUES(3,'2024_02_15_122224_create_users_table',1);
INSERT INTO migrations VALUES(4,'2024_02_15_122225_create_settings_table',1);
INSERT INTO migrations VALUES(5,'2024_02_15_122227_create_extensions_table',1);
INSERT INTO migrations VALUES(6,'2024_02_15_122228_create_categories_table',1);
INSERT INTO migrations VALUES(7,'2024_02_15_122231_create_products_table',1);
INSERT INTO migrations VALUES(8,'2024_02_15_122232_create_currencies_table',1);
INSERT INTO migrations VALUES(9,'2024_02_15_122233_create_plans_table',1);
INSERT INTO migrations VALUES(10,'2024_02_15_122235_create_prices_table',1);
INSERT INTO migrations VALUES(11,'2024_04_20_111206_create_audit_logs_table',1);
INSERT INTO migrations VALUES(12,'2024_06_19_143154_create_config_options_table',1);
INSERT INTO migrations VALUES(13,'2024_06_27_122914_create_config_option_products_table',1);
INSERT INTO migrations VALUES(14,'2024_07_02_190049_create_tax_rates_table',1);
INSERT INTO migrations VALUES(15,'2024_07_05_142906_create_coupons_table',1);
INSERT INTO migrations VALUES(16,'2024_07_05_143848_create_orders_table',1);
INSERT INTO migrations VALUES(17,'2024_07_05_143851_create_services_table',1);
INSERT INTO migrations VALUES(18,'2024_07_05_143855_create_service_configs_table',1);
INSERT INTO migrations VALUES(19,'2024_07_05_144020_create_invoices_table',1);
INSERT INTO migrations VALUES(20,'2024_07_05_144024_create_invoice_items_table',1);
INSERT INTO migrations VALUES(21,'2024_07_10_203119_create_invoice_transactions_table',1);
INSERT INTO migrations VALUES(22,'2024_07_13_121420_create_coupon_products_table',1);
INSERT INTO migrations VALUES(23,'2024_07_15_134223_create_custom_properties_table',1);
INSERT INTO migrations VALUES(24,'2024_07_15_134515_create_properties_table',1);
INSERT INTO migrations VALUES(25,'2024_08_09_120929_create_tickets_table',1);
INSERT INTO migrations VALUES(26,'2024_08_09_120938_create_ticket_messages_table',1);
INSERT INTO migrations VALUES(27,'2024_08_13_185122_create_email_templates_table',1);
INSERT INTO migrations VALUES(28,'2024_08_22_094747_create_email_logs_table',1);
INSERT INTO migrations VALUES(29,'2024_09_13_193832_create_oauth_auth_codes_table',1);
INSERT INTO migrations VALUES(30,'2024_09_13_193833_create_oauth_access_tokens_table',1);
INSERT INTO migrations VALUES(31,'2024_09_13_193834_create_oauth_refresh_tokens_table',1);
INSERT INTO migrations VALUES(32,'2024_09_13_193835_create_oauth_clients_table',1);
INSERT INTO migrations VALUES(33,'2024_09_13_193836_create_oauth_personal_access_clients_table',1);
INSERT INTO migrations VALUES(34,'2024_09_16_171549_create_service_cancellations_table',1);
INSERT INTO migrations VALUES(35,'2024_09_16_171555_create_service_upgrades_table',1);
INSERT INTO migrations VALUES(36,'2024_09_17_111932_create_product_upgrades_table',1);
INSERT INTO migrations VALUES(37,'2024_12_26_185218_create_credits_table',1);
INSERT INTO migrations VALUES(38,'2025_02_24_052318_make_invoice_nullable_in_service_upgrades',1);
INSERT INTO migrations VALUES(39,'2025_03_07_190949_create_debug_logs_table',1);
INSERT INTO migrations VALUES(40,'2025_03_11_205629_add_hidden_to_products',1);
INSERT INTO migrations VALUES(41,'2025_04_25_064729_add_number_to_invoices',1);
INSERT INTO migrations VALUES(42,'2025_05_22_073500_add_max_uses_per_user_to_coupons_table',1);
INSERT INTO migrations VALUES(43,'2025_06_04_132119_create_api_keys_table',1);
INSERT INTO migrations VALUES(44,'2025_06_20_101701_add_upgradable_to_config_options',1);
INSERT INTO migrations VALUES(45,'2025_06_30_092448_cascade_config_value_id_on_service_configs',1);
INSERT INTO migrations VALUES(46,'2025_07_31_165713_create_ticket_message_attachments_table',1);
INSERT INTO migrations VALUES(47,'2025_08_02_150516_create_ticket_mail_logs_table',1);
INSERT INTO migrations VALUES(48,'2025_08_04_181811_add_ticket_mail_log_id_to_ticket_messages',1);
INSERT INTO migrations VALUES(49,'2025_08_15_100111_change_gateway_id_on_invoice_transactions',1);
INSERT INTO migrations VALUES(50,'2025_08_15_132711_create_audits_table',1);
INSERT INTO migrations VALUES(51,'2025_09_03_190940_add_name_to_currencies',1);
INSERT INTO migrations VALUES(52,'2025_09_12_163530_make_number_nullable_on_invoices',1);
INSERT INTO migrations VALUES(53,'2025_09_16_130604_create_invoice_snapshots_table',1);
INSERT INTO migrations VALUES(54,'2025_09_19_140421_create_cron_stats_table',1);
INSERT INTO migrations VALUES(55,'2025_09_20_185200_add_applies_to_to_coupons',1);
INSERT INTO migrations VALUES(56,'2025_09_26_102234_create_carts_table',1);
INSERT INTO migrations VALUES(57,'2025_09_26_102237_create_cart_items_table',1);
INSERT INTO migrations VALUES(58,'2025_09_27_182553_create_billing_agreements_table',1);
INSERT INTO migrations VALUES(59,'2025_09_29_100814_add_deleted_at_to_extensions',1);
INSERT INTO migrations VALUES(60,'2025_09_29_113738_add_billing_agreement_id_to_services',1);
INSERT INTO migrations VALUES(61,'2025_09_30_100549_add_status_to_invoice_transactions',1);
INSERT INTO migrations VALUES(62,'2025_10_04_183152_rename_email_templates_to_notification_templates_table',1);
INSERT INTO migrations VALUES(63,'2025_10_06_161337_create_notification_preferences_table',1);
INSERT INTO migrations VALUES(64,'2025_10_07_195108_create_notification_subscriptions_table',1);
INSERT INTO migrations VALUES(65,'2025_10_08_193743_create_notifications_table',1);
INSERT INTO migrations VALUES(66,'2025_10_14_140749_add_is_credit_transaction_to_invoice_transactions',1);
INSERT INTO migrations VALUES(67,'2025_10_27_210931_create_user_authentication_logs_table',1);
INSERT INTO migrations VALUES(68,'2025_11_02_103925_add_description_to_config_options_table',1);
INSERT INTO migrations VALUES(69,'2025_12_13_125749_create_user_sessions_table',1);
INSERT INTO migrations VALUES(70,'2025_12_15_205656_drop_remember_token_from_users',1);
INSERT INTO migrations VALUES(71,'2025_12_17_125349_add_label_to_services',1);
INSERT INTO migrations VALUES(72,'2026_05_15_140242_change_default_invoice_status_to_draft',1);
INSERT INTO migrations VALUES(73,'2026_05_20_144000_create_adjustment_notes_table',1);
INSERT INTO migrations VALUES(74,'2026_05_26_112630_add_refunded_amount_to_invoice_transactions',1);
INSERT INTO migrations VALUES(75,'2026_08_27_000000_add_suspend_hold_until_to_services',1);
INSERT INTO migrations VALUES(76,'2026_10_11_000000_templates_without_blade',1);
INSERT INTO migrations VALUES(77,'2026_10_11_100000_encrypt_extension_credentials',1);
INSERT INTO migrations VALUES(78,'2026_10_12_000000_add_billing_period_to_invoice_items',1);
INSERT INTO migrations VALUES(79,'2026_10_12_000100_add_billing_day_to_services',1);
INSERT INTO migrations VALUES(80,'2026_10_12_000200_add_applied_at_to_invoice_items',1);
INSERT INTO migrations VALUES(81,'2026_10_12_000300_add_coupon_state_to_services',1);
INSERT INTO migrations VALUES(82,'2026_10_12_000400_add_dunning_suspension_to_services',1);
INSERT INTO migrations VALUES(83,'2026_10_13_000000_add_credited_amount_to_invoice_transactions',1);
INSERT INTO migrations VALUES(84,'2026_10_13_000100_add_cancellation_marker_and_payment_source',1);
INSERT INTO migrations VALUES(85,'2026_10_14_000000_create_money_events_table',1);
INSERT INTO migrations VALUES(86,'2026_10_15_000000_credit_ledger',1);
INSERT INTO migrations VALUES(87,'2026_10_16_000000_forget_plaintext_settings_cache',1);
INSERT INTO migrations VALUES(88,'2026_10_17_000000_add_refund_marker_to_adjustment_notes',1);
INSERT INTO migrations VALUES(89,'2026_10_18_000000_credit_auto_apply',1);
INSERT INTO migrations VALUES(90,'2026_10_19_000000_add_apply_pending_since_to_invoices',1);
INSERT INTO migrations VALUES(91,'2026_10_19_000000_create_payment_gateway_events_table',1);
INSERT INTO migrations VALUES(92,'2026_10_20_000000_restrict_deleting_plans_and_products_in_use',1);
INSERT INTO migrations VALUES(93,'2026_10_21_000000_rename_invoice_status_pending_to_unpaid',1);
INSERT INTO migrations VALUES(94,'2026_10_21_000000_unique_keys_and_long_line_descriptions',1);
INSERT INTO migrations VALUES(95,'2026_10_22_000000_restrict_deleting_config_values_in_use',1);
INSERT INTO migrations VALUES(96,'2026_10_23_000000_create_gateway_refunds_table',1);
INSERT INTO migrations VALUES(97,'2026_10_23_000000_restrict_deleting_orders_with_services',1);
INSERT INTO migrations VALUES(98,'2026_10_24_000000_gateway_refunds_chargebacks',1);
INSERT INTO migrations VALUES(99,'2026_10_24_000000_rename_dead_permission_names_in_roles',1);
INSERT INTO migrations VALUES(100,'2026_10_25_000000_theme_settings_without_theme_name',1);
INSERT INTO migrations VALUES(101,'2026_10_26_000000_add_translations_to_notification_templates',1);
INSERT INTO migrations VALUES(102,'2026_10_26_000000_split_staff_from_users',1);
INSERT INTO migrations VALUES(103,'2026_10_26_000001_new_notification_template_texts',1);
INSERT INTO migrations VALUES(104,'2026_10_27_000000_create_invoice_reminders_table',1);
INSERT INTO migrations VALUES(105,'2026_10_28_000000_split_clients_from_users',1);
INSERT INTO migrations VALUES(106,'2026_10_29_000000_create_client_invites_table',1);
INSERT INTO migrations VALUES(107,'2026_10_30_000000_create_contacts_table',1);
INSERT INTO migrations VALUES(108,'2026_10_31_000000_extensions_namespace_mycs',1);
INSERT INTO migrations VALUES(109,'2026_11_01_000000_create_licenses_tables',1);
INSERT INTO migrations VALUES(110,'2026_11_01_000000_net_amounts_and_tax_per_line',1);
INSERT INTO migrations VALUES(111,'2026_11_02_000000_add_notes_to_orders',1);
INSERT INTO migrations VALUES(112,'2026_11_02_000000_add_whmcs_fields_to_services',1);
INSERT INTO migrations VALUES(113,'2026_11_02_000001_welcome_mail_login_details',1);
INSERT INTO migrations VALUES(114,'2026_11_03_000000_service_servers_and_server_groups',1);
INSERT INTO migrations VALUES(115,'2026_11_04_000000_create_module_actions_table',1);
INSERT INTO migrations VALUES(116,'2026_11_04_000100_add_next_plan_to_services',1);
INSERT INTO migrations VALUES(117,'2026_11_05_000000_add_autosetup_to_products',1);
INSERT INTO migrations VALUES(118,'2026_11_05_000000_add_contact_to_services',1);
INSERT INTO migrations VALUES(119,'2026_11_05_000001_add_status_to_orders',1);
INSERT INTO migrations VALUES(120,'2026_11_05_000002_add_activated_at_to_services',1);
INSERT INTO migrations VALUES(121,'2026_11_06_000000_add_forgone_at_to_invoice_items',1);
INSERT INTO migrations VALUES(122,'2026_11_06_000000_move_directadmin_passwords_out_of_properties',1);
INSERT INTO migrations VALUES(123,'2026_11_07_000000_add_separate_invoices_to_clients',1);
INSERT INTO migrations VALUES(124,'2026_11_07_000000_license_keys_as_service_domains',1);
INSERT INTO migrations VALUES(125,'2026_11_07_000001_add_finish_pending_at_to_invoices',1);
INSERT INTO migrations VALUES(126,'2026_11_08_000000_move_saved_payment_method_to_clients',1);
INSERT INTO migrations VALUES(127,'2026_11_09_000000_add_prorata_to_products',1);
INSERT INTO migrations VALUES(128,'2026_11_09_000000_add_whmcs_id_to_license_access_logs',1);
INSERT INTO migrations VALUES(129,'2026_11_09_000001_service_cancelled_notification',1);
INSERT INTO migrations VALUES(130,'2026_11_10_000000_add_merge_split_to_invoices',1);
INSERT INTO migrations VALUES(131,'2026_11_10_000000_mail_audit_on_email_logs',1);
INSERT INTO migrations VALUES(132,'2026_11_11_000000_create_mcp_calls_table',1);
INSERT INTO migrations VALUES(133,'2026_11_11_000000_create_payment_groups_table',1);
INSERT INTO migrations VALUES(134,'2026_11_12_000000_add_whmcs_invoice_id_to_payment_groups',1);
INSERT INTO migrations VALUES(135,'2026_11_12_000000_create_mcp_oauth_tables',1);
INSERT INTO migrations VALUES(136,'2026_11_12_000001_mcp_oauth_review',1);
INSERT INTO migrations VALUES(137,'2026_11_13_000000_create_mcp_proposals_table',1);
INSERT INTO migrations VALUES(138,'2026_11_13_000000_eu_vat',1);
INSERT INTO migrations VALUES(139,'2026_11_14_000000_create_charge_attempts',1);
INSERT INTO migrations VALUES(140,'2026_11_15_000000_create_cardlink_orders',1);
INSERT INTO migrations VALUES(141,'2026_11_16_000000_h2b_autocharge_flags',1);
INSERT INTO migrations VALUES(142,'2026_11_16_000000_suspension_mail_says_why',1);
INSERT INTO migrations VALUES(143,'2026_11_17_000000_create_domains_tables',1);
INSERT INTO migrations VALUES(144,'2026_11_18_000000_whmcs_ids_on_the_catalog',1);
INSERT INTO migrations VALUES(145,'2026_11_19_000000_staff_on_domain_registrar_logs',1);
INSERT INTO migrations VALUES(146,'2026_11_20_000000_quarantine_dates_on_domains',1);
INSERT INTO migrations VALUES(147,'2026_11_21_000000_kind_on_invoice_items',1);
INSERT INTO migrations VALUES(148,'2026_11_22_000000_domains_on_module_actions',1);
INSERT INTO migrations VALUES(149,'2026_11_23_000000_forgone_domain_periods',1);
INSERT INTO migrations VALUES(150,'2026_11_24_000000_domain_notice_templates',1);
INSERT INTO migrations VALUES(151,'2026_11_25_000000_domain_lines_without_period_are_charges',1);
INSERT INTO migrations VALUES(152,'2026_11_26_000000_user_on_domain_registrar_logs',1);
INSERT INTO migrations VALUES(153,'2026_11_27_000000_domain_orders',1);
INSERT INTO migrations VALUES(154,'2026_11_28_000000_domain_order_templates',1);
INSERT INTO migrations VALUES(155,'2026_11_29_000000_domain_nameserver_settings',1);
INSERT INTO migrations VALUES(156,'2026_11_30_000000_registrar_debug_log',1);
INSERT INTO migrations VALUES(157,'2026_12_01_000000_domain_transferred_template',1);
INSERT INTO migrations VALUES(158,'2026_12_02_000000_registrar_balances',1);
INSERT INTO migrations VALUES(159,'2026_12_03_000000_categories_show_in_menu',1);
INSERT INTO migrations VALUES(160,'2026_12_03_000001_products_retired',1);
INSERT INTO migrations VALUES(161,'2026_12_04_000000_services_started_unpaid_at',1);
INSERT INTO migrations VALUES(162,'2026_12_05_000000_orders_ip_address',1);
INSERT INTO migrations VALUES(163,'2026_12_05_000001_tickets_departments_guests_statuses',1);
INSERT INTO migrations VALUES(164,'2026_12_06_000000_create_client_notes',1);
INSERT INTO migrations VALUES(165,'2026_12_06_000001_tickets_mailboxes_quarantine',1);
INSERT INTO migrations VALUES(166,'2026_12_07_000000_module_actions_subject',1);
INSERT INTO migrations VALUES(167,'2026_12_07_000001_tickets_outbound_mail',1);
INSERT INTO migrations VALUES(168,'2026_12_08_000000_zlic_limits',1);
INSERT INTO migrations VALUES(169,'2026_12_08_000001_tickets_guest_links',1);
INSERT INTO migrations VALUES(170,'2026_12_09_000001_tickets_whmcs_ids',1);
INSERT INTO migrations VALUES(171,'2026_12_10_000001_canned_replies',1);
INSERT INTO migrations VALUES(172,'2026_12_10_000002_tickets_merge',1);
INSERT INTO migrations VALUES(173,'2026_12_10_000003_ticket_ccs',1);
INSERT INTO migrations VALUES(174,'2026_12_11_000000_client_groups',1);
INSERT INTO migrations VALUES(175,'2026_12_11_000001_coupon_rules',1);
INSERT INTO migrations VALUES(176,'2026_12_12_000000_invoices_paid_at',1);
INSERT INTO migrations VALUES(177,'2026_12_13_000000_offers',1);
INSERT INTO migrations VALUES(178,'2026_12_14_000000_bank_transfer_in_invoice_mail',1);
INSERT INTO migrations VALUES(179,'2026_12_15_000000_domain_renewed_and_transfer_failed_templates',1);
INSERT INTO migrations VALUES(180,'2026_12_16_000000_bulk_mails',1);
INSERT INTO migrations VALUES(181,'2026_12_17_000000_config_options_staff_task',1);
INSERT INTO migrations VALUES(182,'2026_12_18_000000_registrar_renew_as_transfer',1);
INSERT INTO migrations VALUES(183,'2026_12_19_000000_drop_renew_transfer_except',1);
INSERT INTO migrations VALUES(184,'2026_12_20_000000_create_service_usages_table',1);
INSERT INTO migrations VALUES(185,'2026_12_21_000000_create_service_hosted_domains_table',1);
INSERT INTO migrations VALUES(186,'2026_12_22_000000_add_credit_to_service_upgrades',1);
INSERT INTO migrations VALUES(187,'2026_12_22_000000_service_usages_accounts_kind',1);
INSERT INTO migrations VALUES(188,'2026_12_23_000000_create_paypal_orders_table',1);
INSERT INTO migrations VALUES(189,'2026_12_24_000000_create_ssl_orders_table',1);
INSERT INTO migrations VALUES(190,'2026_12_25_000000_ssl_orders_renewal_and_notice',1);
INSERT INTO migrations VALUES(191,'2026_12_26_000000_create_knowledgebase_tables',1);
INSERT INTO migrations VALUES(192,'2026_12_26_000000_drop_passport_tables',1);
INSERT INTO migrations VALUES(193,'2026_12_27_000000_add_tfa_recovery_codes_to_users',1);
INSERT INTO migrations VALUES(194,'2026_12_27_000000_staff_presence',1);
INSERT INTO migrations VALUES(195,'2026_12_28_000000_add_language_to_clients_and_users',1);
INSERT INTO migrations VALUES(196,'2026_12_28_000000_create_staff_chat',1);
INSERT INTO migrations VALUES(197,'2026_12_29_000000_create_staff_sessions_table',1);
INSERT INTO migrations VALUES(198,'2026_12_29_000000_invoice_mail_credit_line',1);
INSERT INTO migrations VALUES(199,'2026_12_29_000001_mcp_grants_staff_session',1);
INSERT INTO migrations VALUES(200,'2026_12_30_000000_add_server_ip_to_service_usages',1);
INSERT INTO migrations VALUES(201,'2026_12_31_000000_create_domain_owner_changes',1);
INSERT INTO migrations VALUES(202,'2027_01_01_000000_create_ticket_mail_imports',1);
INSERT INTO migrations VALUES(203,'2027_01_02_000000_add_admin_list_indexes',1);
INSERT INTO migrations VALUES(204,'2027_01_02_000000_create_login_attempts',1);
INSERT INTO migrations VALUES(205,'2027_01_03_000000_drop_unused_languages',1);
INSERT INTO migrations VALUES(206,'2027_01_05_000000_forget_cronjob_invoice_reminder',1);
INSERT INTO migrations VALUES(207,'2027_01_06_000000_create_ssl_versions_table',1);
INSERT INTO migrations VALUES(208,'2027_01_07_000000_ekdosi_flags_on_clients',1);
INSERT INTO migrations VALUES(209,'2027_01_08_000000_create_proxmox_guests_table',1);
INSERT INTO migrations VALUES(210,'2027_01_09_000000_proxmox_guest_pool',1);
INSERT INTO migrations VALUES(211,'2027_01_10_000000_create_bank_movements_table',1);
INSERT INTO migrations VALUES(212,'2027_01_11_000000_dunning_flags_on_clients_and_groups',1);
INSERT INTO migrations VALUES(213,'2027_01_12_000000_create_chat_notification_rules_table',1);
INSERT INTO migrations VALUES(214,'2027_01_13_000000_matching_on_bank_movements',1);
INSERT INTO migrations VALUES(215,'2027_01_14_000000_create_ekdosi_documents',1);
INSERT INTO migrations VALUES(216,'2027_01_14_000000_pinned_tickets',1);
INSERT INTO migrations VALUES(217,'2027_01_15_000000_create_live_chats',1);
INSERT INTO migrations VALUES(218,'2027_01_15_000000_credit_note_counter_from_zero',1);
INSERT INTO migrations VALUES(219,'2027_01_16_000000_ekdosi_requested_on_invoices',1);
INSERT INTO migrations VALUES(220,'2027_01_17_000000_create_mailcow_inventory',1);
INSERT INTO migrations VALUES(221,'2027_01_18_000000_mailcow_domain_dkim',1);
INSERT INTO migrations VALUES(222,'2027_01_19_000000_create_cpanel_accounts',1);
INSERT INTO migrations VALUES(223,'2027_01_20_000000_create_cpanel_lve_samples',1);
INSERT INTO migrations VALUES(224,'2027_01_21_000000_create_ip_pools',1);
