// The shell's drawer is chosen by the surface that served the page (SharedProps.surface), and each surface must see
// ONLY its own entries. This is the regression the console shipped with: PanelLayout rendered the clinic's NAV on
// the super host, where hasRoute() found none of its routes, so the super admin's sidebar was "Dashboard / Live
// queue / Prescriptions" greyed out as "coming soon" while the real navigation sat in a tab strip.
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { ThemeProvider } from '@mui/material/styles';
import { I18nextProvider } from 'react-i18next';
import { theme } from '@panel/theme';
import { i18n, initI18n } from '@shared/i18n';
import { setZiggy } from '@shared/routes';
import type { SharedProps } from '@shared/types/shared-props';
import { SUPER_NAV, SUPER_NAV_SECTIONS } from '@panel/Components/Super/nav';
import { useHttpNotice } from '@panel/hooks/shell/useHttpNotice';
import { PanelLayout } from '../PanelLayout';

initI18n('en');

let props: SharedProps;

// The page context, the PWA registration, the WebSocket and the heartbeat are not what is under test.
vi.mock('@inertiajs/react', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@inertiajs/react')>()),
  Head: () => null,
  usePage: () => ({ props }),
  router: { post: vi.fn(), patch: vi.fn(), visit: vi.fn(), on: () => () => undefined },
}));
vi.mock('@panel/pwa', () => ({ usePwa: () => ({ needRefresh: false, waitingForIdle: false, applyUpdate: () => undefined, dismiss: () => undefined }) }));
vi.mock('@shared/realtime/echo', () => ({ loadEcho: vi.fn(async () => null) }));
vi.mock('@shared/connection/ConnectionIndicator', () => ({ ConnectionIndicator: () => null }));

const SUPER_ROUTES: Record<string, string> = {
  'super.dashboard': '/',
  'super.tenants.index': 'tenants',
  'super.usage.index': 'usage',
  'super.plans.index': 'plans',
  'super.billing.index': 'billing',
  'super.billing.subscriptions.index': 'billing/subscriptions',
  'super.billing.invoices.index': 'billing/invoices',
  'super.billing.payments.index': 'billing/payments',
  'super.billing.dunning.index': 'billing/dunning',
  'super.catalog.index': 'catalog',
  'super.catalog.imports.index': 'catalog/imports',
  'super.catalog.review': 'catalog/review',
  'super.catalog.reconciliation.index': 'catalog/reconciliation',
  'super.admins.index': 'admins',
  'super.notifications.index': 'notifications',
  'super.settings.index': 'settings',
  'super.audit.index': 'audit',
  'super.two-factor.show': 'two-factor',
  'super.profile.show': 'profile',
  'super.logout': 'logout',
};

// Reception is deliberately absent: the clinic shell must still grey out an entry whose route has not shipped.
const PANEL_ROUTES: Record<string, string> = {
  'panel.dashboard': 'panel',
  'panel.queue.index': 'panel/queue',
  'panel.queue.doctor': 'panel/queue/doctor',
  'panel.prescriptions.index': 'panel/prescriptions',
  'panel.patients.index': 'panel/patients',
  'panel.clinic.settings.index': 'panel/clinic/settings',
  'panel.locale': 'panel/locale',
  'panel.logout': 'panel/logout',
};

function ziggy(routes: Record<string, string>): SharedProps['ziggy'] {
  return {
    url: 'http://localhost:3000', port: null, defaults: {},
    routes: Object.fromEntries(Object.entries(routes).map(([name, uri]) => [name, { uri, methods: ['GET', 'HEAD', 'POST', 'PATCH'] }])),
  };
}

function shared(surface: SharedProps['surface'], overrides: Partial<SharedProps> = {}): SharedProps {
  const guard = surface === 'super' ? 'super' : 'web';
  return {
    surface,
    auth: { guard, user: { id: 1, name: 'Ayesha', roles: [guard === 'super' ? 'super_admin' : 'hospital_admin'], permissions: [], doctor_id: null }, impersonating: false },
    tenant: surface === 'super' ? null : { id: 1, slug: 'demo', name: 'Demo Hospital', locale: 'en', timezone: 'Asia/Dhaka', logo_url: null, theme: {}, modules: [] },
    branch: null, branches: [], today_session: null, locale: 'en',
    flash: { success: null, error: null, warning: null, info: null },
    features: {}, ziggy: ziggy(surface === 'super' ? SUPER_ROUTES : PANEL_ROUTES), csrf_token: 'x',
    app: { name: 'Clinic Desk', env: 'testing', version: '1', reverb: { key: 'k', host: 'localhost', port: 8080, scheme: 'http' } },
    errors: {},
    ...overrides,
  };
}

