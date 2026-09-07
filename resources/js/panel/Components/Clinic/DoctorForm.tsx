// The doctor profile editor (BRIEF §5.A). Fees are typed in taka and stored in paisa — the wire is always paisa
// (CONVENTIONS §13) — and the free follow-up window gets its own card with its own explanation, because it is the
// one field on this screen that silently changes what a patient is charged.
import { useTranslation } from 'react-i18next';
import type { InertiaFormProps } from '@inertiajs/react';
import Alert from '@mui/material/Alert';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import Grid from '@mui/material/Grid';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Select from '@mui/material/Select';
import InputLabel from '@mui/material/InputLabel';
import FormControl from '@mui/material/FormControl';
import FormHelperText from '@mui/material/FormHelperText';
import Box from '@mui/material/Box';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { BDT_SIGN } from '@shared/format/money';
import type { ClinicDepartment, ClinicSpecialty } from '@shared/types/models';

export interface DoctorProfileFormData {
  degrees: string;
  degrees_bn: string;
  bmdc_reg_no: string;
  designation: string;
  bio: string;
  bio_bn: string;
  experience_years: string;
  new_fee_paisa: number;
  followup_fee_paisa: number;
  free_followup_within_days: number;
  followup_within_days: number;
  report_visit_free: boolean;
  telemedicine_fee_paisa: string;
  online_booking_fee_delta_paisa: number;
  advance_payment_required: boolean;
  chamber_notes: string;
}

/** The staff login the create screen may open alongside the doctor row (`new_user` in StoreDoctorRequest). */
export interface NewDoctorLogin {
  name: string;
  email: string;
  mobile: string;
  default_branch_id: string;
  locale: 'bn' | 'en';
}

export interface DoctorFormData {
  name: string;
  name_bn: string;
  slug: string;
  code: string;
  gender: string;
  mobile: string;
  email: string;
  department_id: string;
  room_label: string;
  is_active: boolean;
  accepts_online_booking: boolean;
  accepts_telemedicine: boolean;
  sort_order: number;
  specialty_ids: number[];
  primary_specialty_id: string;
  profile: DoctorProfileFormData;
  /** Linked staff login. Empty on Edit means "leave it as it is"; `new_user` is the create-a-login path. */
  user_id: string;
  new_user: NewDoctorLogin | null;
}

export function emptyDoctorForm(): DoctorFormData {
  return {
    name: '', name_bn: '', slug: '', code: '', gender: '', mobile: '', email: '', department_id: '', room_label: '',
    is_active: true, accepts_online_booking: true, accepts_telemedicine: false, sort_order: 0,
    specialty_ids: [], primary_specialty_id: '', profile: emptyDoctorProfile(), user_id: '', new_user: null,
  };
}

export function emptyDoctorProfile(): DoctorProfileFormData {
  return {
    degrees: '', degrees_bn: '', bmdc_reg_no: '', designation: '', bio: '', bio_bn: '', experience_years: '',
    new_fee_paisa: 0, followup_fee_paisa: 0, free_followup_within_days: 0, followup_within_days: 30,
    report_visit_free: true, telemedicine_fee_paisa: '', online_booking_fee_delta_paisa: 0,
    advance_payment_required: false, chamber_notes: '',
  };
}

interface Props {
  form: InertiaFormProps<DoctorFormData>;
  departments: ClinicDepartment[];
  specialties: ClinicSpecialty[];
  genders: string[];
}

/** Paisa in the model, taka in the box. */
function TakaField({ label, paisa, onChange, helperText, error }: { label: string; paisa: number; onChange: (paisa: number) => void; helperText?: string; error?: boolean }) {
  return (
    <TextField
      label={label} type="number" fullWidth value={paisa === 0 ? 0 : paisa / 100}
      onChange={(e) => onChange(Math.round(Number(e.target.value || 0) * 100))}
      helperText={helperText} error={error}
      slotProps={{
        htmlInput: { min: 0, step: 10, inputMode: 'decimal' },
        input: { startAdornment: <InputAdornment position="start">{BDT_SIGN}</InputAdornment> },
      }}
    />
  );
}

