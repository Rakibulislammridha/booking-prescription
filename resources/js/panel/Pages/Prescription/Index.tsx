// Prescription/Index — the finder behind the sidebar's "Prescriptions" entry (`panel.prescriptions.index`): recently
// issued first, searched by patient (name / mobile / code — the patients index's own matcher), filtered by doctor,
// status and day range, fifty a page from the server. A row opens the existing Show page; Print and PDF are the
// existing output routes (`panel.prescription.print` / `.pdf`), opened exactly the way Show opens them. Nothing here
// is heavy enough to lazy-load: a filter bar, a table and a pager.
import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
import InputAdornment from '@mui/material/InputAdornment';
import MenuItem from '@mui/material/MenuItem';
import Pagination from '@mui/material/Pagination';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import PictureAsPdfIcon from '@mui/icons-material/PictureAsPdf';
import PrintIcon from '@mui/icons-material/Print';
import SearchIcon from '@mui/icons-material/Search';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { formatDhaka } from '@shared/format/date';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { Paginated, PrescriptionIndexRow, PrescriptionStatus } from '@shared/types/models';

interface Filters {
  q: string;
  doctor: string | null;
  status: PrescriptionStatus | null;
  from: string | null;
  to: string | null;
}

interface DoctorOption {
  public_id: string;
  name: string;
  name_bn: string | null;
}

type Props = PageProps<{
  filters: Filters;
  prescriptions: Paginated<PrescriptionIndexRow>;
  options: { doctors: DoctorOption[]; statuses: PrescriptionStatus[] };
}>;

const STATUS_COLOR: Record<PrescriptionStatus, 'default' | 'success' | 'warning' | 'error'> = {
  draft: 'default',
  issued: 'success',
  amended: 'warning',
  voided: 'error',
};

const EMPTY: Filters = { q: '', doctor: null, status: null, from: null, to: null };

