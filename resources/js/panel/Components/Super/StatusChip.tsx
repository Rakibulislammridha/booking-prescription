// Status is carried by colour AND text, never colour alone: the console is read by people on cheap monitors and
// by at least one colour-blind operator, and "red" is not a value anyone can act on by itself.
import Chip from '@mui/material/Chip';
import type { ChipProps } from '@mui/material/Chip';
import { useTranslation } from 'react-i18next';
import type { TenantHealth } from './types';

type Tone = ChipProps['color'];

/** Tenant statuses and subscription statuses share one vocabulary; both land in `super.status.*`. */
const STATUS: Record<string, { tone: Tone; label: string }> = {
  trial: { tone: 'info', label: 'super.status.trial' },
  trialing: { tone: 'info', label: 'super.status.trialing' },
  active: { tone: 'success', label: 'super.status.active' },
  past_due: { tone: 'warning', label: 'super.status.past_due' },
  suspended: { tone: 'error', label: 'super.status.suspended' },
  cancelled: { tone: 'default', label: 'super.status.cancelled' },
  expired: { tone: 'default', label: 'super.status.expired' },
};

const HEALTH: Record<TenantHealth, { tone: Tone; label: string }> = {
  healthy: { tone: 'success', label: 'super.health.healthy' },
  trial: { tone: 'info', label: 'super.health.trial' },
  at_risk: { tone: 'warning', label: 'super.health.at_risk' },
  critical: { tone: 'error', label: 'super.health.critical' },
};

export interface StatusChipProps {
  status: string | null;
  size?: ChipProps['size'];
  variant?: ChipProps['variant'];
}

export function StatusChip({ status, size = 'small', variant = 'filled' }: StatusChipProps) {
  const { t } = useTranslation();

  if (status === null || status === '') {
    return <Chip size={size} variant="outlined" color="default" label={t('super.status.none')} />;
  }

  const entry = STATUS[status];

  return (
    <Chip
      size={size}
      variant={variant}
      color={entry ? entry.tone : 'default'}
      label={entry ? t(entry.label) : status}
    />
  );
}

export interface HealthChipProps {
  health: TenantHealth;
  size?: ChipProps['size'];
}

export function HealthChip({ health, size = 'small' }: HealthChipProps) {
  const { t } = useTranslation();
  const entry = HEALTH[health];

  return <Chip size={size} variant="outlined" color={entry.tone} label={t(entry.label)} />;
}
