// Usage against the plan's caps, as bars. Used by BOTH the console's tenant detail and the clinic's own
// subscription screen, so its strings live under `saas.*` (the vocabulary a customer sees) rather than `super.*`.
//
// The metric labels are translated on the server (PlanCatalog::metricLabels()) and arrive ready to print — they
// are rendered verbatim, never passed through t() again.
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import LinearProgress from '@mui/material/LinearProgress';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useTranslation } from 'react-i18next';
import { formatNumber } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { Locale } from '@shared/types/shared-props';
import type { LimitStatus } from './types';

const MB = 1024 * 1024;
const GB = MB * 1024;

/**
 * Storage counters are raw bytes; nobody reads 5368709120. Returns the translation key and its `:size` value
 * rather than a finished string, so the caller keeps its own `t` (and i18next keeps its typing).
 */
export interface BytesParts {
  key: string;
  size: string;
}

export function bytesParts(bytes: number, locale: Locale): BytesParts {
  return bytes >= GB
    ? { key: 'saas.pricing.gb', size: formatNumber(bytes / GB, locale, { maximumFractionDigits: 2 }) }
    : { key: 'saas.subscription.mb', size: formatNumber(bytes / MB, locale, { maximumFractionDigits: bytes < 10 * MB ? 1 : 0 }) };
}

export interface UsageBarsProps {
  usage: LimitStatus[];
  /** metric value → already-translated label, from the server. */
  metric_labels: Record<string, string>;
}

export function UsageBars({ usage, metric_labels }: UsageBarsProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const amount = (value: number, isBytes: boolean): string => {
    if (!isBytes) return formatNumber(value, locale);
    const parts = bytesParts(value, locale);
    return t(parts.key, { size: parts.size });
  };

  if (usage.length === 0) {
    return <Typography variant="body2" color="text.secondary">{t('saas.subscription.usage_empty')}</Typography>;
  }

  return (
    <Stack spacing={1.5}>
      {usage.map((row) => {
        const unlimited = row.limit === null;
        const label = metric_labels[row.metric] ?? row.metric;

        return (
          <Box key={row.metric}>
            <Stack direction="row" spacing={1} sx={{ alignItems: 'baseline', justifyContent: 'space-between' }}>
              <Typography variant="body2" sx={{ minWidth: 0 }}>{label}</Typography>
              <Stack direction="row" spacing={0.75} sx={{ alignItems: 'center', flexShrink: 0 }}>
                <Typography variant="body2" sx={{ fontVariantNumeric: 'tabular-nums', fontWeight: 600 }}>
                  {amount(row.used, row.is_bytes)}
                </Typography>
                <Typography variant="body2" color="text.secondary">
                  {unlimited ? t('saas.pricing.unlimited') : `/ ${amount(row.limit ?? 0, row.is_bytes)}`}
                </Typography>
                {row.exhausted ? <Chip size="small" color="error" label={t('saas.subscription.exhausted')} /> : null}
              </Stack>
            </Stack>
            <LinearProgress
              variant="determinate"
              value={unlimited ? 0 : Math.min(100, Math.max(0, row.percent ?? 0))}
              color={row.exhausted ? 'error' : (row.percent ?? 0) >= 80 ? 'warning' : 'primary'}
              aria-label={label}
              sx={{ height: 6, borderRadius: 1, mt: 0.5, opacity: unlimited ? 0.35 : 1 }}
            />
          </Box>
        );
      })}
    </Stack>
  );
}
