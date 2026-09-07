// Rendered by EnsureTenantIsActive (HTTP 402) when the tenant is suspended — the panel-root twin of site/Pages/Suspended.
import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{ tenant: { name: string | null } }>;

export default function Suspended({ tenant }: Props) {
  const { t } = useTranslation();

  return (
    <Box sx={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', p: 2, bgcolor: 'grey.100' }}>
      <Head title={t('tenancy.suspended_title')} />
      <Paper elevation={1} sx={{ maxWidth: 480, p: { xs: 3, sm: 4 } }}>
        <Typography variant="h5" component="h1" gutterBottom>{tenant.name ?? t('tenancy.suspended_title')}</Typography>
        <Typography variant="body1" sx={{ mb: 1 }}>{t('tenancy.suspended')}</Typography>
        <Typography variant="body2" color="text.secondary">{t('tenancy.suspended_help')}</Typography>
      </Paper>
    </Box>
  );
}
