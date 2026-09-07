import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { CollectPaymentDialog } from '../CollectPaymentDialog';
import type { BillingInvoice } from '@shared/types/models';

function invoice(overrides: Partial<BillingInvoice> = {}): BillingInvoice {
  return {
    public_id: '01J000000000000000000000IN',
    number: 'INV-2026-000001',
    status: 'issued',
    subtotal: { paisa: 80000, formatted: '৳800.00' },
    discount: { paisa: 0, formatted: '৳0.00' },
    coupon_discount: { paisa: 0, formatted: '৳0.00' },
    vat: { paisa: 0, formatted: '৳0.00' },
    total: { paisa: 80000, formatted: '৳800.00' },
    paid: { paisa: 0, formatted: '৳0.00' },
    due: { paisa: 80000, formatted: '৳800.00' },
    subtotal_paisa: 80000,
    discount_paisa: 0,
    coupon_discount_paisa: 0,
    vat_paisa: 0,
    total_paisa: 80000,
    paid_paisa: 0,
    due_paisa: 80000,
    issued_at: '2026-09-07T03:00:00Z',
    paid_at: null,
    voided_at: null,
    void_reason: null,
    notes: null,
    ...overrides,
  };
}

describe('CollectPaymentDialog', () => {
  it('defaults to the outstanding amount and submits it in paisa, never in taka floats', () => {
    const onCollect = vi.fn();
    render(<CollectPaymentDialog open invoice={invoice()} busy={false} error={null} onClose={vi.fn()} onCollect={onCollect} />);

    fireEvent.click(screen.getByRole('button', { name: 'Collect' }));

    expect(onCollect).toHaveBeenCalledWith(80000, 'cash', null);
  });

  it('parses a decimal amount to exact paisa', () => {
    const onCollect = vi.fn();
    render(<CollectPaymentDialog open invoice={invoice()} busy={false} error={null} onClose={vi.fn()} onCollect={onCollect} />);

    fireEvent.change(screen.getByLabelText('Amount (৳)'), { target: { value: '312.45' } });
    fireEvent.click(screen.getByRole('button', { name: 'Collect' }));

    expect(onCollect).toHaveBeenCalledWith(31245, 'cash', null);
  });

  it('refuses an amount above the outstanding balance, and zero', () => {
    const onCollect = vi.fn();
    render(<CollectPaymentDialog open invoice={invoice()} busy={false} error={null} onClose={vi.fn()} onCollect={onCollect} />);
    const input = screen.getByLabelText('Amount (৳)');

    fireEvent.change(input, { target: { value: '900' } });
    expect(screen.getByRole('button', { name: 'Collect' })).toBeDisabled();

    fireEvent.change(input, { target: { value: '0' } });
    expect(screen.getByRole('button', { name: 'Collect' })).toBeDisabled();

    fireEvent.change(input, { target: { value: '800' } });
    expect(screen.getByRole('button', { name: 'Collect' })).toBeEnabled();

    expect(onCollect).not.toHaveBeenCalled();
  });

  it('submits on Enter and stays inert while a request is in flight', () => {
    const onCollect = vi.fn();
    const { rerender } = render(<CollectPaymentDialog open invoice={invoice()} busy={false} error={null} onClose={vi.fn()} onCollect={onCollect} />);

    fireEvent.keyDown(screen.getByLabelText('Amount (৳)'), { key: 'Enter' });
    expect(onCollect).toHaveBeenCalledTimes(1);

    rerender(<CollectPaymentDialog open invoice={invoice()} busy error={null} onClose={vi.fn()} onCollect={onCollect} />);
    fireEvent.keyDown(screen.getByLabelText('Amount (৳)'), { key: 'Enter' });
    expect(onCollect).toHaveBeenCalledTimes(1);
  });

  it('shows the server error rather than swallowing it', () => {
    render(<CollectPaymentDialog open invoice={invoice()} busy={false} error="৳900.00 is more than the ৳800.00 still owed" onClose={vi.fn()} onCollect={vi.fn()} />);

    expect(screen.getByText('৳900.00 is more than the ৳800.00 still owed')).toBeInTheDocument();
  });
});
