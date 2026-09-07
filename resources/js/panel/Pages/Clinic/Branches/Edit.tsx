// Clinic/Branches/Edit — same form as Create; UpdateBranch keeps the single-main invariant.
import { type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { BranchForm, type BranchFormData } from '@panel/Components/Clinic/BranchForm';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { ClinicBranch } from '@shared/types/models';

type Props = PageProps<{ branch: ClinicBranch; timezone: string }>;

export default function Edit({ branch, timezone }: Props) {
  const { t } = useTranslation();
  const form = useForm<BranchFormData>({
    name: branch.name,
    code: branch.code,
    slug: branch.slug,
    address: branch.address ?? '',
    phone: branch.phone ?? '',
    email: branch.email ?? '',
    is_main: branch.is_main,
    is_active: branch.is_active,
  });

  return (
    <form onSubmit={(e) => { e.preventDefault(); form.put(route('panel.clinic.branches.update', { branch: branch.public_id })); }}>
      <Stack spacing={2} sx={{ maxWidth: 900 }}>
        <BranchForm form={form} timezone={timezone} />
        <Stack direction="row" spacing={1}>
          <Button type="submit" variant="contained" disabled={form.processing}>{t('common.actions.save')}</Button>
          <Button component={RouterLink} href={route('panel.clinic.branches.index')}>{t('common.actions.cancel')}</Button>
        </Stack>
      </Stack>
    </form>
  );
}

Edit.layout = (page: ReactNode) => <PanelLayout title="clinic.branches.edit_title">{page}</PanelLayout>;