function desktop(wide: boolean): void {
  // MUI's useMediaQuery asks matchMedia; jsdom has none. `md` and up is the permanent drawer.
  window.matchMedia = (query: string) => ({
    matches: wide && /min-width:\s*900px/.test(query), media: query, onchange: null,
    addListener: () => undefined, removeListener: () => undefined,
    addEventListener: () => undefined, removeEventListener: () => undefined, dispatchEvent: () => false,
  });
}

function at(path: string): void {
  window.history.replaceState(null, '', path);
}

function show(page: SharedProps) {
  props = page;
  setZiggy(page.ziggy);
  return render(
    <I18nextProvider i18n={i18n}><ThemeProvider theme={theme}>
      <PanelLayout title="nav.dashboard"><div data-testid="page">page body</div></PanelLayout>
    </ThemeProvider></I18nextProvider>,
  );
}

// The labelled box inside the drawer — not the `<nav>` column that positions it.
const nav = () => screen.getByRole('navigation', { name: 'Menu' });
const linkNames = () => within(nav()).getAllByRole('link').map((a) => a.textContent?.trim());
const t = (key: string): string => i18n.t(key);
const SUPER_LABELS = SUPER_NAV.map((item) => t(item.label));
const SECTION_LABELS = SUPER_NAV_SECTIONS.map((section) => t(section.label));
const TENANT_LABELS = ['Dashboard', 'Reception', 'Live queue', 'Scheduling', 'Patients', 'Prescriptions', 'Custom brands', 'Billing', 'Notifications', 'Reports', 'Video consultations', 'Setup'];

