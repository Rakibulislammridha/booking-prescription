// One doctor session on today's board: booked / arrived / done / remaining, now serving, call-next, and the serial
// rows with check-in / fee / print / cancel. Offline: check-in and print keep working (event log), the rest is
// disabled with the OFFLINE §6.2 reason.
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Card from '@mui/material/Card';
import CardHeader from '@mui/material/CardHeader';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import Button from '@mui/material/Button';
import IconButton from '@mui/material/IconButton';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableRow from '@mui/material/TableRow';
import Box from '@mui/material/Box';
import CheckIcon from '@mui/icons-material/HowToReg';
import PrintIcon from '@mui/icons-material/Print';
import CashIcon from '@mui/icons-material/Payments';
import CancelIcon from '@mui/icons-material/EventBusy';
import AddIcon from '@mui/icons-material/PersonAdd';
import { formatBn } from '@shared/format/number';
import { formatTimeDhaka } from '@shared/format/date';
import { formatBdt } from '@shared/format/money';
import { getLocale } from '@shared/locale';
import { isAllowedOffline, offlineReason, type DeskAction } from '@shared/offline';
import type { ConnectionMode } from '@shared/connection/store';
import type { BoardSession, DeskSerial, SessionStatus } from '@shared/types/models';

export interface SessionTileProps {
  session: BoardSession;
  mode: ConnectionMode;
  blockRemaining: number;
  can: { issue: boolean; call_next: boolean; cancel: boolean; collect: boolean };
  busy: boolean;
  onBook(session: BoardSession, channel: 'counter' | 'walkin'): void;
  onCallNext(session: BoardSession): void;
  onCheckIn(session: BoardSession, serial: DeskSerial): void;
  onCollect(session: BoardSession, serial: DeskSerial): void;
  onPrint(session: BoardSession, serial: DeskSerial): void;
  onCancel(session: BoardSession, serial: DeskSerial): void;
  onKiosk(session: BoardSession): void;
}

const STATUS_COLOR: Record<SessionStatus, 'default' | 'success' | 'warning' | 'error' | 'info'> = { scheduled: 'default', running: 'success', paused: 'warning', closed: 'info', cancelled: 'error' };
const ACTIVE = new Set(['booked', 'checked_in', 'in_consultation']);

