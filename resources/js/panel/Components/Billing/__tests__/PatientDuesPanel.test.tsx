// The panel is mounted on two screens owned by other modules (the reception board and the patient record), so
// what is tested here is the contract those screens rely on: it renders nothing at all when there is nothing to
// act on, and the outstanding amount plus one link per unpaid invoice when there is.
import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { PatientDuesPanel } from '@panel/Components/Billing/PatientDuesPanel';
import type { PatientDues } from '@panel/api/billing';

const patientDues = vi.fn<(patient: string, signal?: AbortSignal) => Promise<PatientDues>>();

vi.mock('@panel/api/billing', () => ({ patientDues: (p: string, s?: AbortSignal) => patientDues(p, s) }));
vi.mock('@shared/routes', () => ({ route: (name: string) => `/${name}` }));

function dues(duePaisa: number, invoices: PatientDues['invoices'] = []): PatientDues {
  return { patient: { public_id: 'p1', name: 'রহিমা' }, due_paisa: duePaisa, invoices };
}

describe('PatientDuesPanel', () => {
  beforeEach(() => patientDues.mockReset());

  it('renders nothing without a patient and never calls the endpoint', () => {
    const { container } = render(<PatientDuesPanel patient={null} />);

    expect(container).toBeEmptyDOMElement();
    expect(patientDues).not.toHaveBeenCalled();
  });

  it('renders nothing when the patient owes nothing', async () => {
    patientDues.mockResolvedValue(dues(0));
    const { container } = render(<PatientDuesPanel patient="p1" />);

    await waitFor(() => expect(patientDues).toHaveBeenCalledWith('p1', expect.anything()));
    await waitFor(() => expect(container).toBeEmptyDOMElement());
  });

  it('shows the outstanding amount and one link per unpaid invoice', async () => {
    patientDues.mockResolvedValue(dues(50000, [
      { public_id: 'i1', number: 'INV-1', status: 'issued', total_paisa: 80000, paid_paisa: 30000, due_paisa: 50000, issued_at: null },
    ]));
    render(<PatientDuesPanel patient="p1" />);

    expect(await screen.findByText('INV-1')).toBeInTheDocument();
    expect(screen.getByText('Outstanding: ৳500.00')).toBeInTheDocument();
  });
});
