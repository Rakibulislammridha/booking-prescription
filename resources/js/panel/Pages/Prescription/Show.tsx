// Prescription/Show — the ISSUED view (§6). It renders the frozen `snapshot` and nothing else: no catalog read, no
// child rows, no live joins (invariants I3/I6), which is exactly why it can still print identically in ten years.
// The page is visibly immutable — a lock banner, no editable control anywhere — and the only ways forward are
// Amend (creates version+1 and reopens the writer) and Void. A draft opened here is sent back to the writer.
import { useMemo, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Divider from '@mui/material/Divider';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import Select from '@mui/material/Select';
import Snackbar from '@mui/material/Snackbar';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import LockIcon from '@mui/icons-material/Lock';
import LocalPharmacyIcon from '@mui/icons-material/LocalPharmacy';
import SendIcon from '@mui/icons-material/Send';
import FormControlLabel from '@mui/material/FormControlLabel';
import Switch from '@mui/material/Switch';
import ToggleButton from '@mui/material/ToggleButton';
import ToggleButtonGroup from '@mui/material/ToggleButtonGroup';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { PostIssueBar } from '@panel/Components/Prescription/PostIssueBar';
import { amendPrescription, sendPrescription, voidPrescription } from '@panel/api/prescription';
import { formatDateDhaka, formatDhaka } from '@shared/format/date';
import { formatBdt } from '@shared/format/money';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { IssuedPrescription, PrescriptionDraft, PrescriptionQueueLink, PrescriptionSnapshot, VisitRow } from '@shared/types/models';

/**
 * `can` is PrescriptionController::abilities() — the policy's own answer for THIS viewer, not a role read off the
 * shared props. The page is reachable by more people than may act on it: `view` is granted by
 * `prescriptions.vitals.record` so the desk can print the doctor's sheet (BRIEF §5.G.4), while Send is refused to
 * anyone DoctorScope restricts and Amend / Void need `prescriptions.write`. Every action below is gated on its
 * own flag, so nothing on this screen is a button that answers 403.
 */
type Can = { write: boolean; send: boolean; amend: boolean; void: boolean };

type Props = PageProps<{ prescription: IssuedPrescription | PrescriptionDraft; visit?: VisitRow; queue?: PrescriptionQueueLink | null; can: Can }>;

function isIssued(rx: IssuedPrescription | PrescriptionDraft): rx is IssuedPrescription {
  return 'snapshot' in rx && rx.status !== 'draft';
}

export default function Show({ prescription, visit, queue, can }: Props) {
  const { t } = useTranslation();

  if (!isIssued(prescription)) {
    return (
      <Paper variant="outlined" sx={{ p: 2 }}>
        <Typography variant="h6">{t('prescriptions.status.draft')}</Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
          {t('prescriptions.show.draft_hint')}
        </Typography>
        {/* The writer is not a desk screen: a compounder or receptionist may legitimately open an unissued sheet
            (they can read it) and there is nowhere for them to go from here — `can.write` is VisitPolicy::write,
            exactly what WriterController authorises. */}
        {visit !== undefined && can.write ? (
          <Button variant="contained" component={RouterLink} href={route('panel.prescription.writer', { visit: visit.id })}>
            {t('prescriptions.writer.title')}
          </Button>
        ) : null}
      </Paper>
    );
  }

  return <IssuedView prescription={prescription} queue={queue ?? null} can={can} />;
}

Show.layout = (page: ReactNode) => <PanelLayout title="prescriptions.show.title">{page}</PanelLayout>;

function IssuedView({ prescription, queue, can }: { prescription: IssuedPrescription; queue: PrescriptionQueueLink | null; can: Can }) {
  const { t } = useTranslation();
  const snapshot = prescription.snapshot as PrescriptionSnapshot;
  const [dialog, setDialog] = useState<'amend' | 'void' | 'send' | null>(null);
  const [reason, setReason] = useState('');
  const [channel, setChannel] = useState<'sms' | 'whatsapp' | 'email'>('sms');
  const [busy, setBusy] = useState(false);
  const [toast, setToast] = useState<string | null>(null);
  // Print options start from the pad the prescription was ISSUED with (pad_snapshot), not the doctor's pad today —
  // the same rule the server applies. They are per-print overrides, so nothing here is persisted.
  const pad = snapshot.pad ?? {};
  const [paper, setPaper] = useState<'A4' | 'A5'>(pad.paper_size === 'A5' ? 'A5' : 'A4');
  const [letterhead, setLetterhead] = useState<boolean>(pad.letterhead_enabled !== false);
  const [preprinted, setPreprinted] = useState<boolean>(pad.preprinted_mode === true);

  const printParams = { prescription: prescription.id, paper, letterhead: letterhead ? 1 : 0, preprinted: preprinted ? 1 : 0 };
  const openPrint = (): void => {
    // Opened synchronously inside the click handler so the pop-up blocker lets it through (§7.6).
    window.open(route('panel.prescription.print', printParams), '_blank', 'noopener');
  };
  const openPharmacy = (): void => {
    window.open(route('panel.prescription.pharmacy', { prescription: prescription.id, paper: 'A5' }), '_blank', 'noopener');
  };
  const downloadPdf = (): void => {
    // ?sync=1 renders it on the spot when the queued job has not landed yet, so the button is never a dead end.
    window.open(route('panel.prescription.pdf', { prescription: prescription.id, sync: 1 }), '_blank', 'noopener');
  };

  const bothLanguages = snapshot.prescription?.language === 'both';
  const lang: 'bn' | 'en' = snapshot.prescription?.language === 'en' ? 'en' : 'bn';
  const investigationsTotal = snapshot.investigations_total_paisa ?? 0;
  const banner = useMemo(() => {
    if (prescription.status === 'voided') return { severity: 'error' as const, text: t('prescriptions.verify.voided') };
    if (!prescription.is_latest && prescription.superseded_by !== null)
      return {
        severity: 'warning' as const,
        text: t('prescriptions.verify.superseded', { version: prescription.superseded_by.version, date: prescription.superseded_by.issued_at !== null ? formatDateDhaka(prescription.superseded_by.issued_at) : '' }),
      };
    return { severity: 'success' as const, text: t('prescriptions.verify.valid') };
  }, [prescription, t]);

  const amend = (): void => {
    setBusy(true);
    void amendPrescription(prescription.id, reason)
      .then((result) => router.visit(result.writer_url))
      .catch(() => setToast(t('common.status.error')))
      .finally(() => setBusy(false));
  };

  const voidIt = (): void => {
    setBusy(true);
    void voidPrescription(prescription.id, reason)
      .then(() => router.reload())
      .catch(() => setToast(t('common.status.error')))
      .finally(() => setBusy(false));
  };

  const send = (): void => {
    setBusy(true);
    void sendPrescription(prescription.id, channel)
      .then(() => {
        setDialog(null);
        setToast(t('prescriptions.send.queued'));
      })
      .catch(() => setToast(t('common.status.error')))
      .finally(() => setBusy(false));
  };

  return (
    <Stack spacing={1} sx={{ maxWidth: 900 }}>
      <Alert severity={banner.severity} icon={<LockIcon fontSize="small" />}>
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
          <Typography variant="body2" sx={{ fontWeight: 700 }}>
            {banner.text}
          </Typography>
          <Chip size="small" label={t('prescriptions.show.version', { version: prescription.version })} />
          <Chip size="small" variant="outlined" label={t(`prescriptions.status.${prescription.status}`)} />
          {prescription.verification_code !== null ? (
            <Tooltip title={t('prescriptions.show.verify_hint')}>
              {/* The verify URL is frozen INTO the snapshot (§6.2) — the public route is not in the panel's Ziggy group. */}
              <Chip size="small" variant="outlined" component="a" clickable href={snapshot.prescription?.verify_url ?? '#'} target="_blank" rel="noreferrer" label={prescription.verification_code} />
            </Tooltip>
          ) : null}
          {prescription.issued_at !== null ? <Chip size="small" variant="outlined" label={formatDhaka(prescription.issued_at)} /> : null}
          <Chip size="small" variant="outlined" color={prescription.pdf_status === 'ready' ? 'success' : 'default'} label={t(`prescriptions.show.pdf_${prescription.pdf_status}`)} />
        </Stack>
        <Typography variant="caption" color="text.secondary" component="div">
          {t('prescriptions.show.immutable')}
        </Typography>
      </Alert>

      {/* The way forward first (§5.G: the doctor's next step after issuing is the next patient, not this page) —
          Print, PDF, Call next patient, Back to today's session — then the rest of the output surface. */}
      <PostIssueBar queue={queue} onPrint={openPrint} onPdf={downloadPdf} />

      <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', alignItems: 'center' }}>
        <Button size="small" startIcon={<LocalPharmacyIcon />} onClick={openPharmacy}>
          {t('prescriptions.show.pharmacy')}
        </Button>
        {/* Print and the pharmacy copy are `view`, which everyone on this screen holds. Sending the sheet to the
            patient is a different act — the clinic speaking in the doctor's name — and Amend / Void rewrite the
            record, so each of the three waits for its own flag. Hidden rather than disabled: the shell's rule for
            an ability a user does not have (PanelLayout's drawer) is that it is absent, not greyed. */}
        {can.send ? (
          <Button size="small" startIcon={<SendIcon />} onClick={() => setDialog('send')}>
            {t('prescriptions.send.action')}
          </Button>
        ) : null}
        <Box sx={{ flexGrow: 1 }} />
        {can.amend ? (
          <Button
            size="small"
            color="warning"
            disabled={!prescription.is_latest || prescription.status === 'voided'}
            onClick={() => {
              setReason('');
              setDialog('amend');
            }}
          >
            {t('prescriptions.show.amend')}
          </Button>
        ) : null}
        {can.void ? (
          <Button
            size="small"
            color="error"
            disabled={prescription.status === 'voided'}
            onClick={() => {
              setReason('');
              setDialog('void');
            }}
          >
            {t('prescriptions.show.void')}
          </Button>
        ) : null}
      </Stack>

      <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
        <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
          {t('prescriptions.show.print_options')}
        </Typography>
        <ToggleButtonGroup
          size="small"
          exclusive
          value={paper}
          onChange={(_, next: 'A4' | 'A5' | null) => next !== null && setPaper(next)}
          aria-label={t('prescriptions.show.paper')}
        >
          <ToggleButton value="A4">A4</ToggleButton>
          <ToggleButton value="A5">A5</ToggleButton>
        </ToggleButtonGroup>
        <FormControlLabel
          control={<Switch size="small" checked={letterhead} onChange={(e) => setLetterhead(e.target.checked)} />}
          label={<Typography variant="caption">{t('prescriptions.show.letterhead')}</Typography>}
        />
        <Tooltip title={t('prescriptions.show.preprinted_hint')}>
          <FormControlLabel
            control={<Switch size="small" checked={preprinted} onChange={(e) => setPreprinted(e.target.checked)} />}
            label={<Typography variant="caption">{t('prescriptions.show.preprinted')}</Typography>}
          />
        </Tooltip>
      </Stack>

      {prescription.versions.length > 1 ? (
        <Paper variant="outlined" sx={{ p: 1 }}>
          <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
            {t('prescriptions.show.versions')}
          </Typography>
          <Stack direction="row" spacing={0.5} sx={{ mt: 0.5, flexWrap: 'wrap', gap: 0.5 }}>
            {prescription.versions.map((version) => (
              <Chip
                key={version.id}
                size="small"
                color={version.id === prescription.id ? 'primary' : 'default'}
                variant={version.id === prescription.id ? 'filled' : 'outlined'}
                component={RouterLink}
                clickable
                href={route('panel.prescription.prescriptions.show', { prescription: version.id })}
                label={`v${version.version} · ${t(`prescriptions.status.${version.status}`)}${version.issued_at !== null ? ` · ${formatDateDhaka(version.issued_at)}` : ''}`}
              />
            ))}
          </Stack>
          {prescription.amend_reason !== null ? (
            <Typography variant="caption" color="text.secondary" component="div" sx={{ mt: 0.5 }}>
              {t('prescriptions.show.amend_reason', { reason: prescription.amend_reason })}
            </Typography>
          ) : null}
        </Paper>
      ) : null}

      <Paper variant="outlined" sx={{ p: 2 }} data-testid="snapshot">
        <Stack direction="row" sx={{ justifyContent: 'space-between', flexWrap: 'wrap' }}>
          <Box>
            <Typography variant="subtitle1" sx={{ fontWeight: 700 }}>
              {snapshot.clinic?.name}
            </Typography>
            <Typography variant="caption" color="text.secondary" component="div">
              {snapshot.clinic?.branch?.name}
              {snapshot.clinic?.branch?.phone ? ` · ${snapshot.clinic.branch.phone}` : ''}
            </Typography>
          </Box>
          <Box sx={{ textAlign: 'right' }}>
            <Typography variant="subtitle2" sx={{ fontWeight: 700 }}>
              {snapshot.doctor?.name}
            </Typography>
            <Typography variant="caption" color="text.secondary" component="div">
              {snapshot.doctor?.degrees}
              {snapshot.doctor?.bmdc_reg_no ? ` · BMDC ${snapshot.doctor.bmdc_reg_no}` : ''}
            </Typography>
          </Box>
        </Stack>

        <Divider sx={{ my: 1 }} />

        <Stack direction="row" spacing={2} sx={{ flexWrap: 'wrap' }}>
          <Typography variant="body2">
            <strong>{snapshot.patient?.name}</strong> · {snapshot.patient?.age_text} · {snapshot.patient?.gender}
          </Typography>
          <Typography variant="body2" color="text.secondary">
            {snapshot.patient?.patient_code}
          </Typography>
          <Typography variant="body2" color="text.secondary">
            {snapshot.visit?.date} {snapshot.visit?.serial ? `· ${snapshot.visit.serial}` : ''}
          </Typography>
        </Stack>

        {(snapshot.allergies ?? []).length > 0 ? (
          <Alert severity="error" sx={{ py: 0, my: 1 }} variant="outlined">
            <Typography variant="caption">{t('prescriptions.history.allergies')}: {(snapshot.allergies ?? []).join(', ')}</Typography>
          </Alert>
        ) : null}

        <SnapshotBlock title="C/C" hidden={(snapshot.visit?.chief_complaints ?? []).length === 0}>
          {(snapshot.visit?.chief_complaints ?? []).map((c, i) => (
            <Typography key={i} variant="body2">
              {c.text}
              {c.duration_label ? ` — ${c.duration_label[lang]}` : ''}
            </Typography>
          ))}
        </SnapshotBlock>

        <SnapshotBlock title="O/E" hidden={!snapshot.visit?.examination_findings}>
          <Typography variant="body2">{snapshot.visit?.examination_findings}</Typography>
        </SnapshotBlock>

        <SnapshotBlock title="Dx" hidden={(snapshot.visit?.diagnoses ?? []).length === 0}>
          <Stack direction="row" spacing={0.5} sx={{ flexWrap: 'wrap', gap: 0.5 }}>
            {(snapshot.visit?.diagnoses ?? []).map((d, i) => (
              <Chip key={i} size="small" label={`${d.title}${d.icd10_code ? ` (${d.icd10_code})` : ''}`} color={d.kind === 'final' ? 'primary' : 'default'} />
            ))}
          </Stack>
        </SnapshotBlock>

        <SnapshotBlock title="Rx" hidden={(snapshot.items ?? []).length === 0}>
          <Stack spacing={0.75}>
            {(snapshot.items ?? []).map((item, index) => (
              <Box key={index}>
                <Typography variant="body2" sx={{ fontWeight: 600 }}>
                  {index + 1}. {item.brand_name ?? item.generic_name} {item.strength ?? ''} {item.form ?? ''}
                  {item.brand_name !== null && item.brand_name !== item.generic_name ? ` · ${item.generic_name}` : ''}
                </Typography>
                <Typography variant="body2" color="text.secondary" sx={{ pl: 2 }}>
                  {item.display?.[lang]?.interpretation ?? item.dose_schedule}
                </Typography>
                {bothLanguages && item.display?.en?.interpretation ? (
                  <Typography variant="caption" color="text.disabled" sx={{ pl: 2 }}>
                    {item.display.en.interpretation}
                  </Typography>
                ) : null}
                {item.instruction_bn ?? item.instruction ? (
                  <Typography variant="caption" sx={{ pl: 2, display: 'block' }}>
                    {item.instruction_bn ?? item.instruction}
                  </Typography>
                ) : null}
              </Box>
            ))}
          </Stack>
        </SnapshotBlock>

        <SnapshotBlock title={t('prescriptions.writer.zones.investigations')} hidden={(snapshot.investigations ?? []).length === 0}>
          {(snapshot.investigations ?? []).map((x, i) => (
            <Typography key={i} variant="body2">
              {i + 1}. {x.name}
              {x.price_paisa !== null ? ` — ${formatBdt(x.price_paisa)}` : ''}
              {x.is_urgent ? ` · ${t('prescriptions.investigations.urgent')}` : ''}
            </Typography>
          ))}
          {investigationsTotal > 0 ? (
            <Typography variant="caption" sx={{ fontWeight: 700 }}>
              {t('prescriptions.investigations.total', { amount: formatBdt(investigationsTotal) })}
            </Typography>
          ) : null}
        </SnapshotBlock>

        <SnapshotBlock title={t('prescriptions.writer.zones.advice')} hidden={(snapshot.advice ?? []).length === 0}>
          {(snapshot.advice ?? []).map((a, i) => (
            <Typography key={i} variant="body2">
              • {a.text_bn ?? a.text}
            </Typography>
          ))}
        </SnapshotBlock>

        <SnapshotBlock title={t('prescriptions.writer.zones.referral')} hidden={(snapshot.referrals ?? []).length === 0}>
          {(snapshot.referrals ?? []).map((r, i) => (
            <Typography key={i} variant="body2">
              {r.to}
              {r.specialty ? ` (${r.specialty})` : ''}
              {r.note ? ` — ${r.note}` : ''}
            </Typography>
          ))}
        </SnapshotBlock>

        <SnapshotBlock title={t('prescriptions.writer.zones.follow_up')} hidden={snapshot.follow_up?.on == null}>
          <Typography variant="body2">
            {snapshot.follow_up?.on} {snapshot.follow_up?.label ? `· ${snapshot.follow_up.label[lang]}` : ''}
          </Typography>
        </SnapshotBlock>

        {snapshot.handwriting_pages?.length || snapshot.drawing_image_path ? (
          <SnapshotBlock title={t('prescriptions.show.attachments')}>
            {/* The images live on the private `uploads` disk; panel.prescription.files serves them to authorised
                staff and audits every view — a chip alone would hide what the doctor actually wrote. */}
            <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', gap: 1 }}>
              {(snapshot.handwriting_pages ?? []).map((path, i) => (
                <Box
                  key={path}
                  component="a"
                  href={route('panel.prescription.files', { prescription: prescription.id, file: path.split('/').pop() ?? '' })}
                  target="_blank"
                  rel="noreferrer"
                  sx={{ display: 'block', textDecoration: 'none' }}
                >
                  <Box
                    component="img"
                    alt={t('prescriptions.handwriting.page', { page: i + 1 })}
                    src={route('panel.prescription.files', { prescription: prescription.id, file: path.split('/').pop() ?? '' })}
                    sx={{ width: 150, height: 110, objectFit: 'cover', border: 1, borderColor: 'divider', borderRadius: 1, bgcolor: 'background.paper' }}
                  />
                  <Typography variant="caption" color="text.secondary">
                    {t('prescriptions.handwriting.page', { page: i + 1 })}
                  </Typography>
                </Box>
              ))}
              {snapshot.drawing_image_path ? (
                <Box
                  component="a"
                  href={route('panel.prescription.files', { prescription: prescription.id, file: 'drawing.png' })}
                  target="_blank"
                  rel="noreferrer"
                  sx={{ display: 'block', textDecoration: 'none' }}
                >
                  <Box
                    component="img"
                    alt={t('prescriptions.drawing.title')}
                    src={route('panel.prescription.files', { prescription: prescription.id, file: 'drawing.png' })}
                    sx={{ width: 150, height: 110, objectFit: 'contain', border: 1, borderColor: 'divider', borderRadius: 1, bgcolor: 'background.paper' }}
                  />
                  <Typography variant="caption" color="text.secondary">
                    {t('prescriptions.drawing.title')}
                  </Typography>
                </Box>
              ) : null}
            </Stack>
          </SnapshotBlock>
        ) : null}

        <Divider sx={{ my: 1 }} />
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
          {snapshot.qr?.svg_data_uri ? <Box component="img" src={snapshot.qr.svg_data_uri} alt={t('prescriptions.show.qr')} sx={{ width: 72, height: 72 }} /> : null}
          <Box>
            <Typography variant="caption" color="text.secondary" component="div">
              {snapshot.prescription?.verify_url}
            </Typography>
            {prescription.snapshot_sha256 !== null ? (
              <Typography variant="caption" color="text.disabled" component="div" sx={{ fontFamily: 'monospace' }}>
                {prescription.snapshot_sha256.slice(0, 16)}…
              </Typography>
            ) : null}
          </Box>
        </Stack>
      </Paper>

      {(snapshot.safety?.overrides ?? []).length > 0 ? (
        <Paper variant="outlined" sx={{ p: 1 }}>
          <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
            {t('prescriptions.alerts.override')}
          </Typography>
          {(snapshot.safety?.overrides ?? []).map((o, i) => (
            <Typography key={i} variant="caption" component="div">
              {o.kind} — {o.reason}
            </Typography>
          ))}
        </Paper>
      ) : null}

      <Dialog open={dialog === 'amend' || dialog === 'void'} onClose={() => setDialog(null)} maxWidth="xs" fullWidth>
        <DialogTitle sx={{ py: 1 }}>{dialog === 'amend' ? t('prescriptions.show.amend') : t('prescriptions.show.void')}</DialogTitle>
        <DialogContent>
          <Typography variant="caption" color="text.secondary">
            {dialog === 'amend' ? t('prescriptions.show.amend_hint') : t('prescriptions.show.void_hint')}
          </Typography>
          <TextField autoFocus fullWidth multiline minRows={2} size="small" sx={{ mt: 1 }} label={t('prescriptions.show.reason')} value={reason} onChange={(e) => setReason(e.target.value)} />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialog(null)}>{t('common.actions.cancel')}</Button>
          <Button variant="contained" color={dialog === 'void' ? 'error' : 'warning'} disabled={busy || reason.trim().length < 5} onClick={dialog === 'amend' ? amend : voidIt}>
            {t('common.actions.confirm')}
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={dialog === 'send'} onClose={() => setDialog(null)} maxWidth="xs" fullWidth>
        <DialogTitle sx={{ py: 1 }}>{t('prescriptions.send.action')}</DialogTitle>
        <DialogContent>
          <Select fullWidth size="small" value={channel} onChange={(e) => setChannel(e.target.value as typeof channel)} sx={{ mt: 1 }}>
            <MenuItem value="sms">SMS</MenuItem>
            <MenuItem value="whatsapp">WhatsApp</MenuItem>
            <MenuItem value="email">Email</MenuItem>
          </Select>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialog(null)}>{t('common.actions.cancel')}</Button>
          <Button variant="contained" disabled={busy} onClick={send}>
            {t('prescriptions.send.action')}
          </Button>
        </DialogActions>
      </Dialog>

      <Snackbar open={toast !== null} autoHideDuration={4000} onClose={() => setToast(null)} message={toast ?? ''} />
    </Stack>
  );
}

function SnapshotBlock({ title, hidden = false, children }: { title: string; hidden?: boolean; children: ReactNode }) {
  if (hidden) return null;
  return (
    <Box sx={{ mt: 1 }}>
      <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
        {title}
      </Typography>
      {children}
    </Box>
  );
}
