// docs/OFFLINE.md §8 — conflict resolution cards, one at a time in sequence order, every card a mandatory decision
// ("Decide later" keeps the badge; nothing is auto-merged). Cards: duplicate_patient, serial_already_used,
// session_closed, status_regression, already_paid (+ the rejected/unknown fallbacks).
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Dialog from '@mui/material/Dialog';
import DialogTitle from '@mui/material/DialogTitle';
import DialogContent from '@mui/material/DialogContent';
import DialogActions from '@mui/material/DialogActions';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import Alert from '@mui/material/Alert';
import Typography from '@mui/material/Typography';
import Chip from '@mui/material/Chip';
import TextField from '@mui/material/TextField';
import MenuItem from '@mui/material/MenuItem';
import { formatBn } from '@shared/format/number';
import { formatBdt } from '@shared/format/money';
import { formatTimeDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { requiresAdminPin, type ConflictCard, type ConflictResolution } from '@shared/offline';

export interface ConflictCardsProps {
  open: boolean;
  cards: ConflictCard[];
  index: number;
  busy: boolean;
  error: string | null;
  isAdmin: boolean;
  onClose(): void;
  onNext(): void;
  onResolve(card: ConflictCard, resolution: ConflictResolution, params: Record<string, unknown>): void;
}

interface Candidate { public_id: string; name: string; age?: number | null; sex?: string | null; relation?: string | null; last_visit?: string | null }

export function ConflictCards({ open, cards, index, busy, error, isAdmin, onClose, onNext, onResolve }: ConflictCardsProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const card = cards[index] ?? null;
  const [candidate, setCandidate] = useState<string>('');
  const [session, setSession] = useState<string>('');
  const [reason, setReason] = useState('');
  const result = card?.serverResult ?? {};
  const payload = card?.payload ?? {};
  const code = String(payload.displayCode ?? result.display_code ?? '');

  const describe = (): string => {
    if (!card) return '';
    switch (card.reason) {
      case 'duplicate_patient':
      case 'patient_mismatch': {
        const first = (result.candidates as Candidate[] | undefined)?.[0];
        return t('reception.conflict.duplicate_patient', { name: first?.name ?? '', age: first?.age != null ? formatBn(first.age, locale) : '', mobile: String(payload.mobile ?? '') });
      }
      case 'serial_already_used':
      case 'block_released':
        return t('reception.conflict.serial_already_used', { code: formatBn(code, locale) });
      case 'session_closed':
        return t('reception.conflict.session_closed', { code: formatBn(code, locale), time: result.closed_at ? formatTimeDhaka(String(result.closed_at), locale) : '' });
      case 'status_regression': {
        const refund = result.refund as { status?: string; amount?: number } | undefined;
        return t('reception.conflict.status_regression', { code: formatBn(code, locale), time: result.cancelled_at ? formatTimeDhaka(String(result.cancelled_at), locale) : '', refund: refund?.status && refund.status !== 'none' && refund.status !== 'unpaid' ? formatBdt(refund.amount ?? 0, locale) : '' });
      }
      case 'already_paid': {
        const cash = result.cash as { amount?: number } | undefined;
        return t('reception.conflict.already_paid', { code: formatBn(code, locale), amount: formatBdt(cash?.amount ?? 0, locale) });
      }
      default:
        return t('reception.conflict.unknown', { reason: card.reason });
    }
  };

  const params = (resolution: ConflictResolution): Record<string, unknown> => {
    switch (resolution) {
      case 'link_patient': return { patient: candidate };
      case 'family_member': return candidate ? { holder_patient: candidate } : {};
      case 'move_to_session': return { session };
      case 'discard': return { reason: reason || 'discarded' };
      default: return {};
    }
  };

  const disabled = (resolution: ConflictResolution): boolean => {
    if (busy || !card) return true;
    if (resolution === 'link_patient' && candidate === '') return true;
    if (resolution === 'move_to_session' && session === '') return true;
    if (requiresAdminPin(card.type, resolution) && !isAdmin) return true;
    return false;
  };

  return (
    <Dialog open={open && card !== null} onClose={busy ? undefined : onClose} fullWidth maxWidth="sm" aria-labelledby="conflict-title">
      <DialogTitle id="conflict-title">{t('reception.conflict.title', { n: formatBn(index + 1, locale), total: formatBn(cards.length, locale) })}</DialogTitle>
      <DialogContent>
        {card ? (
          <Stack spacing={2} sx={{ pt: 1 }}>
            <Stack direction="row" spacing={1}><Chip size="small" label={t(`reception.conflict.reason.${card.reason}`, { defaultValue: card.reason })} color="error" /><Chip size="small" variant="outlined" label={t(`reception.event.${card.type}`)} /></Stack>
            <Alert severity="warning">{describe()}</Alert>
            {error ? <Alert severity="error">{error}</Alert> : null}
            {(card.reason === 'duplicate_patient' || card.reason === 'patient_mismatch') ? (
              <TextField select label={t('reception.conflict.pick_patient')} value={candidate} onChange={(e) => setCandidate(e.target.value)}>
                {((result.candidates as Candidate[] | undefined) ?? []).map((c) => <MenuItem key={c.public_id} value={c.public_id}>{c.name}{c.age != null ? ` · ${formatBn(c.age, locale)}` : ''}{c.relation ? ` · ${t(`patients.relation.${c.relation}`, { defaultValue: c.relation })}` : ''}{c.last_visit ? ` · ${c.last_visit}` : ''}</MenuItem>)}
              </TextField>
            ) : null}
            {card.reason === 'session_closed' ? (
              <TextField select label={t('reception.conflict.pick_session')} value={session} onChange={(e) => setSession(e.target.value)}>
                {((result.alternatives as Array<{ public_id: string; code: string; date: string; remaining: number }> | undefined) ?? []).map((s) => <MenuItem key={s.public_id} value={s.public_id}>{t('reception.board.session', { code: s.code })} · {s.date} · {t('serials.remaining.counter', { count: formatBn(s.remaining, locale) })}</MenuItem>)}
              </TextField>
            ) : null}
            {card.resolutions.includes('discard') ? <TextField label={t('reception.conflict.discard_reason')} value={reason} onChange={(e) => setReason(e.target.value)} size="small" /> : null}
            {card.resolutions.some((r) => requiresAdminPin(card.type, r)) && !isAdmin ? <Typography variant="caption" color="text.secondary">{t('reception.conflict.admin_required')}</Typography> : null}
          </Stack>
        ) : null}
      </DialogContent>
      <DialogActions sx={{ flexWrap: 'wrap', gap: 1 }}>
        <Button onClick={onClose} disabled={busy}>{t('reception.conflict.decide_later')}</Button>
        {cards.length > 1 ? <Button onClick={onNext} disabled={busy}>{t('common.actions.next')}</Button> : null}
        {card?.resolutions.map((r) => (
          <Button key={r} variant={r === 'discard' ? 'outlined' : 'contained'} color={r === 'discard' ? 'error' : 'primary'} disabled={disabled(r)} onClick={() => onResolve(card, r, params(r))}>
            {t(`reception.conflict.resolution.${r}`)}
          </Button>
        ))}
      </DialogActions>
    </Dialog>
  );
}
