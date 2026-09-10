// The card a clinic's page drops in: it must render the subscription facts, offer "record payment" only on an
// invoice that can take money, and — when it does — prefill the amount due in taka (from integer paisa) and refuse
// to submit until a reference is typed, because the reference is what makes the payment idempotent.
import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { ThemeProvider } from '@mui/material/styles';
import { I18nextProvider } from 'react-i18next';
import { TenantBillingCard } from '../TenantBillingCard';
import type { BillingInvoiceRow, TenantBilling } from '../Billing/types';
import { theme } from '@panel/theme';
import { i18n, initI18n } from '@shared/i18n';
import { setZiggy } from '@shared/routes';

initI18n('en');

const ROUTES = [
  'super.billing.invoices.store', 'super.billing.invoices.issue', 'super.billing.invoices.void', 'super.billing.invoices.pay',
  'super.billing.invoices.print', 'super.billing.invoices.pdf', 'super.billing.invoices.index', 'super.billing.subscriptions.plan',
  'super.billing.dunning.run', 'super.tenants.show',
];
setZiggy({
  url: 'http://super.bp.test', port: null, defaults: {},
  routes: Object.fromEntries(ROUTES.map((name) => [name, { uri: name.replace(/\./g, '/').replace('super/', '') + '/{invoice?}/{tenant?}', methods: ['GET', 'POST'] }])),
});

vi.mock('@inertiajs/react', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@inertiajs/react')>()),
  router: { post: vi.fn(), get: vi.fn(), visit: vi.fn() },
}));

function invoice(overrides: Partial<BillingInvoiceRow>): BillingInvoiceRow {
  return {
    public_id: '01J0000000000000000000INV1', number: 'SI-2026-000001', tenant: { public_id: '01JT', name: 'Demo Hospital', slug: 'demo', status: 'active' },
    status: 'issued', is_past_due: false, period_start: '2026-09-01', period_end: '2026-10-01', subtotal_paisa: 400000, discount_paisa: 0, tax_paisa: 0,
    total_paisa: 400000, paid_paisa: 0, due_paisa: 400000, issued_at: '2026-09-01T03:00:00Z', due_at: '2026-09-08T03:00:00Z', paid_at: null, voided_at: null,
    dunning_step: 0, line_items: [], ...overrides,
  };
}

const billing: TenantBilling = {
  subscription: {
    id: 1, status: 'active', plan_code: 'pro', plan_name: 'Pro', billing_cycle: 'monthly', price_paisa: 400000,
    current_period_start: '2026-09-01T03:00:00Z', current_period_end: '2026-10-01T03:00:00Z', trial_ends_at: null, grace_until: null,
    auto_renew: true, cancel_at_period_end: false, feature_overrides: null,
  },
  addons: [],
  arrears_paisa: 250050,
  arrears_invoices: 1,
  next_invoice_at: '2026-10-01T03:00:00Z',
  invoices: [
    invoice({ public_id: 'INVOPEN', number: 'SI-2026-000002', total_paisa: 250050, due_paisa: 250050 }),
    invoice({ public_id: 'INVPAID', number: 'SI-2026-000001', status: 'paid', paid_paisa: 400000, due_paisa: 0, paid_at: '2026-09-03T03:00:00Z' }),
  ],
  payments: [],
  dunning: [],
};

function show() {
  return render(
    <I18nextProvider i18n={i18n}><ThemeProvider theme={theme}>
      <TenantBillingCard tenant={{ public_id: '01JT', name: 'Demo Hospital' }} billing={billing} plans={[]} />
    </ThemeProvider></I18nextProvider>,
  );
}

describe('TenantBillingCard', () => {
  it('shows the subscription, the arrears and one "record payment" button per payable invoice', () => {
    show();

    expect(screen.getByText('Pro')).toBeInTheDocument();
    expect(screen.getByText('৳2,500.50 outstanding across 1 invoice(s)')).toBeInTheDocument();
    expect(screen.getAllByRole('button', { name: 'Record payment' })).toHaveLength(1);
    expect(screen.getByText('SI-2026-000001')).toBeInTheDocument();
    expect(screen.getAllByRole('link', { name: 'Print' })).toHaveLength(2);
  });

  it('prefills the amount due in taka and refuses to submit without a reference', () => {
    show();
    fireEvent.click(screen.getByRole('button', { name: 'Record payment' }));

    const dialog = screen.getByRole('dialog');
    const amount = within(dialog).getByLabelText('Amount in taka');
    expect(amount).toHaveValue('2500.50');
    expect(within(dialog).getByText('Will be recorded as ৳2,500.50')).toBeInTheDocument();

    const submit = within(dialog).getByRole('button', { name: 'Record payment' });
    expect(submit).toBeDisabled();

    fireEvent.change(within(dialog).getByLabelText(/Reference/), { target: { value: 'DBBL-77123' } });
    expect(submit).toBeEnabled();

    // More than is due is refused before it reaches the server.
    fireEvent.change(amount, { target: { value: '2500.51' } });
    expect(submit).toBeDisabled();
    expect(within(dialog).getByText('More than is due on this invoice.')).toBeInTheDocument();

    fireEvent.change(amount, { target: { value: '৳1,000.25' } });
    expect(submit).toBeEnabled();
    expect(within(dialog).getByText('Will be recorded as ৳1,000.25')).toBeInTheDocument();
  });
});
