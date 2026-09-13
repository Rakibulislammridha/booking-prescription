// Who works this doctor's desk. The screen has to be honest in three states, because two of them are how it looks
// on the day the feature ships: nobody assigned yet, and no compounder accounts existing at all. A "no results"
// row for the second case would send the user looking for a picker that is never going to have anything in it.
//
// The fourth thing under test is the confirmation. Removing an assignment takes a board, a patient list and a fee
// screen away from someone who may be mid-shift, so it must not happen on one click of a small red button.
import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { ThemeProvider } from '@mui/material/styles';
import { I18nextProvider } from 'react-i18next';
import { theme } from '@panel/theme';
import { i18n, initI18n } from '@shared/i18n';
import { setZiggy } from '@shared/routes';
import type { ClinicCompounder, ClinicStaffUser } from '@shared/types/models';
import type { SharedProps } from '@shared/types/shared-props';
import Compounders from '../Compounders';

initI18n('en');

let props: SharedProps;
const del = vi.fn();

vi.mock('@inertiajs/react', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@inertiajs/react')>()),
  usePage: () => ({ props }),
  router: { delete: (url: string) => del(url), on: () => () => undefined },
}));
vi.mock('@panel/Layouts/PanelLayout', () => ({ PanelLayout: ({ children }: { children: React.ReactNode }) => <>{children}</> }));

setZiggy({
  url: 'http://demo.test', port: null, defaults: {},
  routes: Object.fromEntries(Object.entries({
    'panel.clinic.doctors.edit': 'panel/clinic/doctors/{doctor}/edit',
    'panel.clinic.doctors.compounders.index': 'panel/clinic/doctors/{doctor}/compounders',
    'panel.clinic.doctors.compounders.store': 'panel/clinic/doctors/{doctor}/compounders',
    'panel.clinic.doctors.compounders.destroy': 'panel/clinic/doctors/{doctor}/compounders/{compounder}',
    'panel.clinic.staff.index': 'panel/clinic/staff',
  }).map(([name, uri]) => [name, { uri, methods: ['GET'] }])),
});

const shared = (permissions: string[]): SharedProps => ({
  surface: 'panel',
  auth: { guard: 'web', user: { id: 1, name: 'Rahim', roles: ['hospital_admin'], permissions, doctor_id: null }, impersonating: false },
  tenant: null, branch: null, branches: [], today_session: null, locale: 'en',
  flash: { success: null, error: null, warning: null, info: null },
  features: {}, ziggy: { url: 'http://demo.test', port: null, defaults: {}, routes: {} }, csrf_token: 'x',
  app: { name: 'bp', env: 'testing', version: '1', reverb: { key: 'k', host: 'localhost', port: 8080, scheme: 'http' } },
  errors: {},
});

const DOCTOR = { public_id: 'doc_1', name: 'Dr. Md. Abdur Rahman', name_bn: null, code: 'RAH' };

const assigned: ClinicCompounder = {
  id: 22, public_id: 'usr_22', name: 'Shafiqul Islam', email: 'shafiq@demo.test', mobile: '+8801711000021',
  is_active: true, assigned_at: '2026-02-14T04:30:00Z', assigned_by: 'Dr. Md. Abdur Rahman',
};

const free = (id: number, name: string): ClinicStaffUser => ({
  id, public_id: `usr_${id}`, name, email: `${name.toLowerCase()}@demo.test`, mobile: null, default_branch_id: 1,
  locale: 'bn', is_active: true, roles: ['compounder'], role: 'compounder', must_change_password: false,
  session_timeout_minutes: null, last_login_at: null,
});

function show(over: { compounders?: ClinicCompounder[]; available?: ClinicStaffUser[]; permissions?: string[] } = {}) {
  props = shared(over.permissions ?? ['clinic.doctors.manage', 'clinic.users.manage']);
  return render(
    <I18nextProvider i18n={i18n}><ThemeProvider theme={theme}>
      <Compounders
        doctor={DOCTOR}
        compounders={over.compounders ?? [assigned]}
        available={over.available ?? [free(31, 'Nasima')]}
        can={{ manage: true }}
        {...props}
      />
    </ThemeProvider></I18nextProvider>,
  );
}

describe('Clinic/Doctors/Compounders', () => {
  it('names the doctor, states the two limits, and lists who is on the desk with when and by whom', () => {
    show();

    expect(screen.getByText('Compounders for Dr. Md. Abdur Rahman')).toBeInTheDocument();
    expect(screen.getByText(/never another doctor's patient list/)).toBeInTheDocument();

    const row = screen.getByText('Shafiqul Islam').closest('tr') as HTMLElement;
    expect(within(row).getByText('14 Feb 2026')).toBeInTheDocument();
    expect(within(row).getByText('by Dr. Md. Abdur Rahman')).toBeInTheDocument();
    expect(within(row).getByText('Active')).toBeInTheDocument();
  });

  it('says the desk is empty rather than showing a bare table', () => {
    show({ compounders: [] });

    expect(screen.getByText("No compounder works this doctor's desk yet.")).toBeInTheDocument();
  });

  it('says the POOL is empty — a different problem, with a different way out', () => {
    show({ compounders: [], available: [] });

    expect(screen.getByText(/No compounder accounts yet/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Create a compounder account' })).toHaveAttribute('href', '/panel/clinic/staff');
    // the picker is gone entirely: there is nothing to pick
    expect(screen.queryByLabelText('Staff account')).toBeNull();
  });

  it('does not offer the staff screen to a doctor who cannot open it', () => {
    show({ compounders: [], available: [], permissions: ['clinic.pad.design'] });

    expect(screen.getByText(/No compounder accounts yet/)).toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Create a compounder account' })).toBeNull();
  });

  it('will not assign until a staff account is chosen', () => {
    show();

    expect(screen.getByRole('button', { name: 'Assign compounder' })).toBeDisabled();
  });

  // The picker's value is the staff account's public ULID, because that is the only thing
  // StoreDoctorCompounderRequest accepts (`user_public_id`, `size:26`). It used to post the bigint `id`, and the
  // two are interchangeable to look at: a wrong one here is a validation error on a screen that seems fine.
  it('picks a staff account by its public id — the field the request validates', async () => {
    show();

    fireEvent.mouseDown(screen.getByLabelText('Staff account'));
    const option = await screen.findByRole('option', { name: /Nasima/ });

    expect(option).toHaveAttribute('data-value', 'usr_31');
    fireEvent.click(option);
    expect(screen.getByRole('button', { name: 'Assign compounder' })).toBeEnabled();
  });

  it('asks before it takes the desk away, and only then calls the route', () => {
    show();
    del.mockClear();

    fireEvent.click(screen.getByRole('button', { name: 'Remove' }));
    expect(del).not.toHaveBeenCalled();
    expect(screen.getByText(/Remove Shafiqul Islam from this doctor's desk\?/)).toBeInTheDocument();

    const dialog = screen.getByRole('dialog');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Remove' }));
    expect(del).toHaveBeenCalledWith('/panel/clinic/doctors/doc_1/compounders/usr_22');
  });
});
