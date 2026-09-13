// Prescription/Show — who is offered what. The page is deliberately reachable by more people than may act on it:
// `view` is granted by `prescriptions.vitals.record` so the desk can print the doctor's sheet and hand it over
// (BRIEF §5.G.4), while Send is refused to anyone DoctorScope restricts and Amend / Void need
// `prescriptions.write`. Until this wave the page took no `can` prop at all and rendered all three to whoever
// could open it — a compounder met three guaranteed 403s, and so did a receptionist on two of them.
//
// The fixture is cast rather than spelled out: the frozen snapshot's body is SnapshotRenderingTest's subject, and
// what is under test here is only which actions the screen puts in front of which viewer.
import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ThemeProvider } from '@mui/material/styles';
import { I18nextProvider } from 'react-i18next';
import { theme } from '@panel/theme';
import { i18n } from '@shared/i18n';
import { setZiggy } from '@shared/routes';
import type { IssuedPrescription, PrescriptionDraft, VisitRow } from '@shared/types/models';
import type { SharedProps } from '@shared/types/inertia';
import Show from '../Show';

vi.mock('@panel/api/prescription', () => ({ amendPrescription: vi.fn(), sendPrescription: vi.fn(), voidPrescription: vi.fn() }));
vi.mock('@panel/api/queue', () => ({ callNextVisit: vi.fn() }));
// The shell is not what is under test; the page's own actions are.
vi.mock('@panel/Layouts/PanelLayout', () => ({ PanelLayout: ({ children }: { children: React.ReactNode }) => <>{children}</> }));

const ROUTES: Record<string, string> = {
  'panel.prescription.writer': 'panel/visits/{visit}/prescribe',
  'panel.prescription.print': 'panel/prescriptions/{prescription}/print',
  'panel.prescription.pharmacy': 'panel/prescriptions/{prescription}/pharmacy',
  'panel.prescription.pdf': 'panel/prescriptions/{prescription}/pdf',
  'panel.prescription.prescriptions.show': 'panel/prescriptions/{prescription}',
};

setZiggy({
  url: 'http://demo.test', port: null, defaults: {},
  routes: Object.fromEntries(Object.entries(ROUTES).map(([name, uri]) => [name, { uri, methods: ['GET'] }])),
});

const shared: SharedProps = {
  surface: 'panel',
  auth: { guard: 'web', user: { id: 1, name: 'Staff', roles: [], permissions: [], doctor_id: null }, impersonating: false },
  tenant: null, branch: null, branches: [], today_session: null, locale: 'en',
  flash: { success: null, error: null, warning: null, info: null },
  features: {}, ziggy: { url: 'http://demo.test', port: null, defaults: {}, routes: {} }, csrf_token: 'x',
  app: { name: 'bp', env: 'testing', version: '1', reverb: { key: 'k', host: 'localhost', port: 8080, scheme: 'http' } },
  errors: {},
};

type Can = { write: boolean; send: boolean; amend: boolean; void: boolean };

const NONE: Can = { write: false, send: false, amend: false, void: false };

const issued = {
  id: 'rx_1', version: 1, status: 'issued', language: 'bn', verification_code: 'ABC123',
  issued_at: '2026-09-09T04:00:00Z', amend_reason: null, root_id: 'rx_1', supersedes_id: null, visit_id: 'vis_1',
  snapshot: {
    schema: 1,
    prescription: { language: 'bn', verify_url: 'http://demo.test/rx/ABC123' },
    clinic: { name: 'Demo Hospital', branch: { name: 'Main' } },
    doctor: { name: 'Dr Rahman' },
    patient: { name: 'Momena Begum', patient_code: 'P-0001', age_text: '41y', gender: 'female' },
    visit: { date: '2026-09-09', serial: 'A-002', chief_complaints: [], diagnoses: [] },
    items: [], investigations: [], advice: [], referrals: [],
  },
  snapshot_sha256: null, pad_snapshot: null, is_latest: true, superseded_by: null,
  versions: [], pdf_status: 'pending', printed_count: 0, last_printed_at: null, delivered_channels: [], voided: null,
} as unknown as IssuedPrescription;

const draft = { id: 'rx_2', status: 'draft' } as unknown as PrescriptionDraft;
const visit = { id: 'vis_1' } as unknown as VisitRow;

function show(can: Can) {
  return render(
    <I18nextProvider i18n={i18n}><ThemeProvider theme={theme}>
      <Show prescription={issued} queue={null} can={can} {...shared} />
    </ThemeProvider></I18nextProvider>,
  );
}

const action = (name: RegExp | string) => screen.queryByRole('button', { name });

describe('Prescription/Show — the three write actions', () => {
  it('offers Send, Amend and Void to the doctor whose sheet it is', () => {
    show({ write: true, send: true, amend: true, void: true });

    expect(action('Send')).toBeInTheDocument();
    expect(action('Amend')).toBeInTheDocument();
    expect(action('Void')).toBeInTheDocument();
  });

  /**
   * The compounder's version of this screen: everything that prints, nothing that writes or speaks. The Pharmacy
   * view assertion is the other half of the claim — narrowing the actions must not cost the desk the paper, which
   * is the only reason they are on this page.
   */
  it('leaves the desk with the paper and none of the three', () => {
    show(NONE);

    expect(action('Send')).toBeNull();
    expect(action('Amend')).toBeNull();
    expect(action('Void')).toBeNull();
    expect(action('Pharmacy view')).toBeInTheDocument();
    expect(screen.getByTestId('snapshot')).toHaveTextContent('Momena Begum');
  });

  // Each flag is its own answer: a receptionist is unrestricted (so `send` passes) but holds no
  // `prescriptions.write`, which is what Amend and Void are.
  it('reads each flag on its own rather than treating them as one permission', () => {
    show({ ...NONE, send: true });

    expect(action('Send')).toBeInTheDocument();
    expect(action('Amend')).toBeNull();
    expect(action('Void')).toBeNull();
  });
});

describe('Prescription/Show — a draft opened here', () => {
  const showDraft = (can: Can) => render(
    <I18nextProvider i18n={i18n}><ThemeProvider theme={theme}>
      <Show prescription={draft} visit={visit} can={can} {...shared} />
    </ThemeProvider></I18nextProvider>,
  );

  it('sends a prescriber back into the writer', () => {
    showDraft({ ...NONE, write: true });

    expect(screen.getByRole('link', { name: 'Prescription writer' })).toHaveAttribute('href', '/panel/visits/vis_1/prescribe');
  });

  it('offers the desk no way into a writer that would refuse them', () => {
    showDraft(NONE);

    expect(screen.queryByRole('link', { name: 'Prescription writer' })).toBeNull();
    expect(screen.getByText('Draft')).toBeInTheDocument();
  });
});
