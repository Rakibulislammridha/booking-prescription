// Two things a receptionist could not tell from a board row until now, both of them about a patient who is
// physically in the waiting room:
//
//   VitalsChip — "has the compounder seen this one yet?" (BRIEF §5.G.2). Without it the desk had to open every
//   checked-in patient to find out. Recorded / due is a two-state answer, so it is drawn as a two-state chip
//   rather than a colour the eye has to decode, and it is honest about being cached: an offline desk says "as of
//   the last sync" instead of showing a flag it cannot refresh (shared/offline/types.ts CachedSerial).
//
//   HoldChip — "is this number actually mine to count on?" (BRIEF §5.C). An advance-payment booking holds its
//   serial as `pending` until the invoice settles and `booking:expire-holds` takes it back when it does not, so a
//   held row is a number that may vanish. The chip counts the hold down to its real deadline and tells the board
//   when it lapses, so the row does not sit there looking booked after the sweep has released it.
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Chip from '@mui/material/Chip';
import Tooltip from '@mui/material/Tooltip';
import HourglassIcon from '@mui/icons-material/HourglassTop';
import VitalsIcon from '@mui/icons-material/MonitorHeart';
import { formatBn } from '@shared/format/number';
import { formatTimeDhaka } from '@shared/format/date';
import type { Locale } from '@shared/types/shared-props';
import type { DeskVitals } from '@shared/types/models';

export interface VitalsChipProps {
  vitals: DeskVitals;
  /** the desk is offline: this answer is the last one the server gave, not the current one */
  stale: boolean;
  locale: Locale;
}

export function VitalsChip({ vitals, stale, locale }: VitalsChipProps) {
  const { t } = useTranslation();
  const at = vitals.recorded_at === null ? null : formatBn(formatTimeDhaka(vitals.recorded_at), locale);
  const tip = [
    vitals.recorded && at !== null ? t('reception.vitals.recorded_at', { at }) : t('reception.vitals.due'),
    vitals.recorded ? (vitals.reviewed ? t('reception.vitals.reviewed') : t('reception.vitals.awaiting_review')) : null,
    stale ? t('reception.vitals.stale') : null,
  ].filter(Boolean).join(' · ');

  return (
    <Tooltip title={tip}>
      <Chip
        size="small"
        variant={vitals.recorded ? 'filled' : 'outlined'}
        color={vitals.recorded ? 'success' : 'warning'}
        icon={<VitalsIcon fontSize="small" />}
        label={vitals.recorded ? t('reception.vitals.recorded') : t('reception.vitals.due')}
        data-testid={`vitals-${vitals.recorded ? 'recorded' : 'due'}`}
        sx={{ ml: 0.5, opacity: stale ? 0.72 : 1 }}
      />
    </Tooltip>
  );
}

export interface HoldChipProps {
  /** ISO deadline from SerialPresenter; null when the hold is not on the sweep's list (part-paid, say) */
  expiresAt: string | null;
  locale: Locale;
  /** fired once, when a live countdown reaches zero, so the board can go and see what the server did */
  onExpired?: () => void;
}

export function HoldChip({ expiresAt, locale, onExpired }: HoldChipProps) {
  const { t } = useTranslation();
  const deadline = expiresAt === null ? Number.NaN : Date.parse(expiresAt);
  const live = Number.isFinite(deadline);
  const [now, setNow] = useState<number>(() => Date.now());
  const fired = useRef(false);

  useEffect(() => {
    if (!live) return undefined;
    const id = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(id);
  }, [live, deadline]);

  const msLeft = live ? deadline - now : Number.NaN;
  const expired = live && msLeft <= 0;

  useEffect(() => {
    if (!expired || fired.current) return;
    fired.current = true;
    onExpired?.();
  }, [expired, onExpired]);

  const remaining = (): string => {
    const minutes = Math.floor(msLeft / 60_000);
    return minutes >= 1 ? t('reception.board.hold_left', { minutes: formatBn(minutes, locale) }) : t('reception.board.hold_soon');
  };

  const label = !live ? t('reception.board.hold') : expired ? t('reception.board.hold_expired') : `${t('reception.board.hold')} · ${remaining()}`;

  return (
    <Tooltip title={t('reception.board.hold_tooltip')}>
      <Chip
        size="small"
        variant="outlined"
        color={expired ? 'error' : 'warning'}
        icon={<HourglassIcon fontSize="small" />}
        label={label}
        data-testid={expired ? 'hold-expired' : 'hold-pending'}
        sx={{ ml: 0.5 }}
      />
    </Tooltip>
  );
}
