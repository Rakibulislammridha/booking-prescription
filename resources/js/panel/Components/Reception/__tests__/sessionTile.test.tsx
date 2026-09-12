// What the desk actually reads off a session tile, after the product owner's two complaints:
//
//   1. the rows must be in serial-number order — the board used to list them by the engine's queue position, which
//      after a few check-ins and one priority insert reads as random (B-001, B-011, B-012, B-002 …);
//   2. because "Call next" still follows that queue position, the row it would take has to be MARKED, or the desk
//      is misled by the very reordering that made the list legible.
//
// Plus the third thing a compounder needs from the row: the prescription, printed at the desk (BRIEF §5.G.4) —
// offered only when there is an issued one, and never offline (OFFLINE §6.2 keeps clinical output on the server).
import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { SessionTile } from '../SessionTile';
import type { BoardSession, DeskSerial } from '@shared/types/models';

const serial = (number: number, over: Partial<DeskSerial> = {}): DeskSerial => ({
  public_id: `ser_${number}`, display_code: `B-${String(number).padStart(3, '0')}`, number, position: number * 1_000_000,
  status: 'booked', priority: 'normal', source: 'counter', pool: 'counter', patient_id: null, appointment_id: null,
  slot_start_at: null, booked_at: '', checked_in_at: null, called_at: null, completed_at: null, no_show_at: null,
  cancelled_at: null, cancel_reason_code: null, passed_count: 0, skip_count: 0, eta: null,
  patient: { public_id: `pat_${number}`, name: 'রহিমা বেগম', mobile_masked: '017*****21', age_text: null, sex: null, patient_code: '' },
  appointment: null, vitals: null, prescription: null,
  ...over,
});

const session = (serials: DeskSerial[], over: Partial<BoardSession> = {}): BoardSession => ({
  public_id: 'ses_1', code: 'B', date: '2026-09-10', status: 'running', mode: 'serial',
  doctor: { public_id: 'doc_1', slug: 'dr-rahman', name: 'Dr. Md. Abdur Rahman', name_bn: null, room: null },
  planned_start_at: '2026-09-10T03:00:00Z', planned_end_at: '2026-09-10T07:00:00Z', expected_start_at: '2026-09-10T03:00:00Z',
  delay_minutes: 0, now_serving: null, next_serial: null,
  counts: { booked: 0, checked_in: 0, in_consultation: 0, completed: 0, no_show: 0, cancelled: 0, postponed: 0 },
  remaining: { online: 3, counter: 5, buffer: 2, counter_in_blocks: 0, released: 0 },
  fee_new_paisa: 80_000, fee_followup_paisa: 50_000, max_serials: 30, version: 1, serials,
  ...over,
});

const CAN = { issue: true, call_next: true, cancel: true, collect: true, record_vitals: true, print_prescription: true };

function mount(s: BoardSession, over: Partial<React.ComponentProps<typeof SessionTile>> = {}) {
  const onPrintPrescription = vi.fn();
  render(
    <SessionTile
      session={s} mode="online" blockRemaining={0} can={CAN} busy={false}
      onBook={vi.fn()} onCallNext={vi.fn()} onCheckIn={vi.fn()} onCollect={vi.fn()} onPrint={vi.fn()}
      onCancel={vi.fn()} onVitals={vi.fn()} onPrintPrescription={onPrintPrescription} onKiosk={vi.fn()}
      {...over}
    />,
  );
  return { onPrintPrescription };
}

/**
 * The display codes in the order they are rendered down the table. The first cell also holds the row's chips
 * (Next, "offline"), so the code is matched out of it rather than read as the whole cell's text.
 */
const renderedCodes = (): string[] =>
  screen.getAllByRole('row').map((row) => (within(row).getAllByRole('cell')[0]?.textContent ?? '').match(/[A-Z]+-\d+/)?.[0] ?? '');

