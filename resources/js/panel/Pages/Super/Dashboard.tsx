// Super-admin landing page (Inertia::render('Super/Dashboard')). The SaaS module replaces it with the real console;
// until then it proves the super surface renders through the panel root (PanelLayout, guard `super`).
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { useSharedProps } from '@shared/inertia';
import { formatBn } from '@shared/format/number';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{ tenants_count: number }>;

export default function Dashboard({ tenants_count }: Props) {
  const { t } = useTranslation();
  const shared = useSharedProps();

  return (
    <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: 'repeat(3, 1fr)' } }}>
      <Card>
        <CardContent>
          <Typography variant="overline" color="text.secondary">{t('dashboard.welcome')}</Typography>
          <Typography variant="h5" component="h2">{shared.auth.user?.name ?? '—'}</Typography>
        </CardContent>
      </Card>
      <Card>
        <CardContent>
          <Typography variant="overline" color="text.secondary">{t('super.dashboard.tenants')}</Typography>
          <Typography variant="h5" component="h2">{formatBn(tenants_count, shared.locale)}</Typography>
        </CardContent>
      </Card>
      <Card sx={{ gridColumn: { md: '1 / -1' } }}>
        <CardContent>
          <Typography variant="h6" component="h2" gutterBottom>{t('super.dashboard.placeholder_title')}</Typography>
          <Typography variant="body2" color="text.secondary">{t('super.dashboard.placeholder_body')}</Typography>
        </CardContent>
      </Card>
    </Box>
  );
}

Dashboard.layout = (page: ReactNode) => <PanelLayout title="super.dashboard.title">{page}</PanelLayout>;
