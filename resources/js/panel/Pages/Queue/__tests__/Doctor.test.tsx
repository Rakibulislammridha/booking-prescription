// "Today's session" — the doctor's whole session on one page (BRIEF §5.E/§5.G):
//
//   • the flagship link: the serial in the chamber leads into the prescription writer without anyone typing a URL
//     ("nothing more than two clicks deep"). Prescribe is offered exactly when there is a serial in consultation
//     AND this user may write, through `panel.prescription.visits.start` → `writer_url` — the same door
//     Prescription/Show and the telemedicine console use.
//   • the roster: every patient of the session in serial-number order with sex, age and the compounder's vitals,
//     each row offering only the action its state allows (call this patient / start / prescribe / view / print).
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { ThemeProvider } from '@mui/material/styles';
import { I18nextProvider } from 'react-i18next';
import { theme } from '@panel/theme';
import { i18n } from '@shared/i18n';
import { setZiggy } from '@shared/routes';
import type { QueueSerial, QueueState } from '@shared/realtime/types';
import type { QueueSessionRow, SerialStatus } from '@shared/types/models';
import type { SharedProps } from '@shared/types/inertia';
import Doctor from '../Doctor';

const startVisit = vi.fn<(serial: string) => Promise<{ visit: unknown; writer_url: string }>>();
const serialAction = vi.fn<(serial: string, action: string) => Promise<unknown>>();
const fetchRoster = vi.fn<() => Promise<{ session_id: string; version: number; roster: Record<string, QueueSessionRow> }>>();
const visit = vi.fn<(url: string) => void>();

