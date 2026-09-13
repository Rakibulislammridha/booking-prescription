// Clinic/Doctors/Edit — the profile, the photo, this doctor's leave, and the three links out: the weekly schedule
// editor (BRIEF §5.B, already built), the pad designer, and the compounders who work this doctor's desk.
import { useRef, useState, type ChangeEvent, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Avatar from '@mui/material/Avatar';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import AddIcon from '@mui/icons-material/Add';
import DeleteIcon from '@mui/icons-material/DeleteOutlined';
import DescriptionIcon from '@mui/icons-material/Description';
import GroupsIcon from '@mui/icons-material/Groups';
import ScheduleIcon from '@mui/icons-material/CalendarMonth';
import UploadIcon from '@mui/icons-material/UploadFile';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { DoctorForm, emptyDoctorProfile, type DoctorFormData } from '@panel/Components/Clinic/DoctorForm';
import { LeaveDialog } from '@panel/Components/Clinic/LeaveDialog';
import { route } from '@shared/routes';
import { dhakaDateString, formatDateDhaka } from '@shared/format/date';
import type { PageProps } from '@shared/types/inertia';
import type { ClinicBranch, ClinicDepartment, ClinicDoctor, ClinicDoctorLeave, ClinicSpecialty, ClinicStaffUser } from '@shared/types/models';

type Props = PageProps<{
  doctor: ClinicDoctor;
  leaves: ClinicDoctorLeave[];
  departments: ClinicDepartment[];
  specialties: ClinicSpecialty[];
  users: ClinicStaffUser[];
  genders: string[];
  /** Named `branch_options`, not `branches`: SharedProps already owns `branches` (the switcher list). */
  branch_options: ClinicBranch[];
  can: { design_pad: boolean; schedule: boolean; manage_leave: boolean; manage_compounders: boolean };
}>;

export default function Edit({ doctor, leaves, departments, specialties, users, genders, branch_options, can }: Props) {
  const { t } = useTranslation();
  const photoInput = useRef<HTMLInputElement | null>(null);
  const [leaveOpen, setLeaveOpen] = useState(false);
  const profile = doctor.profile;

  const form = useForm<DoctorFormData>({
    name: doctor.name,
    name_bn: doctor.name_bn ?? '',
    slug: doctor.slug,
    code: doctor.code,
    gender: doctor.gender ?? '',
    mobile: doctor.mobile ?? '',
    email: doctor.email ?? '',
    department_id: doctor.department_id ? String(doctor.department_id) : '',
    room_label: doctor.room_label ?? '',
    is_active: doctor.is_active,
    accepts_online_booking: doctor.accepts_online_booking,
    accepts_telemedicine: doctor.accepts_telemedicine,
    sort_order: doctor.sort_order,
    specialty_ids: doctor.specialty_ids ?? [],
    primary_specialty_id: doctor.primary_specialty_id ? String(doctor.primary_specialty_id) : '',
    user_id: doctor.user_id ? String(doctor.user_id) : '',
    new_user: null,
    profile: profile
      ? {
        degrees: profile.degrees ?? '',
        degrees_bn: profile.degrees_bn ?? '',
        bmdc_reg_no: profile.bmdc_reg_no ?? '',
        designation: profile.designation ?? '',
        bio: profile.bio ?? '',
        bio_bn: profile.bio_bn ?? '',
        experience_years: profile.experience_years ? String(profile.experience_years) : '',
        new_fee_paisa: profile.new_fee_paisa,
        followup_fee_paisa: profile.followup_fee_paisa,
        free_followup_within_days: profile.free_followup_within_days,
        followup_within_days: profile.followup_within_days,
        report_visit_free: profile.report_visit_free,
        telemedicine_fee_paisa: profile.telemedicine_fee_paisa ? String(profile.telemedicine_fee_paisa) : '',
        online_booking_fee_delta_paisa: profile.online_booking_fee_delta_paisa,
        advance_payment_required: profile.advance_payment_required,
        chamber_notes: profile.chamber_notes ?? '',
      }
      : emptyDoctorProfile(),
  });

  const uploadPhoto = (event: ChangeEvent<HTMLInputElement>): void => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    router.post(route('panel.clinic.doctors.photo.store', { doctor: doctor.public_id }), { photo: file }, { forceFormData: true, preserveScroll: true });
  };
  const cancelLeave = (leave: ClinicDoctorLeave): void => {
    if (window.confirm(t('clinic.leaves.cancel_confirm'))) {
      router.delete(route('panel.clinic.leaves.destroy', { leave: leave.id }), { preserveScroll: true });
    }
  };

  return (
    <Stack spacing={2} sx={{ maxWidth: 1000 }}>
      <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
        <Avatar src={doctor.photo_url ?? undefined} sx={{ width: 56, height: 56 }}>{doctor.name.slice(0, 1)}</Avatar>
        <Stack sx={{ flexGrow: 1 }}>
          <Typography variant="h6">{doctor.name}</Typography>
          <Typography variant="caption" color="text.secondary">{doctor.user_name ? t('clinic.doctors.linked_login', { name: doctor.user_name }) : t('clinic.doctors.no_login')}</Typography>
        </Stack>
        <Button size="small" startIcon={<UploadIcon />} onClick={() => photoInput.current?.click()}>{t('clinic.doctors.upload_photo')}</Button>
        <input ref={photoInput} type="file" accept="image/png,image/jpeg,image/webp" hidden onChange={uploadPhoto} />
        {can.schedule ? (
          <Button size="small" variant="outlined" startIcon={<ScheduleIcon />} component={RouterLink} href={route('panel.scheduling.index', { doctor: doctor.id })}>
            {t('clinic.doctors.schedule_link')}
          </Button>
        ) : null}
        {can.design_pad ? (
          <Button size="small" variant="outlined" startIcon={<DescriptionIcon />} component={RouterLink} href={route('panel.clinic.doctors.pad.edit', { doctor: doctor.public_id })}>
            {t('clinic.doctors.pad_link')}
          </Button>
        ) : null}
        {can.manage_compounders ? (
          <Button size="small" variant="outlined" startIcon={<GroupsIcon />} component={RouterLink} href={route('panel.clinic.doctors.compounders.index', { doctor: doctor.public_id })}>
            {t('clinic.compounders.link')}
          </Button>
        ) : null}
      </Stack>

      <form onSubmit={(e) => { e.preventDefault(); form.put(route('panel.clinic.doctors.update', { doctor: doctor.public_id })); }}>
        <Stack spacing={2}>
          {/* A doctor may have no login at all (SCHEMA §3.1) — a visiting doctor whose serials reception handles.
              Linking one here gives them the doctor role and the prescription writer. */}
          <Card>
            <CardContent>
              <Typography variant="subtitle2" gutterBottom>{t('clinic.doctors.login.title')}</Typography>
              <TextField
                select fullWidth size="small" label={t('clinic.doctors.login.pick_user')} value={form.data.user_id}
                onChange={(e) => form.setData('user_id', e.target.value)}
                error={Boolean(form.errors.user_id)}
                helperText={form.errors.user_id ?? t('clinic.doctors.login.help_existing')}
              >
                <MenuItem value="">{t('clinic.doctors.login.none')}</MenuItem>
                {doctor.user_id && doctor.user_name
                  ? <MenuItem value={String(doctor.user_id)}>{doctor.user_name}</MenuItem>
                  : null}
                {users.map((u) => <MenuItem key={u.public_id} value={String(u.id)}>{u.name} — {u.email}</MenuItem>)}
              </TextField>
            </CardContent>
          </Card>

          <DoctorForm form={form} departments={departments} specialties={specialties} genders={genders} />
          <Stack direction="row" spacing={1}>
            <Button type="submit" variant="contained" disabled={form.processing}>{t('common.actions.save')}</Button>
            <Button component={RouterLink} href={route('panel.clinic.doctors.index')}>{t('common.actions.cancel')}</Button>
          </Stack>
        </Stack>
      </form>

      <Card>
        <CardContent>
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', mb: 1 }}>
            <Typography variant="subtitle2" sx={{ flexGrow: 1 }}>{t('clinic.leaves.for_doctor')}</Typography>
            {can.manage_leave ? <Button size="small" startIcon={<AddIcon />} onClick={() => setLeaveOpen(true)}>{t('clinic.leaves.add')}</Button> : null}
          </Stack>
          <Table size="small" aria-label={t('clinic.leaves.for_doctor')}>
            <TableHead>
              <TableRow>
                <TableCell>{t('clinic.leaves.columns.dates')}</TableCell>
                <TableCell>{t('clinic.leaves.columns.type')}</TableCell>
                <TableCell>{t('clinic.leaves.columns.branch')}</TableCell>
                <TableCell>{t('clinic.leaves.columns.reason')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {leaves.length === 0 ? (
                <TableRow><TableCell colSpan={5}><Typography variant="body2" color="text.secondary" sx={{ py: 1.5 }}>{t('clinic.leaves.empty')}</Typography></TableCell></TableRow>
              ) : leaves.map((leave) => (
                <TableRow key={leave.id} hover>
                  <TableCell>{formatDateDhaka(leave.starts_on)} — {formatDateDhaka(leave.ends_on)}</TableCell>
                  <TableCell>
                    <Chip size="small" color={leave.type === 'emergency' ? 'warning' : 'default'} label={t(`clinic.leaves.type.${leave.type}`)} />
                    {leave.is_cancelled ? <Chip size="small" sx={{ ml: 0.5 }} label={t('clinic.leaves.withdrawn')} /> : null}
                  </TableCell>
                  <TableCell>{leave.branch_name ?? t('clinic.leaves.all_branches')}</TableCell>
                  <TableCell>{leave.reason ?? '—'}</TableCell>
                  <TableCell align="right">
                    {can.manage_leave && !leave.is_cancelled ? (
                      <IconButton size="small" aria-label={t('clinic.leaves.withdraw')} onClick={() => cancelLeave(leave)}><DeleteIcon fontSize="small" /></IconButton>
                    ) : null}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <LeaveDialog
        open={leaveOpen}
        onClose={() => setLeaveOpen(false)}
        doctors={[doctor]}
        branches={branch_options}
        types={['planned', 'emergency']}
        today={dhakaDateString()}
        doctorId={doctor.id}
      />
    </Stack>
  );
}

Edit.layout = (page: ReactNode) => <PanelLayout title="clinic.doctors.edit_title">{page}</PanelLayout>;
