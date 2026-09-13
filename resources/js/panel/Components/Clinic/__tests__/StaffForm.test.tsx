// Picking a role is the whole of making a staff account — except for one role. A compounder's four permissions
// mean nothing until a doctor puts them on their desk (`doctor_compounder`, read by DoctorScope), so the account
// an admin has just created signs in to an empty board. The generic "roles carry the permissions" line is
// therefore false for exactly this choice, and an admin who believed it would go hunting for a bug. This pins the
// helper line that says what is still missing, and that every other role keeps the generic one.
import { describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { ThemeProvider } from '@mui/material/styles';
import { I18nextProvider } from 'react-i18next';
import type { InertiaFormProps } from '@inertiajs/react';
import { theme } from '@panel/theme';
import { i18n, initI18n } from '@shared/i18n';
import { StaffForm, type StaffFormData } from '../StaffForm';

initI18n('en');

const ROLES = ['hospital_admin', 'doctor', 'receptionist', 'compounder', 'accountant'];

const GENERIC = 'Roles carry the permissions; a person has exactly one.';

function formFor(role: string): InertiaFormProps<StaffFormData> {
  return {
    data: {
      name: 'Nasima Akter', email: 'nasima@demo.test', mobile: '', role,
      default_branch_id: '', locale: 'bn', is_active: true, must_change_password: true, session_timeout_minutes: '',
    },
    errors: {},
    setData: vi.fn(),
  } as unknown as InertiaFormProps<StaffFormData>;
}

const show = (role: string) => render(
  <I18nextProvider i18n={i18n}><ThemeProvider theme={theme}>
    <StaffForm form={formFor(role)} branches={[]} roles={ROLES} />
  </ThemeProvider></I18nextProvider>,
);

describe('Clinic/StaffForm — the role helper', () => {
  it('tells an admin creating a compounder that the account is not finished yet, and where to finish it', () => {
    show('compounder');

    expect(screen.getByText(/sees nothing until a doctor assigns them/)).toBeInTheDocument();
    expect(screen.getByText(/Setup → Doctors, then Compounders/)).toBeInTheDocument();
    expect(screen.queryByText(GENERIC)).toBeNull();
  });

  it('leaves every other role with the generic line', () => {
    for (const role of ROLES.filter((r) => r !== 'compounder')) {
      const { unmount } = show(role);
      expect(screen.queryByText(GENERIC), role).not.toBeNull();
      expect(screen.queryByText(/sees nothing until a doctor assigns them/), role).toBeNull();
      unmount();
    }
  });
});