export default function Index({ filters, prescriptions, options }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [q, setQ] = useState(filters.q);

  // Every filter change is a server round trip (the list is paginated there); a page change keeps the filters.
  const visit = (patch: Partial<Filters>, page?: number): void => {
    const next = { ...filters, ...patch };
    const params: Record<string, string | number> = {};
    for (const key of ['q', 'doctor', 'status', 'from', 'to'] as const) {
      const value = next[key];
      if (value !== null && value !== '') params[key] = value;
    }
    if (page !== undefined && page > 1) params.page = page;
    router.get(route('panel.prescriptions.index'), params, { preserveState: true, preserveScroll: true, replace: page === undefined });
  };

  const open = (row: PrescriptionIndexRow): void => {
    router.visit(route('panel.prescription.prescriptions.show', { prescription: row.id }));
  };
  // Opened synchronously inside the click so the pop-up blocker lets it through — the same rule as Show (§7.6).
  const print = (row: PrescriptionIndexRow): void => {
    window.open(route('panel.prescription.print', { prescription: row.id }), '_blank', 'noopener');
  };
  const pdf = (row: PrescriptionIndexRow): void => {
    window.open(route('panel.prescription.pdf', { prescription: row.id, sync: 1 }), '_blank', 'noopener');
  };

  const doctorLabel = (doctor: DoctorOption | NonNullable<PrescriptionIndexRow['doctor']>): string =>
    locale === 'bn' && doctor.name_bn ? doctor.name_bn : doctor.name;
  const { meta } = prescriptions;

  return (
    <Stack spacing={2}>
      <Box>
        <Typography variant="h5" component="h1">{t('prescriptions.index.title')}</Typography>
        <Typography variant="body2" color="text.secondary">{t('prescriptions.index.subtitle')}</Typography>
      </Box>

      <Card sx={{ p: 2 }}>
        <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ alignItems: { md: 'center' } }}>
          <TextField
            size="small"
            value={q}
            onChange={(e) => setQ(e.target.value)}
            onKeyDown={(e) => {
              if (e.key === 'Enter') visit({ q: q.trim() });
            }}
            placeholder={t('prescriptions.index.search')}
            sx={{ flexGrow: 1, minWidth: 220 }}
            slotProps={{
              htmlInput: { 'aria-label': t('common.actions.search'), lang: 'bn' },
              input: { startAdornment: <InputAdornment position="start"><SearchIcon fontSize="small" /></InputAdornment> },
            }}
          />
          <TextField select size="small" label={t('prescriptions.index.filter_doctor')} value={filters.doctor ?? ''} onChange={(e) => visit({ doctor: e.target.value || null })} sx={{ minWidth: 180 }}>
            <MenuItem value="">{t('prescriptions.index.all')}</MenuItem>
            {options.doctors.map((doctor) => (
              <MenuItem key={doctor.public_id} value={doctor.public_id}>{doctorLabel(doctor)}</MenuItem>
            ))}
          </TextField>
          <TextField select size="small" label={t('prescriptions.index.filter_status')} value={filters.status ?? ''} onChange={(e) => visit({ status: (e.target.value || null) as PrescriptionStatus | null })} sx={{ minWidth: 140 }}>
            <MenuItem value="">{t('prescriptions.index.all')}</MenuItem>
            {options.statuses.map((status) => (
              <MenuItem key={status} value={status}>{t(`prescriptions.status.${status}`)}</MenuItem>
            ))}
          </TextField>
          <TextField type="date" size="small" label={t('prescriptions.index.filter_from')} slotProps={{ inputLabel: { shrink: true } }} value={filters.from ?? ''} onChange={(e) => visit({ from: e.target.value || null })} />
          <TextField type="date" size="small" label={t('prescriptions.index.filter_to')} slotProps={{ inputLabel: { shrink: true } }} value={filters.to ?? ''} onChange={(e) => visit({ to: e.target.value || null })} />
          <Button
            onClick={() => {
              setQ('');
              visit(EMPTY);
            }}
            sx={{ flexShrink: 0 }}
          >
            {t('prescriptions.index.clear_filters')}
          </Button>
        </Stack>
      </Card>

      <Chip size="small" variant="outlined" label={t('prescriptions.index.total', { count: formatBn(meta.total, locale) })} sx={{ alignSelf: 'flex-start' }} />

      <Card>
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{t('prescriptions.index.col_date')}</TableCell>
                <TableCell>{t('prescriptions.index.col_patient')}</TableCell>
                <TableCell>{t('prescriptions.index.col_doctor')}</TableCell>
                <TableCell>{t('prescriptions.index.col_diagnosis')}</TableCell>
                <TableCell align="right">{t('prescriptions.index.col_items')}</TableCell>
                <TableCell>{t('prescriptions.index.col_status')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {prescriptions.data.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={7}>
                    <Typography variant="body2" color="text.secondary" sx={{ py: 3, textAlign: 'center' }}>{t('prescriptions.index.empty')}</Typography>
                  </TableCell>
                </TableRow>
              ) : (
                prescriptions.data.map((row) => {
                  const moment = row.issued_at ?? row.created_at;
                  return (
                    <TableRow key={row.id} hover onClick={() => open(row)} sx={{ cursor: 'pointer' }} tabIndex={0} onKeyDown={(e) => { if (e.key === 'Enter') open(row); }} data-testid="rx-row">
                      <TableCell sx={{ whiteSpace: 'nowrap' }}>{moment ? formatDhaka(moment, 'D MMM YYYY, h:mm a', locale) : '—'}</TableCell>
                      <TableCell lang="bn">
                        {row.patient ? (
                          <>
                            {row.patient.name}
                            <Typography variant="caption" color="text.secondary" component="div">
                              <Box component="span" sx={{ fontFamily: 'monospace' }}>{row.patient.patient_code}</Box>
                              {row.patient.age_text ? ` · ${formatBn(row.patient.age_text, locale)}` : ''}
                              {` · ${formatBn(row.patient.mobile_local, locale)}`}
                            </Typography>
                          </>
                        ) : '—'}
                      </TableCell>
                      <TableCell>{row.doctor ? doctorLabel(row.doctor) : '—'}</TableCell>
                      <TableCell>{row.diagnosis ?? '—'}</TableCell>
                      <TableCell align="right">{formatBn(row.items_count, locale)}</TableCell>
                      <TableCell sx={{ whiteSpace: 'nowrap' }}>
                        <Chip size="small" color={STATUS_COLOR[row.status]} variant={row.status === 'issued' ? 'filled' : 'outlined'} label={t(`prescriptions.status.${row.status}`)} />
                        {row.version > 1 ? <Chip size="small" variant="outlined" sx={{ ml: 0.5 }} label={t('prescriptions.show.version', { version: formatBn(row.version, locale) })} /> : null}
                      </TableCell>
                      <TableCell align="right" sx={{ whiteSpace: 'nowrap' }} onClick={(e) => e.stopPropagation()}>
                        <Button size="small" component={RouterLink} href={route('panel.prescription.prescriptions.show', { prescription: row.id })}>
                          {t('prescriptions.index.open')}
                        </Button>
                        {row.status !== 'draft' ? (
                          <>
                            <Tooltip title={t('common.actions.print')}>
                              <IconButton size="small" aria-label={t('common.actions.print')} onClick={() => print(row)}><PrintIcon fontSize="small" /></IconButton>
                            </Tooltip>
                            <Tooltip title={t('prescriptions.index.pdf')}>
                              <IconButton size="small" aria-label={t('prescriptions.index.pdf')} onClick={() => pdf(row)}><PictureAsPdfIcon fontSize="small" /></IconButton>
                            </Tooltip>
                          </>
                        ) : null}
                      </TableCell>
                    </TableRow>
                  );
                })
              )}
            </TableBody>
          </Table>
        </Box>
      </Card>

      {meta.last_page > 1 ? (
        <Stack sx={{ alignItems: 'center' }}>
          <Pagination count={meta.last_page} page={meta.current_page} onChange={(_, page) => visit({}, page)} />
        </Stack>
      ) : null}
    </Stack>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="prescriptions.index.title">{page}</PanelLayout>;
