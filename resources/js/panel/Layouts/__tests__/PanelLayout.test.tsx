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
    branch: null, branches: [], locale: 'en',
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
      auth: { guard: 'web', user: { id: 2, name: 'Rahim', roles: ['hospital_admin'], permissions: ['serials.issue.counter', 'patients.view', 'clinic.settings.manage'], doctor_id: null }, impersonating: false },
    }));

    // Visible: the permission-free entries plus the three this user holds a permission for.
    expect(linkNames()).toEqual(['Dashboard', 'Live queue', 'Patients', 'Prescriptions']);
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

    expect(linkNames()).toEqual(['Dashboard', 'Live queue', 'Prescriptions']);
    expect(within(nav()).getByText('Live queue').closest('.MuiListItemButton-root')).toHaveClass('Mui-selected');
    for (const label of TENANT_LABELS.filter((l) => !['Dashboard', 'Live queue', 'Prescriptions'].includes(l))) {
      expect(within(nav()).queryByText(label)).toBeNull();
    }
    expect(nav().querySelectorAll('[data-testid^="super-nav-"]')).toHaveLength(0);
  });
});
