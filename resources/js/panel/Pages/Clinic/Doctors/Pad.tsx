// Clinic/Doctors/Pad — the prescription pad designer (BRIEF §5.A).
//
// Left column: every field of `doctor_pad_settings`. Right column: the sheet at true proportion, re-derived on every
// keystroke from the same geometry the renderer uses. Between them sits the only thing that settles an argument
// about alignment — "print a test page", which opens the real print route with this doctor's live pad row.
//
// Every numeric input is clamped to `limits`, which the controller reads off PadGeometry's own clamps: a value the
// renderer would silently pull back can never be typed here, so the preview can never promise something the paper
// will not deliver.
import { useMemo, useRef, useState, type ChangeEvent, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Divider from '@mui/material/Divider';
import FormControlLabel from '@mui/material/FormControlLabel';
import Grid from '@mui/material/Grid';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import AddIcon from '@mui/icons-material/Add';
import ArrowDownIcon from '@mui/icons-material/ArrowDownward';
import ArrowUpIcon from '@mui/icons-material/ArrowUpward';
import DeleteIcon from '@mui/icons-material/DeleteOutlined';
import PrintIcon from '@mui/icons-material/Print';
import ScheduleIcon from '@mui/icons-material/CalendarMonth';
import TextSnippetIcon from '@mui/icons-material/TextSnippet';
import UploadIcon from '@mui/icons-material/UploadFile';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { PadLetterheadEditor } from '@panel/Components/Clinic/PadLetterheadEditor';
import { PadUnderlayControls } from '@panel/Components/Clinic/PadUnderlayControls';
import { PadPreview } from '@panel/Components/Clinic/PadPreview';
import { blankLine, MAX_LINES } from '@panel/lib/clinic/letterhead';
import { route } from '@shared/routes';
import { useSharedProps } from '@shared/inertia';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { ClinicDoctor, Letterhead, PadLimits, PadSectionKey, PadSettings } from '@shared/types/models';

type Props = PageProps<{
  doctor: ClinicDoctor;
  pad: PadSettings;
  defaults: PadSettings;
  options: {
    paper_sizes: string[];
    orientations: string[];
    token_slip_templates: string[];
    languages: string[];
    sections: PadSectionKey[];
  };
  limits: PadLimits;
  assets: { logo_url: string | null; signature_url: string | null; sample_url: string | null; sample_kind: 'image' | 'pdf' | null };
  /** `Letterhead::defaults()` — what "Reset to my profile" loads: the doctor's real name, degrees and clinic. */
  letterhead_defaults: Letterhead;
  /** Whether "read text from the sample" can do anything on this tenant, and why not when it cannot. */
  ocr: { available: boolean; reason: string | null };
  /** One-shot: the lines the last read produced, offered per line rather than applied behind the doctor's back. */
  sample_lines: string[];
}>;

export default function Pad({ doctor, pad, defaults, options, limits, assets, letterhead_defaults: letterheadDefaults, ocr, sample_lines: sampleLines }: Props) {
  const { t } = useTranslation();
  const shared = useSharedProps();
  const locale = getLocale();
  const form = useForm<PadSettings>({ ...pad });
  const logoInput = useRef<HTMLInputElement | null>(null);
  const signatureInput = useRef<HTMLInputElement | null>(null);
  const sampleInput = useRef<HTMLInputElement | null>(null);
  const [uploading, setUploading] = useState<'logo' | 'signature' | 'sample' | null>(null);
  // The underlay is a viewing aid, not pad data: it lives in component state and is never saved or printed.
  const [underlayOn, setUnderlayOn] = useState(true);
  const [underlayOpacity, setUnderlayOpacity] = useState(0.4);
  const data = form.data;
  const underlayVisible = underlayOn && assets.sample_url !== null;

  const clampTo = (bound: { min: number; max: number }, value: number, fallback: number): number =>
    Number.isFinite(value) ? Math.min(bound.max, Math.max(bound.min, value)) : fallback;

  const setMargin = (side: keyof PadSettings['margins'], raw: string): void => {
    form.setData('margins', { ...data.margins, [side]: clampTo(limits.margin_mm, Math.round(Number(raw)), defaults.margins[side]) });
  };
  const setLayout = (patch: Partial<PadSettings['layout']>): void => form.setData('layout', { ...data.layout, ...patch });
  const setFlag = (key: keyof PadSettings['layout']['flags'], value: boolean): void =>
    setLayout({ flags: { ...data.layout.flags, [key]: value } });

  const moveSection = (index: number, delta: number): void => {
    const next = [...data.layout.sections];
    const target = index + delta;
    if (target < 0 || target >= next.length) return;
    const moved = next[index];
    const swapped = next[target];
    if (!moved || !swapped) return;
    next[index] = swapped;
    next[target] = moved;
    setLayout({ sections: next });
  };
  const toggleSection = (index: number, visible: boolean): void => {
    const next = [...data.layout.sections];
    const row = next[index];
    if (!row) return;
    next[index] = { ...row, visible };
    setLayout({ sections: next });
  };

  // Turning preprinted mode on turns the letterhead off — exactly what UpdateDoctorPadSettings does server-side,
  // done here too so the preview never shows a state the save would reject.
  const setPreprinted = (value: boolean): void => {
    form.setData((current) => ({ ...current, preprinted_mode: value, letterhead_enabled: value ? false : current.letterhead_enabled }));
  };

  const uploadAsset = (kind: 'logo' | 'signature', event: ChangeEvent<HTMLInputElement>): void => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    setUploading(kind);
    router.post(route('panel.clinic.doctors.pad.asset', { doctor: doctor.public_id }), { kind, file }, {
      forceFormData: true,
      preserveScroll: true,
      onFinish: () => setUploading(null),
    });
  };
  const removeAsset = (kind: 'logo' | 'signature'): void => {
    router.delete(route('panel.clinic.doctors.pad.asset.destroy', { doctor: doctor.public_id }), { data: { kind }, preserveScroll: true });
  };

  const uploadSample = (event: ChangeEvent<HTMLInputElement>): void => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    setUploading('sample');
    router.post(route('panel.clinic.doctors.pad.sample', { doctor: doctor.public_id }), { file }, {
      forceFormData: true,
      preserveScroll: true,
      onFinish: () => setUploading(null),
    });
  };
  const removeSample = (): void => {
    router.delete(route('panel.clinic.doctors.pad.sample.destroy', { doctor: doctor.public_id }), { preserveScroll: true });
  };
  const readSample = (): void => {
    router.post(route('panel.clinic.doctors.pad.sample.read', { doctor: doctor.public_id }), {}, { preserveScroll: true });
  };

  // A prefilled line is APPENDED for the doctor to style, never applied as a design: the OCR read text, not a pad.
  const setLetterhead = (next: Letterhead): void => form.setData('letterhead', next);
  const acceptSampleLine = (text: string): void => {
    if (data.letterhead.header.lines.length >= MAX_LINES) return;
    setLetterhead({ ...data.letterhead, header: { ...data.letterhead.header, lines: [...data.letterhead.header.lines, blankLine({ text })] } });
  };

  const testPrintUrl = route('panel.clinic.doctors.pad.test_print', { doctor: doctor.public_id });
  const dirty = form.isDirty;

  const submit = (): void => {
    form.put(route('panel.clinic.doctors.pad.update', { doctor: doctor.public_id }), { preserveScroll: true });
  };

  const numberField = (label: string, value: number, bound: { min: number; max: number }, onChange: (raw: string) => void, help?: string): ReactNode => (
    <TextField
      label={label}
      type="number"
      size="small"
      fullWidth
      value={value}
      helperText={help ?? t('clinic.pad.range', { min: formatBn(bound.min, locale), max: formatBn(bound.max, locale) })}
      onChange={(e) => onChange(e.target.value)}
      slotProps={{ htmlInput: { min: bound.min, max: bound.max, step: 1, inputMode: 'numeric' } }}
    />
  );

  const sectionLabels = useMemo(
    () => Object.fromEntries(options.sections.map((key) => [key, t(`clinic.pad.section.${key}`)])),
    [options.sections, t],
  );

  return (
    <Grid container spacing={3}>
      <Grid size={{ xs: 12, lg: 6, xl: 5 }}>
        <Stack spacing={2}>
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
            <Typography variant="h6" sx={{ flexGrow: 1 }}>{doctor.name}</Typography>
            <Button component={RouterLink} href={route('panel.clinic.doctors.edit', { doctor: doctor.public_id })} size="small">
              {t('clinic.pad.back_to_doctor')}
            </Button>
            <Button component="a" href={testPrintUrl} target="_blank" rel="noopener" size="small" variant="outlined" startIcon={<PrintIcon />}>
              {t('clinic.pad.test_print')}
            </Button>
          </Stack>

          {dirty ? <Alert severity="info">{t('clinic.pad.unsaved_hint')}</Alert> : null}

          <Card>
            <CardContent>
              <Typography variant="subtitle2" gutterBottom>{t('clinic.pad.group.paper')}</Typography>
              <Grid container spacing={2}>
                <Grid size={{ xs: 6 }}>
                  <TextField select fullWidth size="small" label={t('clinic.pad.paper_size')} value={data.paper_size}
                    onChange={(e) => form.setData('paper_size', e.target.value as PadSettings['paper_size'])}>
                    {options.paper_sizes.map((p) => <MenuItem key={p} value={p}>{p}</MenuItem>)}
                  </TextField>
                </Grid>
                <Grid size={{ xs: 6 }}>
                  <TextField select fullWidth size="small" label={t('clinic.pad.orientation')} value={data.orientation}
                    onChange={(e) => form.setData('orientation', e.target.value as PadSettings['orientation'])}>
                    {options.orientations.map((o) => <MenuItem key={o} value={o}>{t(`clinic.pad.orientation_${o}`)}</MenuItem>)}
                  </TextField>
                </Grid>
                {(['top', 'right', 'bottom', 'left'] as const).map((side) => (
                  <Grid key={side} size={{ xs: 6, sm: 3 }}>
                    {numberField(t(`clinic.pad.margin_${side}`), data.margins[side], limits.margin_mm, (raw) => setMargin(side, raw))}
                  </Grid>
                ))}
              </Grid>
            </CardContent>
          </Card>

          <Card>
            <CardContent>
              <Typography variant="subtitle2" gutterBottom>{t('clinic.pad.group.letterhead')}</Typography>
              <Stack spacing={2}>
                <FormControlLabel
                  control={<Switch checked={data.preprinted_mode} onChange={(e) => setPreprinted(e.target.checked)} />}
                  label={t('clinic.pad.preprinted_mode')}
                />
                <Typography variant="caption" color="text.secondary">{t('clinic.pad.preprinted_help')}</Typography>
                <Tooltip title={data.preprinted_mode ? t('clinic.pad.letterhead_disabled') : ''}>
                  <FormControlLabel
                    control={<Switch checked={data.letterhead_enabled} disabled={data.preprinted_mode} onChange={(e) => form.setData('letterhead_enabled', e.target.checked)} />}
                    label={t('clinic.pad.letterhead_enabled')}
                  />
                </Tooltip>
                <Grid container spacing={2}>
                  <Grid size={{ xs: 6 }}>
                    {numberField(t('clinic.pad.header_height'), data.header_height_mm, limits.header_height_mm,
                      (raw) => form.setData('header_height_mm', clampTo(limits.header_height_mm, Math.round(Number(raw)), defaults.header_height_mm)),
                      t('clinic.pad.header_height_help'))}
                  </Grid>
                  <Grid size={{ xs: 6 }}>
                    {numberField(t('clinic.pad.footer_height'), data.footer_height_mm, limits.footer_height_mm,
                      (raw) => form.setData('footer_height_mm', clampTo(limits.footer_height_mm, Math.round(Number(raw)), defaults.footer_height_mm)))}
                  </Grid>
                </Grid>

                <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                  <Button size="small" variant="outlined" startIcon={<UploadIcon />} disabled={uploading !== null} onClick={() => logoInput.current?.click()}>
                    {t('clinic.pad.upload_logo')}
                  </Button>
                  {assets.logo_url ? (
                    <>
                      <Box component="img" src={assets.logo_url} alt="" sx={{ height: 32, maxWidth: 96, objectFit: 'contain' }} />
                      <IconButton size="small" aria-label={t('clinic.pad.remove_logo')} onClick={() => removeAsset('logo')}><DeleteIcon fontSize="small" /></IconButton>
                    </>
                  ) : <Typography variant="caption" color="text.secondary">{t('clinic.pad.no_logo')}</Typography>}
                  <input ref={logoInput} type="file" accept="image/png,image/jpeg,image/webp" hidden onChange={(e) => uploadAsset('logo', e)} />
                </Stack>

                <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                  <Button size="small" variant="outlined" startIcon={<UploadIcon />} disabled={uploading !== null} onClick={() => signatureInput.current?.click()}>
                    {t('clinic.pad.upload_signature')}
                  </Button>
                  {assets.signature_url ? (
                    <>
                      <Box component="img" src={assets.signature_url} alt="" sx={{ height: 32, maxWidth: 120, objectFit: 'contain' }} />
                      <IconButton size="small" aria-label={t('clinic.pad.remove_signature')} onClick={() => removeAsset('signature')}><DeleteIcon fontSize="small" /></IconButton>
                    </>
                  ) : <Typography variant="caption" color="text.secondary">{t('clinic.pad.no_signature')}</Typography>}
                  <input ref={signatureInput} type="file" accept="image/png,image/jpeg,image/webp" hidden onChange={(e) => uploadAsset('signature', e)} />
                </Stack>
              </Stack>
            </CardContent>
          </Card>

          <PadLetterheadEditor
            value={data.letterhead}
            disabled={data.preprinted_mode}
            onChange={setLetterhead}
            onReset={() => setLetterhead(letterheadDefaults)}
          />

          {/* The sample pad. The copy here is deliberately blunt about what this is: a photo you trace against,
              not an import. Promising "we will match your design" is a promise this cannot keep. */}
          <Card>
            <CardContent>
              <Typography variant="subtitle2" gutterBottom>{t('clinic.pad.underlay.title')}</Typography>
              <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1.5 }}>{t('clinic.pad.underlay.help')}</Typography>

              <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
                <Button size="small" variant="outlined" startIcon={<UploadIcon />} disabled={uploading !== null} onClick={() => sampleInput.current?.click()}>
                  {assets.sample_url ? t('clinic.pad.underlay.replace') : t('clinic.pad.underlay.upload')}
                </Button>
                {assets.sample_url ? (
                  <IconButton size="small" aria-label={t('clinic.pad.underlay.remove')} onClick={removeSample}><DeleteIcon fontSize="small" /></IconButton>
                ) : <Typography variant="caption" color="text.secondary">{t('clinic.pad.underlay.none')}</Typography>}
                <input ref={sampleInput} type="file" accept="image/png,image/jpeg,image/webp,application/pdf" hidden onChange={uploadSample} />
              </Stack>

              {assets.sample_url ? (
                <PadUnderlayControls
                  shown={underlayOn}
                  opacity={underlayOpacity}
                  isPdf={assets.sample_kind === 'pdf'}
                  onShown={setUnderlayOn}
                  onOpacity={setUnderlayOpacity}
                />
              ) : null}

              <Divider sx={{ my: 2 }} />

              {/* OCR is a seam, not a promise: with no engine configured the button is not rendered at all and
                  the reason is stated, rather than a control that looks alive and does nothing. */}
              <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('clinic.pad.underlay.read_help')}</Typography>
              {ocr.available ? (
                <Button size="small" sx={{ mt: 1 }} startIcon={<TextSnippetIcon />} onClick={readSample}>{t('clinic.pad.underlay.read')}</Button>
              ) : (
                <Alert severity="info" sx={{ mt: 1 }}>{t(`clinic.pad.underlay.read_off_${ocr.reason ?? 'not_configured'}`)}</Alert>
              )}

              {sampleLines.length > 0 ? (
                <Box data-testid="pad-sample-lines" sx={{ mt: 1.5 }}>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>{t('clinic.pad.underlay.prefill')}</Typography>
                  <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: .5 }}>{t('clinic.pad.underlay.prefill_help')}</Typography>
                  <Stack spacing={.5}>
                    {sampleLines.map((line, index) => (
                      <Stack key={`${index}-${line}`} direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                        <Typography variant="body2" sx={{ flexGrow: 1, minWidth: 0, overflowWrap: 'anywhere' }}>{line}</Typography>
                        <Button size="small" startIcon={<AddIcon />} onClick={() => acceptSampleLine(line)}>{t('clinic.pad.underlay.prefill_add')}</Button>
                      </Stack>
                    ))}
                  </Stack>
                </Box>
              ) : null}
            </CardContent>
          </Card>

          {/* Kept only for a pad designed before the letterhead above existed: nothing new writes these, and the
              designer will not grow a rich-text box again. Clearing them hands the sheet back to the letterhead. */}
          {(data.header_html ?? '') !== '' || (data.footer_html ?? '') !== '' ? (
            <Card>
              <CardContent>
                <Typography variant="subtitle2" gutterBottom>{t('clinic.pad.legacy_html')}</Typography>
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mb: 1 }}>{t('clinic.pad.legacy_html_help')}</Typography>
                <Stack spacing={1}>
                  <TextField label={t('clinic.pad.header_html')} multiline minRows={2} size="small" fullWidth value={data.header_html ?? ''}
                    onChange={(e) => form.setData('header_html', e.target.value === '' ? null : e.target.value)}
                    slotProps={{ htmlInput: { lang: 'bn', maxLength: 20000 } }} error={Boolean(form.errors.header_html)} />
                  <TextField label={t('clinic.pad.footer_html')} multiline minRows={2} size="small" fullWidth value={data.footer_html ?? ''}
                    onChange={(e) => form.setData('footer_html', e.target.value === '' ? null : e.target.value)}
                    slotProps={{ htmlInput: { lang: 'bn', maxLength: 20000 } }} error={Boolean(form.errors.footer_html)} />
                  <Box>
                    <Button size="small" onClick={() => form.setData((current) => ({ ...current, header_html: null, footer_html: null }))}>
                      {t('clinic.pad.legacy_html_clear')}
                    </Button>
                  </Box>
                </Stack>
              </CardContent>
            </Card>
          ) : null}

          <Card>
            <CardContent>
              <Typography variant="subtitle2" gutterBottom>{t('clinic.pad.group.typography')}</Typography>
              <Grid container spacing={2}>
                <Grid size={{ xs: 12, sm: 6 }}>
                  <TextField label={t('clinic.pad.font_family')} size="small" fullWidth value={data.font_family}
                    onChange={(e) => form.setData('font_family', e.target.value)} helperText={t('clinic.pad.font_family_help')} />
                </Grid>
                <Grid size={{ xs: 6, sm: 3 }}>
                  {numberField(t('clinic.pad.font_size'), data.font_size_pt, limits.font_size_pt,
                    (raw) => form.setData('font_size_pt', clampTo(limits.font_size_pt, Number(raw), defaults.font_size_pt)))}
                </Grid>
                <Grid size={{ xs: 6, sm: 3 }}>
                  <TextField
                    label={t('clinic.pad.rx_font_size')} type="number" size="small" fullWidth
                    value={data.layout.rx_font_size_pt ?? ''}
                    helperText={t('clinic.pad.rx_font_size_help')}
                    onChange={(e) => setLayout({ rx_font_size_pt: e.target.value === '' ? null : clampTo(limits.rx_font_size_pt, Number(e.target.value), data.font_size_pt + 0.5) })}
                    slotProps={{ htmlInput: { min: limits.rx_font_size_pt.min, max: limits.rx_font_size_pt.max, step: 0.5, inputMode: 'decimal' } }}
                  />
                </Grid>
                <Grid size={{ xs: 6 }}>
                  <TextField select fullWidth size="small" label={t('clinic.pad.columns')} value={String(data.layout.columns)}
                    onChange={(e) => setLayout({ columns: e.target.value === '2' ? 2 : 1 })}>
                    <MenuItem value="1">{t('clinic.pad.columns_one')}</MenuItem>
                    <MenuItem value="2">{t('clinic.pad.columns_two')}</MenuItem>
                  </TextField>
                </Grid>
                <Grid size={{ xs: 6 }}>
                  <TextField select fullWidth size="small" label={t('clinic.pad.default_language')} value={data.default_language}
                    onChange={(e) => form.setData('default_language', e.target.value as PadSettings['default_language'])}>
                    {options.languages.map((l) => <MenuItem key={l} value={l}>{t(`clinic.pad.language_${l}`)}</MenuItem>)}
                  </TextField>
                </Grid>
                <Grid size={{ xs: 12 }}>
                  <TextField select fullWidth size="small" label={t('clinic.pad.token_slip_template')} value={data.token_slip_template}
                    onChange={(e) => form.setData('token_slip_template', e.target.value)}>
                    {options.token_slip_templates.map((tpl) => <MenuItem key={tpl} value={tpl}>{t(`clinic.pad.token_slip_${tpl}`)}</MenuItem>)}
                  </TextField>
                </Grid>
              </Grid>
            </CardContent>
          </Card>

          <Card>
            <CardContent>
              <Typography variant="subtitle2" gutterBottom>{t('clinic.pad.group.layout')}</Typography>
              <Stack spacing={0.5}>
                <FormControlLabel control={<Switch checked={data.show_qr} onChange={(e) => form.setData('show_qr', e.target.checked)} />} label={t('clinic.pad.show_qr')} />
                <FormControlLabel control={<Switch checked={data.show_vitals} onChange={(e) => form.setData('show_vitals', e.target.checked)} />} label={t('clinic.pad.show_vitals')} />
                <FormControlLabel control={<Switch checked={data.show_drug_info_url} onChange={(e) => form.setData('show_drug_info_url', e.target.checked)} />} label={t('clinic.pad.show_drug_info_url')} />
                <Divider sx={{ my: 1 }} />
                <FormControlLabel control={<Switch checked={data.layout.flags.icd_codes} onChange={(e) => setFlag('icd_codes', e.target.checked)} />} label={t('clinic.pad.flag_icd_codes')} />
                <FormControlLabel control={<Switch checked={data.layout.flags.investigation_prices} onChange={(e) => setFlag('investigation_prices', e.target.checked)} />} label={t('clinic.pad.flag_investigation_prices')} />
                <FormControlLabel control={<Switch checked={data.layout.flags.generic_names} onChange={(e) => setFlag('generic_names', e.target.checked)} />} label={t('clinic.pad.flag_generic_names')} />
              </Stack>

              <Divider sx={{ my: 2 }} />
              <Typography variant="subtitle2" gutterBottom>{t('clinic.pad.sections')}</Typography>
              <Stack spacing={0.5}>
                {data.layout.sections.map((section, index) => (
                  <Stack key={section.key} direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                    <Switch size="small" checked={section.visible} slotProps={{ input: { 'aria-label': sectionLabels[section.key] } }} onChange={(e) => toggleSection(index, e.target.checked)} />
                    <Typography variant="body2" sx={{ flexGrow: 1 }}>{sectionLabels[section.key]}</Typography>
                    <IconButton size="small" aria-label={t('clinic.pad.move_up')} disabled={index === 0} onClick={() => moveSection(index, -1)}><ArrowUpIcon fontSize="small" /></IconButton>
                    <IconButton size="small" aria-label={t('clinic.pad.move_down')} disabled={index === data.layout.sections.length - 1} onClick={() => moveSection(index, 1)}><ArrowDownIcon fontSize="small" /></IconButton>
                  </Stack>
                ))}
              </Stack>
            </CardContent>
          </Card>

          <Stack direction="row" spacing={1}>
            <Button variant="contained" onClick={submit} disabled={form.processing}>{t('common.actions.save')}</Button>
            <Button onClick={() => form.setData({ ...pad })} disabled={form.processing || !dirty}>{t('common.actions.cancel')}</Button>
            <Box sx={{ flexGrow: 1 }} />
            <Button component={RouterLink} href={route('panel.scheduling.index', { doctor: doctor.id })} size="small" startIcon={<ScheduleIcon />}>
              {t('clinic.doctors.schedule_link')}
            </Button>
          </Stack>
        </Stack>
      </Grid>

      <Grid size={{ xs: 12, lg: 6, xl: 7 }}>
        <Box sx={{ position: { lg: 'sticky' }, top: { lg: 96 } }}>
          <Typography variant="subtitle2" gutterBottom>{t('clinic.pad.preview.title')}</Typography>
          <PadPreview
            pad={data}
            clinicName={shared.tenant?.name ?? ''}
            doctorName={doctor.name}
            degrees={doctor.profile?.degrees ?? null}
            bmdc={doctor.profile?.bmdc_reg_no ?? null}
            logoUrl={assets.logo_url}
            signatureUrl={assets.signature_url}
            sampleUrl={assets.sample_url}
            sampleKind={assets.sample_kind}
            sampleOpacity={underlayOpacity}
            sampleVisible={underlayVisible}
          />
        </Box>
      </Grid>
    </Grid>
  );
}

Pad.layout = (page: ReactNode) => <PanelLayout title="clinic.pad.title">{page}</PanelLayout>;
