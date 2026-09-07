// Documents tab: upload (photo/PDF → POST panel.patients.documents.store, multipart) and the list with an
// "Open" link (GET panel.patients.documents.show streams the file and audits the download).
import { useRef, useState, type FormEvent } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Table from '@mui/material/Table';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TableCell from '@mui/material/TableCell';
import TableBody from '@mui/material/TableBody';
import Chip from '@mui/material/Chip';
import Button from '@mui/material/Button';
import TextField from '@mui/material/TextField';
import MenuItem from '@mui/material/MenuItem';
import Typography from '@mui/material/Typography';
import Stack from '@mui/material/Stack';
import Box from '@mui/material/Box';
import Link from '@mui/material/Link';
import Alert from '@mui/material/Alert';
import UploadIcon from '@mui/icons-material/UploadFile';
import { route } from '@shared/routes';
import { formatDateDhaka } from '@shared/format/date';
import type { PatientDocument, PatientDocumentType } from '@shared/types/models';
import type { Locale } from '@shared/types/shared-props';
import { DOCUMENT_TYPES, fileSize } from './labels';

export interface DocumentsTabProps {
  patient: string;
  documents: PatientDocument[];
  canUpload: boolean;
}

export function DocumentsTab({ patient, documents, canUpload }: DocumentsTabProps) {
  const { t, i18n } = useTranslation();
  const locale: Locale = i18n.language === 'bn' ? 'bn' : 'en';
  const fileInput = useRef<HTMLInputElement | null>(null);
  const [fileName, setFileName] = useState<string>('');
  const form = useForm<{ file: File | null; type: PatientDocumentType; title: string; document_date: string }>({ file: null, type: 'lab_report', title: '', document_date: '' });

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    form.post(route('panel.patients.documents.store', { patient }), {
      forceFormData: true,
      preserveScroll: true,
      onSuccess: () => {
        form.reset();
        setFileName('');
        if (fileInput.current) fileInput.current.value = '';
      },
    });
  };

  return (
    <Stack spacing={2}>
      {canUpload ? (
        <Box component="form" onSubmit={submit} noValidate sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', md: 'auto 1fr 1fr 1fr auto' }, alignItems: 'start' }}>
          <Button component="label" variant="outlined" size="small" startIcon={<UploadIcon />} sx={{ whiteSpace: 'nowrap' }}>
            {fileName || t('patients.documents.file')}
            <input
              ref={fileInput}
              type="file"
              hidden
              accept="image/jpeg,image/png,image/webp,application/pdf"
              onChange={(e) => {
                const f = e.target.files?.[0] ?? null;
                form.setData('file', f);
                setFileName(f?.name ?? '');
              }}
            />
          </Button>
          <TextField select size="small" label={t('patients.documents.type')} value={form.data.type} onChange={(e) => form.setData('type', e.target.value as PatientDocumentType)} error={Boolean(form.errors.type)} helperText={form.errors.type}>
            {DOCUMENT_TYPES.map((x) => <MenuItem key={x} value={x}>{t(`patients.documents.types.${x}`)}</MenuItem>)}
          </TextField>
          <TextField size="small" label={t('patients.documents.title')} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} error={Boolean(form.errors.title)} helperText={form.errors.title} />
          <TextField size="small" type="date" label={t('patients.documents.date')} value={form.data.document_date} onChange={(e) => form.setData('document_date', e.target.value)} error={Boolean(form.errors.document_date)} helperText={form.errors.document_date} slotProps={{ inputLabel: { shrink: true } }} />
          <Button type="submit" variant="contained" size="small" disabled={form.processing || form.data.file === null} sx={{ mt: 0.25 }}>{t('patients.documents.upload')}</Button>
          {form.errors.file ? <Alert severity="error" sx={{ gridColumn: '1 / -1' }}>{form.errors.file}</Alert> : null}
        </Box>
      ) : null}

      {documents.length === 0 ? (
        <Typography variant="body2" color="text.secondary">{t('patients.documents.empty')}</Typography>
      ) : (
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>{t('patients.documents.title')}</TableCell>
                <TableCell>{t('patients.documents.type')}</TableCell>
                <TableCell>{t('patients.documents.date')}</TableCell>
                <TableCell>{t('patients.documents.uploaded_by')}</TableCell>
                <TableCell />
                <TableCell align="right" />
              </TableRow>
            </TableHead>
            <TableBody>
              {documents.map((d) => (
                <TableRow key={d.id}>
                  <TableCell>
                    {d.title}
                    <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{d.original_filename} · {fileSize(d.size_bytes, locale)}</Typography>
                  </TableCell>
                  <TableCell><Chip size="small" variant="outlined" label={t(`patients.documents.types.${d.type}`)} /></TableCell>
                  <TableCell>{formatDateDhaka(d.document_date ?? d.created_at, locale)}</TableCell>
                  <TableCell>{d.uploaded_by_type}</TableCell>
                  <TableCell><Typography variant="caption" color="text.secondary">{t(`patients.documents.ocr.${d.ocr_status}`)}</Typography></TableCell>
                  <TableCell align="right">
                    <Link href={route('panel.patients.documents.show', { patient, document: d.id })} target="_blank" rel="noopener" variant="body2">{t('patients.documents.open')}</Link>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
      )}
    </Stack>
  );
}
