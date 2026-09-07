// Create / edit / add-family-member form (Inertia useForm). Bangla-capable name, mobile (E.164 or 017… — the
// server normalises), gender, dob OR age, address, ENC fields (national id, notes) and the family relation when
// registering under an existing mobile owner. Enter submits, Esc is left to the dialog that hosts it.
import type { FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import TextField from '@mui/material/TextField';
import MenuItem from '@mui/material/MenuItem';
import Button from '@mui/material/Button';
import Alert from '@mui/material/Alert';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import type { BloodGroup, PatientGender, PatientRecord, PatientRelationType, PatientSummary } from '@shared/types/models';
import { BLOOD_GROUPS, GENDERS, RELATIONS } from './labels';

export interface PatientFormValues {
  name: string;
  mobile: string;
  gender: PatientGender | '';
  dob: string;
  age_years: string;
  blood_group: BloodGroup | '';
  email: string;
  address: string;
  district: string;
  national_id: string;
  guardian_name: string;
  preferred_language: 'bn' | 'en';
  notes: string;
  registered_branch_id: string;
  relation: PatientRelationType;
  primary_public_id: string;
}

export interface BranchOption {
  id: number;
  name: string;
}

export interface PatientFormProps {
  mode: 'create' | 'edit' | 'dependent';
  url: string;
  patient?: PatientRecord | null;          // edit mode
  primary?: PatientSummary | null;         // create-under-a-primary / dependent mode
  branches?: BranchOption[];
  onCancel?: () => void;
  onSuccess?: () => void;
}

export function valuesFromRecord(patient: PatientRecord | null | undefined, primary: PatientSummary | null | undefined): PatientFormValues {
  return {
    name: patient?.name ?? '',
    mobile: patient?.mobile_local ?? primary?.mobile_local ?? '',
    gender: patient?.gender ?? '',
    dob: patient && !patient.dob_is_estimated ? (patient.dob ?? '') : '',
    age_years: patient?.dob_is_estimated && patient.age_years !== null ? String(patient.age_years) : '',
    blood_group: patient?.blood_group ?? '',
    email: patient?.email ?? '',
    address: patient?.address ?? '',
    district: patient?.district ?? '',
    national_id: patient?.national_id ?? '',
    guardian_name: patient?.guardian_name ?? '',
    preferred_language: patient?.preferred_language ?? 'bn',
    notes: patient?.notes ?? '',
    registered_branch_id: patient?.registered_branch_id ? String(patient.registered_branch_id) : '',
    relation: patient?.primary?.relation ?? 'other',
    primary_public_id: primary?.public_id ?? '',
  };
}

export function PatientForm({ mode, url, patient, primary, branches = [], onCancel, onSuccess }: PatientFormProps) {
  const { t } = useTranslation();
  const form = useForm<PatientFormValues>(valuesFromRecord(patient, primary));
  const domainError = form.errors['domain' as keyof PatientFormValues];
  const showMobile = mode !== 'dependent';
  const showRelation = mode === 'dependent' || Boolean(primary);

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    const options = { preserveScroll: true, onSuccess };
    if (mode === 'edit') form.put(url, options);
    else form.post(url, options);
  };

  const field = (key: keyof PatientFormValues) => ({
    value: form.data[key],
    onChange: (e: { target: { value: string } }) => form.setData(key, e.target.value as never),
    error: Boolean(form.errors[key]),
    helperText: form.errors[key],
    fullWidth: true,
    size: 'small' as const,
  });

  return (
    <Box component="form" onSubmit={submit} noValidate sx={{ display: 'grid', gap: 2 }}>
      {domainError ? <Alert severity="error">{domainError}</Alert> : null}
      {primary ? (
        <Alert severity="info">{t('patients.form.primary_hint', { name: primary.name, mobile: primary.mobile_local })}</Alert>
      ) : null}

      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } }}>
        <TextField {...field('name')} label={t('patients.form.name')} required autoFocus slotProps={{ htmlInput: { lang: 'bn', maxLength: 160 } }} />
        {showMobile ? (
          <TextField {...field('mobile')} label={t('patients.form.mobile')} required type="tel" slotProps={{ htmlInput: { inputMode: 'tel', maxLength: 20 } }} placeholder="01XXXXXXXXX" />
        ) : (
          <TextField {...field('relation')} select label={t('patients.form.relation')} required>
            {RELATIONS.map((r) => <MenuItem key={r} value={r}>{t(`patients.relation.${r}`)}</MenuItem>)}
          </TextField>
        )}
        <TextField {...field('gender')} select label={t('patients.form.gender')}>
          <MenuItem value="">{t('common.status.none')}</MenuItem>
          {GENDERS.map((g) => <MenuItem key={g} value={g}>{t(`patients.gender.${g}`)}</MenuItem>)}
        </TextField>
        <TextField {...field('blood_group')} select label={t('patients.form.blood_group')}>
          <MenuItem value="">{t('common.status.none')}</MenuItem>
          {BLOOD_GROUPS.map((b) => <MenuItem key={b} value={b}>{b}</MenuItem>)}
        </TextField>
        <TextField {...field('dob')} label={t('patients.form.dob')} type="date" slotProps={{ inputLabel: { shrink: true } }} />
        <TextField {...field('age_years')} label={t('patients.form.age_years')} type="number" slotProps={{ htmlInput: { min: 0, max: 130, inputMode: 'numeric' } }} disabled={form.data.dob !== ''} />
      </Box>
      <Typography variant="caption" color="text.secondary">{t('patients.form.dob_or_age_hint')}</Typography>

      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } }}>
        <TextField {...field('address')} label={t('patients.form.address')} multiline minRows={2} sx={{ gridColumn: { md: '1 / -1' } }} />
        <TextField {...field('district')} label={t('patients.form.district')} />
        <TextField {...field('email')} label={t('patients.form.email')} type="email" />
        <TextField {...field('national_id')} label={t('patients.form.national_id')} autoComplete="off" />
        <TextField {...field('guardian_name')} label={t('patients.form.guardian_name')} slotProps={{ htmlInput: { lang: 'bn' } }} />
        <TextField {...field('preferred_language')} select label={t('patients.form.preferred_language')}>
          <MenuItem value="bn">{t('patients.language.bn')}</MenuItem>
          <MenuItem value="en">{t('patients.language.en')}</MenuItem>
        </TextField>
        {branches.length > 0 ? (
          <TextField {...field('registered_branch_id')} select label={t('patients.form.branch')}>
            <MenuItem value="">{t('common.status.none')}</MenuItem>
            {branches.map((b) => <MenuItem key={b.id} value={String(b.id)}>{b.name}</MenuItem>)}
          </TextField>
        ) : null}
        {showRelation && showMobile ? (
          <TextField {...field('relation')} select label={t('patients.form.relation')}>
            {RELATIONS.map((r) => <MenuItem key={r} value={r}>{t(`patients.relation.${r}`)}</MenuItem>)}
          </TextField>
        ) : null}
        <TextField {...field('notes')} label={t('patients.form.notes')} multiline minRows={2} sx={{ gridColumn: { md: '1 / -1' } }} />
      </Box>

      <Stack direction="row" spacing={1} sx={{ justifyContent: 'flex-end' }}>
        {onCancel ? <Button onClick={onCancel} color="inherit">{t('patients.form.cancel')}</Button> : null}
        <Button type="submit" variant="contained" disabled={form.processing}>{t('patients.form.save')}</Button>
      </Stack>
    </Box>
  );
}
