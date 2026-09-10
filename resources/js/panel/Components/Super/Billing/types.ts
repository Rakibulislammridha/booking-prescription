// Wire shapes of the platform billing desk (App\Domain\SaaS\Queries\{PlatformRevenueSummary, BillingSubscriptions,
// BillingInvoices, BillingPayments, BillingDunningQueue, BillingTenantPanel}). Same conventions as ../types.ts:
// snake_case, money in integer paisa, timestamps as ISO-8601 UTC strings. Kept in the billing folder so the
// console's base type file stays free of the desk's vocabulary.
import type {
  BillingCycleValue, InvoiceStatusValue, PaymentMethodValue, SubscriptionStatusValue, SuperPlan, TenantAddon, TenantSubscription,
} from '../types';

export type PaymentStatusValue = 'pending' | 'succeeded' | 'failed' | 'refunded';

export interface BillingTenantRef {
  public_id: string;
  name: string;
  slug: string;
  status: string;
  owner_email?: string;
}

/** PlatformRevenueSummary::summary(). */
export interface RevenueSummary {
  month: string;
  mrr_paisa: number;
  arr_paisa: number;
  monthly_paisa: number;
  yearly_paisa: number;
  recurring_subscriptions: number;
  trialing: number;
  active: number;
  past_due: number;
  suspended: number;
  cancelled: number;
  expired: number;
  overdue_paisa: number;
  overdue_invoices: number;
  outstanding_paisa: number;
  outstanding_invoices: number;
  collected_month_paisa: number;
  collected_month_payments: number;
  draft_invoices: number;
}

/** PlatformRevenueSummary::collectedByMonth(). */
export interface CollectedMonth {
  period: string;
  collected_paisa: number;
  payments: number;
  invoiced_paisa: number;
  invoices: number;
}

/** PlatformRevenueSummary::arrearsByTenant(). */
export interface ArrearsRow {
  public_id: string;
  name: string;
  slug: string;
  status: string;
  arrears_paisa: number;
  invoices: number;
  oldest_due_at: string | null;
}

/** BillingDunningQueue::totals(). */
export interface DunningTotals {
  tenants: number;
  notices: number;
  suspensions: number;
  arrears_paisa: number;
}

/** One row of BillingSubscriptions::list(). */
export interface BillingSubscriptionRow {
  id: number;
  tenant: BillingTenantRef;
  is_current: boolean;
  plan_code: string;
  plan_name: string;
  is_addon: boolean;
  status: SubscriptionStatusValue;
  billing_cycle: BillingCycleValue;
  price_paisa: number;
  current_period_start: string;
  current_period_end: string;
  trial_ends_at: string | null;
  grace_until: string | null;
  auto_renew: boolean;
  cancel_at_period_end: boolean;
  cancelled_at: string | null;
  arrears_paisa: number;
  arrears_invoices: number;
}

export interface InvoiceLine {
  description: string;
  quantity: number;
  unit_paisa: number;
  total_paisa: number;
  feature_key: string | null;
}

/** BillingInvoices::row(). `tenant` is null only for a row whose clinic has been hard-deleted. */
export interface BillingInvoiceRow {
  public_id: string;
  number: string;
  tenant: BillingTenantRef | null;
  status: InvoiceStatusValue;
  is_past_due: boolean;
  period_start: string | null;
  period_end: string | null;
  subtotal_paisa: number;
  discount_paisa: number;
  tax_paisa: number;
  total_paisa: number;
  paid_paisa: number;
  due_paisa: number;
  issued_at: string | null;
  due_at: string | null;
  paid_at: string | null;
  voided_at: string | null;
  dunning_step: number;
  line_items: InvoiceLine[];
}

/** BillingPayments::row(). */
export interface BillingPaymentRow {
  public_id: string;
  tenant: BillingTenantRef | null;
  invoice: { public_id: string; number: string; status: InvoiceStatusValue } | null;
  method: PaymentMethodValue;
  status: PaymentStatusValue;
  amount_paisa: number;
  gateway_txn_id: string | null;
  idempotency_key: string | null;
  paid_at: string | null;
  created_at: string | null;
  recorded_by: string | null;
}

/** One invoice of BillingDunningQueue::preview() — what the next saas:dun run does to it. */
export interface DunningInvoicePreview {
  public_id: string;
  number: string;
  status: InvoiceStatusValue;
  total_paisa: number;
  paid_paisa: number;
  due_paisa: number;
  due_at: string;
  days_overdue: number;
  dunning_step: number;
  due_step: number;
  will_notify: boolean;
  is_final_notice: boolean;
  will_suspend: boolean;
  grace_deadline: string;
  next_step_at: string | null;
}

export interface DunningGroup {
  tenant: BillingTenantRef;
  invoices: DunningInvoicePreview[];
  arrears_paisa: number;
  will_notify: number;
  will_suspend: boolean;
  grace_deadline: string | null;
  days_overdue: number;
}

export interface DunningScheduleInfo {
  net_days: number;
  steps: number[];
  grace_days: number;
}

/** The `billing` prop of a clinic's page (BillingTenantPanel::for) — what TenantBillingCard renders. */
export interface TenantBilling {
  subscription: TenantSubscription | null;
  addons: TenantAddon[];
  arrears_paisa: number;
  arrears_invoices: number;
  next_invoice_at: string | null;
  invoices: BillingInvoiceRow[];
  payments: BillingPaymentRow[];
  dunning: DunningInvoicePreview[];
}

/** PlanCatalog::forConsole() / ::all() — every console-only column present. */
export interface ConsolePlan extends SuperPlan {
  id: number;
  is_public: boolean;
  sort_order: number;
  archived_at: string | null;
}

export interface ConsoleMeta {
  current_page: number;
  last_page: number;
  total: number;
  per_page?: number;
}
