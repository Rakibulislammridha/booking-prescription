// Patients/Index: instant search by mobile / name / patient code (api.patients.search, debounced XHR) over the
// recent-patients list the server renders first. Rows open Patients/Show.
import { useEffect, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Card from '@mui/material/Card';
import TextField from '@mui/material/TextField';
import InputAdornment from '@mui/material/InputAdornment';
import Button from '@mui/material/Button';
import Table from '@mui/material/Table';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TableCell from '@mui/material/TableCell';
import TableBody from '@mui/material/TableBody';
import Chip from '@mui/material/Chip';
import Typography from '@mui/material/Typography';
import Stack from '@mui/material/Stack';
import LinearProgress from '@mui/material/LinearProgress';
import SearchIcon from '@mui/icons-material/Search';
import PersonAddIcon from '@mui/icons-material/PersonAdd';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { searchPatients } from '@panel/api/patients';
import { ageSexLabel } from '@panel/Components/Patients/labels';
import { route } from '@shared/routes';
import { formatBn } from '@shared/format/number';
import { formatDateDhaka } from '@shared/format/date';
import type { PageProps } from '@shared/types/inertia';
import type { PatientSummary } from '@shared/types/models';
import type { Locale } from '@shared/types/shared-props';

type Props = PageProps<{
  filters: { q: string };
  patients: PatientSummary[];
  search_engine: 'meilisearch' | 'database';
  can: { create: boolean };
}>;

export default function Index({ filters, patients, search_engine, can }: Props) {
  const { t, i18n } = useTranslation();
  const locale: Locale = i18n.language === 'bn' ? 'bn' : 'en';
  const [q, setQ] = useState(filters.q);
  const [rows, setRows] = useState<PatientSummary[]>(patients);
  const [engine, setEngine] = useState(search_engine);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (q.trim() === filters.q.trim()) {
      setRows(patients);
      return undefined;
    }
    const controller = new AbortController();
    const timer = setTimeout(() => {
      setBusy(true);
      searchPatients(q.trim(), 50, controller.signal)
        .then((result) => {
          setRows(result.data);
          setEngine(result.meta.engine);
        })
        .catch(() => undefined)
        .finally(() => setBusy(false));
    }, 250);
    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [q, filters.q, patients]);

  const open = (patient: PatientSummary): void => {
    router.visit(route('panel.patients.show', { patient: patient.public_id }));
  };

  return (
    <Stack spacing={2}>
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' } }}>
        <TextField
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder={t('patients.index.search_placeholder')}
          autoFocus
          fullWidth
          size="small"
          slotProps={{
            htmlInput: { 'aria-label': t('common.actions.search'), lang: 'bn' },
            input: { startAdornment: <InputAdornment position="start"><SearchIcon /></InputAdornment> },
          }}
        />
        <Chip size="small" variant="outlined" label={t(`patients.index.engine.${engine}`)} sx={{ flexShrink: 0 }} />
        {can.create ? (
          <Button component={RouterLink} href={route('panel.patients.create')} variant="contained" startIcon={<PersonAddIcon />} sx={{ flexShrink: 0 }}>
            {t('patients.index.new')}
          </Button>
        ) : null}
      </Stack>

      <Card>
        {busy ? <LinearProgress /> : null}
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{t('patients.index.columns.code')}</TableCell>
                <TableCell>{t('patients.index.columns.name')}</TableCell>
                <TableCell>{t('patients.index.columns.mobile')}</TableCell>
                <TableCell>{t('patients.index.columns.age')}</TableCell>
                <TableCell>{t('patients.index.columns.last_visit')}</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {rows.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={5}>
                    <Typography variant="body2" color="text.secondary" sx={{ py: 2, textAlign: 'center' }}>{busy ? t('patients.index.searching') : t('patients.index.empty')}</Typography>
                  </TableCell>
                </TableRow>
              ) : (
                rows.map((p) => (
                  <TableRow key={p.public_id} hover onClick={() => open(p)} sx={{ cursor: 'pointer' }} tabIndex={0} onKeyDown={(e) => { if (e.key === 'Enter') open(p); }}>
                    <TableCell sx={{ fontFamily: 'monospace' }}>{p.patient_code}</TableCell>
                    <TableCell lang="bn">
                      {p.name}
                      {!p.is_mobile_owner ? <Chip size="small" variant="outlined" label={t('portal.home.member')} sx={{ ml: 1 }} /> : null}
                    </TableCell>
                    <TableCell>{formatBn(p.mobile_local, locale)}</TableCell>
                    <TableCell>{ageSexLabel(t, p, locale) || '—'}</TableCell>
                    <TableCell>{p.last_visit_at ? formatDateDhaka(p.last_visit_at, locale) : '—'}</TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </Box>
      </Card>
    </Stack>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="patients.index.title">{page}</PanelLayout>;
