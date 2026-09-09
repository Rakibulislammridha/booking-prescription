// The flagship flow's one missing link: from the doctor screen, the serial in the chamber has to lead into the
// prescription writer without anyone typing a URL (BRIEF §5.G: "nothing more than two clicks deep"). Prescribe is
// offered exactly when there is a serial in consultation AND this user may write, and it goes through
// `panel.prescription.visits.start` → `writer_url`, the same door Prescription/Show and the telemedicine console use.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { ThemeProvider } from '@mui/material/styles';
import { I18nextProvider } from 'react-i18next';
import { theme } from '@panel/theme';
import { i18n } from '@shared/i18n';
import type { QueueSerial, QueueState } from '@shared/realtime/types';
import type { SharedProps } from '@shared/types/inertia';
import Doctor from '../Doctor';

const startVisit = vi.fn<(serial: string) => Promise<{ visit: unknown; writer_url: string }>>();
const visit = vi.fn<(url: string) => void>();

vi.mock('@panel/api/prescription', () => ({ startVisit: (s: string) => startVisit(s) }));
vi.mock('@panel/api/serials', () => ({ callNext: vi.fn(), delaySession: vi.fn(), serialAction: vi.fn() }));
vi.mock('@inertiajs/react', async (importOriginal) => ({ ...(await importOriginal<typeof import('@inertiajs/react')>()), router: { visit: (u: string) => visit(u) } }));
// The shell (and its PWA registration) and the live feed are not what is under test: the page gets its initial
// state straight from the props, exactly as it does before the socket connects.
vi.mock('@panel/Layouts/PanelLayout', () => ({ PanelLayout: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock('@shared/realtime/useQueueState', () => ({ useQueueState: ({ initial }: { initial: QueueState | null }) => ({ state: initial, mode: 'online', updatedAt: null }) }));
vi.mock('@shared/realtime/useChannel', () => ({ useChannel: () => undefined }));

const shared: SharedProps = {
  auth: { guard: 'web', user: { id: 1, name: 'Dr Rahman', roles: ['doctor'], permissions: [], doctor_id: 1 }, impersonating: false },
  tenant: null, branch: null, branches: [], locale: 'en',
  flash: { success: null, error: null, warning: null, info: null },
  features: {}, ziggy: { url: 'http://demo.test', port: null, defaults: {}, routes: {} }, csrf_token: 'x',
  app: { name: 'bp', env: 'testing', version: '1', reverb: { key: 'k', host: 'localhost', port: 8080, scheme: 'http' } },
  errors: {},
};

const serial = (id: string, s: QueueSerial['s'], n: number): QueueSerial => ({ id, c: `A-${String(n).padStart(3, '0')}`, n, p: n, s, eta: null, ahead: 0 });

function state(serials: QueueSerial[]): QueueState {
  const inChamber = serials.find((x) => x.s === 'i') ?? null;
  return {
    v: 1,
    session: {
      id: 'ses_1', code: 'A', date: '2026-09-09', status: 'running', mode: 'serial', planned_start_at: '2026-09-09T03:00:00Z', expected_start_at: '2026-09-09T03:00:00Z', delay_minutes: 0,
      doctor: { id: 'doc_1', slug: 'dr-rahman', name: 'Dr Rahman', name_bn: null, room: null }, branch: { id: 'br_1', name: 'Main' },
    },
    now_serving: inChamber ? { id: inChamber.id, c: inChamber.c, n: inChamber.n, called_at: '2026-09-09T03:05:00Z' } : null,
    last_called: [],
    counts: { booked: 0, checked_in: serials.filter((x) => x.s === 'c').length, in_consultation: inChamber ? 1 : 0, completed: 0, no_show: 0, cancelled: 0, postponed: 0, waiting: serials.filter((x) => x.s === 'c').length },
    avg_consult_seconds: 300,
    eta_confidence: 'normal',
    serials,
    updated_at: '2026-09-09T03:05:00Z',
    version: 3,
  };
}

function show(serials: QueueSerial[], can: { call_next: boolean; delay: boolean; prescribe: boolean } = { call_next: true, delay: true, prescribe: true }) {
  return render(
    <I18nextProvider i18n={i18n}><ThemeProvider theme={theme}>
      <Doctor
        tenant_public_id={null}
        doctor_channel={null}
        doctor={{ public_id: 'doc_1', slug: 'dr-rahman', name: 'Dr Rahman', name_bn: null, room: null }}
        date="2026-09-09"
        session_id="ses_1"
        state={state(serials)}
        sessions={[{ public_id: 'ses_1', code: 'A', status: 'running', planned_start_at: '2026-09-09T03:00:00Z', delay_minutes: 0 }]}
        patients={{ ser_2: { name: 'Rahima Begum', age_text: '41y', sex: 'female', patient_code: 'P-0002' } }}
        can={can}
        {...shared}
      />
    </ThemeProvider></I18nextProvider>,
  );
}

describe('Queue/Doctor — Prescribe', () => {
  beforeEach(() => { startVisit.mockReset(); visit.mockReset(); });

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
    show([serial('ser_3', 'c', 3), serial('ser_4', 'b', 4)]);

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
});
