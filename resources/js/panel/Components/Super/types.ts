// Server-shaped types for the super console and the two tenant-facing SaaS screens.
//
// They live in the module rather than in `shared/types/models.d.ts` because that file is foundation-owned
// (CONVENTIONS §7.1) and its SaaS block is appended separately. Everything here is the wire shape: snake_case,
// money in integer paisa, timestamps as ISO-8601 UTC strings.

/** `public.tenants.status` (App\Domain\SaaS\Enums\TenantStatus). */
export type TenantStatusValue = 'trial' | 'active' | 'past_due' | 'suspended' | 'cancelled';

/** Derived by TenantOverview::health() — not a column. */
export type TenantHealth = 'healthy' | 'trial' | 'at_risk' | 'critical';

/** App\Domain\SaaS\Enums\SubscriptionStatus. */
export type SubscriptionStatusValue = 'trialing' | 'active' | 'past_due' | 'suspended' | 'cancelled' | 'expired';

/** App\Domain\SaaS\Enums\SubscriptionInvoiceStatus. */
export type InvoiceStatusValue = 'draft' | 'issued' | 'paid' | 'overdue' | 'void';

/** App\Domain\SaaS\Enums\SubscriptionPaymentMethod. */
export type PaymentMethodValue = 'bkash' | 'nagad' | 'sslcommerz' | 'bank_transfer' | 'cash' | 'manual';

export type BillingCycleValue = 'monthly' | 'yearly';

/** App\Domain\SaaS\Data\LimitStatus::toArray(). `limit === null` is unlimited; `0` means "not in this plan". */
export interface LimitStatus {
  metric: string;
  used: number;
  limit: number | null;
  period: string;
  remaining: number | null;
  percent: number | null;
  exhausted: boolean;
  is_bytes: boolean;
}

export interface TenantCounts {
  doctors: number;
  branches: number;
  appointments: number;
  prescriptions: number;
  sms_credits: number;
  storage_bytes: number;
}

export interface TenantLimits {
  doctors: number | null;
  branches: number | null;
  appointments: number | null;
  sms_credits: number | null;
  storage_bytes: number | null;
}

/** One row of TenantOverview::list() — and the base of the detail payload. */
export interface TenantRow {
  public_id: string;
  name: string;
  slug: string;
  host: string;
  status: TenantStatusValue;
  plan_code: string | null;
  plan_name: string;
  subscription_status: string | null;
  trial_ends_at: string | null;
  period_end: string | null;
  suspended_at: string | null;
  created_at: string | null;
  arrears_paisa: number;
  arrears_invoices: number;
  health: TenantHealth;
  counts: TenantCounts;
  limits: TenantLimits;
}

export interface PlatformTotals {
  tenants: number;
  trial: number;
  active: number;
  past_due: number;
  suspended: number;
  cancelled: number;
  appointments_this_month: number;
  sms_this_month: number;
  mrr_paisa: number;
  outstanding_paisa: number;
}

/** One entry of `subscriptions.feature_overrides` (ToggleTenantFeature / UpdateTenantLimits write this shape). */
export interface FeatureOverride {
  enabled?: boolean;
  limit_value?: number | null;
}

export interface TenantSubscription {
  id: number;
  status: SubscriptionStatusValue;
  plan_code: string;
  plan_name: string;
  billing_cycle: BillingCycleValue;
  price_paisa: number;
  current_period_start: string;
  current_period_end: string;
  trial_ends_at: string | null;
  grace_until: string | null;
  auto_renew: boolean;
  cancel_at_period_end: boolean;
  feature_overrides: Record<string, FeatureOverride | null> | null;
}

export interface TenantAddon {
  plan_code: string;
  plan_name: string;
  status: SubscriptionStatusValue;
}

/** PlanEntitlements: both maps are keyed by PlanFeatureKey, not by usage metric. */
export interface TenantEntitlements {
  plan_code: string;
  plan_name: string;
  toggles: Record<string, boolean>;
  limits: Record<string, number | null>;
}

export interface TenantOwner {
  name: string | null;
  email: string | null;
  mobile: string | null;
}

/** TenantOverview::detail(): a TenantRow plus everything the detail screen needs. */
export interface TenantDetail extends TenantRow {
  owner: TenantOwner;
  timezone: string;
  locale: string;
  schema_name: string;
  provisioned_at: string | null;
  last_backup_at: string | null;
  data_export_requested_at: string | null;
  onboarding: Record<string, unknown> | null;
  suspension_reason: string | null;
  subscription: TenantSubscription | null;
  addons: TenantAddon[];
  usage: LimitStatus[];
  entitlements: TenantEntitlements;
}

export interface PlanLimitRow {
  key: string;
  value: number | null;
  is_bytes: boolean;
}

export interface PlanToggleRow {
  key: string;
  enabled: boolean;
}

/**
 * PlanCatalog::present(). `id`, `is_public` and `archived_at` only come back from `PlanCatalog::all()` (the
 * console list); the public pricing payload omits them.
 */
export interface SuperPlan {
  id?: number;
  code: string;
  name: string;
  description: string | null;
  price_monthly_paisa: number;
  price_yearly_paisa: number;
  trial_days: number;
  is_addon: boolean;
  is_featured: boolean;
  is_public?: boolean;
  archived_at?: string | null;
  /** Not sent today, but SavePlanRequest requires it — see the report on `sort_order`. */
  sort_order?: number;
  limits: PlanLimitRow[];
  toggles: PlanToggleRow[];
}

export interface AuditTenantRef {
  public_id: string;
  name: string;
  slug: string;
}

