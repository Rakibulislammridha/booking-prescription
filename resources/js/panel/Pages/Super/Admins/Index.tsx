// Super/Admins/Index — the platform's operators (public.super_admins, SCHEMA §2.8): who can open this console.
// A short list by design (ARCHITECTURE §6.2: a handful of people, no role matrix), so it is one table with the
// facts that matter at a glance — active, second factor, last seen — and a dialog for a new account. Everything
// that changes an existing account lives on its edit page, behind the operator's own password where it counts.
import { useState, type FormEvent, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogContentText from '@mui/material/DialogContentText';
import DialogTitle from '@mui/material/DialogTitle';
import FormControlLabel from '@mui/material/FormControlLabel';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import AddIcon from '@mui/icons-material/Add';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import type { SuperAdminRow, SuperTwoFactorState } from '@panel/Components/Super/types';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  admins: SuperAdminRow[];
  active_count: number;
}>;

interface CreateForm {
  name: string;
  email: string;
  send_link: boolean;
  password: string;
  password_confirmation: string;
  current_password: string;
}

const TWO_FACTOR: Record<SuperTwoFactorState, { label: string; color: 'success' | 'warning' | 'default' }> = {
  enabled: { label: 'super.admins.two_factor.enabled', color: 'success' },
  enrolling: { label: 'super.admins.two_factor.enrolling', color: 'warning' },
  none: { label: 'super.admins.two_factor.none', color: 'default' },
};

export function TwoFactorChip({ state }: { state: SuperTwoFactorState }) {
  const { t } = useTranslation();
  const entry = TWO_FACTOR[state];
  return <Chip size="small" variant={state === 'enabled' ? 'filled' : 'outlined'} color={entry.color} label={t(entry.label)} />;
}

function CreateDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useTranslation();
  const form = useForm<CreateForm>({ name: '', email: '', send_link: true, password: '', password_confirmation: '', current_password: '' });
  const domainError = (form.errors as Record<string, string | undefined>).domain;

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    form.post(route('super.admins.store'), {
      preserveScroll: true,
      onSuccess: () => { form.reset(); onClose(); },
      onFinish: () => form.reset('password', 'password_confirmation', 'current_password'),
    });
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="sm">
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>{t('super.admins.create_title')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <DialogContentText>{t('super.admins.create_body')}</DialogContentText>
          {domainError ? <Alert severity="error">{domainError}</Alert> : null}
          <TextField
            label={t('super.admins.field.name')} name="name" autoFocus required size="small" autoComplete="off"
            value={form.data.name} onChange={(e) => form.setData('name', e.target.value)}
            error={Boolean(form.errors.name)} helperText={form.errors.name}
          />
          <TextField
            label={t('super.admins.field.email')} name="email" type="email" required size="small" autoComplete="off"
            value={form.data.email} onChange={(e) => form.setData('email', e.target.value)}
            error={Boolean(form.errors.email)} helperText={form.errors.email}
          />
          <FormControlLabel
            control={<Switch checked={form.data.send_link} onChange={(e) => form.setData('send_link', e.target.checked)} />}
            label={t('super.admins.field.send_link')}
          />
          <Typography variant="caption" color="text.secondary" sx={{ mt: -1.5 }}>
            {t(form.data.send_link ? 'super.admins.field.send_link_help' : 'super.admins.field.password_help')}
          </Typography>
          {form.data.send_link ? null : (
            <>
              <TextField
                label={t('super.admins.field.password')} name="password" type="password" size="small" autoComplete="new-password"
                value={form.data.password} onChange={(e) => form.setData('password', e.target.value)}
                error={Boolean(form.errors.password)} helperText={form.errors.password}
              />
              <TextField
                label={t('auth.confirm_password')} name="password_confirmation" type="password" size="small" autoComplete="new-password"
                value={form.data.password_confirmation} onChange={(e) => form.setData('password_confirmation', e.target.value)}
                error={Boolean(form.errors.password_confirmation)} helperText={form.errors.password_confirmation}
              />
            </>
          )}
          <TextField
            label={t('super.admins.field.current_password')} name="current_password" type="password" required size="small" autoComplete="current-password"
            value={form.data.current_password} onChange={(e) => form.setData('current_password', e.target.value)}
            error={Boolean(form.errors.current_password)} helperText={form.errors.current_password ?? t('super.admins.field.current_password_help')}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>{t('super.actions.cancel')}</Button>
          <Button type="submit" variant="contained" disabled={form.processing}>{t('super.admins.create_submit')}</Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}

export default function Index({ admins, active_count }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [creating, setCreating] = useState(false);

  const columns: SuperColumn<SuperAdminRow>[] = [
    {
      key: 'name',
      label: t('super.admins.column.name'),
      bn: true,
      render: (row) => (
        <Box>
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
            <Typography variant="body2" sx={{ fontWeight: 600 }}>{row.name}</Typography>
            {row.is_self ? <Chip size="small" color="primary" variant="outlined" label={t('super.admins.you')} /> : null}
          </Stack>
          <Typography variant="caption" color="text.secondary">{row.email}</Typography>
        </Box>
      ),
    },
    {
      key: 'active',
      label: t('super.admins.column.active'),
      render: (row) => <Chip size="small" color={row.is_active ? 'success' : 'default'} label={t(row.is_active ? 'super.admins.active' : 'super.admins.inactive')} />,
    },
    { key: 'two_factor', label: t('super.admins.column.two_factor'), render: (row) => <TwoFactorChip state={row.two_factor} /> },
    {
      key: 'last_login',
      label: t('super.admins.column.last_login'),
      render: (row) => (row.last_login_at ? (
        <Box>
          <Typography variant="body2">{formatDhaka(row.last_login_at, 'D MMM YYYY, h:mm a', locale)}</Typography>
          {row.last_login_ip ? <Typography variant="caption" color="text.secondary" sx={{ fontFamily: 'monospace' }}>{row.last_login_ip}</Typography> : null}
        </Box>
      ) : <Typography variant="body2" color="text.secondary">{t('super.admins.never')}</Typography>),
    },
    { key: 'created', label: t('super.admins.column.created'), render: (row) => (row.created_at ? formatDhaka(row.created_at, 'D MMM YYYY', locale) : '—') },
  ];

  return (
    <Box>
      <Stack spacing={2}>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' }, justifyContent: 'space-between' }}>
          <Box>
            <Typography variant="body2" color="text.secondary">{t('super.admins.intro')}</Typography>
            <Typography variant="caption" color="text.secondary">
              {t('super.admins.summary', { total: formatNumber(admins.length, locale), active: formatNumber(active_count, locale) })}
            </Typography>
          </Box>
          <Button variant="contained" startIcon={<AddIcon />} onClick={() => setCreating(true)}>{t('super.admins.create')}</Button>
        </Stack>

        <Card variant="outlined">
          <SuperTable
            columns={columns}
            rows={admins}
            rowKey={(row) => String(row.id)}
            empty={t('super.admins.empty')}
            label={t('super.admins.title')}
            onRowClick={(row) => router.visit(route('super.admins.edit', { admin: row.id }))}
          />
        </Card>
      </Stack>

      <CreateDialog open={creating} onClose={() => setCreating(false)} />
    </Box>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="super.admins.title">{page}</PanelLayout>;
