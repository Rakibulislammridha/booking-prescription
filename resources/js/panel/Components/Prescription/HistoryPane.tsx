// LEFT pane (PRESCRIPTION.md §1.1, §8.1): who the patient is and what is already true about them — allergies first
// and in red, then conditions and current medications, then the last visits. Everything here arrives with the single
// writer request; "copy from a past visit" is the only lazy read.
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import Link from '@mui/material/Link';
import { RouterLink } from '@panel/Layouts/RouterLink';
import Paper from '@mui/material/Paper';
import Stack from '@mui/material/Stack';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import WarningIcon from '@mui/icons-material/WarningAmber';
import { formatDhaka } from '@shared/format/date';
import { hasRoute, route } from '@shared/routes';
import type { PatientClinicalCard, VisitBrief } from '@shared/types/models';

export interface HistoryPaneProps {
  patient: PatientClinicalCard;
  recentVisits: VisitBrief[];
  onCopyVisit: (visit: VisitBrief) => void;
}

export function HistoryPane({ patient, recentVisits, onCopyVisit }: HistoryPaneProps) {
  const { t } = useTranslation();
  const flags = Object.entries(patient.flags).filter(([, on]) => on);

  return (
    <Stack spacing={1} sx={{ height: '100%', overflowY: 'auto', pr: 0.5 }}>
      <Paper variant="outlined" sx={{ p: 1 }}>
        <Typography variant="subtitle2" sx={{ fontWeight: 700, lineHeight: 1.2 }}>
          {hasRoute('panel.patients.show') ? (
            <Link component={RouterLink} href={route('panel.patients.show', { patient: patient.public_id })} underline="hover" color="inherit">
              {patient.name}
            </Link>
          ) : (
            patient.name
          )}
        </Typography>
        <Typography variant="caption" color="text.secondary" component="div">
          {[patient.age_text, patient.sex, patient.patient_code].filter(Boolean).join(' · ')}
        </Typography>
        <Typography variant="caption" color="text.secondary" component="div">
          {patient.phone}
          {patient.blood_group ? ` · ${patient.blood_group}` : ''}
          {patient.family_head ? ` · ${t('prescriptions.history.family_of', { name: patient.family_head })}` : ''}
        </Typography>
        {flags.length > 0 ? (
          <Stack direction="row" spacing={0.5} sx={{ mt: 0.5, flexWrap: 'wrap' }}>
            {flags.map(([flag]) => (
              <Chip key={flag} size="small" color="info" variant="outlined" sx={{ height: 18, fontSize: 11 }} label={t(`prescriptions.history.flags.${flag}`)} />
            ))}
          </Stack>
        ) : null}
      </Paper>

      <Paper variant="outlined" sx={{ p: 1, borderColor: patient.allergies.length > 0 ? 'error.main' : 'divider' }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
          {patient.allergies.length > 0 ? <WarningIcon color="error" sx={{ fontSize: 16 }} /> : null}
          <Typography variant="caption" sx={{ fontWeight: 700, color: patient.allergies.length > 0 ? 'error.main' : 'text.secondary' }}>
            {t('prescriptions.history.allergies')}
          </Typography>
        </Box>
        {patient.allergies.length === 0 ? (
          <Typography variant="caption" color="text.secondary">
            {t('prescriptions.history.no_allergies')}
          </Typography>
        ) : (
          <Stack direction="row" spacing={0.5} sx={{ flexWrap: 'wrap', gap: 0.5 }}>
            {patient.allergies.map((a) => (
              <Tooltip key={a.id} title={a.reaction ?? ''}>
                <Chip size="small" color="error" variant={a.severity === 'severe' ? 'filled' : 'outlined'} sx={{ height: 20 }} label={a.allergen_name} />
              </Tooltip>
            ))}
          </Stack>
        )}
      </Paper>

      {patient.conditions.length > 0 ? (
        <Paper variant="outlined" sx={{ p: 1 }}>
          <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
            {t('prescriptions.history.conditions')}
          </Typography>
          <Stack sx={{ mt: 0.25 }}>
            {patient.conditions.map((c) => (
              <Typography key={c.id} variant="caption" noWrap>
                {c.condition_name}
                {c.icd10_code ? ` (${c.icd10_code})` : ''}
              </Typography>
            ))}
          </Stack>
        </Paper>
      ) : null}

      {patient.medications.length > 0 ? (
        <Paper variant="outlined" sx={{ p: 1 }}>
          <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
            {t('prescriptions.history.medications')}
          </Typography>
          <Stack sx={{ mt: 0.25 }}>
            {patient.medications.map((m) => (
              <Typography key={m.id} variant="caption" noWrap>
                {m.brand_name ?? m.generic_name}
                {m.dose_text ? ` · ${m.dose_text}` : ''}
              </Typography>
            ))}
          </Stack>
        </Paper>
      ) : null}

      <Paper variant="outlined" sx={{ p: 1 }}>
        <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
          {t('prescriptions.history.timeline')}
        </Typography>
        {recentVisits.length === 0 ? (
          <Typography variant="caption" color="text.secondary" component="div">
            {t('prescriptions.history.first_visit')}
          </Typography>
        ) : (
          <Stack divider={<Divider flexItem />} spacing={0.5} sx={{ mt: 0.5 }}>
            {recentVisits.map((visit) => (
              <Box key={visit.id}>
                <Typography variant="caption" sx={{ fontWeight: 600 }} component="div">
                  {formatDhaka(visit.date)} · {visit.doctor ?? ''}
                </Typography>
                <Typography variant="caption" color="text.secondary" component="div" noWrap>
                  {visit.dx.join(', ') || t('common.status.none')}
                </Typography>
                <Box sx={{ display: 'flex', gap: 0.5, alignItems: 'center' }}>
                  <Typography variant="caption" color="text.secondary">
                    {t('prescriptions.history.rx_items', { count: visit.rx_item_count })}
                  </Typography>
                  {visit.prescription_id !== null ? (
                    <Chip size="small" variant="outlined" sx={{ height: 18, fontSize: 11 }} label={t('prescriptions.history.copy')} onClick={() => onCopyVisit(visit)} />
                  ) : null}
                </Box>
              </Box>
            ))}
          </Stack>
        )}
      </Paper>
    </Stack>
  );
}

export default HistoryPane;
