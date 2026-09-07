// Notifications/Index — the outbound log. Filters by channel, status, event and date range; a row opens the
// attempt drawer; a dead-lettered row can be queued again. The dead-letter count is the chip that matters: it is
// the number of patients who were told nothing.
import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import MenuItem from '@mui/material/MenuItem';
import Pagination from '@mui/material/Pagination';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import ReplayIcon from '@mui/icons-material/Replay';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { AttemptDrawer } from '@panel/Components/Notifications/AttemptDrawer';
import { route } from '@shared/routes';
import { formatDhaka } from '@shared/format/date';
import { formatBn } from '@shared/format/number';
import type { PageProps } from '@shared/types/inertia';
import type { Locale } from '@shared/types/shared-props';
import type { NotificationRow, NotificationStatus } from '@shared/types/models';

interface Filters {
  channel: string | null;
  status: string | null;
  event_key: string | null;
  from: string | null;
  to: string | null;
  q: string;
}

type Props = PageProps<{
  filters: Filters;
  notifications: { data: NotificationRow[]; meta: { current_page: number; last_page: number; total: number } };
  options: { channels: string[]; statuses: string[]; events: string[] };
  stats: Record<string, number>;
  can: { retry: boolean };
}>;

const STATUS_COLOR: Record<NotificationStatus, 'default' | 'success' | 'error' | 'warning' | 'info'> = {
  queued: 'default',
  scheduled: 'info',
  sending: 'info',
  sent: 'info',
  delivered: 'success',
  failed: 'error',
  cancelled: 'default',
};