describe('SessionTile row order', () => {
  it('lists the rows by serial number even when the queue positions are shuffled', () => {
    // Exactly the board from the screenshot: by position it reads B-001, B-011, B-012, B-013, B-021, B-002, B-003, B-014.
    const shuffled = [
      serial(1, { position: 1_000_000 }), serial(11, { position: 2_000_000 }), serial(12, { position: 3_000_000 }),
      serial(13, { position: 4_000_000 }), serial(21, { position: 5_000_000 }), serial(2, { position: 6_000_000 }),
      serial(3, { position: 7_000_000 }), serial(14, { position: 8_000_000 }),
    ];
    mount(session(shuffled));

    expect(renderedCodes()).toEqual(['B-001', 'B-002', 'B-003', 'B-011', 'B-012', 'B-013', 'B-014', 'B-021']);
  });
});

describe('SessionTile Next chip', () => {
  it('marks the checked-in row with the lowest queue position, not the top of the list', () => {
    mount(session([
      serial(1, { status: 'booked', position: 1_000_000 }),
      serial(2, { status: 'checked_in', position: 5_000_000 }),
      serial(3, { status: 'checked_in', position: 3_000_000 }),
    ]));

    const chip = screen.getByTestId('next-to-call');
    expect(chip).toHaveTextContent('Next');
    expect(within(chip.closest('tr') as HTMLElement).getAllByRole('cell')[0]).toHaveTextContent('B-003');
  });

  it('follows a priority insert onto the row that jumped the queue', () => {
    mount(session([
      serial(1, { status: 'checked_in', position: 3_000_000 }),
      serial(2, { status: 'checked_in', position: 4_000_000 }),
      serial(9, { status: 'checked_in', position: 1_500_000, priority: 'emergency' }),
    ]));

    const chip = screen.getByTestId('next-to-call');
    const row = chip.closest('tr') as HTMLElement;
    expect(within(row).getAllByRole('cell')[0]).toHaveTextContent('B-009');
    expect(renderedCodes()).toEqual(['B-001', 'B-002', 'B-009']);   // still last in the list, first in the queue
  });

  it('marks nothing when nobody is checked in, or when the session is closed', () => {
    mount(session([serial(1, { status: 'booked' }), serial(2, { status: 'completed' })]));
    expect(screen.queryByTestId('next-to-call')).toBeNull();

    mount(session([serial(1, { status: 'checked_in' })], { status: 'closed' }));
    expect(screen.queryByTestId('next-to-call')).toBeNull();
  });
});

/**
 * Issuing a prescription completes the serial (CompleteConsultationOnPrescriptionIssued). A row whose sheet has
 * already been printed is a finished patient like any other and is reached through "Show all"; a row whose sheet
 * has NOT been printed is the patient standing at the counter, and stays in the default view (the section below).
 * These drive the printed case through the toggle, the real path for a finished row.
 */
describe('SessionTile prescription print', () => {
  const withRx = serial(1, { status: 'completed', prescription: { public_id: 'rx_1', verification_code: 'A1B2C3D4', version: 1, printed: true } });
  const showAll = (): void => { fireEvent.click(screen.getByRole('button', { name: /Show all/ })); };

  it('offers print and PDF only on a row that has an issued prescription', () => {
    mount(session([withRx, serial(2, { status: 'completed' })]));
    showAll();

    expect(renderedCodes()).toEqual(['B-001', 'B-002']);
    expect(screen.getAllByTestId('print-prescription')).toHaveLength(1);
    expect(screen.getAllByTestId('prescription-pdf')).toHaveLength(1);
    expect(screen.getByTestId('print-prescription')).toBeEnabled();
  });

  it('offers nothing on a row whose prescription is still a draft', () => {
    // A draft never reaches the board as a handle — the server sends `prescription: null` for it.
    mount(session([serial(1, { status: 'completed', prescription: null })]));
    showAll();

    expect(screen.queryByTestId('print-prescription')).toBeNull();
  });

  it('hands the row and the chosen output back to the board', () => {
    const { onPrintPrescription } = mount(session([withRx]));
    showAll();

    fireEvent.click(screen.getByTestId('print-prescription'));
    expect(onPrintPrescription).toHaveBeenCalledWith(expect.objectContaining({ public_id: 'ses_1' }), expect.objectContaining({ public_id: 'ser_1' }), 'print');

    fireEvent.click(screen.getByTestId('prescription-pdf'));
    expect(onPrintPrescription).toHaveBeenLastCalledWith(expect.anything(), expect.anything(), 'pdf');
  });

  it('is disabled offline — the sheet is rendered on the server (OFFLINE §6.2)', () => {
    const { onPrintPrescription } = mount(session([withRx]), { mode: 'offline' });
    showAll();

    expect(screen.getByTestId('print-prescription')).toBeDisabled();
    expect(screen.getByTestId('prescription-pdf')).toBeDisabled();
    fireEvent.click(screen.getByTestId('print-prescription'));
    expect(onPrintPrescription).not.toHaveBeenCalled();
  });

  it('is not offered at all to a role that may not print', () => {
    mount(session([withRx]), { can: { ...CAN, print_prescription: false } });
    showAll();

    expect(screen.queryByTestId('print-prescription')).toBeNull();
    expect(screen.queryByTestId('prescription-pdf')).toBeNull();
  });
});

