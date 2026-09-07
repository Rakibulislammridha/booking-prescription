// Header card of Patients/Show: identity strip (code, mobile, age/sex, blood group), household position, visits.
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Typography from '@mui/material/Typography';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import Button from '@mui/material/Button';
import EditIcon from '@mui/icons-material/Edit';
import MergeIcon from '@mui/icons-material/CallMerge';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { route } from '@shared/routes';
import { formatBn } from '@shared/format/number';
import { formatDateDhaka } from '@shared/format/date';
import type { PatientRecord } from '@shared/types/models';
import type { Locale } from '@shared/types/shared-props';
import { ageSexLabel } from './labels';
import { MergeDialog } from './MergeDialog';

export interface PatientHeaderProps {
  patient: PatientRecord;
  canUpdate: boolean;
  canMerge: boolean;
}

export function PatientHeader({ patient, canUpdate, canMerge }: PatientHeaderProps) {
  const { t, i18n } = useTranslation();
  const locale: Locale = i18n.language === 'bn' ? 'bn' : 'en';
  const [mergeOpen, setMergeOpen] = useState(false);

  const facts: Array<[string, string]> = [
    [t('patients.show.code'), patient.patient_code],
    [t('patients.show.mobile'), formatBn(patient.mobile_local, locale)],
    [t('patients.show.age'), ageSexLabel(t, patient, locale) || '—'],
    [t('patients.show.blood_group'), patient.blood_group ?? '—'],
    [t('patients.show.last_visit'), patient.last_visit_at ? formatDateDhaka(patient.last_visit_at, locale) : t('patients.show.never_visited')],
    [t('patients.show.registered'), patient.created_at ? formatDateDhaka(patient.created_at, locale) : '—'],
  ];

  return (
    <Card>
      <CardContent>
        <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ alignItems: { md: 'flex-start' }, justifyContent: 'space-between' }}>
          <div>
            <Stack direction="row" spacing={1} useFlexGap sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
              <Typography variant="h5" component="h2" lang="bn">{patient.name}</Typography>
              {patient.is_mobile_owner ? (
                <Chip size="small" color="primary" variant="outlined" label={t('patients.show.owner')} />
              ) : patient.primary ? (
                <Chip
                  size="small"
                  variant="outlined"
                  component={RouterLink}
                  clickable
                  href={route('panel.patients.show', { patient: patient.primary.public_id })}
                  label={`${t('patients.show.dependent_of', { name: patient.primary.name })} · ${t(`patients.relation.${patient.primary.relation}`)}`}
                />
              ) : null}
              {!patient.is_active ? <Chip size="small" color="warning" label={t('patients.allergies.inactive')} /> : null}
              {patient.tags.map((tag) => <Chip key={tag} size="small" label={tag} />)}
            </Stack>
            <Stack direction="row" spacing={3} useFlexGap sx={{ mt: 1.5, flexWrap: 'wrap' }}>
              {facts.map(([label, value]) => (
                <div key={label}>
                  <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{label}</Typography>
                  <Typography variant="body2">{value}</Typography>
                </div>
              ))}
            </Stack>
          </div>
          <Stack direction="row" spacing={1} sx={{ flexShrink: 0 }}>
            {canUpdate ? (
              <Button component={RouterLink} href={route('panel.patients.edit', { patient: patient.public_id })} startIcon={<EditIcon />} variant="outlined" size="small">
                {t('patients.show.edit')}
              </Button>
            ) : null}
            {canMerge ? (
              <Button onClick={() => setMergeOpen(true)} startIcon={<MergeIcon />} variant="outlined" color="warning" size="small">
                {t('patients.show.merge')}
              </Button>
            ) : null}
          </Stack>
        </Stack>
      </CardContent>
      {canMerge ? <MergeDialog open={mergeOpen} onClose={() => setMergeOpen(false)} patient={patient} /> : null}
    </Card>
  );
}
