import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { RefundDialog } from '../RefundDialog';
import type { BillingPayment } from '@shared/types/models';

function payment(refundable = 50000): BillingPayment {
  return {
    public_id: '01J000000000000000000000PY',
    receipt_number: 'RCT-2026-000001',
    method: 'cash',
    status: 'succeeded',
    amount: { paisa: 80000, formatted: '৳800.00' },
    refunded: { paisa: 30000, formatted: '৳300.00' },
    amount_paisa: 80000,
    refunded_paisa: 30000,
    refundable_paisa: refundable,
    gateway: null,
    gateway_txn_id: null,
    paid_at: '2026-09-07T04:00:00Z',
    failed_reason: null,
  };
}

describe('RefundDialog', () => {
  it('defaults to everything still refundable and always sends a reason code', () => {
    const onRefund = vi.fn();
    render(<RefundDialog open payment={payment()} busy={false} error={null} explanation={null} onClose={vi.fn()} onRefund={onRefund} />);

    fireEvent.click(screen.getByRole('button', { name: 'Refund' }));

    expect(onRefund).toHaveBeenCalledWith(50000, 'patient_cancelled', null);
  });

  it('cannot refund more than is left on the payment', () => {
    render(<RefundDialog open payment={payment()} busy={false} error={null} explanation={null} onClose={vi.fn()} onRefund={vi.fn()} />);

    fireEvent.change(screen.getByLabelText('Amount (৳)'), { target: { value: '800' } });
    expect(screen.getByRole('button', { name: 'Refund' })).toBeDisabled();

    fireEvent.change(screen.getByLabelText('Amount (৳)'), { target: { value: '500' } });
    expect(screen.getByRole('button', { name: 'Refund' })).toBeEnabled();
  });

  it('surfaces the eligibility explanation from the server', () => {
    render(<RefundDialog open payment={payment()} busy={false} error={null} explanation="The clinic cancelled, so the fee is returned in full" onClose={vi.fn()} onRefund={vi.fn()} />);

    expect(screen.getByText('The clinic cancelled, so the fee is returned in full')).toBeInTheDocument();
  });
});
