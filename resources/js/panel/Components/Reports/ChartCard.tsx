// A titled card holding a chart and, under it, the same numbers as a table — BRIEF §5.L asks for both, and a
// chart without its table is a picture nobody can check.
import type { ReactNode } from 'react';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Divider from '@mui/material/Divider';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';

export interface ChartCardProps {
  title: string;
  subtitle?: string;
  action?: ReactNode;
  chart?: ReactNode;
  children?: ReactNode;
}

export function ChartCard({ title, subtitle, action, chart, children }: ChartCardProps) {
  return (
    <Card variant="outlined">
      <CardContent>
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', mb: 1 }}>
          <Stack sx={{ flexGrow: 1, minWidth: 0 }}>
            <Typography variant="subtitle1" component="h2">{title}</Typography>
            {subtitle ? <Typography variant="caption" color="text.secondary">{subtitle}</Typography> : null}
          </Stack>
          {action}
        </Stack>
        {chart ? <>{chart}<Divider sx={{ my: 1.5 }} /></> : null}
        {children}
      </CardContent>
    </Card>
  );
}
