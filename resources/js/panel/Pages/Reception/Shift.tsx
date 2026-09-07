// Shift-close summary (Inertia::render('Reception/Shift'), BRIEF §5.F): collected vs expected, per receptionist.
import type { ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Table from '@mui/material/Table';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TableCell from '@mui/material/TableCell';
import TableBody from '@mui/material/TableBody';
import TextField from '@mui/material/TextField';
import Button from '@mui/material/Button';
import Grid from '@mui/material/Grid';
import Box from '@mui/material/Box';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { route } from '@shared/routes';
import { formatBdt } from '@shared/format/money';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { ShiftSummary } from '@shared/types/models';

type Props = PageProps<{ summary: ShiftSummary }>;

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <Card variant="outlined"><CardContent><Typography variant="caption" color="text.secondary">{label}</Typography><Typography variant="h5">{value}</Typography></CardContent></Card>
  );
}

export default function Shift({ summary }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const m = summary.money;

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ alignItems: { sm: 'center' } }}>
        <Typography variant="h5" component="h1" sx={{ flexGrow: 1 }}>{summary.branch.name} · {formatBn(summary.date, locale)}</Typography>
        <TextField type="date" size="small" value={summary.date} onChange={(e) => e.target.value && router.get(route('panel.reception.shift'), { date: e.target.value }, { preserveState: true })} slotProps={{ inputLabel: { shrink: true } }} label={t('scheduling.filter.date')} />
        <Button variant="outlined" onClick={() => window.print()}>{t('common.actions.print')}</Button>
      </Stack>
      <Grid container spacing={2}>
        <Grid size={{ xs: 6, md: 3 }}><Stat label={t('reception.shift.issued')} value={formatBn(summary.serials.issued, locale)} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><Stat label={t('reception.shift.expected')} value={formatBdt(m.expected_paisa, locale)} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><Stat label={t('reception.shift.collected')} value={formatBdt(m.collected_paisa, locale)} /></Grid>
        <Grid size={{ xs: 6, md: 3 }}><Stat label={t('reception.shift.unpaid')} value={formatBn(m.unpaid_appointments, locale)} /></Grid>
      </Grid>
      <Card variant="outlined">
        <CardContent>
          <Typography variant="subtitle1" sx={{ mb: 1 }}>{t('reception.shift.per_user')}</Typography>
          <Box sx={{ overflowX: 'auto' }}>
            <Table size="small">
              <TableHead><TableRow><TableCell>{t('reception.shift.user')}</TableCell><TableCell align="right">{t('reception.shift.issued')}</TableCell><TableCell align="right">{t('reception.board.arrived')}</TableCell><TableCell align="right">{t('serials.status.cancelled')}</TableCell><TableCell align="right">{t('reception.shift.collected')}</TableCell></TableRow></TableHead>
              <TableBody>
                {summary.per_user.map((row, i) => (
                  <TableRow key={row.user?.public_id ?? i}>
                    <TableCell>{row.user?.name ?? t('reception.shift.self_service')}</TableCell>
                    <TableCell align="right">{formatBn(row.issued, locale)}</TableCell>
                    <TableCell align="right">{formatBn(row.checked_in, locale)}</TableCell>
                    <TableCell align="right">{formatBn(row.cancelled, locale)}</TableCell>
                    <TableCell align="right">{formatBdt(row.collected_paisa, locale)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Box>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 2 }}>{t('reception.shift.by_source')}: {Object.entries(summary.serials.by_source).map(([k, v]) => `${t(`serials.source.${k}`)} ${formatBn(v, locale)}`).join(' · ') || '—'}</Typography>
          <Typography variant="body2" color="text.secondary">{t('reception.shift.by_status')}: {Object.entries(summary.serials.by_status).map(([k, v]) => `${t(`serials.status.${k}`)} ${formatBn(v, locale)}`).join(' · ') || '—'}</Typography>
          {m.offline_cash_paisa > 0 ? <Typography variant="body2" color="text.secondary">{t('reception.shift.offline_cash')}: {formatBdt(m.offline_cash_paisa, locale)}</Typography> : null}
          {m.waived_paisa > 0 ? <Typography variant="body2" color="text.secondary">{t('reception.shift.waived')}: {formatBdt(m.waived_paisa, locale)}</Typography> : null}
        </CardContent>
      </Card>
    </Stack>
  );
}

Shift.layout = (page: ReactNode) => <PanelLayout title="reception.shift.title">{page}</PanelLayout>;
