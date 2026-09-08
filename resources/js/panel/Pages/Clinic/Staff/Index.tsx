// Clinic/Staff/Index — every login the clinic has, filtered by role, searched server-side (a hospital's staff list
// grows past a page long before its branch list does). The row actions are the three an admin actually performs:
// edit, deactivate, send a password reset.
import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import AddIcon from '@mui/icons-material/PersonAdd';
import KeyIcon from '@mui/icons-material/VpnKey';
import SearchIcon from '@mui/icons-material/Search';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { Pager } from '@panel/Components/Clinic/Pager';
import { route } from '@shared/routes';
import { formatDhaka } from '@shared/format/date';
import type { PageProps } from '@shared/types/inertia';
import type { ClinicStaffUser, Paginated } from '@shared/types/models';

type Props = PageProps<{
  users: Paginated<ClinicStaffUser>;
  filters: { q: string; role: string; status: string };
  roles: string[];
  current_user_id: number;
  can: { manage: boolean };
}>;

export default function Index({ users, filters, roles, current_user_id, can }: Props) {
  const { t } = useTranslation();
  const [q, setQ] = useState(filters.q);

  const reload = (patch: Partial<Props['filters']>): void => {
    const next = { q, role: filters.role, status: filters.status, ...patch };
    router.get(route('panel.clinic.staff.index'), Object.fromEntries(Object.entries(next).filter(([, v]) => v !== '')), {
      preserveState: true, replace: true, only: ['users', 'filters'],
    });
  };
  const toggle = (user: ClinicStaffUser): void => {
    router.patch(route('panel.clinic.staff.status', { user: user.public_id }), { is_active: !user.is_active }, { preserveScroll: true });
  };
  const sendReset = (user: ClinicStaffUser): void => {
    router.post(route('panel.clinic.staff.password_reset', { user: user.public_id }), {}, { preserveScroll: true });
  };

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ alignItems: { md: 'center' } }}>
        <TextField
          value={q}
          onChange={(e) => { setQ(e.target.value); }}
          onKeyDown={(e) => { if (e.key === 'Enter') reload({ q: (e.target as HTMLInputElement).value }); }}
          onBlur={(e) => reload({ q: e.target.value })}
          size="small" fullWidth placeholder={t('clinic.staff.search_placeholder')}
          slotProps={{
            htmlInput: { 'aria-label': t('common.actions.search'), lang: 'bn' },
            input: { startAdornment: <InputAdornment position="start"><SearchIcon /></InputAdornment> },
          }}
        />
        <TextField select size="small" label={t('clinic.staff.filters.role')} value={filters.role} sx={{ minWidth: 180 }} onChange={(e) => reload({ role: e.target.value })}>
          <MenuItem value="">{t('clinic.staff.filters.all_roles')}</MenuItem>
          {roles.map((role) => <MenuItem key={role} value={role}>{t(`roles.${role}`)}</MenuItem>)}
        </TextField>
        <TextField select size="small" label={t('clinic.staff.filters.status')} value={filters.status} sx={{ minWidth: 160 }} onChange={(e) => reload({ status: e.target.value })}>
          <MenuItem value="">{t('clinic.staff.filters.all_statuses')}</MenuItem>
          <MenuItem value="active">{t('clinic.status.active')}</MenuItem>
          <MenuItem value="inactive">{t('clinic.status.inactive')}</MenuItem>
        </TextField>
        {can.manage ? (
          <Button component={RouterLink} href={route('panel.clinic.staff.create')} variant="contained" startIcon={<AddIcon />} sx={{ flexShrink: 0 }}>
            {t('clinic.staff.add')}
          </Button>
        ) : null}
      </Stack>

      <Card>
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small" aria-label={t('clinic.staff.title')}>
            <TableHead>
              <TableRow>
                <TableCell>{t('clinic.staff.columns.name')}</TableCell>
                <TableCell>{t('clinic.staff.columns.role')}</TableCell>
                <TableCell>{t('clinic.staff.columns.branch')}</TableCell>
                <TableCell>{t('clinic.staff.columns.last_login')}</TableCell>
                <TableCell>{t('clinic.staff.columns.active')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {users.data.length === 0 ? (
                <TableRow><TableCell colSpan={6}><Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('clinic.staff.empty')}</Typography></TableCell></TableRow>
              ) : users.data.map((user) => {
                const isSelf = user.id === current_user_id;
                return (
                  <TableRow key={user.public_id} hover>
                    <TableCell>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>
                        {user.name}{isSelf ? <Chip size="small" label={t('clinic.staff.you')} sx={{ ml: 1 }} /> : null}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">{user.email}{user.mobile ? ` · ${user.mobile}` : ''}</Typography>
                    </TableCell>
                    <TableCell>{user.role ? t(`roles.${user.role}`) : '—'}</TableCell>
                    <TableCell>{user.branch_name ?? '—'}</TableCell>
                    <TableCell>{user.last_login_at ? formatDhaka(user.last_login_at) : '—'}</TableCell>
                    <TableCell>
                      <Tooltip title={isSelf ? t('clinic.staff.cannot_deactivate_self') : ''}>
                        <span>
                          <Switch
                            size="small" checked={user.is_active} disabled={!can.manage || isSelf}
                            slotProps={{ input: { 'aria-label': t('clinic.staff.toggle_aria', { name: user.name }) } }}
                            onChange={() => toggle(user)}
                          />
                        </span>
                      </Tooltip>
                    </TableCell>
                    <TableCell align="right">
                      {can.manage ? (
                        <Stack direction="row" spacing={0.5} sx={{ justifyContent: 'flex-end' }}>
                          <Button size="small" startIcon={<KeyIcon />} onClick={() => sendReset(user)}>{t('clinic.staff.send_reset')}</Button>
                          <Button size="small" component={RouterLink} href={route('panel.clinic.staff.sessions.index', { user: user.public_id })}>{t('clinic.staff.sessions')}</Button>
                          <Button size="small" component={RouterLink} href={route('panel.clinic.staff.edit', { user: user.public_id })}>{t('common.actions.edit')}</Button>
                        </Stack>
                      ) : null}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </Box>
        <Pager page={users} />
      </Card>
    </Stack>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="clinic.staff.title">{page}</PanelLayout>;
