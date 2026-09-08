// Today's video consultations. A board, not a second queue: the ordering, the statuses and the serial codes all
// come from the ordinary serial the appointment already owns.
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { formatBdt } from '@shared/format/money';
import { formatTimeDhaka } from '@shared/format/date';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { TelemedicineRoomSummary } from '@shared/types/models';

type Props = PageProps<{ date: string; rooms: TelemedicineRoomSummary[] }>;

const STATUS_COLOR: Record<string, 'default' | 'info' | 'success' | 'warning'> = {
  scheduled: 'default',
  open: 'success',
  ended: 'info',
  cancelled: 'warning',
};

export default function Index({ rooms }: Props) {
  const { t } = useTranslation();

  return (
    <Paper variant="outlined" sx={{ p: 2 }} data-testid="telemedicine-board">
      <Stack direction="row" spacing={1} sx={{ mb: 2, alignItems: 'center' }}>
        <Typography variant="h6">{t('telemedicine.board.title')}</Typography>
        <Chip size="small" label={rooms.length} />
      </Stack>

      {rooms.length === 0 ? (
        <Typography variant="body2" color="text.secondary" data-testid="board-empty">{t('telemedicine.board.empty')}</Typography>
      ) : (
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{t('telemedicine.board.time')}</TableCell>
                <TableCell>{t('telemedicine.board.patient')}</TableCell>
                <TableCell>{t('telemedicine.board.serial')}</TableCell>
                <TableCell>{t('telemedicine.board.fee')}</TableCell>
                <TableCell>{t('telemedicine.board.status')}</TableCell>
                <TableCell align="right" />
              </TableRow>
            </TableHead>
            <TableBody>
              {rooms.map((room) => (
                <TableRow key={room.room} hover data-testid={`room-${room.room}`}>
                  <TableCell>{formatTimeDhaka(room.scheduled_at)}</TableCell>
                  <TableCell>
                    {room.patient.name}
                    <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{room.patient.code}</Typography>
                  </TableCell>
                  <TableCell>{room.serial?.code ?? '—'}</TableCell>
                  <TableCell>{formatBdt(room.fee_paisa)}</TableCell>
                  <TableCell><Chip size="small" color={STATUS_COLOR[room.status] ?? 'default'} label={t(`telemedicine.console.status.${room.status}`)} /></TableCell>
                  <TableCell align="right">
                    <Button size="small" variant="contained" component={RouterLink} href={route('panel.telemedicine.console', { room: room.room })} data-testid={`open-${room.room}`}>
                      {t('telemedicine.board.open')}
                    </Button>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
      )}
    </Paper>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="telemedicine.board.title">{page}</PanelLayout>;
