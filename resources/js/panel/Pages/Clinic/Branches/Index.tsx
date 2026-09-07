// Clinic/Branches/Index — the clinic's physical locations (BRIEF §5.A). Server-side search + pagination: a hospital
// group can run dozens of branches, and the list is the first screen of the setup flow.
import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import InputAdornment from '@mui/material/InputAdornment';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import AddIcon from '@mui/icons-material/Add';
import SearchIcon from '@mui/icons-material/Search';
import StarIcon from '@mui/icons-material/Star';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { Pager } from '@panel/Components/Clinic/Pager';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { ClinicBranch, Paginated } from '@shared/types/models';

type Props = PageProps<{
  branches: Paginated<ClinicBranch>;
  filters: { q: string };
  timezone: string;
  can: { manage: boolean };
}>;

export default function Index({ branches, filters, timezone, can }: Props) {
  const { t } = useTranslation();
  const [q, setQ] = useState(filters.q);

  const search = (value: string): void => {
    setQ(value);
    router.get(route('panel.clinic.branches.index'), value === '' ? {} : { q: value }, { preserveState: true, replace: true, only: ['branches', 'filters'] });
  };
  const toggle = (branch: ClinicBranch): void => {
    router.patch(route('panel.clinic.branches.status', { branch: branch.public_id }), { is_active: !branch.is_active }, { preserveScroll: true });
  };
  const makeMain = (branch: ClinicBranch): void => {
    router.post(route('panel.clinic.branches.main', { branch: branch.public_id }), {}, { preserveScroll: true });
  };

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' } }}>
        <TextField
          value={q} onChange={(e) => search(e.target.value)} size="small" fullWidth
          placeholder={t('clinic.branches.search_placeholder')}
          slotProps={{
            htmlInput: { 'aria-label': t('common.actions.search'), lang: 'bn' },
            input: { startAdornment: <InputAdornment position="start"><SearchIcon /></InputAdornment> },
          }}
        />
        <Typography variant="body2" color="text.secondary" sx={{ flexShrink: 0 }}>{t('clinic.branches.timezone_note', { timezone })}</Typography>
        {can.manage ? (
          <Button component={RouterLink} href={route('panel.clinic.branches.create')} variant="contained" startIcon={<AddIcon />} sx={{ flexShrink: 0 }}>
            {t('clinic.branches.add')}
          </Button>
        ) : null}
      </Stack>

      <Card>
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small" aria-label={t('clinic.branches.title')}>
            <TableHead>
              <TableRow>
                <TableCell>{t('clinic.branches.columns.name')}</TableCell>
                <TableCell>{t('clinic.branches.columns.code')}</TableCell>
                <TableCell>{t('clinic.branches.columns.contact')}</TableCell>
                <TableCell>{t('clinic.branches.columns.main')}</TableCell>
                <TableCell>{t('clinic.branches.columns.active')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {branches.data.length === 0 ? (
                <TableRow><TableCell colSpan={6}><Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{t('clinic.branches.empty')}</Typography></TableCell></TableRow>
              ) : branches.data.map((branch) => (
                <TableRow key={branch.public_id} hover>
                  <TableCell>
                    <Typography variant="body2" sx={{ fontWeight: 600 }}>{branch.name}</Typography>
                    <Typography variant="caption" color="text.secondary">{branch.address ?? '—'}</Typography>
                  </TableCell>
                  <TableCell sx={{ fontFamily: 'monospace' }}>{branch.code}</TableCell>
                  <TableCell>
                    <Typography variant="body2">{branch.phone ?? '—'}</Typography>
                    <Typography variant="caption" color="text.secondary">{branch.email ?? ''}</Typography>
                  </TableCell>
                  <TableCell>
                    {branch.is_main ? <Chip size="small" color="primary" icon={<StarIcon />} label={t('clinic.branches.main')} /> : can.manage ? (
                      <Button size="small" onClick={() => makeMain(branch)}>{t('clinic.branches.make_main')}</Button>
                    ) : null}
                  </TableCell>
                  <TableCell>
                    <Switch
                      size="small" checked={branch.is_active} disabled={!can.manage}
                      slotProps={{ input: { 'aria-label': t('clinic.branches.toggle_aria', { name: branch.name }) } }}
                      onChange={() => toggle(branch)}
                    />
                  </TableCell>
                  <TableCell align="right">
                    {can.manage ? (
                      <Button size="small" component={RouterLink} href={route('panel.clinic.branches.edit', { branch: branch.public_id })}>
                        {t('common.actions.edit')}
                      </Button>
                    ) : null}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
        <Pager page={branches} />
      </Card>
    </Stack>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="clinic.branches.title">{page}</PanelLayout>;
