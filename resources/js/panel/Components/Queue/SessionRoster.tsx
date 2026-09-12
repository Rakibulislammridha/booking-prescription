// The roster of "Today's session": every patient of the session in serial-number order (the desk board's order),
// with name, sex, age, status, the compounder's vitals and ONE action per state — Call this patient (checked in),
// Start / Prescribe (in the chamber), View / Print (issued). The statuses come from the live QueueState where it
// lists the serial and from the last roster read otherwise, so a check-in at the desk moves a row without reload.
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Typography from '@mui/material/Typography';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { formatBn } from '@shared/format/number';
import { formatTemperature } from '@shared/format/temperature';
import { route } from '@shared/routes';
import type { Locale } from '@shared/types/shared-props';
import type { QueueRosterVitals, QueueSessionRow, SerialStatus } from '@shared/types/models';

export interface SessionRosterProps {
  rows: QueueSessionRow[];
  servingId: string | null;
  busy: boolean;
  can: { call_next: boolean; prescribe: boolean };
  locale: Locale;
  onCall: (row: QueueSessionRow) => void;
  onStart: (row: QueueSessionRow) => void;
  onPrescribe: (row: QueueSessionRow) => void;
}

const STATUS_COLOR: Record<SerialStatus, 'default' | 'info' | 'success' | 'warning' | 'error'> = {
  booked: 'default', checked_in: 'info', in_consultation: 'success', completed: 'default', no_show: 'warning', cancelled: 'error', postponed: 'default',
};

/** BP · pulse · temperature (°F) · SpO2 · weight, each omitted when not recorded; null when nothing was. */
export function vitalsSummary(v: QueueRosterVitals | null, locale: Locale): string | null {
  if (v === null) return null;
  const parts: string[] = [];
  if (v.bp_systolic != null && v.bp_diastolic != null) parts.push(`BP ${formatBn(`${v.bp_systolic}/${v.bp_diastolic}`, locale)}`);
  if (v.pulse_bpm != null) parts.push(`P ${formatBn(v.pulse_bpm, locale)}`);
  const temperature = formatTemperature(v.temperature_c, locale);
  if (temperature !== null) parts.push(temperature);
  if (v.spo2_percent != null) parts.push(`SpO₂ ${formatBn(v.spo2_percent, locale)}%`);
  if (v.weight_kg != null) parts.push(`${formatBn(v.weight_kg, locale)} kg`);
  return parts.length === 0 ? null : parts.join(' · ');
}

export function SessionRoster({ rows, servingId, busy, can, locale, onCall, onStart, onPrescribe }: SessionRosterProps) {
  const { t } = useTranslation();

  const openPrint = (prescription: string): void => {
    window.open(route('panel.prescription.print', { prescription }), '_blank', 'noopener');
  };

  return (
    <Paper variant="outlined" sx={{ overflowX: 'auto' }} data-testid="session-roster">
      <Typography variant="subtitle1" sx={{ px: 2, pt: 1.5 }}>{t('queue.doctor.roster')}</Typography>
      {rows.length === 0 ? (
        <Typography color="text.secondary" sx={{ px: 2, py: 2 }}>{t('queue.doctor.no_patients')}</Typography>
      ) : (
        <Table size="small" sx={{ mt: 1 }}>
          <TableHead>
            <TableRow>
              <TableCell>{t('queue.doctor.col_serial')}</TableCell>
              <TableCell>{t('queue.doctor.col_patient')}</TableCell>
              <TableCell>{t('queue.doctor.col_sex')}</TableCell>
              <TableCell>{t('queue.doctor.col_age')}</TableCell>
              <TableCell>{t('queue.doctor.col_status')}</TableCell>
              <TableCell>{t('queue.doctor.col_vitals')}</TableCell>
              <TableCell align="right">{t('queue.doctor.col_actions')}</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {rows.map((row) => {
              const issued = row.prescription !== null && row.prescription.status !== 'draft';
              const draft = row.prescription !== null && row.prescription.status === 'draft';
              const inChamber = row.status === 'in_consultation';
              const vitals = vitalsSummary(row.vitals, locale);

              return (
                <TableRow key={row.serial_id} selected={row.serial_id === servingId} data-testid={`roster-row-${row.serial_id}`} data-status={row.status}>
                  <TableCell sx={{ fontWeight: 700, whiteSpace: 'nowrap' }}>{formatBn(row.code, locale)}</TableCell>
                  <TableCell>
                    {row.patient ? (
                      <>
                        <Typography variant="body2" sx={{ fontWeight: 600 }}>{row.patient.name}</Typography>
                        <Typography variant="caption" color="text.secondary">{formatBn(row.patient.patient_code, locale)}</Typography>
                      </>
                    ) : <Typography variant="body2" color="text.secondary">—</Typography>}
                  </TableCell>
                  <TableCell>{row.patient?.sex ? t(`patients.gender.${row.patient.sex}`) : '—'}</TableCell>
                  <TableCell>{row.patient?.age_text ? formatBn(row.patient.age_text, locale) : '—'}</TableCell>
                  <TableCell>
                    <Chip size="small" color={STATUS_COLOR[row.status]} variant={row.status === 'completed' ? 'outlined' : 'filled'} label={t(`serials.status.${row.status}`)} />
                  </TableCell>
                  <TableCell sx={{ whiteSpace: 'nowrap' }}>
                    {vitals !== null ? <Typography variant="body2">{vitals}</Typography> : <Typography variant="caption" color="text.secondary">{t('queue.doctor.no_vitals')}</Typography>}
                  </TableCell>
                  <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                    <Stack direction="row" spacing={0.5} sx={{ justifyContent: 'flex-end' }}>
                      {row.status === 'checked_in' && can.call_next ? (
                        <Button size="small" variant="outlined" disabled={busy} onClick={() => onCall(row)} data-testid={`call-${row.serial_id}`}>{t('queue.doctor.call_this')}</Button>
                      ) : null}
                      {inChamber && can.call_next && row.consultation_started_at === null ? (
                        <Button size="small" disabled={busy} onClick={() => onStart(row)} data-testid={`start-${row.serial_id}`}>{t('queue.doctor.start')}</Button>
                      ) : null}
                      {(inChamber || (row.status === 'completed' && draft)) && can.prescribe ? (
                        <Button size="small" variant="contained" color="success" disabled={busy} onClick={() => onPrescribe(row)} data-testid={`prescribe-${row.serial_id}`}>{t('queue.doctor.prescribe')}</Button>
                      ) : null}
                      {issued && row.prescription ? (
                        <>
                          <Button size="small" component={RouterLink} href={route('panel.prescription.prescriptions.show', { prescription: row.prescription.id })} data-testid={`view-${row.serial_id}`}>{t('queue.doctor.view_prescription')}</Button>
                          <Button size="small" onClick={() => openPrint(row.prescription?.id ?? '')} data-testid={`print-${row.serial_id}`}>{t('common.actions.print')}</Button>
                        </>
                      ) : null}
                    </Stack>
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      )}
      <Box sx={{ height: 8 }} />
    </Paper>
  );
}

export default SessionRoster;
