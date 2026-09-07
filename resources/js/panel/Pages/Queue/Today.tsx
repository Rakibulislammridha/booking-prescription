// Today's queues at the active branch (Inertia::render('Queue/Today')): one row per session with now serving,
// waiting and the delay badge, each linking into the doctor screen. Live over the reception channel's
// `board.updated`; polled every 5 s in degraded mode.
import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
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
import { fetchQueueToday } from '@panel/api/queue';
import { useChannel } from '@shared/realtime/useChannel';
import { QUEUE_EVENTS } from '@shared/realtime/types';
import { useConnection } from '@shared/connection/store';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { QueueBoard, QueueDoctor } from '@shared/types/models';

type Doctors = Record<string, Omit<QueueDoctor, 'public_id'>>;

type Props = PageProps<{
  tenant_public_id: string | null;
  channel: string | null;
  branch: { public_id: string; slug: string; name: string };
  date: string;
  board: QueueBoard;
  doctors: Doctors;
  can: { call_next: boolean };
}>;

const POLL_MS = 5_000;

export default function Today({ channel, branch, date, board: initial, doctors: initialDoctors }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [board, setBoard] = useState<QueueBoard>(initial);
  const [doctors, setDoctors] = useState<Doctors>(initialDoctors);
  const mode = useConnection((s) => s.mode);

  const refresh = useCallback(async (): Promise<void> => {
    const data = await fetchQueueToday();
    setBoard(data.board);
    setDoctors(data.doctors);
  }, []);

  useChannel(channel, {
    [QUEUE_EVENTS.boardUpdated]: (payload: unknown) => setBoard(payload as QueueBoard),
    [QUEUE_EVENTS.serialCalled]: () => { void refresh().catch(() => undefined); },
  }, { enabled: mode === 'online' && Boolean(channel) });

  useEffect(() => {
    if (mode !== 'degraded') return undefined;
    const id = window.setInterval(() => { void refresh().catch(() => undefined); }, POLL_MS);
    return () => window.clearInterval(id);
  }, [mode, refresh]);

  return (
    <Stack spacing={2}>
      <Typography variant="h5" component="h1">{branch.name} · {formatBn(date, locale)}</Typography>
      {mode !== 'online' ? <Alert severity="info">{t('queue.page.polling')}</Alert> : null}

      <Paper variant="outlined">
        {board.sessions.length === 0 ? (
          <Typography sx={{ p: 3 }} color="text.secondary">{t('queue.today.empty')}</Typography>
        ) : (
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{t('queue.doctor.title')}</TableCell>
                <TableCell>{t('queue.today.now_serving')}</TableCell>
                <TableCell align="right">{t('queue.today.waiting')}</TableCell>
                <TableCell align="right">{t('queue.page.done')}</TableCell>
                <TableCell>{t('queue.today.delay')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {board.sessions.map((s) => {
                const doctor = doctors[s.doctor];
                const name = doctor ? (locale === 'bn' && doctor.name_bn ? doctor.name_bn : doctor.name) : s.doctor;
                return (
                  <TableRow key={s.id} hover>
                    <TableCell>
                      {name}
                      <Chip size="small" sx={{ ml: 1 }} label={`${formatBn(s.code, locale)} · ${t(`queue.status.${s.status}`)}`} />
                    </TableCell>
                    <TableCell sx={{ fontWeight: 700 }}>{s.now_serving ? formatBn(s.now_serving, locale) : '—'}</TableCell>
                    <TableCell align="right">{formatBn(s.counts.booked + s.counts.checked_in, locale)}</TableCell>
                    <TableCell align="right">{formatBn(s.counts.completed, locale)}</TableCell>
                    <TableCell>{s.delay_minutes > 0 ? <Chip size="small" color="warning" label={formatBn(s.delay_minutes, locale)} /> : null}</TableCell>
                    <TableCell align="right">
                      {doctor ? (
                        <Button size="small" component={RouterLink} href={`${route('panel.queue.doctor')}?doctor=${doctor.slug}&session=${s.id}`}>
                          {t('queue.today.open_doctor')}
                        </Button>
                      ) : null}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        )}
      </Paper>
    </Stack>
  );
}

Today.layout = (page: ReactNode) => <PanelLayout title="queue.today.title">{page}</PanelLayout>;
