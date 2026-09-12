// The post-issue bar (BRIEF §5.G): after Issue, the doctor is never stranded on the Show page. Print and PDF are
// always there; when the visit belongs to one of today's open sessions the doctor may drive, so are "Call next
// patient" — one click that calls next AND lands on that patient's writer — and "Back to today's session".
// The engine's refusal (a serial still in consultation) is shown, never swallowed.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { ThemeProvider } from '@mui/material/styles';
import { I18nextProvider } from 'react-i18next';
import { theme } from '@panel/theme';
import { i18n } from '@shared/i18n';
import { ApiError } from '@shared/apiError';
import type { CallNextVisitResponse, PrescriptionQueueLink } from '@shared/types/models';
import { PostIssueBar } from '../PostIssueBar';

const callNextVisit = vi.fn<(session: string) => Promise<CallNextVisitResponse>>();
const visit = vi.fn<(...args: unknown[]) => void>();

vi.mock('@panel/api/queue', () => ({ callNextVisit: (s: string) => callNextVisit(s) }));
// …(...args) so a one-argument visit is recorded as one argument: the difference between "go to the writer" and
// "go back to the session WITH a notice" is that second argument.
vi.mock('@inertiajs/react', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@inertiajs/react')>()),
  router: { visit: (...args: unknown[]) => visit(...args) },
}));

const queue: PrescriptionQueueLink = { session_id: 'ses_1', code: 'B', session_url: '/panel/queue/doctor?session=ses_1', can_call_next: true };

const called: CallNextVisitResponse['called'] = {
  public_id: 'ser_7', display_code: 'B-007', number: 7, position: 7, status: 'in_consultation', priority: 'normal', source: 'counter', pool: 'counter',
  patient_id: 3, patient: null, appointment_id: null, slot_start_at: null, booked_at: '2026-09-10T03:00:00Z', checked_in_at: '2026-09-10T03:10:00Z',
  called_at: '2026-09-10T03:20:00Z', completed_at: null, no_show_at: null, cancelled_at: null, cancel_reason_code: null, passed_count: 0, skip_count: 0, eta: null,
};

const onPrint = vi.fn();
const onPdf = vi.fn();

function show(link: PrescriptionQueueLink | null = queue) {
  return render(
    <I18nextProvider i18n={i18n}><ThemeProvider theme={theme}>
      <PostIssueBar queue={link} onPrint={onPrint} onPdf={onPdf} />
    </ThemeProvider></I18nextProvider>,
  );
}

describe('PostIssueBar', () => {
  beforeEach(() => { callNextVisit.mockReset(); visit.mockReset(); onPrint.mockReset(); onPdf.mockReset(); });

  it('offers print, PDF, call next and back to the session', () => {
    show();

    expect(screen.getByTestId('post-issue-print')).toHaveTextContent('Print');
    expect(screen.getByTestId('post-issue-pdf')).toHaveTextContent('Download PDF');
    expect(screen.getByTestId('post-issue-call-next')).toHaveTextContent('Call next patient');
    expect(screen.getByTestId('post-issue-back')).toHaveAttribute('href', '/panel/queue/doctor?session=ses_1');

    fireEvent.click(screen.getByTestId('post-issue-print'));
    fireEvent.click(screen.getByTestId('post-issue-pdf'));
    expect(onPrint).toHaveBeenCalledOnce();
    expect(onPdf).toHaveBeenCalledOnce();
  });

  it('keeps print and PDF but drops the queue actions for a visit outside today (no session link)', () => {
    show(null);

    expect(screen.getByTestId('post-issue-print')).toBeInTheDocument();
    expect(screen.queryByTestId('post-issue-call-next')).not.toBeInTheDocument();
    expect(screen.queryByTestId('post-issue-back')).not.toBeInTheDocument();
  });

  it('offers the way back but no call to someone who may not drive the queue', () => {
    show({ ...queue, can_call_next: false });

    expect(screen.queryByTestId('post-issue-call-next')).not.toBeInTheDocument();
    expect(screen.getByTestId('post-issue-back')).toBeInTheDocument();
  });

  it('calls the next patient and lands straight on their writer', async () => {
    callNextVisit.mockResolvedValue({ called, visit: null, writer_url: '/panel/visits/vis_7/prescribe', waiting_booked: 2 });
    show();

    fireEvent.click(screen.getByTestId('post-issue-call-next'));

    await waitFor(() => expect(callNextVisit).toHaveBeenCalledWith('ses_1'));
    await waitFor(() => expect(visit).toHaveBeenCalledWith('/panel/visits/vis_7/prescribe'));
    // one click, one call: the button is busy while the request is in flight
    expect(screen.getByTestId('post-issue-call-next')).toBeDisabled();
  });

  it('goes back to the session with a notice when no one is waiting', async () => {
    callNextVisit.mockResolvedValue({ called: null, visit: null, writer_url: null, waiting_booked: 0 });
    show();

    fireEvent.click(screen.getByTestId('post-issue-call-next'));

    await waitFor(() => expect(visit).toHaveBeenCalledWith('/panel/queue/doctor?session=ses_1', { data: { notice: 'no_one_waiting' } }));
  });

  it('falls back to the session page when the caller may not open the writer', async () => {
    callNextVisit.mockResolvedValue({ called, visit: null, writer_url: null, waiting_booked: 0 });
    show();

    fireEvent.click(screen.getByTestId('post-issue-call-next'));

    await waitFor(() => expect(visit).toHaveBeenCalledWith('/panel/queue/doctor?session=ses_1'));
  });

  it('surfaces the engine refusing to call while a patient is still in the chamber', async () => {
    callNextVisit.mockRejectedValue(new ApiError('still in consultation', { status: 409, code: 'queue.chamber_occupied' }));
    show();

    fireEvent.click(screen.getByTestId('post-issue-call-next'));

    const error = await screen.findByTestId('post-issue-error');
    expect(error).toHaveTextContent('A patient is still in consultation');
    expect(visit).not.toHaveBeenCalled();
    // and the doctor may try again once they have completed that patient
    expect(screen.getByTestId('post-issue-call-next')).not.toBeDisabled();
  });
});