describe('PanelLayout on the super surface', () => {
  beforeEach(() => { desktop(true); at('/'); });

  it('renders the fourteen console entries under their six section headers, and not one clinic entry', async () => {
    show(shared('super'));

    await within(nav()).findByTestId('super-nav-dashboard');
    expect(SUPER_LABELS).toHaveLength(14);
    expect(linkNames()).toEqual(SUPER_LABELS);
    for (const header of SECTION_LABELS) expect(within(nav()).getAllByText(header).length).toBeGreaterThan(0);
    expect(within(nav()).getByRole('list', { name: 'Console sections' })).toBeInTheDocument();

    // The leak: none of the clinic's entries, and nothing greyed out as "coming soon".
    for (const label of ['Reception', 'Live queue', 'Prescriptions', 'Scheduling', 'Patients', 'Reports', 'Setup', 'Video consultations']) {
      expect(within(nav()).queryByText(label)).toBeNull();
    }
    expect(nav().querySelectorAll('.Mui-disabled')).toHaveLength(0);
    expect(screen.queryByText('Coming soon')).toBeNull();
    expect(screen.getByTestId('page')).toHaveTextContent('page body');
  });

  it('highlights the entry whose route pattern matches the current URL, like the tab strip did', async () => {
    at('/tenants');
    show(shared('super'));

    const tenants = await within(nav()).findByTestId('super-nav-tenants');
    expect(tenants).toHaveClass('Mui-selected');
    expect(within(nav()).getByTestId('super-nav-dashboard')).not.toHaveClass('Mui-selected');
    expect(nav().querySelectorAll('.Mui-selected')).toHaveLength(1);
  });

  it("unfolds Billing's five desks under Billing only while one of them is open", async () => {
    at('/billing/invoices');
    show(shared('super'));

    const billing = await within(nav()).findByTestId('super-nav-billing');
    expect(billing).toHaveClass('Mui-selected');
    // MUI's nested list is a `div` inside the Collapse (its documented pattern), so it is found by its label.
    const desks = within(nav()).getByLabelText('Billing sections');
    expect(within(desks).getAllByRole('link').map((a) => a.textContent?.trim())).toEqual(['Overview', 'Subscriptions', 'Invoices', 'Payments', 'Dunning']);
    expect(within(nav()).getByTestId('super-nav-billing_invoices')).toHaveClass('Mui-selected');
    expect(within(nav()).getByTestId('super-nav-billing_overview')).not.toHaveClass('Mui-selected');
    expect(linkNames()).toHaveLength(14 + 5);
  });

  it('keeps the desks folded away on every other console page', async () => {
    at('/settings');
    show(shared('super'));

    await within(nav()).findByTestId('super-nav-settings');
    expect(within(nav()).queryByLabelText('Billing sections')).toBeNull();
    expect(linkNames()).toHaveLength(14);
  });

  it('drops an entry whose route the surface has not shipped instead of greying it out', async () => {
    const routes = { ...SUPER_ROUTES };
    delete routes['super.catalog.review'];
    show(shared('super', { ziggy: ziggy(routes) }));

    await within(nav()).findByTestId('super-nav-catalog');
    expect(within(nav()).queryByTestId('super-nav-review')).toBeNull();
    expect(linkNames()).toHaveLength(13);
    expect(nav().querySelectorAll('.Mui-disabled')).toHaveLength(0);
  });

  it('collapses to the hamburger and a temporary drawer on a phone, exactly like the clinic shell', async () => {
    desktop(false);
    show(shared('super'));

    // Closed: the temporary drawer is kept mounted but hidden, so no accessible navigation — only the hamburger.
    const hamburger = screen.getByRole('button', { name: 'Menu' });
    expect(screen.queryByRole('navigation', { name: 'Menu' })).toBeNull();
    await act(async () => { fireEvent.click(hamburger); });

    const drawer = await screen.findByRole('navigation', { name: 'Menu' });
    expect(drawer.closest('.MuiDrawer-root')).toHaveClass('MuiDrawer-modal');
    await within(drawer).findByTestId('super-nav-tenants');
    expect(linkNames()).toEqual(SUPER_LABELS);
    expect(within(drawer).getByRole('link', { name: 'Clinics' })).toBeVisible();
  });

  it('keeps the app bar working: the account menu with the profile link and logout', async () => {
    show(shared('super'));
    await within(nav()).findByTestId('super-nav-dashboard');

    fireEvent.click(screen.getByRole('button', { name: 'Account' }));
    expect(await screen.findByRole('menuitem', { name: 'Account' })).toHaveAttribute('href', '/profile');
    expect(screen.getByRole('menuitem', { name: 'Log out' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /language/i })).toBeNull();
  });
});

describe('PanelLayout on the clinic panel', () => {
  beforeEach(() => { desktop(true); at('/panel'); });

  it('renders the clinic NAV with its guards intact, and not one console entry or section header', () => {
    show(shared('panel', {
      auth: { guard: 'web', user: { id: 2, name: 'Rahim', roles: ['hospital_admin'], permissions: ['serials.check-in', 'patients.view', 'clinic.settings.manage'], doctor_id: null }, impersonating: false },
    }));

    // Visible: the two permission-free entries plus the one this user holds a permission for. Prescriptions is
    // NOT here — check-in is not one of the three grants its list accepts (see its own section below).
    expect(linkNames()).toEqual(['Dashboard', 'Live queue', 'Patients']);
    // Reception: permitted, but its route is not in this ziggy group — greyed out, never dropped (the clinic rule).
    const reception = within(nav()).getByText('Reception').closest('.MuiListItemButton-root');
    expect(reception).toHaveClass('Mui-disabled');
    // Setup group present for the settings permission; the other setup screens hidden.
    expect(within(nav()).getByText('Setup')).toBeInTheDocument();
    expect(within(nav()).queryByText('Branches')).toBeNull();
    expect(within(nav()).getByText('Demo Hospital')).toBeInTheDocument();

    for (const label of SECTION_LABELS) expect(within(nav()).queryByText(label)).toBeNull();
    for (const label of ['Clinics', 'Plans', 'Usage', 'Catalogue', 'Imports', 'Catalogue review', 'Reconciliation', 'Admins', 'Platform settings', 'Audit', 'Security']) {
      expect(within(nav()).queryByText(label)).toBeNull();
    }
    expect(within(nav()).queryByRole('list', { name: 'Console sections' })).toBeNull();
    expect(nav().querySelectorAll('[data-testid^="super-nav-"]')).toHaveLength(0);
    expect(screen.getByRole('button', { name: 'Switch language' })).toBeInTheDocument();
  });

  it('never mounts the console drawer: the tenant NAV also survives a user with no permissions at all', () => {
    at('/panel/queue');
    show(shared('panel'));

    expect(linkNames()).toEqual(['Dashboard', 'Live queue']);
    expect(within(nav()).getByText('Live queue').closest('.MuiListItemButton-root')).toHaveClass('Mui-selected');
    for (const label of TENANT_LABELS.filter((l) => !['Dashboard', 'Live queue'].includes(l))) {
      expect(within(nav()).queryByText(label)).toBeNull();
    }
    expect(nav().querySelectorAll('[data-testid^="super-nav-"]')).toHaveLength(0);
  });
});

