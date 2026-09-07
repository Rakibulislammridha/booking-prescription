// One session instance of the day: status/lifecycle chips, counts, remaining per pool, the queue with drag reorder.
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import CardHeader from '@mui/material/CardHeader';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import Button from '@mui/material/Button';
import Typography from '@mui/material/Typography';
import Alert from '@mui/material/Alert';
import IconButton from '@mui/material/IconButton';
import Tooltip from '@mui/material/Tooltip';
import CheckIcon from '@mui/icons-material/HowToReg';
import DoneIcon from '@mui/icons-material/TaskAlt';
import { QueueList } from '@panel/Components/Serials/QueueList';
import { callNext, issueSerial, reorderSerial, serialAction, sessionAction } from '@panel/api/serials';
import { isApiError } from '@shared/http';
import { formatTimeDhaka } from '@shared/format/date';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { Serial, SessionInstance, SessionStatus } from '@shared/types/models';

export interface SessionCardProps {
  session: SessionInstance;
  permissions: { reorder: boolean; call_next: boolean; issue: boolean };
  onChanged: () => void;
}

const STATUS_COLOR: Record<SessionStatus, 'default' | 'success' | 'warning' | 'error' | 'info'> = {
  scheduled: 'default', running: 'success', paused: 'warning', closed: 'info', cancelled: 'error',
};

export function SessionCard({ session, permissions, onChanged }: SessionCardProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const open = session.status === 'scheduled' || session.status === 'running' || session.status === 'paused';

  const run = async (fn: () => Promise<unknown>): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      await fn();
      onChanged();
    } catch (e) {
      setError(isApiError(e) ? t(`serials.errors.${e.code ?? 'unknown'}`, { defaultValue: e.message }) : String(e));
      if (isApiError(e) && e.is('serials.reorder_stale')) onChanged();
    } finally {
      setBusy(false);
    }
  };

  const remaining = session.remaining;
  const counts = session.counts;

  return (
    <Card variant="outlined" data-testid={`session-${session.session_code}`}>
      <CardHeader
        title={
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
            <Typography variant="h6" component="h2">{t('scheduling.session.title', { code: session.session_code })}</Typography>
            <Chip size="small" color={STATUS_COLOR[session.status]} label={t(`scheduling.status.${session.status}`)} />
            {session.delay_minutes > 0 ? <Chip size="small" color="warning" variant="outlined" label={t('scheduling.session.delayed_by', { minutes: formatBn(String(session.delay_minutes), locale) })} /> : null}
            <Chip size="small" variant="outlined" label={t(`scheduling.mode.${session.mode}`)} />
          </Stack>
        }
        subheader={formatBn(`${formatTimeDhaka(session.planned_start_at)} – ${formatTimeDhaka(session.planned_end_at)}`, locale)}
        action={
          open ? (
            <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap' }}>
              {permissions.call_next ? <Button size="small" variant="contained" disabled={busy} onClick={() => void run(() => callNext(session.public_id))}>{t('serials.actions.call_next')}</Button> : null}
              {permissions.issue ? <Button size="small" variant="outlined" disabled={busy || (remaining?.counter ?? 0) + (remaining?.released ?? 0) === 0} onClick={() => void run(() => issueSerial(session.public_id, { source: 'counter' }))}>{t('serials.actions.issue_counter')}</Button> : null}
              {permissions.issue ? <Button size="small" variant="outlined" disabled={busy || (remaining?.buffer ?? 0) === 0} onClick={() => void run(() => issueSerial(session.public_id, { source: 'walkin' }))}>{t('serials.actions.issue_walkin')}</Button> : null}
              {session.status === 'scheduled' ? <Button size="small" disabled={busy} onClick={() => void run(() => sessionAction(session.public_id, 'start'))}>{t('scheduling.actions.start')}</Button> : null}
              {session.status === 'running' ? <Button size="small" disabled={busy} onClick={() => void run(() => sessionAction(session.public_id, 'pause'))}>{t('scheduling.actions.pause')}</Button> : null}
              {session.status === 'paused' ? <Button size="small" disabled={busy} onClick={() => void run(() => sessionAction(session.public_id, 'resume'))}>{t('scheduling.actions.resume')}</Button> : null}
              <Button size="small" color="warning" disabled={busy} onClick={() => { if (window.confirm(t('scheduling.actions.close_confirm'))) void run(() => sessionAction(session.public_id, 'close')); }}>{t('scheduling.actions.close')}</Button>
            </Stack>
          ) : null
        }
      />
      <CardContent sx={{ pt: 0 }}>
        {error ? <Alert severity="error" onClose={() => setError(null)} sx={{ mb: 1 }}>{error}</Alert> : null}
        <Stack direction="row" spacing={1} sx={{ mb: 1, flexWrap: 'wrap' }}>
          <Chip size="small" label={`${t('serials.status.booked')} ${formatBn(String(counts.booked), locale)}`} />
          <Chip size="small" color="info" label={`${t('serials.status.checked_in')} ${formatBn(String(counts.checked_in), locale)}`} />
          <Chip size="small" color="success" label={`${t('serials.status.completed')} ${formatBn(String(counts.completed), locale)}`} />
          <Chip size="small" color="error" variant="outlined" label={`${t('serials.status.no_show')} ${formatBn(String(counts.no_show), locale)}`} />
          {remaining ? (
            <>
              <Chip size="small" variant="outlined" label={t('serials.remaining.counter', { count: remaining.counter + remaining.released })} />
              <Chip size="small" variant="outlined" label={t('serials.remaining.online', { count: remaining.online })} />
              <Chip size="small" variant="outlined" label={t('serials.remaining.buffer', { count: remaining.buffer })} />
              {remaining.counter_in_blocks > 0 ? <Chip size="small" variant="outlined" label={t('serials.remaining.on_devices', { count: remaining.counter_in_blocks })} /> : null}
            </>
          ) : null}
          <Chip size="small" variant="outlined" label={t('scheduling.session.max_serials', { count: session.max_serials })} />
        </Stack>
        <QueueList
          serials={session.serials ?? []}
          canReorder={permissions.reorder && open}
          onReorder={(serial: Serial, after, before) => run(() => reorderSerial(serial.public_id, after, before))}
          renderActions={(serial) => open ? (
            <Stack direction="row" spacing={0.5}>
              {serial.status === 'booked' ? (
                <Tooltip title={t('serials.actions.check_in')}><IconButton size="small" disabled={busy} onClick={() => void run(() => serialAction(serial.public_id, 'check-in'))}><CheckIcon fontSize="small" /></IconButton></Tooltip>
              ) : null}
              {serial.status === 'in_consultation' && permissions.call_next ? (
                <Tooltip title={t('serials.actions.complete')}><IconButton size="small" disabled={busy} onClick={() => void run(() => serialAction(serial.public_id, 'complete'))}><DoneIcon fontSize="small" /></IconButton></Tooltip>
              ) : null}
            </Stack>
          ) : null}
        />
      </CardContent>
    </Card>
  );
}

export default SessionCard;
