// The Bangla segment counter. A clinic is billed per SMS part, and Bangla is UCS-2 — 70 characters per part, not
// 160 — so the meter states the encoding out loud and turns amber the moment a body spills into a second part.
// The maths is `@panel/lib/notifications/segments`, the exact mirror of the server's SegmentCounter.
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import LinearProgress from '@mui/material/LinearProgress';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import { useTranslation } from 'react-i18next';
import { countSegments } from '@panel/lib/notifications/segments';
import { formatBn } from '@shared/format/number';
import type { Locale } from '@shared/types/shared-props';
import type { SmsSegmentCount } from '@shared/types/models';

interface Props {
  body: string;
  locale: Locale;
  /** Server-computed count; when present it wins, so the meter never disagrees with what will be billed. */
  server?: SmsSegmentCount | null;
}

export function SegmentMeter({ body, locale, server }: Props) {
  const { t } = useTranslation();
  const count = server ?? countSegments(body);
  const unicode = count.encoding === 'UCS-2';
  const filled = Math.min(100, ((count.per_segment - count.remaining) / count.per_segment) * 100);

  return (
    <Stack spacing={0.75} sx={{ mt: 1 }}>
      <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
        <Chip
          size="small"
          color={unicode ? 'warning' : 'default'}
          variant={unicode ? 'filled' : 'outlined'}
          label={`${t('notifications.segments.encoding')}: ${count.encoding}`}
        />
        <Chip
          size="small"
          color={count.segments > 1 ? 'warning' : 'success'}
          variant="outlined"
          label={`${t('notifications.segments.label')}: ${formatBn(count.segments, locale)}`}
        />
        <Typography variant="caption" color="text.secondary">
          {t('notifications.segments.remaining', { count: formatBn(count.remaining, locale) })}
        </Typography>
      </Stack>
      <LinearProgress variant="determinate" value={filled} color={count.segments > 1 ? 'warning' : 'primary'} sx={{ height: 6, borderRadius: 3 }} />
      <Typography variant="caption" color="text.secondary">
        {unicode ? t('notifications.segments.unicode_warning', { segments: formatBn(count.segments, locale) }) : t('notifications.segments.gsm')}
      </Typography>
      <Box component="span" sx={{ display: 'none' }} data-testid="segment-units">
        {count.units}
      </Box>
    </Stack>
  );
}
