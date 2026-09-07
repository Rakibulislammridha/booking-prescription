// "Family on this number": the other people registered on the patient's mobile (owner first) and the
// add-family-member dialog (POST panel.patients.dependents.store — same mobile, linked to the household owner).
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import CardHeader from '@mui/material/CardHeader';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemText from '@mui/material/ListItemText';
import Chip from '@mui/material/Chip';
import Button from '@mui/material/Button';
import Typography from '@mui/material/Typography';
import Dialog from '@mui/material/Dialog';
import DialogTitle from '@mui/material/DialogTitle';
import DialogContent from '@mui/material/DialogContent';
import PersonAddIcon from '@mui/icons-material/PersonAdd';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { route } from '@shared/routes';
import type { FamilyMember, PatientRecord } from '@shared/types/models';
import type { Locale } from '@shared/types/shared-props';
import { ageSexLabel } from './labels';
import { PatientForm } from './PatientForm';

export interface FamilyPanelProps {
  patient: PatientRecord;
  family: FamilyMember[];
  canCreate: boolean;
}

export function FamilyPanel({ patient, family, canCreate }: FamilyPanelProps) {
  const { t, i18n } = useTranslation();
  const locale: Locale = i18n.language === 'bn' ? 'bn' : 'en';
  const [open, setOpen] = useState(false);

  return (
    <Card>
      <CardHeader
        title={t('patients.show.family')}
        titleTypographyProps={{ variant: 'subtitle1', fontWeight: 600 }}
        action={canCreate ? (
          <Button size="small" startIcon={<PersonAddIcon />} onClick={() => setOpen(true)}>{t('patients.show.add_family_member')}</Button>
        ) : null}
      />
      <CardContent sx={{ pt: 0 }}>
        {family.length === 0 ? (
          <Typography variant="body2" color="text.secondary">{t('patients.show.no_family')}</Typography>
        ) : (
          <List dense disablePadding>
            {family.map((member) => (
              <ListItemButton key={member.public_id} component={RouterLink} href={route('panel.patients.show', { patient: member.public_id })}>
                <ListItemText primary={<span lang="bn">{member.name}</span>} secondary={[member.patient_code, ageSexLabel(t, member, locale)].filter(Boolean).join(' · ')} />
                {member.is_mobile_owner ? (
                  <Chip size="small" color="primary" variant="outlined" label={t('patients.show.owner')} />
                ) : member.relation ? (
                  <Chip size="small" variant="outlined" label={t(`patients.relation.${member.relation}`)} />
                ) : null}
              </ListItemButton>
            ))}
          </List>
        )}
      </CardContent>

      <Dialog open={open} onClose={() => setOpen(false)} fullWidth maxWidth="md">
        <DialogTitle>{t('patients.show.add_family_member')}</DialogTitle>
        <DialogContent sx={{ pt: '8px !important' }}>
          <PatientForm mode="dependent" url={route('panel.patients.dependents.store', { patient: patient.public_id })} primary={patient} onCancel={() => setOpen(false)} onSuccess={() => setOpen(false)} />
        </DialogContent>
      </Dialog>
    </Card>
  );
}
