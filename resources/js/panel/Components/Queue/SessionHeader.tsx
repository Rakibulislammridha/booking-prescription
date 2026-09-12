// The session header of "Today's session" (BRIEF §5.E / §5.G): which session this is — code, planned hours, room,
// status — and its counts (booked / arrived / in consultation / completed / no-show / remaining), all read from the
// live QueueState the page already subscribes to, so the numbers move with the desk's check-ins without a fetch.
import { useTranslation } from 'react-i18next';
import Chip from '@mui/material/Chip';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { formatBn } from '@shared/format/number';
import { formatTimeDhaka } from '@shared/format/date';
import type { QueueState } from '@shared/realtime/types';
import type { Locale } from '@shared/types/shared-props';

export interface SessionHeaderProps {
  state: QueueState;
  /** From the page's `sessions` prop: the planned end is not part of QueueState. */
  plannedEndAt: string | null;
  locale: Locale;
}

const STATUS_COLOR: Record<QueueState['session']['status'], 'default' | 'success' | 'warning' | 'error' | 'info'> = {
  scheduled: 'info', running: 'success', paused: 'warning', closed: 'default', cancelled: 'error',
};

export function SessionHeader({ state, plannedEndAt, locale }: SessionHeaderProps) {
  const { t } = useTranslation();
  const { session, counts } = state;
  const hours = `${formatTimeDhaka(session.planned_start_at, locale)}${plannedEndAt ? ` – ${formatTimeDhaka(plannedEndAt, locale)}` : ''}`;
  const tiles: Array<{ key: string; label: string; value: number; testId: string }> = [
    { key: 'booked', label: t('serials.status.booked'), value: counts.booked, testId: 'count-booked' },
    { key: 'checked_in', label: t('serials.status.checked_in'), value: counts.checked_in, testId: 'count-checked_in' },
    { key: 'in_consultation', label: t('serials.status.in_consultation'), value: counts.in_consultation, testId: 'count-in_consultation' },
    { key: 'completed', label: t('serials.status.completed'), value: counts.completed, testId: 'count-completed' },
    { key: 'no_show', label: t('serials.status.no_show'), value: counts.no_show, testId: 'count-no_show' },
    { key: 'remaining', label: t('queue.doctor.remaining'), value: counts.waiting, testId: 'count-remaining' },
  ];

  return (
    <Paper variant="outlined" sx={{ px: 2, py: 1.5 }} data-testid="session-header">
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={1} sx={{ alignItems: { md: 'center' }, flexWrap: 'wrap' }}>
        <Typography variant="h6" component="h2" sx={{ fontWeight: 700 }}>
          {t('queue.doctor.session', { code: formatBn(session.code, locale) })}
        </Typography>
        <Typography color="text.secondary">{formatBn(hours, locale)}</Typography>
        {/* `room_label` is the clinic's own wording ("Room 1", "Chamber 2") — printed as the clinic wrote it,
            the way the waiting-room display and the desk board print it, never wrapped in a second "Room". */}
        {session.doctor.room ? <Typography color="text.secondary">{formatBn(session.doctor.room, locale)}</Typography> : null}
        <Chip size="small" color={STATUS_COLOR[session.status]} label={t(`queue.doctor.status.${session.status}`)} data-testid="session-status" />
      </Stack>
      <Stack direction="row" spacing={1} sx={{ mt: 1, flexWrap: 'wrap', gap: 1 }}>
        {tiles.map((tile) => (
          <Chip
            key={tile.key}
            variant="outlined"
            data-testid={tile.testId}
            label={(
              <span>
                <Typography component="span" variant="body2" sx={{ fontWeight: 700, mr: 0.5 }}>{formatBn(tile.value, locale)}</Typography>
                <Typography component="span" variant="body2" color="text.secondary">{tile.label}</Typography>
              </span>
            )}
          />
        ))}
      </Stack>
    </Paper>
  );
}

export default SessionHeader;