export default function Index({ filters, notifications, options, stats, can }: Props) {
  const { t, i18n } = useTranslation();
  const locale: Locale = i18n.language === 'bn' ? 'bn' : 'en';
  const [open, setOpen] = useState<number | null>(null);
  const [q, setQ] = useState(filters.q);

  const apply = (patch: Partial<Filters>): void => {
    router.get(route('panel.notifications.index'), { ...filters, ...patch } as Record<string, string>, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
    });
  };

  const retry = (row: NotificationRow): void => {
    if (!window.confirm(t('notifications.index.retry_confirm'))) return;
    router.post(route('panel.notifications.retry', { notification: row.id }), {}, { preserveScroll: true });
  };

  return (
    <Stack spacing={2}>
      <Box>
        <Typography variant="h5">{t('notifications.index.title')}</Typography>
        <Typography variant="body2" color="text.secondary">
          {t('notifications.index.subtitle')}
        </Typography>
      </Box>

      <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', gap: 1 }}>
        <Chip color="error" variant={(stats.failed ?? 0) > 0 ? 'filled' : 'outlined'} label={`${t('notifications.index.dead_letters')}: ${formatBn(stats.failed ?? 0, locale)}`} />
        {(['delivered', 'sent', 'scheduled', 'queued'] as const).map((status) => (
          <Chip key={status} variant="outlined" label={`${t(`notifications.status.${status}`)}: ${formatBn(stats[status] ?? 0, locale)}`} />
        ))}
      </Stack>

      <Card sx={{ p: 2 }}>
        <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5}>
          <TextField
            select
            size="small"
            label={t('notifications.index.filter_channel')}
            value={filters.channel ?? ''}
            onChange={(e) => apply({ channel: e.target.value || null })}
            sx={{ minWidth: 140 }}
          >
            <MenuItem value="">{t('common.status.none')}</MenuItem>
            {options.channels.map((channel) => (
              <MenuItem key={channel} value={channel}>
                {t(`notifications.channel.${channel}`)}
              </MenuItem>
            ))}
          </TextField>

          <TextField
            select
            size="small"
            label={t('notifications.index.filter_status')}
            value={filters.status ?? ''}
            onChange={(e) => apply({ status: e.target.value || null })}
            sx={{ minWidth: 140 }}
          >
            <MenuItem value="">{t('common.status.none')}</MenuItem>
            {options.statuses.map((status) => (
              <MenuItem key={status} value={status}>
                {t(`notifications.status.${status}`)}
              </MenuItem>
            ))}
          </TextField>

          <TextField
            select
            size="small"
            label={t('notifications.index.filter_event')}
            value={filters.event_key ?? ''}
            onChange={(e) => apply({ event_key: e.target.value || null })}
            sx={{ minWidth: 180 }}
          >
            <MenuItem value="">{t('common.status.none')}</MenuItem>
            {options.events.map((event) => (
              <MenuItem key={event} value={event}>
                {t(`notifications.event.${event}`)}
              </MenuItem>
            ))}
          </TextField>

          <TextField
            type="date"
            size="small"
            label={t('notifications.index.filter_from')}
            slotProps={{ inputLabel: { shrink: true } }}
            value={filters.from ?? ''}
            onChange={(e) => apply({ from: e.target.value || null })}
          />
          <TextField
            type="date"
            size="small"
            label={t('notifications.index.filter_to')}
            slotProps={{ inputLabel: { shrink: true } }}
            value={filters.to ?? ''}
            onChange={(e) => apply({ to: e.target.value || null })}
          />
          <TextField
            size="small"
            label={t('notifications.index.search')}
            value={q}
            onChange={(e) => setQ(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') apply({ q });
            }}
          />
          <Button onClick={() => { setQ(''); apply({ channel: null, status: null, event_key: null, from: null, to: null, q: '' }); }}>
            {t('notifications.index.clear_filters')}
          </Button>
        </Stack>
      </Card>

      <Card>
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{t('notifications.index.col_created')}</TableCell>
                <TableCell>{t('notifications.index.col_event')}</TableCell>
                <TableCell>{t('notifications.index.col_channel')}</TableCell>
                <TableCell>{t('notifications.index.col_recipient')}</TableCell>
                <TableCell>{t('notifications.index.col_status')}</TableCell>
                <TableCell align="right">{t('notifications.index.col_attempts')}</TableCell>
                <TableCell align="right">{t('notifications.index.col_segments')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {notifications.data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={8}>
                    <Typography variant="body2" color="text.secondary" sx={{ py: 3, textAlign: 'center' }}>
                      {t('notifications.index.empty')}
                    </Typography>
                  </TableCell>
                </TableRow>
              ) : (
                notifications.data.map((row) => (
                  <TableRow key={row.id} hover sx={{ cursor: 'pointer' }} onClick={() => setOpen(row.id)}>
                    <TableCell>{formatDhaka(row.created_at, 'D MMM, h:mm a', locale)}</TableCell>
                    <TableCell>{t(`notifications.event.${row.event_key}`)}</TableCell>
                    <TableCell>{t(`notifications.channel.${row.channel}`)}</TableCell>
                    <TableCell sx={{ fontFamily: 'monospace' }}>{row.recipient}</TableCell>
                    <TableCell>
                      <Chip size="small" color={STATUS_COLOR[row.status]} variant={row.status === 'failed' ? 'filled' : 'outlined'} label={t(`notifications.status.${row.status}`)} />
                      {row.last_error ? (
                        <Typography variant="caption" color="text.secondary" component="div">
                          {row.last_error}
                        </Typography>
                      ) : null}
                    </TableCell>
                    <TableCell align="right">{formatBn(row.attempts, locale)}</TableCell>
                    <TableCell align="right">{row.segments === null ? '—' : formatBn(row.segments, locale)}</TableCell>
                    <TableCell align="right">
                      {can.retry && row.status === 'failed' ? (
                        <Button
                          size="small"
                          startIcon={<ReplayIcon />}
                          onClick={(e) => {
                            e.stopPropagation();
                            retry(row);
                          }}
                        >
                          {t('notifications.index.retry')}
                        </Button>
                      ) : null}
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </Box>
      </Card>

      {notifications.meta.last_page > 1 ? (
        <Stack sx={{ alignItems: 'center' }}>
          <Pagination
            count={notifications.meta.last_page}
            page={notifications.meta.current_page}
            onChange={(_, page) => router.get(route('panel.notifications.index'), { ...filters, page } as Record<string, string | number>, { preserveState: true, preserveScroll: true })}
          />
        </Stack>
      ) : null}

      <AttemptDrawer notificationId={open} locale={locale} onClose={() => setOpen(null)} />
    </Stack>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="notifications.index.title">{page}</PanelLayout>;