/**
 * The fix for the friction the desk actually felt: the doctor issues, the serial completes, and the patient who is
 * RIGHT THERE waiting for their printout used to vanish behind "Show all". So the default view is now "active +
 * awaiting print" — and the row has to say why it is there, without pretending the patient is still waiting to be
 * seen: the status chip goes on reading Completed.
 */
describe('SessionTile rows awaiting a printout', () => {
  const awaiting = (number: number) => serial(number, { status: 'completed', prescription: { public_id: `rx_${number}`, verification_code: 'A1B2C3D4', version: 1, printed: false } });
  const printed = (number: number) => serial(number, { status: 'completed', prescription: { public_id: `rx_${number}`, verification_code: 'A1B2C3D4', version: 1, printed: true } });

  it('keeps a completed row in the DEFAULT view while its prescription has never been printed', () => {
    mount(session([serial(1, { status: 'checked_in' }), awaiting(2), serial(3, { status: 'booked' })]));

    expect(renderedCodes()).toEqual(['B-001', 'B-002', 'B-003']);
    expect(screen.getByTestId('print-prescription')).toBeEnabled();
  });

  it('says why the row is still there — and does not claim the patient is still waiting to be seen', () => {
    mount(session([awaiting(2)]));

    const row = screen.getByTestId('awaiting-print').closest('tr') as HTMLElement;
    expect(screen.getByTestId('awaiting-print')).toHaveTextContent('Waiting for printout');
    expect(row).toHaveTextContent('Completed');
  });

  it('drops the row from the default view once the sheet is printed, and keeps it under Show all', () => {
    mount(session([serial(1, { status: 'checked_in' }), printed(2)]));

    expect(renderedCodes()).toEqual(['B-001']);
    expect(screen.queryByTestId('awaiting-print')).toBeNull();

    fireEvent.click(screen.getByRole('button', { name: /Show all/ }));
    expect(renderedCodes()).toEqual(['B-001', 'B-002']);
    expect(screen.queryByTestId('awaiting-print')).toBeNull();
  });

  it('does not keep a completed row with nothing to print, or an unfinished row with one', () => {
    mount(session([serial(1, { status: 'completed' }), serial(2, { status: 'no_show' }), serial(3, { status: 'in_consultation', prescription: { public_id: 'rx_3', verification_code: null, version: 1, printed: false } })]));

    expect(renderedCodes()).toEqual(['B-003']);
    expect(screen.queryByTestId('awaiting-print')).toBeNull();
  });

  it('leaves the "Show all" count meaning the whole list', () => {
    mount(session([serial(1, { status: 'checked_in' }), awaiting(2), printed(3), serial(4, { status: 'no_show' })]));

    expect(renderedCodes()).toEqual(['B-001', 'B-002']);
    expect(screen.getByRole('button', { name: 'Show all (4)' })).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Show all (4)' }));
    expect(renderedCodes()).toEqual(['B-001', 'B-002', 'B-003', 'B-004']);
  });
});
