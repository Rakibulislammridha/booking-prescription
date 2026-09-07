// Clinic/Staff/Edit. An admin editing their own account cannot take their admin role off or deactivate themselves —
// the form locks both, and UpdateStaffUser refuses them anyway for any caller (CannotDemoteSelf / CannotDeactivateSelf).
import { type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import KeyIcon from '@mui/icons-material/VpnKey';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { StaffForm, type StaffFormData } from '@panel/Components/Clinic/StaffForm';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { ClinicBranch, ClinicStaffUser } from '@shared/types/models';

type Props = PageProps<{ user: ClinicStaffUser; /** Named `branch_options`, not `branches`: SharedProps already owns `branches` (the switcher list). */
  branch_options: ClinicBranch[]; roles: string[]; is_self: boolean }>;

export default function Edit({ user, branch_options, roles, is_self }: Props) {
  const { t } = useTranslation();
  const form = useForm<StaffFormData>({
    name: user.name,
    email: user.email,
    mobile: user.mobile ?? '',
    role: user.role ?? 'receptionist',
    default_branch_id: user.default_branch_id ? String(user.default_branch_id) : '',
    locale: user.locale,
    is_active: user.is_active,
    must_change_password: user.must_change_password,
    session_timeout_minutes: user.session_timeout_minutes ? String(user.session_timeout_minutes) : '',
  });

  return (
    <form onSubmit={(e) => { e.preventDefault(); form.put(route('panel.clinic.staff.update', { user: user.public_id })); }}>
      <Stack spacing={2} sx={{ maxWidth: 900 }}>
        <StaffForm form={form} branches={branch_options} roles={roles} isSelf={is_self} isAdminSelf={is_self && user.role === 'hospital_admin'} />
        <Stack direction="row" spacing={1}>
          <Button type="submit" variant="contained" disabled={form.processing}>{t('common.actions.save')}</Button>
          <Button component={RouterLink} href={route('panel.clinic.staff.index')}>{t('common.actions.cancel')}</Button>
          <Button
            startIcon={<KeyIcon />}
            onClick={() => router.post(route('panel.clinic.staff.password_reset', { user: user.public_id }), {}, { preserveScroll: true })}
          >
            {t('clinic.staff.send_reset')}
          </Button>
        </Stack>
      </Stack>
    </form>
  );
}

Edit.layout = (page: ReactNode) => <PanelLayout title="clinic.staff.edit_title">{page}</PanelLayout>;
