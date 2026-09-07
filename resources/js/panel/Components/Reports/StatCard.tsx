// One number, big. The unit and an optional "of what" line sit under it, because a clinic owner reading
// "18" needs to know it is minutes and not patients before he acts on it.
import type { ReactNode } from 'react';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Stack from '@mui/material/Stack';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';

export interface StatCardProps {
  label: string;
  value: ReactNode;
  unit?: string;
  hint?: string;
  /** Metric definition — the tooltip that keeps an ambiguous number honest. */
  note?: string;
  tone?: 'default' | 'warning' | 'success';
}

export function StatCard({ label, value, unit, hint, note, tone = 'default' }: StatCardProps) {
  const color = tone === 'warning' ? 'warning.main' : tone === 'success' ? 'success.main' : 'text.primary';

  return (
    <Card variant="outlined" sx={{ height: '100%' }}>
      <CardContent sx={{ py: 1.5, '&:last-child': { pb: 1.5 } }}>
        <Stack direction="row" spacing={0.5} sx={{ alignItems: 'center', minHeight: 20 }}>
          <Typography variant="caption" color="text.secondary" noWrap>{label}</Typography>
          {note ? (
            <Tooltip title={note} enterTouchDelay={0}>
              <InfoOutlinedIcon sx={{ fontSize: 14, color: 'text.disabled' }} aria-label={note} />
            </Tooltip>
          ) : null}
        </Stack>
        <Stack direction="row" spacing={0.5} sx={{ alignItems: 'baseline' }}>
          <Typography variant="h5" sx={{ color, fontVariantNumeric: 'tabular-nums' }}>{value}</Typography>
          {unit ? <Typography variant="body2" color="text.secondary">{unit}</Typography> : null}
        </Stack>
        {hint ? <Typography variant="caption" color="text.secondary">{hint}</Typography> : null}
      </CardContent>
    </Card>
  );
}
