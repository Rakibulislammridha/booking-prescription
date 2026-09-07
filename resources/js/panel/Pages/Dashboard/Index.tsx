// Dashboard placeholder (Inertia::render('Dashboard/Index') from the panel surface). Modules add real widgets later.
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Typography from '@mui/material/Typography';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { useSharedProps } from '@shared/inertia';
import { useConnection, selectMode } from '@shared/connection/store';
import { formatDhaka } from '@shared/format/date';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{ today?: string }>;

export default function Index({ today }: Props) {
  const { t } = useTranslation();
  const shared = useSharedProps();
  const mode = useConnection(selectMode);
  const user = shared.auth.user;

  return (
    <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: 'repeat(3, 1fr)' } }}>
      <Card>
        <CardContent>
          <Typography variant="overline" color="text.secondary">{t('dashboard.welcome')}</Typography>
          <Typography variant="h5" component="h2">{user?.name ?? '—'}</Typography>
          <Stack direction="row" spacing={1} sx={{ mt: 1, flexWrap: "wrap" }}>
            {user?.roles.map((r) => <Chip key={r} size="small" label={t(`roles.${r}`, { defaultValue: r })} />)}
          </Stack>
        </CardContent>
      </Card>
      <Card>
        <CardContent>
          <Typography variant="overline" color="text.secondary">{t('nav.branch')}</Typography>
          <Typography variant="h5" component="h2">{shared.branch?.name ?? '—'}</Typography>
          <Typography variant="body2" color="text.secondary">{shared.tenant?.name}</Typography>
        </CardContent>
      </Card>
      <Card>
        <CardContent>
          <Typography variant="overline" color="text.secondary">{t('dashboard.today')}</Typography>
          <Typography variant="h5" component="h2">{formatDhaka(today ?? Date.now(), 'D MMM YYYY')}</Typography>
          <Typography variant="body2" color="text.secondary">{t(`connection.${mode}_short`)}</Typography>
        </CardContent>
      </Card>
      <Card sx={{ gridColumn: { md: '1 / -1' } }}>
        <CardContent>
          <Typography variant="h6" component="h2" gutterBottom>{t('dashboard.placeholder_title')}</Typography>
          <Typography variant="body2" color="text.secondary">{t('dashboard.placeholder_body')}</Typography>
        </CardContent>
      </Card>
    </Box>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="nav.dashboard">{page}</PanelLayout>;
