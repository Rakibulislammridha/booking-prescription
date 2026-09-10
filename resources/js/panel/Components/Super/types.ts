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
  public_id?: string;
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
  /** The master brand's presentations, for the proposed-vs-master comparison. */
  strengths?: SimilarMasterStrength[];
}

export interface PromotionDetail extends PromotionRow {
  use_count: number;
  similar_master_brands: SimilarMasterBrand[];
  /** The clinic's proposal as one flat block (snapshot + denormalised columns). */
  proposed?: PromotionProposed;
  /** The molecule as the catalogue knows it today; null when it has vanished. */
  catalog_generic?: { id: number; name: string; slug: string; is_active: boolean } | null;
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
  /** Control hints from the registry: a textarea, an email/tel/url box, numeric bounds, template placeholders. */
  multiline: boolean;
  input: string | null;
  min: number | null;
  max: number | null;
  placeholders: string[];
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

// ── Catalogue browser (Super/Catalog/Browse — App\Domain\Catalog\Queries\CatalogBrowser) ────────────────

export type CatalogTab = 'generics' | 'brands' | 'strengths' | 'icd10' | 'interactions' | 'allergy_classes';

export interface CatalogRef {
  id: number;
  name: string | null;
}

interface CatalogRowBase {
  id: number;
  is_active: boolean;
  catalog_version_id: number | null;
}

export interface CatalogGenericRow extends CatalogRowBase {
  name: string;
  name_bn: string | null;
  slug: string;
  atc_code: string | null;
  therapeutic_class: string | null;
  aliases: string[];
  is_controlled: boolean;
  is_pediatric_weight_based: boolean;
  needs_review: boolean;
  brands_count: number;
  info_slug: string | null;
  info_published: boolean;
}

export interface CatalogBrandRow extends CatalogRowBase {
  name: string;
  slug: string;
  manufacturer: string | null;
  dar_number: string | null;
  popularity: number;
  aliases: string[];
  generic: CatalogRef;
  strengths_count: number;
  discontinued_at: string | null;
}

export interface CatalogStrengthRow extends CatalogRowBase {
  brand: CatalogRef;
  generic: CatalogRef;
  form: { name: string; code: string };
  route: { name: string; code: string } | null;
  strength_label: string;
  pack_size: string | null;
  strength_mg: number | null;
  per_ml: number | null;
  unit_price_paisa: number | null;
}

export interface CatalogIcd10Row extends CatalogRowBase {
  code: string;
  title: string;
  title_bn: string | null;
  chapter: string | null;
  block: string | null;
  parent_code: string | null;
  aliases: string[];
  is_billable: boolean;
}

export interface CatalogInteractionRow extends CatalogRowBase {
  generic_a: CatalogRef;
  generic_b: CatalogRef;
  severity: string;
  effect: string;
  mechanism: string | null;
  management: string | null;
  evidence_level: string | null;
  source: string | null;
}

export interface CatalogAllergyClassRow extends CatalogRowBase {
  name: string;
  slug: string;
  description: string | null;
  members_count: number;
  cross_reacts_with: { allergy_class_id: number; probability_pct: number }[];
}

export type CatalogRow = CatalogGenericRow | CatalogBrandRow | CatalogStrengthRow | CatalogIcd10Row | CatalogInteractionRow | CatalogAllergyClassRow;

export interface CatalogVersionRef {
  id: number;
  version: string;
  applied_at: string | null;
}

export interface CatalogDrugInformation {
  id: number;
  public_slug: string;
  published_at: string | null;
  indications: string | null;
  indications_bn: string | null;
  side_effects: string | null;
  side_effects_bn: string | null;
  contraindications: string | null;
  precautions: string | null;
  patient_advice_bn: string | null;
}

/** The drawer payload (`super.catalog.show`); `kind` says which shape the rest is. */
export interface CatalogDetail {
  kind: CatalogTab;
  id: number;
  is_active: boolean;
  version: CatalogVersionRef | null;
  name?: string;
  name_bn?: string | null;
  slug?: string;
  atc_code?: string | null;
  therapeutic_class?: string | null;
  aliases?: string[];
  is_controlled?: boolean;
  is_pediatric_weight_based?: boolean;
  needs_review?: boolean;
  components?: { generic_id: number; mg: number | null; name: string | null }[];
  brands?: { id: number; name: string; manufacturer: string | null; popularity: number; strengths_count: number; is_active: boolean }[];
  information?: CatalogDrugInformation | null;
  allergy_classes?: { id: number; name: string; slug: string }[];
  pregnancy?: { trimester: number | null; category: string; lactation: string; notes: string | null }[];
  renal?: { egfr_below: number | null; level: string; advice: string }[];
  hepatic?: { child_pugh_class: string | null; level: string; advice: string }[];
  max_doses?: { route: string | null; population: string; max_mg_per_day: number | null; max_mg_per_kg_per_day: number | null; max_mg_per_dose: number | null; min_age_months: number | null; max_age_months: number | null; notes: string | null }[];
  interactions?: { id: number; with: CatalogRef; severity: string; effect: string; management: string | null; is_active: boolean }[];
  manufacturer?: string | null;
  dar_number?: string | null;
  popularity?: number;
  discontinued_at?: string | null;
  generic?: { id: number; name: string; slug?: string; is_active: boolean } | null;
  strengths?: { id: number; strength_label: string; form: string | null; form_code?: string | null; route: string | null; pack_size: string | null; strength_mg: number | null; per_ml: number | null; unit_price_paisa: number | null; is_active: boolean }[];
  strength_label?: string;
  strength_value?: number | null;
  strength_unit?: string | null;
  per_volume_ml?: number | null;
  strength_mg?: number | null;
  per_ml?: number | null;
  pack_size?: string | null;
  pack_size_value?: number | null;
  pack_unit?: string | null;
  unit_price_paisa?: number | null;
  brand?: { id: number; name: string; manufacturer: string | null; is_active: boolean } | null;
  form?: { name: string; code: string; default_unit?: string } | null;
  route?: { name: string; code: string } | null;
  code?: string;
  title?: string;
  title_bn?: string | null;
  chapter?: string | null;
  block?: string | null;
  is_billable?: boolean;
  parent?: { id: number; code: string; title: string } | null;
  children?: { id: number; code: string; title: string; is_active: boolean }[];
  generic_a?: CatalogRef;
  generic_b?: CatalogRef;
  severity?: string;
  mechanism?: string | null;
  effect?: string;
  management?: string | null;
  evidence_level?: string | null;
  source?: string | null;
  description?: string | null;
  cross_reacts_with?: { allergy_class_id: number; name: string | null; probability_pct: number | null }[];
  members?: { id: number; name: string; is_active: boolean }[];
}

// ── Catalogue imports and maintenance jobs (public.catalog_jobs) ────────────────────────────────────────

export type CatalogJobKind = 'import' | 'reindex' | 'reconcile';

export type CatalogJobStatus = 'uploaded' | 'queued' | 'running' | 'succeeded' | 'failed';

export interface ImportIssueSample {
  kind: string;
  source_row: number | null;
  payload: Record<string, unknown>;
}

/** App\Domain\Catalog\Data\ImportReport::toArray(). */
export interface ImportReport {
  status: 'applied' | 'dry_run' | 'already_imported';
  version_id: number | null;
  version: string;
  checksum: string;
  row_counts: Record<string, { rows: number; inserted: number; updated: number; deactivated: number }>;
  issues: Record<string, number>;
  duration_ms: number;
  issue_samples: ImportIssueSample[];
  total_changes: number;
  indexed?: boolean;
  index_error?: string;
  /** Rebuild jobs: uid → documents / ms. */
  indexes?: Record<string, { documents: number; ms: number }>;
  /** Reconcile jobs. */
  run_id?: string;
  tenants?: number;
}

export interface CatalogJobRow {
  public_id: string;
  kind: CatalogJobKind;
  mode: 'dry_run' | 'apply' | null;
  status: CatalogJobStatus;
  source: string | null;
  version: string | null;
  release_ref: string | null;
  full: boolean;
  bundle_files: string[];
  /** The uploaded files are still on disk (a discarded or pruned bundle cannot be re-run). */
  has_bundle: boolean;
  checksum: string | null;
  progress: { step?: string; percent?: number; rows?: Record<string, number>; run_id?: string; code?: string; force?: boolean };
  report: ImportReport | null;
  error: string | null;
  catalog_version_id: number | null;
  requested_by: string | null;
  queued_at: string | null;
  started_at: string | null;
  finished_at: string | null;
  created_at: string | null;
}

export interface CatalogVersionRow {
  id: number;
  version: string;
  status: string;
  dgda_release_ref: string | null;
  applied_at: string | null;
  applied_by: string | null;
  notes: string | null;
  row_counts: Record<string, { rows?: number; inserted?: number; updated?: number; deactivated?: number }>;
  checksum: string | null;
  issues_open: number;
  issues_total: number;
  is_current: boolean;
}

export interface CatalogImportIssueRow {
  id: number;
  kind: string;
  source_row: number | null;
  payload: Record<string, unknown>;
  resolved_at: string | null;
  resolution: Record<string, unknown> | null;
}

export interface SearchIndexStatus {
  driver: string;
  reachable: boolean;
  indexes: Record<string, number | null>;
}

// ── Promotion review, extended (proposed vs master; tenant filter) ──────────────────────────────────────

export interface SimilarMasterStrength {
  id: number;
  strength_label: string;
  form: string | null;
  pack_size: string | null;
  is_active: boolean;
}

export interface PromotionProposed {
  brand_name: string;
  manufacturer: string | null;
  generic_name: string | null;
  strength: string | null;
  form: string | null;
  route: string | null;
}

export interface PromotionTenantOption {
  public_id: string;
  name: string;
  slug: string;
}

// ── Reconciliation detail ───────────────────────────────────────────────────────────────────────────────

export interface ReconciliationReference {
  ref: string;
  rows: number;
  name: string | null;
  is_active: boolean | null;
  exists: boolean;
}

export interface ReconciliationRun {
  run_id: string;
  started_at: string;
  rows: number;
  orphans: number;
}

// ── Platform notifications (Super/Notifications/Index) ──────────────────────────────────────────────────

export interface PlatformMessageRow {
  id: number;
  channel: 'email' | 'sms';
  kind: string;
  recipient: string;
  subject: string | null;
  locale: string;
  status: 'sent' | 'failed' | 'rejected';
  provider: string | null;
  error: string | null;
  tenant: { public_id: string; name: string } | null;
  sent_by: string | null;
  created_at: string | null;
}

export type TemplateLocaleRows = { subject: PlatformSettingRow; body: PlatformSettingRow };

export type TemplateRows = Record<string, Record<'en' | 'bn', TemplateLocaleRows>>;

export interface TemplatePreview {
  subject: string;
  body: string;
}

// ── Super admins, profile, dashboard, audit and usage (S1: the console shell) ────────────────────────────

/** SuperAdminDirectory::row(): one platform operator as the Admins list shows it. */
export type SuperTwoFactorState = 'enabled' | 'enrolling' | 'none';

export interface SuperAdminRow {
  id: number;
  name: string;
  email: string;
  is_active: boolean;
  two_factor: SuperTwoFactorState;
  recovery_codes: number;
  last_login_at: string | null;
  last_login_ip: string | null;
  created_at: string | null;
  is_self: boolean;
}

/** SuperAdminDirectory::detail(): the edit page decides between Delete and Deactivate from these two. */
export interface SuperAdminDetail extends SuperAdminRow {
  never_used: boolean;
  is_last_active: boolean;
}

/** ProfileController::show(). */
export interface SuperProfile {
  name: string;
  email: string;
  two_factor: SuperTwoFactorState;
  two_factor_policy: 'required' | 'optional' | 'disabled';
  recovery_codes: number;
  last_login_at: string | null;
  last_login_ip: string | null;
  created_at: string | null;
}

/** QueueHealth::snapshot(). `available` false means Horizon's Redis could not be read. */
export interface QueueSnapshot {
  available: boolean;
  running: boolean;
  depth: number;
  longest_wait: number;
  queues: Array<{ name: string; length: number; wait: number }>;
  failed: number;
  failed_recent: number;
  horizon_url: string;
}

/** PlatformKpis::all(): the dashboard tiles. */
export interface DashboardKpis {
  trials_ending_7d: number;
  past_due: { tenants: number; invoices: number; paisa: number };
  /** Billing's PlatformRevenueSummary, the slice the tiles show. */
  revenue: {
    mrr_paisa: number;
    arr_paisa: number;
    outstanding_paisa: number;
    outstanding_invoices: number;
    collected_month_paisa: number;
    collected_month_payments: number;
  };
  signups_month: number;
  appointments_today: number;
  sms_near_limit: number;
  over_limit: number;
  backups: {
    never: number;
    stale: number;
    oldest_hours: number | null;
    worst: { public_id: string; name: string; slug: string; last_backup_at: string | null } | null;
  };
  queue: QueueSnapshot;
}

export type AttentionSeverity = 'error' | 'warning' | 'info';

/** AttentionItems::all(): one actionable count with the place it is dealt with. */
export interface AttentionItem {
  key: string;
  count: number;
  severity: AttentionSeverity;
  route: string | null;
  params: Record<string, string>;
  href: string | null;
  amount_paisa?: number;
}

/** PlatformTrend::last30Days(): one calendar day (Dhaka). */
export interface TrendDay {
  day: string;
  signups: number;
  appointments: number;
}

/** AuditLogSearch::present(): the full row, request context included, for the detail drawer. */
export interface AuditDetailRow extends AuditRow {
  actor_id: number | null;
  user_agent: string | null;
  request_id: string | null;
}

export interface AuditFilters {
  tenant: string;
  admin: string;
  action: string;
  from: string;
  to: string;
  q: string;
}

export interface AuditFilterOptions {
  admins: Array<{ id: number; name: string; is_active: boolean }>;
  tenants: AuditTenantRef[];
}

/** TenantUsageBoard::board() rows: one clinic's counter against its cap for the selected metric. */
export interface UsageBoardRow {
  public_id: string;
  name: string;
  slug: string;
  status: string;
  plan_name: string;
  value: number;
  limit: number | null;
  percent: number | null;
  exhausted: boolean;
  near: boolean;
}

export type UsageBoardFilter = 'all' | 'over' | 'near';
export type UsageBoardSort = 'percent' | 'value' | 'name';

/** TenantUsageBoard::detail(): a LimitStatus plus its six-month history. */
export interface TenantUsageMetric extends LimitStatus {
  near: boolean;
  capped: boolean;
  is_gauge: boolean;
  history: UsagePoint[];
}

export interface UsageTenantRef {
  public_id: string;
  name: string;
  slug: string;
  status: string;
  plan_name: string;
  plan_code: string | null;
  timezone: string;
}
