// The portal home (site/Pages/Portal/Home): the upcoming card sits above the family, and a visit in "Recent activity"
// links to the prescription once issued, else to the live queue while today's session is open — from the meta the
// timeline row already carries, never a URL built here.
import { describe, expect, it, vi } from 'vitest';
import { render, screen, within } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import { i18n } from '@shared/i18n';
import type { FamilyMember, TimelineEntry, UpcomingSerial } from '@shared/types/models';
import Home from '../Home';

vi.mock('@inertiajs/react', () => ({
  Link: ({ href, children, ...rest }: { href: string; children: React.ReactNode }) => <a href={href} {...rest}>{children}</a>,
  router: { patch: vi.fn() },
}));
vi.mock('@site/Layouts/SiteLayout', () => ({ SiteLayout: ({ children }: { children: React.ReactNode }) => <>{children}</> }));
vi.mock('@shared/routes', () => ({ route: (name: string) => `/${name}`, hasRoute: (name: string) => name === 'site.booking.index' }));

const owner: FamilyMember = {
  public_id: 'pat_1', patient_code: 'P-000413', name: 'Rakibul Islam', mobile: '+8801798055245', mobile_local: '01798055245', is_mobile_owner: true,
  gender: 'male', dob: null, dob_is_estimated: false, age_text: null, age_years: null, is_active: true, last_visit_at: null, visit_count: 1, relation: null,
};

const shared = {
  surface: 'site' as const, auth: { guard: 'patient' as const, user: null, impersonating: false }, tenant: null, branch: null, branches: [], locale: 'en' as const,
  flash: { success: null, error: null, warning: null, info: null }, features: {}, ziggy: { url: 'http://demo.test', port: null, defaults: {}, routes: {} }, csrf_token: 'x',
  app: { name: 'bp', env: 'testing', version: '1', reverb: { key: 'k', host: 'localhost', port: 8080, scheme: 'http' as const } }, errors: {},
};

const entry = (over: Partial<TimelineEntry>): TimelineEntry => ({
  kind: 'visit', id: 1, occurred_at: '2026-09-10T04:00:00Z', title: 'Opd visit', subtitle: 'Dr. Md. Abdur Rahman · B-011', ref: 'vis_1', meta: {}, cursor: 'c', ...over,
});

const upcoming: UpcomingSerial = {
  appointment_id: 'apt_1', status: 'checked_in', is_telemedicine: false, is_today: true,
  patient: { public_id: 'pat_1', name: 'Rakibul Islam' }, doctor: { slug: 'dr-rahman', name: 'Dr. Md. Abdur Rahman', name_bn: null, room: null }, branch: { name: 'Demo Hospital' },
  session: { public_id: 'ses_1', code: 'B', date: '2026-09-10', status: 'running', planned_start_at: '2026-09-10T09:00:00Z', planned_end_at: '2026-09-10T13:00:00Z', delay_minutes: 0 },
  serial: { public_id: 'ser_1', display_code: 'B-011', number: 11, status: 'checked_in' }, queue_url: '/q/dr-rahman/today?s=ser_1', hold_expires_at: null, pay_url: null, live: null,
};

function show(props: { timeline: TimelineEntry[]; upcoming: UpcomingSerial[] }) {
  return render(
    <I18nextProvider i18n={i18n}>
      <Home {...shared} patient={owner} family={[owner]} acting_for={owner} timeline={{ data: props.timeline, meta: { next_cursor: null } }} upcoming={props.upcoming} />
    </I18nextProvider>,
  );
}

describe('Portal/Home', () => {
  it('puts the upcoming card above the family and the empty state links to the doctor list', () => {
    show({ timeline: [], upcoming: [] });
    const headings = screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent);
    expect(headings[0]).toBe('Upcoming serials');
    expect(headings[1]).toBe('Your family');
    expect(screen.getByRole('link', { name: 'Book a serial' })).toHaveAttribute('href', '/site.booking.index');
  });

  it('shows the serial with its Track button when one is booked today', () => {
    show({ timeline: [], upcoming: [upcoming] });
    expect(screen.getByTestId('upcoming-code')).toHaveTextContent('B-011');
    expect(screen.getByRole('link', { name: 'Track my serial' })).toHaveAttribute('href', '/q/dr-rahman/today?s=ser_1');
    expect(screen.queryByRole('link', { name: 'Book a serial' })).toBeNull();
  });

  it('links a visit row to the live queue while open today, to the prescription once issued, and to nothing otherwise', () => {
    show({
      upcoming: [],
      timeline: [
        entry({ id: 1, meta: { queue_url: '/q/dr-rahman/today?s=ser_1', rx_url: null } }),
        entry({ id: 2, meta: { queue_url: '/q/dr-rahman/today?s=ser_1', rx_url: '/rx/ABCD1234' } }),
        entry({ id: 3, meta: { queue_url: null, rx_url: null } }),
        entry({ id: 4, kind: 'vital', title: 'Vitals', subtitle: null }),
      ],
    });
    const rows = screen.getAllByRole('listitem').filter((li) => li.textContent?.includes('Visit') || li.textContent?.includes('Vitals'));
    expect(rows).toHaveLength(4);
    expect(within(rows[0]!).getByRole('link', { name: 'Follow the live queue' })).toHaveAttribute('href', '/q/dr-rahman/today?s=ser_1');
    expect(within(rows[1]!).getByRole('link', { name: 'View prescription' })).toHaveAttribute('href', '/rx/ABCD1234');
    expect(within(rows[1]!).queryByRole('link', { name: 'Follow the live queue' })).toBeNull();
    expect(within(rows[2]!).queryByRole('link')).toBeNull();
    expect(within(rows[3]!).queryByRole('link')).toBeNull();
  });
});
