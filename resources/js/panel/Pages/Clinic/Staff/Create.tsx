// Clinic/Staff/Create — no password field by design: the account is created with a random one and
// `must_change_password`, and the admin sends a reset link from the list.
import { type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { StaffForm, type StaffFormData } from '@panel/Components/Clinic/StaffForm';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { ClinicBranch } from '@shared/types/models';

type Props = PageProps<{ /** Named `branch_options`, not `branches`: SharedProps already owns `branches` (the switcher list). */
  branch_options: ClinicBranch[]; roles: string[] }>;

export default function Create({ branch_options, roles }: Props) {
  const { t } = useTranslation();
  const form = useForm<StaffFormData>({
    name: '', email: '', mobile: '', role: 'receptionist',
    default_branch_id: branch_options.find((b) => b.is_main)?.id ? String(branch_options.find((b) => b.is_main)?.id) : '',
    locale: 'bn', is_active: true, must_change_password: true, session_timeout_minutes: '',
  });

  return (
    <form onSubmit={(e) => { e.preventDefault(); form.post(route('panel.clinic.staff.store')); }}>
      <Stack spacing={2} sx={{ maxWidth: 900 }}>
        <Alert severity="info">{t('clinic.staff.create_password_notice')}</Alert>
        <StaffForm form={form} branches={branch_options} roles={roles} />
        <Stack direction="row" spacing={1}>
          <Button type="submit" variant="contained" disabled={form.processing}>{t('common.actions.save')}</Button>
          <Button component={RouterLink} href={route('panel.clinic.staff.index')}>{t('common.actions.cancel')}</Button>
        </Stack>
      </Stack>
    </form>
  );
}

Create.layout = (page: ReactNode) => <PanelLayout title="clinic.staff.create_title">{page}</PanelLayout>;