// A doctor's day starts and ends on one screen (BRIEF §5.E/§5.G). The drawer says so: "Today's session" in place
// of the generic "Live queue" — the same route family, the page `panel.queue.index` would have redirected them to
// anyway — with the count of patients who have arrived and are waiting for them on it.
describe('PanelLayout — Today\'s session', () => {
  beforeEach(() => { desktop(true); at('/panel'); });

  const asDoctor = (waiting: number | null) => shared('panel', {
    auth: { guard: 'web', user: { id: 3, name: 'Dr Rahman', roles: ['doctor'], permissions: ['prescriptions.write'], doctor_id: 7 }, impersonating: false },
    today_session: waiting === null ? null : { session_id: 'ses_1', code: 'B', status: 'running', waiting },
  });

  it('gives a doctor Today\'s session with the waiting count, and no second queue entry', () => {
    show(asDoctor(4));

    // one queue entry, not two — the count rides inside the entry, hence the label check by text
    expect(within(nav()).getAllByRole('link')).toHaveLength(3);
    expect(within(nav()).getByText("Today's session")).toBeInTheDocument();
    expect(within(nav()).queryByText('Live queue')).toBeNull();
    expect(within(nav()).getByRole('link', { name: /Today's session/ })).toHaveAttribute('href', '/panel/queue/doctor');

    const badge = within(nav()).getByTestId('today-session-badge');
    expect(badge).toHaveTextContent('4');
    expect(badge).toHaveAccessibleName('4 waiting');
  });

  it('drops the badge when nobody has arrived yet, and when there is no session at all', () => {
    const { unmount } = show(asDoctor(0));
    expect(within(nav()).getByText("Today's session")).toBeInTheDocument();
    expect(within(nav()).queryByTestId('today-session-badge')).toBeNull();
    unmount();

    show(asDoctor(null));
    expect(within(nav()).getByText("Today's session")).toBeInTheDocument();
    expect(within(nav()).queryByTestId('today-session-badge')).toBeNull();
  });

  it('selects the entry on the session page, since it is the same route family', () => {
    at('/panel/queue/doctor');
    show(asDoctor(2));

    expect(within(nav()).getByText("Today's session").closest('.MuiListItemButton-root')).toHaveClass('Mui-selected');
  });

  it('leaves everyone who is not a doctor with Live queue and no badge', () => {
    show(shared('panel', {
      auth: { guard: 'web', user: { id: 4, name: 'Ayesha', roles: ['receptionist'], permissions: [], doctor_id: null }, impersonating: false },
      // even if a stale count arrived, a user without a doctors row has no session of their own to badge
      today_session: { session_id: 'ses_1', code: 'B', status: 'running', waiting: 9 },
    }));

    expect(within(nav()).getByText('Live queue')).toBeInTheDocument();
    expect(within(nav()).queryByText("Today's session")).toBeNull();
    expect(within(nav()).queryByTestId('today-session-badge')).toBeNull();
  });
});

/**
 * The desk entry, and the whole of its truth table in one place — because it has now been got wrong twice in
 * opposite directions. A compounder works the SAME reception board as the receptionist, filtered server-side to
 * the doctors assigned to them, so the drawer has to separate five roles and no single permission does it:
 * `serials.issue.counter` (the original gate) misses the compounder, `billing.payments.collect` catches the
 * accountant, and `serials.check-in` on its own catches the doctor, who has their own session page instead.
 *
 * The second try — `serials.check-in` + `doctor: false` — then dropped the entry for a hospital admin who is ALSO
 * a doctors row: the owner of a single-doctor chamber, who runs the desk himself and is the likeliest person in
 * this product to want the link. Nothing caught it because every hospital-admin fixture here had `doctor_id: null`.
 * Hence the rule under test: issues at the counter, OR can mark a patient arrived and is not a doctor.
 */
describe('PanelLayout — the reception desk entry', () => {
  beforeEach(() => { desktop(true); at('/panel'); });

  const asRole = (role: string, permissions: string[], doctorId: number | null = null) => shared('panel', {
    auth: { guard: 'web', user: { id: 5, name: 'Staff', roles: [role], permissions, doctor_id: doctorId }, impersonating: false },
  });

  // Exactly what RoleMatrix gives each role, trimmed to the permissions this entry can see.
  const RECEPTIONIST = ['serials.issue.counter', 'serials.check-in', 'billing.payments.collect'];
  const COMPOUNDER = ['prescriptions.vitals.record', 'billing.payments.collect', 'serials.check-in', 'billing.invoices.view'];
  const DOCTOR = ['prescriptions.write', 'prescriptions.vitals.record', 'serials.check-in'];
  const ACCOUNTANT = ['billing.payments.collect', 'billing.invoices.view', 'billing.reports.view'];
  // A hospital admin holds every permission; these are the ones this entry reads.
  const HOSPITAL_ADMIN = ['serials.issue.counter', 'serials.check-in', 'billing.payments.collect', 'prescriptions.vitals.record'];

  // [who, role, permissions, doctors row, sees the desk]
  const TRUTH: Array<[string, string, string[], number | null, boolean]> = [
    ['a receptionist', 'receptionist', RECEPTIONIST, null, true],
    ['a compounder', 'compounder', COMPOUNDER, null, true],
    ['a hospital admin', 'hospital_admin', HOSPITAL_ADMIN, null, true],
    ['a hospital admin who is also a doctor', 'hospital_admin', HOSPITAL_ADMIN, 9, true],
    ['a plain doctor', 'doctor', DOCTOR, 7, false],
    ['an accountant', 'accountant', ACCOUNTANT, null, false],
  ];

  it.each(TRUTH)('%s: sees the desk = %j', (who, role, permissions, doctorId, sees) => {
    show(asRole(role, permissions, doctorId));

    const entry = within(nav()).queryByText('Reception');
    if (sees) expect(entry, who).not.toBeNull();
    else expect(entry, who).toBeNull();
  });

  // The regression spelled out: the owner-doctor keeps BOTH the desk he works and the session page he consults
  // from, and the queue entry is still the doctor's one — the `doctor` flag on the other entries is untouched.
  it('gives the owner-doctor the desk AND Today\'s session, and no second queue entry', () => {
    show(asRole('hospital_admin', [...HOSPITAL_ADMIN, 'prescriptions.write'], 9));

    expect(within(nav()).getByText('Reception')).toBeInTheDocument();
    expect(within(nav()).getByText("Today's session")).toBeInTheDocument();
    expect(within(nav()).queryByText('Live queue')).toBeNull();
  });

  it('leaves the compounder without a Patients entry — they hold no patients.view', () => {
    show(asRole('compounder', COMPOUNDER));

    expect(within(nav()).queryByText('Patients')).toBeNull();
    expect(within(nav()).getByText('Billing')).toBeInTheDocument();
  });
});

/**
 * The same gate, once more with the route actually shipped. The section above reads the entry by its text, which a
 * greyed-out "coming soon" entry also answers to — true here only because this ziggy group deliberately omits the
 * desk. So this one adds `panel.reception.board` and reads the drawer as a list of LINKS: the desk either is or is
 * not somewhere the user can go, and the doctor's "Today's session" is proved to be what stands in its place rather
 * than merely proved to be somewhere else in the file.
 */
describe('PanelLayout — who can reach the desk once its route ships', () => {
  beforeEach(() => { desktop(true); at('/panel'); });

  const WITH_RECEPTION = ziggy({ ...PANEL_ROUTES, 'panel.reception.board': 'panel/reception' });

  const asRole = (role: string, permissions: string[], doctorId: number | null = null) => shared('panel', {
    auth: { guard: 'web', user: { id: 6, name: 'Staff', roles: [role], permissions, doctor_id: doctorId }, impersonating: false },
    ziggy: WITH_RECEPTION,
  });

  // RoleMatrix, trimmed to the permissions any drawer entry reads.
  const RECEPTIONIST = ['serials.issue.counter', 'serials.check-in', 'billing.payments.collect', 'patients.view'];
  const COMPOUNDER = ['prescriptions.vitals.record', 'billing.payments.collect', 'serials.check-in', 'billing.invoices.view'];
  const DOCTOR = ['prescriptions.write', 'prescriptions.vitals.record', 'serials.check-in'];
  const ACCOUNTANT = ['billing.payments.collect', 'billing.invoices.view', 'billing.reports.view'];
  const OWNER_DOCTOR = ['serials.issue.counter', 'serials.check-in', 'prescriptions.write', 'patients.view'];

  it('takes the compounder to the desk — and gives them no Patients entry on the way', () => {
    show(asRole('compounder', COMPOUNDER));

    expect(linkNames()).toEqual(['Dashboard', 'Reception', 'Live queue', 'Prescriptions']);
    expect(within(nav()).getByRole('link', { name: 'Reception' })).toHaveAttribute('href', '/panel/reception');
    expect(within(nav()).queryByText('Patients')).toBeNull();
  });

  it('takes the receptionist to the same entry, so the compounder is on the desk and not beside it', () => {
    show(asRole('receptionist', RECEPTIONIST));

    expect(within(nav()).getByRole('link', { name: 'Reception' })).toHaveAttribute('href', '/panel/reception');
  });

  // …and out of the clinical list as well, which was a dead entry for this role from the day it shipped: the
  // accountant holds no prescription grant, so `panel.prescriptions.index` has always answered 403.
  it('keeps an accountant out entirely — not greyed out, absent', () => {
    show(asRole('accountant', ACCOUNTANT));

    expect(linkNames()).toEqual(['Dashboard', 'Live queue']);
    expect(within(nav()).queryByText('Reception')).toBeNull();
    expect(within(nav()).queryByText('Prescriptions')).toBeNull();
  });

  it("gives a doctor Today's session instead of the desk, though they hold the same permission", () => {
    show(asRole('doctor', DOCTOR, 7));

    expect(linkNames()).toEqual(['Dashboard', "Today's session", 'Prescriptions']);
    expect(within(nav()).queryByText('Reception')).toBeNull();
    expect(within(nav()).queryByText('Live queue')).toBeNull();
  });

  // The single-doctor chamber's owner: a doctors row AND the counter permission. He gets a working link to the
  // desk he stands at, next to the session page he consults from — the case the check-in-only gate lost.
  it('takes the owner-doctor to the desk as well as to his own session', () => {
    show(asRole('hospital_admin', OWNER_DOCTOR, 9));

    expect(linkNames()).toEqual(['Dashboard', 'Reception', "Today's session", 'Patients', 'Prescriptions']);
    expect(within(nav()).getByRole('link', { name: 'Reception' })).toHaveAttribute('href', '/panel/reception');
  });
});

/**
 * The clinical list's entry. `PrescriptionPolicy::viewAny` opens `panel.prescriptions.index` to the holder of ANY
 * of three grants — `prescriptions.view.any`, `.write`, `.vitals.record` — so no single `permission` on the entry
 * could be right: each of the three would have taken the list away from a role that owns it. Carrying none at all
 * was worse in both directions, and stayed wrong for two releases in a row:
 *
 *   • the ACCOUNTANT has had a dead entry since the list shipped (they hold no grant, so it has always 403'd);
 *   • the COMPOUNDER now 403s too where they did not before, because viewAny gained DoctorScope's conjunct.
 *
 * The half this predicate cannot ask is that same conjunct: an unassigned compounder holds `vitals.record` and
 * still has no list. They keep the entry here and meet the shell's refusal banner instead of a black modal.
 */
describe('PanelLayout — the prescriptions entry', () => {
  beforeEach(() => { desktop(true); at('/panel'); });

  const asRole = (role: string, permissions: string[], doctorId: number | null = null) => shared('panel', {
    auth: { guard: 'web', user: { id: 7, name: 'Staff', roles: [role], permissions, doctor_id: doctorId }, impersonating: false },
  });

  // RoleMatrix, trimmed to the permissions the drawer reads.
  const TRUTH: Array<[string, string, string[], number | null, boolean]> = [
    ['a hospital admin (view.any)', 'hospital_admin', ['prescriptions.view.any', 'prescriptions.write', 'prescriptions.vitals.record', 'serials.check-in'], null, true],
    ['a doctor (write)', 'doctor', ['prescriptions.write', 'prescriptions.vitals.record', 'serials.check-in'], 7, true],
    ['a receptionist (vitals.record)', 'receptionist', ['serials.issue.counter', 'serials.check-in', 'prescriptions.vitals.record', 'patients.view'], null, true],
    ['a compounder (vitals.record)', 'compounder', ['serials.check-in', 'prescriptions.vitals.record', 'billing.payments.collect', 'billing.invoices.view'], null, true],
    ['an accountant (none of the three)', 'accountant', ['billing.payments.collect', 'billing.invoices.view', 'billing.reports.view', 'patients.view'], null, false],
  ];

  it.each(TRUTH)('%s: sees the clinical list = %j', (who, role, permissions, doctorId, sees) => {
    show(asRole(role, permissions, doctorId));

    const entry = within(nav()).queryByRole('link', { name: 'Prescriptions' });
    if (sees) expect(entry, who).toHaveAttribute('href', '/panel/prescriptions');
    else expect(entry, who).toBeNull();
  });

  // The desk's two roles keep the entry for the same reason they keep the desk: BRIEF §5.G.4 has the sheet
  // printed and handed to the patient at the counter, and `vitals.record` is what grants `view` on it.
  it('leaves the desk able to reach the sheet it has to print', () => {
    show(asRole('compounder', ['serials.check-in', 'prescriptions.vitals.record']));

    expect(linkNames()).toEqual(['Dashboard', 'Live queue', 'Prescriptions']);
  });
});

/**
 * The shell's refusal banner (panel/app.tsx registers the `httpException` handler that fills it). What is proved
 * here is the rendering contract the handler depends on: 403 is a dismissible warning, 419 is an error carrying
 * the only action that can fix it, and the page under the banner is still there — a refused visit never swapped
 * it, which is exactly why the shell says "no" in place rather than redirecting.
 */
describe('PanelLayout — the refusal banner', () => {
  beforeEach(() => { desktop(true); at('/panel'); useHttpNotice.setState({ kind: null }); });

  it('says nothing at all when the server has refused nothing', () => {
    show(shared('panel'));

    expect(screen.queryByTestId('http-notice')).toBeNull();
  });

  it('turns a 403 into a dismissible sentence over the page the user is still on', () => {
    show(shared('panel'));
    act(() => { useHttpNotice.getState().show('forbidden'); });

    const notice = screen.getByTestId('http-notice');
    expect(notice).toHaveTextContent('You do not have permission to open that. Ask your hospital admin if you need it.');
    expect(notice).toHaveClass('MuiAlert-colorWarning');
    expect(screen.getByTestId('page')).toHaveTextContent('page body');
    expect(within(notice).queryByRole('button', { name: 'Sign in again' })).toBeNull();

    fireEvent.click(within(notice).getByRole('button', { name: 'Close' }));
    expect(screen.queryByTestId('http-notice')).toBeNull();
  });

  it('turns a 419 into an error with the one button that helps, and no Close to hide it with', () => {
    show(shared('panel'));
    act(() => { useHttpNotice.getState().show('session_expired'); });

    const notice = screen.getByTestId('http-notice');
    expect(notice).toHaveTextContent('You have been signed out. Sign in again to continue; anything already saved is safe.');
    expect(notice).toHaveClass('MuiAlert-colorError');
    expect(within(notice).getByRole('button', { name: 'Sign in again' })).toBeInTheDocument();
    expect(within(notice).queryByRole('button', { name: 'Close' })).toBeNull();
  });
});
