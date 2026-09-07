// Billing module XHR (CONVENTIONS §7.2): the only place the panel talks JSON to the Billing endpoints.
// Every mutation carries a `client_event_id` so a double-tap or a retried request lands on the SAME payment row
// (`payments.idempotency_key` is UNIQUE) instead of charging the patient twice.
import { http } from '@shared/http';
import { route } from '@shared/routes';
import { ulid } from '@shared/ulid';
import type {
  BillingInvoice,
  BillingPaymentMethod,
  BillingPaymentResult,
  BillingRefund,
  BillingRefundEligibility,
  DiscountReason,
  DiscountType,
  InvoiceStatus,
  RefundReason,
} from '@shared/types/models';

export interface CollectPaymentInput {
  amountPaisa: number;
  method: BillingPaymentMethod;
  note?: string | null;
  /** Reused verbatim on a retry — that is what makes the retry a no-op. */
  clientEventId?: string;
}

export function newClientEventId(): string {
  return ulid();
}

export async function collectPayment(invoice: string, input: CollectPaymentInput): Promise<{ payment: BillingPaymentResult; invoice: BillingInvoice }> {
  const { data } = await http.post<{ payment: BillingPaymentResult; invoice: BillingInvoice }>(route('panel.billing.invoices.payments.store', { invoice }), {
    amount_paisa: input.amountPaisa,
    method: input.method,
    note: input.note ?? null,
    client_event_id: input.clientEventId ?? newClientEventId(),
  });

  return data;
}

export async function applyDiscount(invoice: string, input: { type: DiscountType; value: string; reasonCode: DiscountReason; note?: string | null; approvedBy?: string | null }): Promise<{ invoice: BillingInvoice }> {
  const { data } = await http.post<{ invoice: BillingInvoice }>(route('panel.billing.invoices.discounts.store', { invoice }), {
    type: input.type,
    value: input.value,
    reason_code: input.reasonCode,
    note: input.note ?? null,
    approved_by: input.approvedBy ?? null,
  });

  return data;
}

export async function applyCoupon(invoice: string, code: string): Promise<{ invoice: BillingInvoice }> {
  const { data } = await http.post<{ invoice: BillingInvoice }>(route('panel.billing.invoices.coupon', { invoice }), { code });

  return data;
}

export async function issueRefund(invoice: string, input: { payment: string; amountPaisa?: number | null; reasonCode: RefundReason; note?: string | null; autoProcess?: boolean }): Promise<{ refund: BillingRefund; invoice: BillingInvoice }> {
  const { data } = await http.post<{ refund: BillingRefund; invoice: BillingInvoice }>(route('panel.billing.invoices.refunds.store', { invoice }), {
    payment: input.payment,
    amount_paisa: input.amountPaisa ?? null,
    reason_code: input.reasonCode,
    note: input.note ?? null,
    auto_process: input.autoProcess ?? true,
  });

  return data;
}

export async function processRefund(invoice: string, refund: number, reject = false, note?: string | null): Promise<{ refund: BillingRefund; invoice: BillingInvoice }> {
  const { data } = await http.post<{ refund: BillingRefund; invoice: BillingInvoice }>(route('panel.billing.invoices.refunds.process', { invoice, refund }), { reject, note: note ?? null });

  return data;
}

export interface PatientDues {
  patient: { public_id: string; name: string };
  due_paisa: number;
  invoices: Array<{ public_id: string; number: string; status: InvoiceStatus; total_paisa: number; paid_paisa: number; due_paisa: number; issued_at: string | null }>;
}

/** The outstanding balance the desk board and the patient record show (BRIEF §5.I "due tracking"). */
export async function patientDues(patient: string, signal?: AbortSignal): Promise<PatientDues> {
  const { data } = await http.get<PatientDues>(route('panel.billing.patients.dues', { patient }), { signal });

  return data;
}

export async function refundEligibility(invoice: string, byPatient = false, signal?: AbortSignal): Promise<BillingRefundEligibility> {
  const { data } = await http.get<BillingRefundEligibility>(route('panel.billing.invoices.refunds.eligibility', { invoice }), { params: { by_patient: byPatient ? 1 : 0 }, signal });

  return data;
}
