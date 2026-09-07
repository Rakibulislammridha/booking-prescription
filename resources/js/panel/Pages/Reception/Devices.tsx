// Registered reception devices (Inertia::render('Reception/Devices')): name, branch, number, last seen/sync, revoke (Hospital Admin).
import type { ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import Card from '@mui/material/Card';
import Table from '@mui/material/Table';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TableCell from '@mui/material/TableCell';
import TableBody from '@mui/material/TableBody';
import Chip from '@mui/material/Chip';
import Button from '@mui/material/Button';
import Box from '@mui/material/Box';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { route } from '@shared/routes';
import { formatDhaka } from '@shared/format/date';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { ReceptionDevice } from '@shared/types/models';

type Props = PageProps<{ devices: ReceptionDevice[]; can: { revoke: boolean } }>;

export default function Devices({ devices, can }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const revoke = (d: ReceptionDevice): void => {
    if (!window.confirm(t('reception.device.revoke_confirm', { name: d.name }))) return;
    router.post(route('panel.reception.devices.revoke', { device: d.public_id }), {}, { preserveScroll: true });
  };

  return (
    <Stack spacing={2}>
      <Typography variant="h5" component="h1">{t('reception.device.list_title')}</Typography>
      <Card variant="outlined">
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead><TableRow><TableCell>{t('reception.device.name')}</TableCell><TableCell>#</TableCell><TableCell>{t('reception.device.kind')}</TableCell><TableCell>{t('reception.device.status')}</TableCell><TableCell>{t('reception.device.last_seen')}</TableCell><TableCell>{t('reception.device.last_sync')}</TableCell><TableCell /></TableRow></TableHead>
            <TableBody>
              {devices.length === 0 ? <TableRow><TableCell colSpan={7}><Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('reception.device.none')}</Typography></TableCell></TableRow> : null}
              {devices.map((d) => (
                <TableRow key={d.public_id}>
                  <TableCell>{d.name}</TableCell>
                  <TableCell>{formatBn(d.number, locale)}</TableCell>
                  <TableCell>{t(`reception.device.kinds.${d.kind}`)}</TableCell>
                  <TableCell><Chip size="small" color={d.status === 'active' ? 'success' : 'default'} label={t(`reception.device.statuses.${d.status}`)} /></TableCell>
                  <TableCell>{d.last_seen_at ? formatDhaka(d.last_seen_at) : '—'}</TableCell>
                  <TableCell>{d.last_sync_at ? formatDhaka(d.last_sync_at) : '—'}</TableCell>
                  <TableCell align="right">{can.revoke && d.status === 'active' ? <Button size="small" color="error" onClick={() => revoke(d)}>{t('reception.device.revoke')}</Button> : null}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
      </Card>
    </Stack>
  );
}

Devices.layout = (page: ReactNode) => <PanelLayout title="reception.device.list_title">{page}</PanelLayout>;
