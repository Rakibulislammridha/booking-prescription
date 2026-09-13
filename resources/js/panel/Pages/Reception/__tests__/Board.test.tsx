// The desk, seen from the one seat that does not own the whole branch. A compounder works THIS board — there is no
// second board page — narrowed server-side by DoctorScope to the doctors assigned to them, which means the screen
// they open is legitimately short. So it has to say why: an absent colleague must not read as a cancelled session.
//
// What is proved here is that the page believes the server about it (`doctor_scoped`, DoctorScope's own answer —
// the page used to re-derive the rule from the shared auth roles), that it hands the same answer to the desk hook,
// which uses it to stay off the device cache, and that the narrowing changes WHICH sessions arrive, never how the
// rows inside one are ordered — "serials will be in an ordered list" holds on the filtered board too.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import { ThemeProvider } from '@mui/material/styles';
import { I18nextProvider } from 'react-i18next';
import { theme } from '@panel/theme';
import { i18n } from '@shared/i18n';
import { setZiggy } from '@shared/routes';
import type { ConnectionMode } from '@shared/connection/store';
import type { Board as BoardDoc, BoardSession, DeskSerial } from '@shared/types/models';
import type { SharedProps } from '@shared/types/inertia';
import Board from '../Board';

// The shell, the device cache, the live feed and every endpoint the desk can reach are not what is under test: the
// page is rendered exactly as it paints before Dexie or the socket has said anything, straight off its props.
vi.mock('@panel/Layouts/PanelLayout', () => ({ PanelLayout: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock('@panel/hooks/reception/useDesk', () => ({
  useDesk: (initial: BoardDoc, options: Record<string, unknown>) => { deskOptions = options; return desk(initial); },
  deviceFingerprint: vi.fn(async () => 'fingerprint'),
  APP_VERSION: '1.0.0',
}));
vi.mock('@panel/api/serials', () => ({ callNext: vi.fn(), serialAction: vi.fn() }));
vi.mock('@panel/api/reception', () => ({
  cancelBooking: vi.fn(), collectFee: vi.fn(), fetchKioskUrl: vi.fn(), registerDevice: vi.fn(),
  fetchPrintTemplates: vi.fn(async () => ({ templates: [] })),
  patientLookup: vi.fn(async () => ({ hits: [], history: [] })),
}));
vi.mock('@panel/Components/Billing/PatientDuesPanel', () => ({ PatientDuesPanel: () => null }));
vi.mock('@inertiajs/react', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@inertiajs/react')>()),
  usePage: () => ({ props }),
  router: { post: vi.fn(), patch: vi.fn(), visit: vi.fn(), on: () => () => undefined },
}));

let props: SharedProps;
let mode: ConnectionMode = 'online';
let deskOptions: Record<string, unknown> = {};

/** The desk hook's surface, stubbed: the board is whatever the props carried, and nothing else has happened yet. */
function desk(initial: BoardDoc) {
  return {
    mode, board: initial, registered: true, device: null, actorPublicId: 'usr_1', blocks: [], templates: [],
    settings: { defaultBlockSize: 5, topupThreshold: 3, maxActiveBlocks: 2, pinIdleMinutes: 15 },
    refresh: vi.fn(async () => undefined), registerFromResponse: vi.fn(async () => undefined), forgetDevice: vi.fn(async () => undefined),
    ensureBlocks: vi.fn(async () => undefined), issueOffline: vi.fn(), checkInOffline: vi.fn(async () => undefined),
    collectCashOffline: vi.fn(async () => ''), printed: vi.fn(async () => undefined), voidLocal: vi.fn(async () => undefined),
    resolve: vi.fn(async () => undefined), flush: vi.fn(async () => undefined), cachedPatients: vi.fn(async () => []),
  };
}

setZiggy({
  url: 'http://demo.test', port: null, defaults: {},
  routes: Object.fromEntries(Object.entries({
    'panel.reception.board': 'panel/reception',
    'panel.reception.shift': 'panel/reception/shift',
    'panel.reception.devices.index': 'panel/reception/devices',
    'panel.reception.vitals.open': 'panel/reception/serials/{serial}/vitals',
  }).map(([name, uri]) => [name, { uri, methods: ['GET', 'POST'] }])),
});

const serial = (number: number, over: Partial<DeskSerial> = {}): DeskSerial => ({
  public_id: `ser_${number}`, display_code: `B-${String(number).padStart(3, '0')}`, number, position: number * 1_000_000,
  status: 'booked', priority: 'normal', source: 'counter', pool: 'counter', patient_id: null, appointment_id: null,
  slot_start_at: null, booked_at: '', checked_in_at: null, called_at: null, completed_at: null, no_show_at: null,
  cancelled_at: null, cancel_reason_code: null, passed_count: 0, skip_count: 0, eta: null,
  patient: { public_id: `pat_${number}`, name: 'রহিমা বেগম', mobile_masked: '017*****21', age_text: null, sex: null, patient_code: '' },
  appointment: null, vitals: null, prescription: null,
  ...over,
});

const session = (code: string, doctor: string, serials: DeskSerial[]): BoardSession => ({
  public_id: `ses_${code}`, code, date: '2026-09-10', status: 'running', mode: 'serial',
  doctor: { public_id: `doc_${code}`, slug: `dr-${code.toLowerCase()}`, name: doctor, name_bn: null, room: null },
  planned_start_at: '2026-09-10T03:00:00Z', planned_end_at: '2026-09-10T07:00:00Z', expected_start_at: '2026-09-10T03:00:00Z',
  delay_minutes: 0, now_serving: null, next_serial: null,
  counts: { booked: serials.length, checked_in: 0, in_consultation: 0, completed: 0, no_show: 0, cancelled: 0, postponed: 0 },
  remaining: { online: 3, counter: 5, buffer: 2, counter_in_blocks: 0, released: 0 },
  fee_new_paisa: 80_000, fee_followup_paisa: 50_000, max_serials: 30, version: 1, serials,
});

const board = (sessions: BoardSession[]): BoardDoc => ({
  date: '2026-09-10', branch: { public_id: 'br_1', name: 'Dhanmondi', code: 'DHK', slug: 'dhanmondi' },
  sessions, generated_at: '2026-09-10T04:00:00Z',
});

/** RoleMatrix's four permissions for the compounder, and the board flags BoardController derives from them. */
const COMPOUNDER_PERMISSIONS = ['prescriptions.vitals.record', 'billing.payments.collect', 'serials.check-in', 'billing.invoices.view'];
const COMPOUNDER_CAN = { issue: false, call_next: false, cancel: false, collect: true, register_device: false, record_vitals: true, search_patients: false, print_prescription: true, check_in: true, kiosk: false };
const RECEPTIONIST_CAN = { ...COMPOUNDER_CAN, issue: true, call_next: true, cancel: true, register_device: true, search_patients: true, kiosk: true };

function shared(roles: string[], permissions: string[]): SharedProps {
  return {
    surface: 'panel',
    auth: { guard: 'web', user: { id: 1, name: 'Karim', roles, permissions, doctor_id: null }, impersonating: false },
    tenant: null, branch: null, branches: [], today_session: null, locale: 'en',
    flash: { success: null, error: null, warning: null, info: null },
    features: {}, ziggy: { url: 'http://demo.test', port: null, defaults: {}, routes: {} }, csrf_token: 'x',
    app: { name: 'bp', env: 'testing', version: '1', reverb: { key: 'k', host: 'localhost', port: 8080, scheme: 'http' } },
    errors: {},
  };
}

// `doctor_scoped` is BoardController's own DoctorScope answer for the viewer, not something the page derives any
// more: it decides the banner AND whether the desk may open the device cache at all, so it has to come from the
// server. The default here is what the server would have said for these roles, so each case still reads as a role.
function show(page: SharedProps, doc: BoardDoc, can = COMPOUNDER_CAN, scoped = page.auth.user?.roles.includes('compounder') === true && page.auth.user.roles.includes('hospital_admin') !== true) {
  props = page;
  return render(
    <I18nextProvider i18n={i18n}><ThemeProvider theme={theme}>
      <Board board={doc} tenant_public_id="ten_1" channel={null} print_format="a5" settings={{}} can={can} actor_public_id="usr_1" doctor_scoped={scoped} {...page} />
    </ThemeProvider></I18nextProvider>,
  );
}

const banner = () => screen.queryByTestId('desk-scope');

describe('Reception/Board — the scope banner', () => {
  beforeEach(() => { mode = 'online'; });

  it('tells a compounder whose desk they are working when the board carries one doctor', () => {
    show(shared(['compounder'], COMPOUNDER_PERMISSIONS), board([session('B', 'Dr. Md. Abdur Rahman', [serial(1)])]));

    expect(banner()).toHaveTextContent("You are working Dr. Md. Abdur Rahman's desk: this board shows their sessions only.");
  });

  it('names both when a compounder stands behind two doctors, and each doctor once however many sessions they have', () => {
    show(shared(['compounder'], COMPOUNDER_PERMISSIONS), board([
      session('B', 'Dr. Md. Abdur Rahman', [serial(1)]),
      session('C', 'Dr. Sultana Razia', [serial(1)]),
      { ...session('D', 'Dr. Md. Abdur Rahman', [serial(2)]), doctor: { public_id: 'doc_B', slug: 'dr-b', name: 'Dr. Md. Abdur Rahman', name_bn: null, room: null } },
    ]));

    expect(banner()).toHaveTextContent('You are working the desks of Dr. Md. Abdur Rahman, Dr. Sultana Razia: this board shows their sessions only.');
  });

  // The empty board is the case the banner exists for: without it, "no sessions today at this branch" is a lie the
  // compounder cannot check — the branch may be busy with doctors who are not theirs.
  it('still explains the emptiness when none of their doctors is here today', () => {
    show(shared(['compounder'], COMPOUNDER_PERMISSIONS), board([]));

    expect(banner()).toHaveTextContent('none of them has one here today');
    expect(screen.getByText('No sessions today at this branch.')).toBeInTheDocument();
  });

  // The banner is a fact about the VIEWER, not about the connection: offline the board is rebuilt from Dexie, and a
  // desk that is short because of the scope must still say so when it is also short because it is offline.
  it('keeps explaining the scope while the desk is offline', () => {
    mode = 'offline';
    show(shared(['compounder'], COMPOUNDER_PERMISSIONS), board([session('B', 'Dr. Md. Abdur Rahman', [serial(1)])]));

    expect(banner()).toHaveTextContent("You are working Dr. Md. Abdur Rahman's desk");
  });

  it('says nothing to a receptionist, who is looking at the whole branch', () => {
    show(shared(['receptionist'], ['serials.issue.counter', 'serials.check-in', 'billing.payments.collect']), board([session('B', 'Dr. Md. Abdur Rahman', [serial(1)])]), RECEPTIONIST_CAN);

    expect(banner()).toBeNull();
  });

  // DoctorScope: a hospital admin is unrestricted even if somebody assigned them to a doctor. The screen must not
  // claim otherwise — this is the clause the client mirrors, so it is the clause that can drift.
  it('says nothing to a hospital admin who also carries the compounder role', () => {
    show(shared(['compounder', 'hospital_admin'], [...COMPOUNDER_PERMISSIONS, 'serials.issue.counter']), board([session('B', 'Dr. Md. Abdur Rahman', [serial(1)])]), RECEPTIONIST_CAN);

    expect(banner()).toBeNull();
  });
});

describe('Reception/Board — what the compounder can do with the sessions they are given', () => {
  beforeEach(() => { mode = 'online'; });

  it('lists the filtered board\'s rows in serial-number order, whatever order the server sent them in', () => {
    show(shared(['compounder'], COMPOUNDER_PERMISSIONS), board([session('B', 'Dr. Md. Abdur Rahman', [
      serial(12, { position: 1_000_000 }), serial(3, { position: 2_000_000 }), serial(7, { position: 3_000_000 }), serial(1, { position: 9_000_000 }),
    ])]));

    const codes = screen.getAllByRole('row').map((row) => (within(row).getAllByRole('cell')[0]?.textContent ?? '').match(/[A-Z]+-\d+/)?.[0] ?? '');
    expect(codes).toEqual(['B-001', 'B-003', 'B-007', 'B-012']);
  });

  it('carries the flag set down to the tile: the arrival tick, no kiosk QR, no call-next', () => {
    show(shared(['compounder'], COMPOUNDER_PERMISSIONS), board([session('B', 'Dr. Md. Abdur Rahman', [serial(1)])]));

    expect(screen.getByRole('button', { name: 'Check in' })).toBeEnabled();
    expect(screen.queryByRole('button', { name: 'Kiosk QR' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Call next' })).toBeNull();
    // …and no device registration either: that flag is `register_device`, which the role does not hold.
    expect(screen.queryByRole('button', { name: 'Register this device' })).toBeNull();
  });

  // The quick-search had no flag at all, so it rendered for the compounder and then swallowed the 403 that
  // PatientLookupController answers them with (`viewAny` on Patient = `patients.view`, which they do not hold):
  // a box that says "no patients" to every query, above a dues panel whose only input is a patient picked in it.
  it('offers no patient search — nor the dues panel that has nothing else to read', () => {
    show(shared(['compounder'], COMPOUNDER_PERMISSIONS), board([session('B', 'Dr. Md. Abdur Rahman', [serial(1)])]));

    expect(screen.queryByLabelText('Search')).toBeNull();
    expect(screen.queryByText('Find a patient')).toBeNull();
  });

  // The hook refuses the device cache — the tablet's Dexie board, which belongs to whoever registered it — on this
  // one flag. A page that forgot to pass it would leave that refusal switched off and nothing on screen would say so.
  it('tells the desk hook it is scoped, which is what keeps it off the device cache', () => {
    show(shared(['compounder'], COMPOUNDER_PERMISSIONS), board([session('B', 'Dr. Md. Abdur Rahman', [serial(1)])]));
    expect(deskOptions.doctorScoped).toBe(true);

    show(shared(['receptionist'], ['serials.issue.counter']), board([]), RECEPTIONIST_CAN);
    expect(deskOptions.doctorScoped).toBe(false);
  });

  it('leaves the search where it was for a desk that may look patients up', () => {
    show(shared(['receptionist'], ['serials.issue.counter', 'patients.view']), board([session('B', 'Dr. Md. Abdur Rahman', [serial(1)])]), RECEPTIONIST_CAN);

    expect(screen.getByLabelText('Search')).toBeInTheDocument();
  });
});
