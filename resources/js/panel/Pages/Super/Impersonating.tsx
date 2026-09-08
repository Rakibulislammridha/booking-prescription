// The first screen a super admin sees after stepping into a clinic's panel as one of its staff.
//
// PanelLayout already keeps a thin amber banner on every subsequent page; this is the loud front door that gives
// that banner its meaning. It has to answer three questions before the operator clicks anything: WHO am I signed
// in as, at WHICH clinic, and since WHEN — plus the fact that everything done from here is written to that
// clinic's audit log under the operator's own name. Nobody should be able to forget they are impersonating.
import { type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import AlertTitle from '@mui/material/AlertTitle';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import ArrowForwardIcon from '@mui/icons-material/ArrowForward';
import LogoutIcon from '@mui/icons-material/Logout';
import WarningAmberIcon from '@mui/icons-material/WarningAmber';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { useSharedProps } from '@shared/inertia';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  tenant: { name: string | null; slug: string | null };
  user: { name: string; email: string; roles: string[] } | null;
  started_at: string;
  console_url: string;
}>;

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <Box sx={{ minWidth: 0 }}>
      <Typography variant="overline" color="text.secondary" sx={{ display: 'block', lineHeight: 1.6 }}>{label}</Typography>
      <Typography variant="h6" component="div" sx={{ wordBreak: 'break-word' }}>{children}</Typography>
    </Box>
  );
}

export default function Impersonating({ tenant, user, started_at, console_url }: Props) {
  const { t } = useTranslation();
  const shared = useSharedProps();
  const locale = getLocale();
  const clinic = tenant.name ?? tenant.slug ?? '—';

  return (
    <Box sx={{ maxWidth: 880, mx: 'auto' }}>
      <Alert severity="warning" variant="filled" icon={<WarningAmberIcon fontSize="inherit" />} sx={{ mb: 2 }}>
        <AlertTitle sx={{ fontWeight: 700 }}>{t('super.impersonation.banner_title')}</AlertTitle>
        {t('super.impersonation.banner_body')}
      </Alert>

      <Paper variant="outlined" sx={{ p: { xs: 2, md: 3 }, borderWidth: 2, borderColor: 'warning.main' }}>
        <Typography variant="h4" component="h1" sx={{ fontWeight: 700, mb: 1 }}>
          {t('super.impersonation.heading')}
        </Typography>
        <Typography variant="body1" color="text.secondary" sx={{ mb: 3 }}>
          {t('super.impersonation.lede', { user: user?.name ?? '—', clinic })}
        </Typography>

        <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', sm: 'repeat(3, 1fr)' } }}>
          <Fact label={t('super.impersonation.who')}>
            <span lang="bn">{user?.name ?? '—'}</span>
            <Typography variant="body2" color="text.secondary" sx={{ wordBreak: 'break-all' }}>{user?.email ?? '—'}</Typography>
            {user !== null && user.roles.length > 0 ? (
              <Stack direction="row" spacing={0.5} useFlexGap sx={{ flexWrap: 'wrap', mt: 0.5 }}>
                {user.roles.map((role) => <Chip key={role} size="small" variant="outlined" label={role} />)}
              </Stack>
            ) : null}
          </Fact>
          <Fact label={t('super.impersonation.where')}>
            <span lang="bn">{clinic}</span>
            {tenant.slug ? <Typography variant="body2" color="text.secondary">{tenant.slug}</Typography> : null}
          </Fact>
          <Fact label={t('super.impersonation.since')}>
            {started_at === '' ? '—' : formatDhaka(started_at, 'D MMM YYYY, h:mm a', locale)}
          </Fact>
        </Box>

        <Alert severity="warning" variant="outlined" sx={{ mt: 3 }}>
          {t('super.impersonation.audit_notice', { user: user?.name ?? '—', clinic })}
        </Alert>

        <Divider sx={{ my: 3 }} />

        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
          {hasRoute('panel.dashboard') ? (
            <Button
              component={RouterLink}
              href={route('panel.dashboard')}
              variant="contained"
              size="large"
              endIcon={<ArrowForwardIcon />}
              sx={{ py: 1.5, flexGrow: 1 }}
            >
              {t('super.impersonation.continue')}
            </Button>
          ) : null}

          {hasRoute('panel.impersonate.leave') ? (
            // A plain HTML form, not an Inertia post: `leave` answers with `redirect()->away()` back to the
            // console on the super host, and only a real browser navigation can follow a cross-host redirect.
            <Box component="form" method="post" action={route('panel.impersonate.leave')} sx={{ flexGrow: 1, display: 'flex' }}>
              <input type="hidden" name="_token" value={shared.csrf_token} />
              <Button type="submit" variant="outlined" color="warning" size="large" startIcon={<LogoutIcon />} sx={{ py: 1.5, flexGrow: 1 }}>
                {t('super.impersonation.leave')}
              </Button>
            </Box>
          ) : null}
        </Stack>

        <Typography variant="body2" sx={{ mt: 2 }}>
          {/* The console lives on a different host, so this can only ever be a plain anchor. */}
          <Box component="a" href={console_url} sx={{ color: 'text.secondary' }}>{t('super.impersonation.back_to_console')}</Box>
        </Typography>
      </Paper>
    </Box>
  );
}

Impersonating.layout = (page: ReactNode) => <PanelLayout title="super.impersonation.title">{page}</PanelLayout>;