vi.mock('@panel/api/prescription', () => ({ startVisit: (s: string) => startVisit(s) }));
vi.mock('@panel/api/serials', () => ({ callNext: vi.fn(), delaySession: vi.fn(), serialAction: (s: string, a: string) => serialAction(s, a) }));
vi.mock('@panel/api/queue', () => ({ fetchRoster: () => fetchRoster() }));
vi.mock('@inertiajs/react', async (importOriginal) => ({ ...(await importOriginal<typeof import('@inertiajs/react')>()), router: { visit: (u: string) => visit(u) } }));
// The shell (and its PWA registration) and the live feed are not what is under test: the page gets its initial
// state straight from the props, exactly as it does before the socket connects.
vi.mock('@panel/Layouts/PanelLayout', () => ({ PanelLayout: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock('@shared/realtime/useQueueState', () => ({ useQueueState: ({ initial }: { initial: QueueState | null }) => ({ state: initial, mode: 'online', updatedAt: null }) }));
vi.mock('@shared/realtime/useChannel', () => ({ useChannel: () => undefined }));

// The roster's View / Print reach the Prescription module by name, so the page needs a Ziggy group like any other.
const ROUTES: Record<string, string> = {
  'panel.prescription.prescriptions.show': 'panel/prescriptions/{prescription}',
  'panel.prescription.print': 'panel/prescriptions/{prescription}/print',
  'panel.prescription.writer': 'panel/visits/{visit}/prescribe',
};

setZiggy({
  url: 'http://demo.test', port: null, defaults: {},
  routes: Object.fromEntries(Object.entries(ROUTES).map(([name, uri]) => [name, { uri, methods: ['GET'] }])),
});

const shared: SharedProps = {
  surface: 'panel',
  auth: { guard: 'web', user: { id: 1, name: 'Dr Rahman', roles: ['doctor'], permissions: [], doctor_id: 1 }, impersonating: false },
  tenant: null, branch: null, branches: [], today_session: null, locale: 'en',
  flash: { success: null, error: null, warning: null, info: null },
  features: {}, ziggy: { url: 'http://demo.test', port: null, defaults: {}, routes: {} }, csrf_token: 'x',
  app: { name: 'bp', env: 'testing', version: '1', reverb: { key: 'k', host: 'localhost', port: 8080, scheme: 'http' } },
  errors: {},
};

const code = (n: number): string => `A-${String(n).padStart(3, '0')}`;
const serial = (id: string, s: QueueSerial['s'], n: number): QueueSerial => ({ id, c: code(n), n, p: n, s, eta: null, ahead: 0 });

/** One roster row; `vitals` defaults to a full set so a row without them has to say so deliberately. */
function row(id: string, n: number, status: SerialStatus, over: Partial<QueueSessionRow> = {}): QueueSessionRow {
  return {
    serial_id: id, code: code(n), number: n, status, priority: 'normal',
    called_at: null, consultation_started_at: null,
    patient: { name: `Patient ${n}`, age_text: '41y', sex: 'female', patient_code: `P-000${n}` },
    vitals: { bp_systolic: 120, bp_diastolic: 80, pulse_bpm: 88, temperature_c: 38, spo2_percent: 97, weight_kg: 60, recorded_at: '2026-09-09T03:00:00Z' },
    visit_id: `vis_${n}`, prescription: null,
    ...over,
  };
}

function state(serials: QueueSerial[]): QueueState {
  const inChamber = serials.find((x) => x.s === 'i') ?? null;
  return {
    v: 1,
    session: {
      id: 'ses_1', code: 'A', date: '2026-09-09', status: 'running', mode: 'serial', planned_start_at: '2026-09-09T03:00:00Z', expected_start_at: '2026-09-09T03:00:00Z', delay_minutes: 0,
      doctor: { id: 'doc_1', slug: 'dr-rahman', name: 'Dr Rahman', name_bn: null, room: 'Room 3' }, branch: { id: 'br_1', name: 'Main' },
    },
    now_serving: inChamber ? { id: inChamber.id, c: inChamber.c, n: inChamber.n, called_at: '2026-09-09T03:05:00Z' } : null,
    last_called: [],
    counts: { booked: 2, checked_in: serials.filter((x) => x.s === 'c').length, in_consultation: inChamber ? 1 : 0, completed: 3, no_show: 1, cancelled: 0, postponed: 0, waiting: 2 + serials.filter((x) => x.s === 'c').length },
    avg_consult_seconds: 300,
    eta_confidence: 'normal',
    serials,
    updated_at: '2026-09-09T03:05:00Z',
    version: 3,
  };
}

type Can = { call_next: boolean; delay: boolean; prescribe: boolean };

function show(serials: QueueSerial[], can: Can = { call_next: true, delay: true, prescribe: true }, roster: QueueSessionRow[] = [row('ser_2', 2, 'in_consultation')]) {
  return render(
    <I18nextProvider i18n={i18n}><ThemeProvider theme={theme}>
      <Doctor
        tenant_public_id={null}
        doctor_channel={null}
        doctor={{ public_id: 'doc_1', slug: 'dr-rahman', name: 'Dr Rahman', name_bn: null, room: 'Room 3' }}
        date="2026-09-09"
        session_id="ses_1"
        state={state(serials)}
        sessions={[{ public_id: 'ses_1', code: 'A', status: 'running', planned_start_at: '2026-09-09T03:00:00Z', planned_end_at: '2026-09-09T07:00:00Z', delay_minutes: 0 }]}
        roster={Object.fromEntries(roster.map((r) => [r.serial_id, r]))}
        notice={null}
        can={can}
        {...shared}
      />
    </ThemeProvider></I18nextProvider>,
  );
}

describe('Queue/Doctor — Prescribe', () => {
  beforeEach(() => { startVisit.mockReset(); visit.mockReset(); serialAction.mockReset(); fetchRoster.mockReset(); });

  it('offers Prescribe for the serial in consultation and opens its writer through visits.start', async () => {
    startVisit.mockResolvedValue({ visit: {}, writer_url: '/panel/visits/vis_9/prescribe' });
    show([serial('ser_2', 'i', 2), serial('ser_3', 'c', 3)]);

    expect(screen.getByTestId('now-serving')).toHaveTextContent('A-002');
    const button = screen.getByTestId('prescribe');
    expect(button).toHaveTextContent('Prescribe');

    fireEvent.click(button);

    await waitFor(() => expect(startVisit).toHaveBeenCalledWith('ser_2'));
    await waitFor(() => expect(visit).toHaveBeenCalledWith('/panel/visits/vis_9/prescribe'));
  });

  it('P is the keyboard way in', async () => {
    startVisit.mockResolvedValue({ visit: {}, writer_url: '/panel/visits/vis_9/prescribe' });
    show([serial('ser_2', 'i', 2)]);

    fireEvent.keyDown(window, { key: 'p' });

    await waitFor(() => expect(visit).toHaveBeenCalledWith('/panel/visits/vis_9/prescribe'));
  });

  it('is absent while nobody is in the chamber', () => {
    show([serial('ser_3', 'c', 3), serial('ser_4', 'b', 4)], undefined, [row('ser_3', 3, 'checked_in'), row('ser_4', 4, 'booked')]);

    expect(screen.getByTestId('now-serving')).toHaveTextContent('—');
    expect(screen.queryByTestId('prescribe')).not.toBeInTheDocument();

    fireEvent.keyDown(window, { key: 'p' });
    expect(startVisit).not.toHaveBeenCalled();
  });

  it('is absent for an operator who may call next but not write', () => {
    show([serial('ser_2', 'i', 2)], { call_next: true, delay: false, prescribe: false });

    expect(screen.getByTestId('now-serving')).toHaveTextContent('A-002');
    expect(screen.queryByTestId('prescribe')).not.toBeInTheDocument();

    fireEvent.keyDown(window, { key: 'p' });
    expect(startVisit).not.toHaveBeenCalled();
  });

  it('names the patient in the chamber with sex, age and code', () => {
    show([serial('ser_2', 'i', 2)]);

    expect(screen.getByTestId('now-serving-card')).toHaveTextContent('Patient 2 · Female · 41y · P-0002');
  });
});

describe('Queue/Doctor — the session header', () => {
  it('shows the session, its hours, the room and every count', () => {
    show([serial('ser_2', 'i', 2), serial('ser_3', 'c', 3)]);

    const header = screen.getByTestId('session-header');
    expect(header).toHaveTextContent('Session A');
    expect(header).toHaveTextContent('Room 3');
    expect(within(header).getByTestId('session-status')).toHaveTextContent('Running');
    expect(within(header).getByTestId('count-booked')).toHaveTextContent('2Booked');
    expect(within(header).getByTestId('count-checked_in')).toHaveTextContent('1Checked in');
    expect(within(header).getByTestId('count-in_consultation')).toHaveTextContent('1In consultation');
    expect(within(header).getByTestId('count-completed')).toHaveTextContent('3Completed');
    expect(within(header).getByTestId('count-no_show')).toHaveTextContent('1No-show');
    expect(within(header).getByTestId('count-remaining')).toHaveTextContent('3Remaining');
  });
});

describe('Queue/Doctor — the session roster', () => {
  beforeEach(() => { startVisit.mockReset(); visit.mockReset(); serialAction.mockReset(); fetchRoster.mockReset(); });

  const wholeSession = [
    row('ser_4', 4, 'booked'),
    row('ser_1', 1, 'completed', { prescription: { id: 'rx_1', status: 'issued' } }),
    row('ser_3', 3, 'checked_in'),
    row('ser_2', 2, 'in_consultation'),
  ];

  it('lists every patient of the session in serial-number order with sex, age and vitals', () => {
    show([serial('ser_2', 'i', 2), serial('ser_3', 'c', 3), serial('ser_4', 'b', 4)], undefined, wholeSession);

    const codes = screen.getAllByTestId(/^roster-row-/).map((r) => r.querySelector('td')?.textContent);
    expect(codes).toEqual(['A-001', 'A-002', 'A-003', 'A-004']);

    const chamber = screen.getByTestId('roster-row-ser_2');
    expect(chamber).toHaveTextContent('Patient 2');
    expect(chamber).toHaveTextContent('P-0002');
    expect(chamber).toHaveTextContent('Female');
    expect(chamber).toHaveTextContent('41y');
    expect(chamber).toHaveTextContent('In consultation');
    // BP · pulse · temperature in °F (the row carries °C) · SpO2 · weight
    expect(chamber).toHaveTextContent('BP 120/80 · P 88 · 100.4 °F · SpO₂ 97% · 60 kg');
  });

  it('says so for a patient whose vitals have not been recorded', () => {
    show([serial('ser_3', 'c', 3)], undefined, [row('ser_3', 3, 'checked_in', { vitals: null })]);

    expect(screen.getByTestId('roster-row-ser_3')).toHaveTextContent('No vitals');
  });

  it('offers exactly the action each row state allows', () => {
    show([serial('ser_2', 'i', 2), serial('ser_3', 'c', 3), serial('ser_4', 'b', 4)], undefined, wholeSession);

    // checked in → call this patient, and nothing else
    expect(screen.getByTestId('call-ser_3')).toHaveTextContent('Call this patient');
    expect(screen.queryByTestId('prescribe-ser_3')).not.toBeInTheDocument();
    expect(screen.queryByTestId('start-ser_3')).not.toBeInTheDocument();

    // in the chamber → start + prescribe, never "call this patient" again
    expect(screen.getByTestId('start-ser_2')).toBeInTheDocument();
    expect(screen.getByTestId('prescribe-ser_2')).toBeInTheDocument();
    expect(screen.queryByTestId('call-ser_2')).not.toBeInTheDocument();

    // issued → view + print, and no way back into the writer from the row
    expect(screen.getByTestId('view-ser_1')).toHaveAttribute('href', '/panel/prescriptions/rx_1');
    expect(screen.getByTestId('print-ser_1')).toBeInTheDocument();
    expect(screen.queryByTestId('prescribe-ser_1')).not.toBeInTheDocument();

    // still booked (not arrived) → no action at all
    expect(screen.queryByTestId('call-ser_4')).not.toBeInTheDocument();
    expect(screen.queryByTestId('prescribe-ser_4')).not.toBeInTheDocument();
  });

  it('calls one specific patient through the serial engine (CallSerial), not call-next', async () => {
    serialAction.mockResolvedValue({});
    fetchRoster.mockResolvedValue({ session_id: 'ses_1', version: 4, roster: {} });
    show([serial('ser_2', 'i', 2), serial('ser_3', 'c', 3), serial('ser_4', 'b', 4)], undefined, wholeSession);

    fireEvent.click(screen.getByTestId('call-ser_3'));

    await waitFor(() => expect(serialAction).toHaveBeenCalledWith('ser_3', 'call'));
  });

  // `start` stamps consultation_started_at and nothing else: no status change, so no queue version bump and no
  // frame to refresh on (SERIAL_ENGINE §6.4). The row has to re-read the roster itself or it never updates.
  it('re-reads the roster after a row action, including Start', async () => {
    serialAction.mockResolvedValue({});
    fetchRoster.mockResolvedValue({ session_id: 'ses_1', version: 4, roster: {} });
    show([serial('ser_2', 'i', 2)], undefined, wholeSession);

    fireEvent.click(screen.getByTestId('start-ser_2'));

    await waitFor(() => expect(serialAction).toHaveBeenCalledWith('ser_2', 'start'));
    await waitFor(() => expect(fetchRoster).toHaveBeenCalled());
  });

  it('opens the patient own writer from their row', async () => {
    startVisit.mockResolvedValue({ visit: {}, writer_url: '/panel/visits/vis_2/prescribe' });
    show([serial('ser_2', 'i', 2)], undefined, wholeSession);

    fireEvent.click(screen.getByTestId('prescribe-ser_2'));

    await waitFor(() => expect(startVisit).toHaveBeenCalledWith('ser_2'));
    await waitFor(() => expect(visit).toHaveBeenCalledWith('/panel/visits/vis_2/prescribe'));
  });

  it('offers no per-row call to an operator without queue.call-next', () => {
    show([serial('ser_3', 'c', 3)], { call_next: false, delay: false, prescribe: false }, [row('ser_3', 3, 'checked_in')]);

    expect(screen.getByTestId('roster-row-ser_3')).toBeInTheDocument();
    expect(screen.queryByTestId('call-ser_3')).not.toBeInTheDocument();
  });
});
