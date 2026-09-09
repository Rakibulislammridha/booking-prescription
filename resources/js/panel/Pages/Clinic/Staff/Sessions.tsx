// Clinic/Staff/Sessions — staff device management (BRIEF §5.N). Every device this account is signed in on, and a
// button to throw one off. Sessions are addressed by an opaque `ref`, never by the session id itself.
import { type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Typography from '@mui/material/Typography';
import LogoutIcon from '@mui/icons-material/Logout';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { route } from '@shared/routes';
import { formatDhaka } from '@shared/format/date';
import type { PageProps } from '@shared/types/inertia';
import type { StaffSession } from '@shared/types/models';

type Props = PageProps<{
  staff: { public_id: string; name: string; email: string };
  sessions: StaffSession[];
  idle_timeout_minutes: number;
  is_self: boolean;
}>;

export default function Sessions({ staff, sessions, idle_timeout_minutes, is_self }: Props) {
  const { t } = useTranslation();

  const revoke = (ref: string): void => {
    router.delete(route('panel.clinic.staff.sessions.destroy', { user: staff.public_id, ref }), { preserveScroll: true });
  };
  const revokeOthers = (): void => {
    if (!window.confirm(t('clinic.sessions.confirm_others'))) return;
    router.delete(route('panel.clinic.staff.sessions.destroy_others', { user: staff.public_id }), { preserveScroll: true });
  };

  const others = sessions.filter((s) => !s.is_current).length;

  return (
    <Stack spacing={2}>
      <Box>
        <Typography variant="h6">{staff.name}</Typography>
        <Typography variant="body2" color="text.secondary">{staff.email}</Typography>
      </Box>

      <Typography variant="body2" color="text.secondary">{t('clinic.sessions.subtitle')}</Typography>
      <Alert severity="info">{t('clinic.sessions.idle_note', { minutes: idle_timeout_minutes })}</Alert>
      <Alert severity="warning">{t('clinic.sessions.remember_note')}</Alert>

      <Card>
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small" aria-label={t('clinic.sessions.title')}>
            <TableHead>
              <TableRow>
                <TableCell>{t('clinic.sessions.columns.device')}</TableCell>
                <TableCell>{t('clinic.sessions.columns.ip')}</TableCell>
                <TableCell>{t('clinic.sessions.columns.signed_in')}</TableCell>
                <TableCell>{t('clinic.sessions.columns.last_seen')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {sessions.length === 0 ? (
                <TableRow><TableCell colSpan={5}><Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('clinic.sessions.empty')}</Typography></TableCell></TableRow>
              ) : sessions.map((session) => (
                <TableRow key={session.ref} hover>
                  <TableCell>
                    <Typography variant="body2" sx={{ fontWeight: 600 }}>
                      {session.device}
                      {session.is_current && is_self ? <Chip size="small" color="primary" label={t('clinic.sessions.current')} sx={{ ml: 1 }} /> : null}
                    </Typography>
                  </TableCell>
                  <TableCell>{session.ip ?? '—'}</TableCell>
                  <TableCell>{formatDhaka(session.login_at)}</TableCell>
                  <TableCell>{formatDhaka(session.last_seen_at)}</TableCell>
                  <TableCell align="right">
                    <Button size="small" color="error" startIcon={<LogoutIcon />} onClick={() => revoke(session.ref)}>
                      {t('clinic.sessions.revoke')}
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
      </Card>

      {others > 0 ? (
        <Box>
          <Button variant="outlined" color="error" onClick={revokeOthers}>{t('clinic.sessions.revoke_others')}</Button>
        </Box>
      ) : null}
    </Stack>
  );
}

Sessions.layout = (page: ReactNode) => <PanelLayout title="clinic.sessions.title">{page}</PanelLayout>;