export function DoctorForm({ form, departments, specialties, genders }: Props) {
  const { t } = useTranslation();
  const { data, errors } = form;
  const profile = data.profile;
  const setProfile = (patch: Partial<DoctorProfileFormData>): void => form.setData('profile', { ...profile, ...patch });
  const err = (key: string): string | undefined => (errors as Record<string, string | undefined>)[key];

  return (
    <Stack spacing={2}>
      <Card>
        <CardContent>
          <Typography variant="subtitle2" gutterBottom>{t('clinic.doctors.group.identity')}</Typography>
          <Grid container spacing={2}>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                label={t('clinic.doctors.fields.name')} value={data.name} required autoFocus fullWidth
                onChange={(e) => form.setData('name', e.target.value)}
                error={Boolean(errors.name)} helperText={errors.name ?? t('clinic.doctors.fields.name_help')}
                slotProps={{ htmlInput: { maxLength: 160 } }}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                label={t('clinic.doctors.fields.name_bn')} value={data.name_bn} fullWidth
                onChange={(e) => form.setData('name_bn', e.target.value)}
                error={Boolean(errors.name_bn)} helperText={errors.name_bn}
                slotProps={{ htmlInput: { lang: 'bn', maxLength: 200 } }}
              />
            </Grid>
            <Grid size={{ xs: 6, sm: 3 }}>
              <TextField
                label={t('clinic.doctors.fields.code')} value={data.code} required fullWidth
                onChange={(e) => form.setData('code', e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8))}
                error={Boolean(errors.code)} helperText={errors.code ?? t('clinic.doctors.fields.code_help')}
                slotProps={{ htmlInput: { maxLength: 8, style: { textTransform: 'uppercase' } } }}
              />
            </Grid>
            <Grid size={{ xs: 6, sm: 3 }}>
              <TextField
                select label={t('clinic.doctors.fields.gender')} value={data.gender} fullWidth
                onChange={(e) => form.setData('gender', e.target.value)}
              >
                <MenuItem value="">{t('common.status.none')}</MenuItem>
                {genders.map((g) => <MenuItem key={g} value={g}>{t(`clinic.gender.${g}`)}</MenuItem>)}
              </TextField>
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                label={t('clinic.doctors.fields.slug')} value={data.slug} fullWidth
                onChange={(e) => form.setData('slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))}
                error={Boolean(errors.slug)} helperText={errors.slug ?? t('clinic.doctors.fields.slug_help')}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                label={t('clinic.doctors.fields.mobile')} value={data.mobile} fullWidth
                onChange={(e) => form.setData('mobile', e.target.value)}
                error={Boolean(errors.mobile)} helperText={errors.mobile}
                slotProps={{ htmlInput: { inputMode: 'tel', maxLength: 20 } }}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                label={t('clinic.doctors.fields.email')} value={data.email} type="email" fullWidth
                onChange={(e) => form.setData('email', e.target.value)}
                error={Boolean(errors.email)} helperText={errors.email}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                select label={t('clinic.doctors.fields.department')} value={data.department_id} fullWidth
                onChange={(e) => form.setData('department_id', e.target.value)}
                error={Boolean(errors.department_id)} helperText={errors.department_id}
              >
                <MenuItem value="">{t('common.status.none')}</MenuItem>
                {departments.map((d) => <MenuItem key={d.id} value={String(d.id)}>{d.name}</MenuItem>)}
              </TextField>
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                label={t('clinic.doctors.fields.room_label')} value={data.room_label} fullWidth
                onChange={(e) => form.setData('room_label', e.target.value)}
                error={Boolean(errors.room_label)} helperText={errors.room_label ?? t('clinic.doctors.fields.room_label_help')}
                slotProps={{ htmlInput: { lang: 'bn', maxLength: 40 } }}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <FormControl fullWidth error={Boolean(err('specialty_ids'))}>
                <InputLabel id="doctor-specialties-label">{t('clinic.doctors.fields.specialties')}</InputLabel>
                <Select
                  labelId="doctor-specialties-label"
                  multiple
                  value={data.specialty_ids.map(String)}
                  label={t('clinic.doctors.fields.specialties')}
                  onChange={(e) => {
                    const value = e.target.value;
                    const ids = (typeof value === 'string' ? value.split(',') : value).map(Number);
                    form.setData('specialty_ids', ids);
                    if (!ids.includes(Number(data.primary_specialty_id))) form.setData('primary_specialty_id', ids[0] ? String(ids[0]) : '');
                  }}
                  renderValue={(selected) => (
                    <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5 }}>
                      {(selected as string[]).map((id) => <Chip key={id} size="small" label={specialties.find((s) => String(s.id) === id)?.name ?? id} />)}
                    </Box>
                  )}
                >
                  {specialties.map((s) => <MenuItem key={s.id} value={String(s.id)}>{s.name}{s.name_bn ? ` · ${s.name_bn}` : ''}</MenuItem>)}
                </Select>
                <FormHelperText>{err('specialty_ids') ?? t('clinic.doctors.fields.specialties_help')}</FormHelperText>
              </FormControl>
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                select label={t('clinic.doctors.fields.primary_specialty')} value={data.primary_specialty_id} fullWidth
                disabled={data.specialty_ids.length === 0}
                onChange={(e) => form.setData('primary_specialty_id', e.target.value)}
              >
                <MenuItem value="">{t('common.status.none')}</MenuItem>
                {data.specialty_ids.map((id) => <MenuItem key={id} value={String(id)}>{specialties.find((s) => s.id === id)?.name ?? id}</MenuItem>)}
              </TextField>
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      <Card>
        <CardContent>
          <Typography variant="subtitle2" gutterBottom>{t('clinic.doctors.group.credentials')}</Typography>
          <Grid container spacing={2}>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                label={t('clinic.doctors.fields.degrees')} value={profile.degrees} fullWidth
                onChange={(e) => setProfile({ degrees: e.target.value })}
                error={Boolean(err('profile.degrees'))} helperText={err('profile.degrees') ?? t('clinic.doctors.fields.degrees_help')}
                slotProps={{ htmlInput: { maxLength: 255 } }}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 6 }}>
              <TextField
                label={t('clinic.doctors.fields.degrees_bn')} value={profile.degrees_bn} fullWidth
                onChange={(e) => setProfile({ degrees_bn: e.target.value })}
                slotProps={{ htmlInput: { lang: 'bn', maxLength: 255 } }}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 4 }}>
              <TextField
                label={t('clinic.doctors.fields.bmdc')} value={profile.bmdc_reg_no} fullWidth
                onChange={(e) => setProfile({ bmdc_reg_no: e.target.value })}
                error={Boolean(err('profile.bmdc_reg_no'))} helperText={err('profile.bmdc_reg_no') ?? t('clinic.doctors.fields.bmdc_help')}
                slotProps={{ htmlInput: { maxLength: 32 } }}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 5 }}>
              <TextField
                label={t('clinic.doctors.fields.designation')} value={profile.designation} fullWidth
                onChange={(e) => setProfile({ designation: e.target.value })}
                slotProps={{ htmlInput: { lang: 'bn', maxLength: 160 } }}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 3 }}>
              <TextField
                label={t('clinic.doctors.fields.experience')} type="number" value={profile.experience_years} fullWidth
                onChange={(e) => setProfile({ experience_years: e.target.value })}
                slotProps={{ htmlInput: { min: 0, max: 80, inputMode: 'numeric' } }}
              />
            </Grid>
            <Grid size={{ xs: 12 }}>
              <TextField
                label={t('clinic.doctors.fields.bio')} value={profile.bio} fullWidth multiline minRows={2}
                onChange={(e) => setProfile({ bio: e.target.value })}
                helperText={t('clinic.doctors.fields.bio_help')}
              />
            </Grid>
            <Grid size={{ xs: 12 }}>
              <TextField
                label={t('clinic.doctors.fields.bio_bn')} value={profile.bio_bn} fullWidth multiline minRows={2}
                onChange={(e) => setProfile({ bio_bn: e.target.value })}
                slotProps={{ htmlInput: { lang: 'bn' } }}
              />
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      <Card>
        <CardContent>
          <Typography variant="subtitle2" gutterBottom>{t('clinic.doctors.group.fees')}</Typography>
          <Grid container spacing={2}>
            <Grid size={{ xs: 12, sm: 4 }}>
              <TakaField label={t('clinic.doctors.fields.new_fee')} paisa={profile.new_fee_paisa} onChange={(paisa) => setProfile({ new_fee_paisa: paisa })} error={Boolean(err('profile.new_fee_paisa'))} helperText={err('profile.new_fee_paisa')} />
            </Grid>
            <Grid size={{ xs: 12, sm: 4 }}>
              <TakaField label={t('clinic.doctors.fields.followup_fee')} paisa={profile.followup_fee_paisa} onChange={(paisa) => setProfile({ followup_fee_paisa: paisa })} error={Boolean(err('profile.followup_fee_paisa'))} helperText={err('profile.followup_fee_paisa')} />
            </Grid>
            <Grid size={{ xs: 12, sm: 4 }}>
              <TakaField label={t('clinic.doctors.fields.telemedicine_fee')} paisa={Number(profile.telemedicine_fee_paisa || 0)} onChange={(paisa) => setProfile({ telemedicine_fee_paisa: paisa === 0 ? '' : String(paisa) })} helperText={t('clinic.doctors.fields.telemedicine_fee_help')} />
            </Grid>
          </Grid>

          {/* The brief singles this out: the billing engine already honours it, so the screen must make it plain. */}
          <Alert severity="info" sx={{ mt: 2 }}>
            <Typography variant="body2" sx={{ fontWeight: 600 }}>{t('clinic.doctors.free_followup.title')}</Typography>
            <Typography variant="body2">{t('clinic.doctors.free_followup.explain')}</Typography>
          </Alert>
          <Grid container spacing={2} sx={{ mt: 0 }}>
            <Grid size={{ xs: 12, sm: 4 }}>
              <TextField
                label={t('clinic.doctors.fields.free_followup_days')} type="number" fullWidth
                value={profile.free_followup_within_days}
                onChange={(e) => setProfile({ free_followup_within_days: Math.max(0, Number(e.target.value || 0)) })}
                error={Boolean(err('profile.free_followup_within_days'))}
                helperText={err('profile.free_followup_within_days') ?? t('clinic.doctors.fields.free_followup_days_help')}
                slotProps={{ htmlInput: { min: 0, max: profile.followup_within_days, inputMode: 'numeric' } }}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 4 }}>
              <TextField
                label={t('clinic.doctors.fields.followup_days')} type="number" fullWidth
                value={profile.followup_within_days}
                onChange={(e) => setProfile({ followup_within_days: Math.max(0, Number(e.target.value || 0)) })}
                error={Boolean(err('profile.followup_within_days'))}
                helperText={err('profile.followup_within_days') ?? t('clinic.doctors.fields.followup_days_help')}
                slotProps={{ htmlInput: { min: 0, inputMode: 'numeric' } }}
              />
            </Grid>
            <Grid size={{ xs: 12, sm: 4 }}>
              <TakaField label={t('clinic.doctors.fields.online_delta')} paisa={profile.online_booking_fee_delta_paisa} onChange={(paisa) => setProfile({ online_booking_fee_delta_paisa: paisa })} helperText={t('clinic.doctors.fields.online_delta_help')} />
            </Grid>
          </Grid>
          <Stack sx={{ mt: 1 }}>
            <FormControlLabel control={<Switch checked={profile.report_visit_free} onChange={(e) => setProfile({ report_visit_free: e.target.checked })} />} label={t('clinic.doctors.fields.report_visit_free')} />
            <FormControlLabel control={<Switch checked={profile.advance_payment_required} onChange={(e) => setProfile({ advance_payment_required: e.target.checked })} />} label={t('clinic.doctors.fields.advance_payment_required')} />
          </Stack>
        </CardContent>
      </Card>

      <Card>
        <CardContent>
          <Typography variant="subtitle2" gutterBottom>{t('clinic.doctors.group.availability')}</Typography>
          <Stack spacing={0.5}>
            <FormControlLabel control={<Switch checked={data.is_active} onChange={(e) => form.setData('is_active', e.target.checked)} />} label={t('clinic.doctors.fields.is_active')} />
            <FormControlLabel control={<Switch checked={data.accepts_online_booking} onChange={(e) => form.setData('accepts_online_booking', e.target.checked)} />} label={t('clinic.doctors.fields.accepts_online_booking')} />
            <FormControlLabel control={<Switch checked={data.accepts_telemedicine} onChange={(e) => form.setData('accepts_telemedicine', e.target.checked)} />} label={t('clinic.doctors.fields.accepts_telemedicine')} />
          </Stack>
          <TextField
            label={t('clinic.doctors.fields.chamber_notes')} value={profile.chamber_notes} fullWidth multiline minRows={2} sx={{ mt: 2 }}
            onChange={(e) => setProfile({ chamber_notes: e.target.value })}
            helperText={t('clinic.doctors.fields.chamber_notes_help')}
            slotProps={{ htmlInput: { lang: 'bn' } }}
          />
        </CardContent>
      </Card>
    </Stack>
  );
}