/** `public.audit_logs_central` as the Audit index sends it. */
export interface AuditRow {
  id: number;
  action: string;
  actor: string | null;
  tenant: AuditTenantRef | null;
  auditable_type: string | null;
  auditable_id: number | null;
  before: unknown;
  after: unknown;
  ip: string | null;
  occurred_at: string | null;
}

/**
 * The same table as seen from one tenant's detail page: TenantController::show() omits `id`, `tenant` and
 * `auditable_id`, so the timeline there is typed without them.
 */
export type TenantAuditEntry = Omit<AuditRow, 'id' | 'tenant' | 'auditable_id'>;

/** `public.subscription_invoices` as the console sends it. */
export interface InvoiceRow {
  public_id: string;
  number: string;
  status: InvoiceStatusValue;
  total_paisa: number;
  paid_paisa: number;
  period_start: string | null;
  period_end: string | null;
  issued_at: string | null;
  due_at: string | null;
  dunning_step: number;
}

/** The clinic's own invoice list (Panel\SaaS\SubscriptionController): due amount and a signed central pay link. */
export interface TenantInvoiceRow {
  public_id: string;
  number: string;
  status: InvoiceStatusValue;
  total_paisa: number;
  paid_paisa: number;
  due_paisa: number;
  issued_at: string | null;
  due_at: string | null;
  pay_url: string | null;
}

/**
 * DomainVerifier::instructions(). `is_apex` arrives as the STRING '1' / '0' today; the union keeps the component
 * correct if the server starts sending a real boolean.
 */
export interface DomainInstructions {
  txt_name: string;
  txt_value: string;
  record_kind: string;
  record_name: string;
  record_value: string;
  is_apex: boolean | string;
}

export type DomainVerificationStatus = 'pending' | 'verified' | 'failed';

export interface DomainRow {
  id: number;
  domain: string;
  type: 'subdomain' | 'custom';
  is_primary: boolean;
  verification_status: DomainVerificationStatus;
  verified_at: string | null;
  last_checked_at: string | null;
  instructions: DomainInstructions;
}

export interface BackupRow {
  id: number;
  type: 'daily' | 'manual' | 'export';
  status: 'pending' | 'running' | 'completed' | 'failed';
  size_bytes: number | null;
  completed_at: string | null;
  expires_at: string | null;
  error: string | null;
}

/** UsageMeter::history() — one point per calendar month (or the single `current` point for a gauge). */
export interface UsagePoint {
  period: string;
  value: number;
}

/** The platform-wide series on the usage screen. */
export interface UsageSeriesPoint {
  period: string;
  total: number;
  tenants: number;
}

export interface UsageTopRow {
  public_id: string;
  name: string;
  slug: string;
  status: string;
  value: number;
  limit: number | null;
}

export interface ReconciliationRow {
  id: number;
  run_id: string;
  tenant: AuditTenantRef | null;
  table_name: string;
  column_name: string;
  checked_count: number;
  orphan_count: number;
  status: string;
  sample_ids: unknown;
  details: unknown;
  resolved_at: string | null;
  created_at: string | null;
}

/** Pagination metadata as the console controllers send it (a slice of Laravel's paginator meta). */
export interface ConsoleMeta {
  current_page: number;
  last_page: number;
  total: number;
  per_page?: number;
}

// ── Custom-brand promotion queue (Catalog's JSON API, consumed through panel/api/super.ts) ──────────────

export type PromotionStatus = 'pending' | 'approved' | 'rejected' | 'promoted';

export interface PromotionTenantRef {
  id: number;
  slug?: string;
  name?: string;
}

export interface PromotionRow {
  public_id: string;
  tenant: PromotionTenantRef;
  custom_brand_id: number;
  brand_name: string;
  manufacturer: string | null;
  generic_id: number;
  generic_name: string | null;
  snapshot: Record<string, unknown> | null;
  status: PromotionStatus;
  submitted_at: string | null;
  reviewed_at: string | null;
  decision: Record<string, unknown> | null;
}

export interface SimilarMasterBrand {
  id: number;
  name: string;
  manufacturer: string | null;
  generic_id: number;
  generic_name: string | null;
  is_active: boolean;
  same_generic: boolean;
}

export interface PromotionDetail extends PromotionRow {
  use_count: number;
  similar_master_brands: SimilarMasterBrand[];
}

/** Laravel's length-aware paginator as it arrives over JSON. */
export interface JsonPage<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

/**
 * Super\Catalog\ApprovePromotionRequest. `strength_id` and `reason` are accepted by the client contract but are
 * NOT in the server's validation rules today — see the report.
 */
export interface ApprovePromotionPayload {
  mode: 'map' | 'create';
  brand_id?: number | null;
  strength_id?: number | null;
  manufacturer?: string;
  presentations?: string;
  note?: string;
  reason?: string;
}

export interface RejectPromotionPayload {
  reason: string;
}

/** One option of a closed-list platform setting (App\Domain\SaaS\Queries\PlatformSettingsScreen). */
export interface PlatformSettingOption {
  value: string;
  label: string;
  help: string;
}

/** One `PlatformSettingsRegistry` key as the Platform settings screen renders it. Secrets arrive MASKED. */
export interface PlatformSettingRow {
  key: string;
  type: 'string' | 'int' | 'number' | 'bool';
  value: string | number | boolean | null;
  default: string | number | boolean | null;
  options: PlatformSettingOption[] | null;
  secret: boolean;
  is_set: boolean;
  /** Saving re-asks the operator's current password (every `security.*` key). */
  requires_password: boolean;
  label: string;
  description: string;
  updated_at: string | null;
  updated_by: string | null;
}

export interface PlatformSettingGroup {
  key: string;
  label: string;
  settings: PlatformSettingRow[];
}
