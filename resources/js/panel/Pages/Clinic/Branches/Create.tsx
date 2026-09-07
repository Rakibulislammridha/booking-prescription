// Clinic/Branches/Create — the first screen of a clinic's setup. With no branches yet, the main-branch switch is
// forced on, because CreateBranch makes the first branch main whatever the form says.
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

type Props = PageProps<{ timezone: string; has_branches: boolean }>;

export default function Create({ timezone, has_branches }: Props) {
  const { t } = useTranslation();
  const form = useForm<BranchFormData>({
    name: '', code: '', slug: '', address: '', phone: '', email: '', is_main: !has_branches, is_active: true,
  });

  return (
    <form onSubmit={(e) => { e.preventDefault(); form.post(route('panel.clinic.branches.store')); }}>
      <Stack spacing={2} sx={{ maxWidth: 900 }}>
        <BranchForm form={form} timezone={timezone} forcedMain={!has_branches} />
        <Stack direction="row" spacing={1}>
          <Button type="submit" variant="contained" disabled={form.processing}>{t('common.actions.save')}</Button>
          <Button component={RouterLink} href={route('panel.clinic.branches.index')}>{t('common.actions.cancel')}</Button>
        </Stack>
      </Stack>
    </form>
  );
}

Create.layout = (page: ReactNode) => <PanelLayout title="clinic.branches.create_title">{page}</PanelLayout>;