export function SessionTile({ session, mode, blockRemaining, can, busy, onBook, onCallNext, onCheckIn, onCollect, onPrint, onCancel, onKiosk }: SessionTileProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [showAll, setShowAll] = useState(false);
  const open = session.status === 'scheduled' || session.status === 'running' || session.status === 'paused';
  const offline = mode === 'offline';
  const c = session.counts;
  const r = session.remaining;
  const remainingCounter = r.counter + r.released;
  const rows = showAll ? session.serials : session.serials.filter((s) => ACTIVE.has(s.status));
  const guard = (action: DeskAction, allowed: boolean, node: React.ReactElement): React.ReactElement => {
    if (!allowed) return <span>{node}</span>;
    if (!isAllowedOffline(action, mode)) return <Tooltip title={t(offlineReason(action))}><span>{node}</span></Tooltip>;
    return node;
  };

  return (
    <Card variant="outlined" data-testid={`board-session-${session.code}`} sx={{ opacity: open ? 1 : 0.75 }}>
      <CardHeader
        title={
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
            <Typography variant="h6" component="h2">{locale === 'bn' && session.doctor.name_bn ? session.doctor.name_bn : session.doctor.name}</Typography>
            <Chip size="small" label={t('reception.board.session', { code: session.code })} />
            <Chip size="small" color={STATUS_COLOR[session.status]} label={t(`scheduling.status.${session.status}`)} />
            {session.delay_minutes > 0 ? <Chip size="small" color="warning" variant="outlined" label={t('scheduling.session.delayed_by', { minutes: formatBn(session.delay_minutes, locale) })} /> : null}
          </Stack>
        }
        subheader={`${formatBn(formatTimeDhaka(session.planned_start_at), locale)} – ${formatBn(formatTimeDhaka(session.planned_end_at), locale)}${session.doctor.room ? ` · ${session.doctor.room}` : ''}`}
        action={open ? (
          <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', justifyContent: 'flex-end' }}>
            {can.call_next ? guard('call_next', true, <Button size="small" variant="contained" disabled={busy || offline || c.checked_in === 0} onClick={() => onCallNext(session)}>{t('serials.actions.call_next')}</Button>) : null}
            {can.issue ? (
              <Button size="small" variant="outlined" startIcon={<AddIcon />} disabled={busy || (offline ? blockRemaining === 0 : remainingCounter === 0)} onClick={() => onBook(session, 'counter')}>
                {offline ? t('connection.issue_offline_block') : t('reception.board.new_booking')}
              </Button>
            ) : null}
            {can.issue ? guard('issue_buffer', true, <Button size="small" variant="outlined" disabled={busy || offline || r.buffer === 0} onClick={() => onBook(session, 'walkin')}>{t('serials.actions.issue_walkin')}</Button>) : null}
            <Button size="small" disabled={offline} onClick={() => onKiosk(session)}>{t('reception.board.kiosk_qr')}</Button>
          </Stack>
        ) : null}
      />
      <CardContent sx={{ pt: 0 }}>
        <Stack direction="row" spacing={1} sx={{ mb: 1, flexWrap: 'wrap' }}>
          <Chip size="small" label={`${t('serials.status.booked')} ${formatBn(c.booked, locale)}`} />
          <Chip size="small" color="info" label={`${t('reception.board.arrived')} ${formatBn(c.checked_in, locale)}`} />
          <Chip size="small" color="success" label={`${t('serials.status.completed')} ${formatBn(c.completed, locale)}`} />
          <Chip size="small" color="error" variant="outlined" label={`${t('serials.status.no_show')} ${formatBn(c.no_show, locale)}`} />
          <Chip size="small" variant="outlined" label={t('serials.remaining.counter', { count: formatBn(remainingCounter, locale) })} />
          <Chip size="small" variant="outlined" label={t('serials.remaining.online', { count: formatBn(r.online, locale) })} />
          <Chip size="small" variant="outlined" label={t('serials.remaining.buffer', { count: formatBn(r.buffer, locale) })} />
          {blockRemaining > 0 ? <Chip size="small" color="primary" variant="outlined" label={t('reception.board.block_left', { count: formatBn(blockRemaining, locale) })} /> : null}
          {session.now_serving ? <Chip size="small" color="secondary" label={t('reception.board.now_serving', { code: formatBn(session.now_serving.display_code, locale) })} /> : null}
        </Stack>
        {session.serials.length === 0 ? (
          <Typography variant="body2" color="text.secondary">{t('serials.queue.empty')}</Typography>
        ) : (
          <Box sx={{ overflowX: 'auto' }}>
            <Table size="small" aria-label={t('serials.queue.title')}>
              <TableBody>
                {rows.map((s) => (
                  <TableRow key={s.public_id} hover sx={{ opacity: ACTIVE.has(s.status) ? 1 : 0.6 }}>
                    <TableCell sx={{ fontFamily: 'monospace', fontWeight: 700, whiteSpace: 'nowrap' }}>{formatBn(s.display_code, locale)}{s.public_id.startsWith('local:') ? <Chip size="small" label={t('serials.source.offline')} sx={{ ml: 0.5 }} /> : null}</TableCell>
                    <TableCell sx={{ minWidth: 140 }}>
                      <Typography variant="body2" sx={{ fontWeight: 600 }} lang="bn">{s.patient?.name ?? t('reception.board.no_patient')}</Typography>
                      <Typography variant="caption" color="text.secondary">{[s.patient?.mobile_masked, s.patient?.age_text ? formatBn(s.patient.age_text, locale) : null].filter(Boolean).join(' · ')}</Typography>
                    </TableCell>
                    <TableCell sx={{ whiteSpace: 'nowrap' }}>
                      <Chip size="small" label={t(`serials.status.${s.status}`)} color={s.status === 'checked_in' ? 'info' : s.status === 'in_consultation' ? 'secondary' : s.status === 'completed' ? 'success' : 'default'} />
                      {s.priority !== 'normal' ? <Chip size="small" variant="outlined" color="warning" label={t(`serials.priority.${s.priority}`)} sx={{ ml: 0.5 }} /> : null}
                    </TableCell>
                    <TableCell sx={{ whiteSpace: 'nowrap' }}>
                      {s.appointment ? <Typography variant="caption" color={s.appointment.payment_status === 'paid' ? 'success.main' : 'text.secondary'}>{formatBdt(s.appointment.fee_paisa, locale)} · {t(`reception.payment.${s.appointment.payment_status}`)}</Typography> : null}
                    </TableCell>
                    <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                      {s.status === 'booked' && open ? <Tooltip title={t('serials.actions.check_in')}><IconButton size="small" disabled={busy} onClick={() => onCheckIn(session, s)} aria-label={t('serials.actions.check_in')}><CheckIcon fontSize="small" /></IconButton></Tooltip> : null}
                      {can.collect && s.appointment && s.appointment.payment_status !== 'paid' && ACTIVE.has(s.status) ? <Tooltip title={t('reception.board.collect_fee')}><IconButton size="small" disabled={busy} onClick={() => onCollect(session, s)} aria-label={t('reception.board.collect_fee')}><CashIcon fontSize="small" /></IconButton></Tooltip> : null}
                      <Tooltip title={t('reception.board.print_slip')}><IconButton size="small" disabled={busy} onClick={() => onPrint(session, s)} aria-label={t('reception.board.print_slip')}><PrintIcon fontSize="small" /></IconButton></Tooltip>
                      {can.cancel && ACTIVE.has(s.status) ? guard('cancel', true, <IconButton size="small" disabled={busy || offline || !s.appointment} onClick={() => onCancel(session, s)} aria-label={t('reception.cancel.title')}><CancelIcon fontSize="small" /></IconButton>) : null}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
            {session.serials.length !== rows.length || showAll ? (
              <Button size="small" onClick={() => setShowAll((v) => !v)}>{showAll ? t('reception.board.show_active') : t('reception.board.show_all', { count: formatBn(session.serials.length, locale) })}</Button>
            ) : null}
          </Box>
        )}
      </CardContent>
    </Card>
  );
}
