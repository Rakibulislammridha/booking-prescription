// The catalogue browser's drawer: everything the catalogue knows about one row, read-only — plus the three edits
// that are safe centrally (active switch, drug information text/slug/publish, ICD-10 aliases). Structural changes
// to molecules, brands and strengths are deliberately absent: those are the versioned import's job.
//
// Every edit is an Inertia PUT returning a redirect + flash; the drawer then re-fetches its JSON so what it shows
// is what the catalogue holds, not what the form last sent.
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import CircularProgress from '@mui/material/CircularProgress';
import Divider from '@mui/material/Divider';
import Drawer from '@mui/material/Drawer';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import CloseIcon from '@mui/icons-material/Close';
import { catalogDetail } from '@panel/api/super';
import type { CatalogDetail, CatalogTab } from '@panel/Components/Super/types';
import { isApiError } from '@shared/http';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';

export interface DetailDrawerProps {
  kind: CatalogTab;
  id: number | null;
  onClose: () => void;
}

const INFO_FIELDS = ['indications', 'indications_bn', 'side_effects', 'side_effects_bn', 'contraindications', 'precautions', 'patient_advice_bn'] as const;

type InfoField = (typeof INFO_FIELDS)[number];

function Field({ label, value, bn }: { label: string; value: string | number | null | undefined; bn?: boolean }) {
  return (
    <Box sx={{ minWidth: 120 }}>
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{label}</Typography>
      <Typography variant="body2" lang={bn ? 'bn' : undefined}>{value === null || value === undefined || value === '' ? '—' : String(value)}</Typography>
    </Box>
  );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
  return (
    <Box>
      <Typography variant="subtitle2" gutterBottom>{title}</Typography>
      {children}
    </Box>
  );
}

function ActiveSwitch({ kind, detail, busy, setBusy }: { kind: CatalogTab; detail: CatalogDetail; busy: boolean; setBusy: (b: boolean) => void }) {
  const { t } = useTranslation();

  const toggle = (next: boolean): void => {
    setBusy(true);
    router.put(route('super.catalog.active', { kind, id: detail.id }), { active: next }, { preserveScroll: true, preserveState: true, onFinish: () => setBusy(false) });
  };

  return (
    <FormControlLabel
      control={<Switch checked={detail.is_active} disabled={busy} onChange={(e) => toggle(e.target.checked)} slotProps={{ input: { 'aria-label': t('super.catalog.drawer.active') } }} />}
      label={detail.is_active ? t('super.catalog.drawer.active') : t('super.catalog.drawer.inactive')}
    />
  );
}

function InformationForm({ detail, busy, setBusy }: { detail: CatalogDetail; busy: boolean; setBusy: (b: boolean) => void }) {
  const { t } = useTranslation();
  const info = detail.information ?? null;
  const [slug, setSlug] = useState(info?.public_slug ?? detail.slug ?? '');
  const [published, setPublished] = useState(info?.published_at !== null && info?.published_at !== undefined);
  const [text, setText] = useState<Record<InfoField, string>>(() => Object.fromEntries(INFO_FIELDS.map((f) => [f, info?.[f] ?? ''])) as Record<InfoField, string>);
  const [error, setError] = useState<string | null>(null);
  const locked = info !== null && info.published_at !== null;

  const submit = (e: FormEvent): void => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    router.put(route('super.catalog.information', { id: detail.id }), { ...text, public_slug: slug, published }, {
      preserveScroll: true,
      preserveState: true,
      onError: (errors) => setError(Object.values(errors)[0] ?? t('super.catalog.drawer.save_failed')),
      onFinish: () => setBusy(false),
    });
  };

  return (
    <Box component="form" onSubmit={submit} noValidate sx={{ display: 'grid', gap: 1.5 }}>
      <Stack direction="row" spacing={2} sx={{ alignItems: 'center', flexWrap: 'wrap' }} useFlexGap>
        <TextField
          size="small"
          label={t('super.catalog.drawer.info.slug')}
          value={slug}
          onChange={(e) => setSlug(e.target.value)}
          disabled={locked}
          helperText={locked ? t('super.catalog.drawer.info.slug_locked') : t('super.catalog.drawer.info.slug_help')}
          sx={{ minWidth: 260 }}
        />
        <FormControlLabel control={<Switch checked={published} onChange={(e) => setPublished(e.target.checked)} />} label={t('super.catalog.drawer.info.published')} />
      </Stack>
      {INFO_FIELDS.map((field) => (
        <TextField
          key={field}
          size="small"
          multiline
          minRows={2}
          label={t(`super.catalog.drawer.info.${field}`)}
          value={text[field]}
          onChange={(e) => setText({ ...text, [field]: e.target.value })}
          slotProps={{ htmlInput: { lang: field.endsWith('_bn') ? 'bn' : undefined, maxLength: 4000 } }}
        />
      ))}
      {error ? <Alert severity="error">{error}</Alert> : null}
      <Box>
        <Button type="submit" variant="contained" size="small" disabled={busy}>{t('super.catalog.drawer.info.save')}</Button>
      </Box>
    </Box>
  );
}

function AliasesForm({ detail, busy, setBusy }: { detail: CatalogDetail; busy: boolean; setBusy: (b: boolean) => void }) {
  const { t } = useTranslation();
  const [aliases, setAliases] = useState((detail.aliases ?? []).join('\n'));
  const [titleBn, setTitleBn] = useState(detail.title_bn ?? '');
  const [error, setError] = useState<string | null>(null);

  const submit = (e: FormEvent): void => {
    e.preventDefault();
    setBusy(true);
    setError(null);
    router.put(route('super.catalog.icd10.aliases', { id: detail.id }), { aliases: aliases.split('\n').map((a) => a.trim()).filter((a) => a !== ''), title_bn: titleBn }, {
      preserveScroll: true,
      preserveState: true,
      onError: (errors) => setError(Object.values(errors)[0] ?? t('super.catalog.drawer.save_failed')),
      onFinish: () => setBusy(false),
    });
  };

  return (
    <Box component="form" onSubmit={submit} noValidate sx={{ display: 'grid', gap: 1.5 }}>
      <TextField size="small" label={t('super.catalog.drawer.icd10.title_bn')} value={titleBn} onChange={(e) => setTitleBn(e.target.value)} slotProps={{ htmlInput: { lang: 'bn', maxLength: 255 } }} />
      <TextField
        size="small"
        multiline
        minRows={3}
        label={t('super.catalog.drawer.icd10.aliases')}
        helperText={t('super.catalog.drawer.icd10.aliases_help')}
        value={aliases}
        onChange={(e) => setAliases(e.target.value)}
      />
      {error ? <Alert severity="error">{error}</Alert> : null}
      <Box>
        <Button type="submit" variant="contained" size="small" disabled={busy}>{t('super.catalog.drawer.icd10.save')}</Button>
      </Box>
    </Box>
  );
}

export function DetailDrawer({ kind, id, onClose }: DetailDrawerProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [detail, setDetail] = useState<CatalogDetail | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [refreshKey, setRefreshKey] = useState(0);

  useEffect(() => {
    if (id === null) { setDetail(null); return; }
    const controller = new AbortController();
    setLoading(true);
    setError(null);
    catalogDetail(route('super.catalog.show', { kind, id }), controller.signal)
      .then((data) => setDetail(data))
      .catch((err: unknown) => { if (!controller.signal.aborted) setError(isApiError(err) && err.message !== '' ? err.message : t('super.catalog.drawer.load_failed')); })
      .finally(() => setLoading(false));
    return () => controller.abort();
  }, [kind, id, refreshKey, t]);

  // A finished Inertia visit (the PUTs above) means the catalogue changed under us: re-read the drawer.
  useEffect(() => router.on('finish', () => { if (id !== null) setRefreshKey((k) => k + 1); }), [id]);

  const n = (v: number | null | undefined): string => (v === null || v === undefined ? '—' : formatNumber(v, locale));
  const title = detail === null ? '' : (detail.name ?? detail.code ?? detail.strength_label ?? (detail.generic_a ? `${detail.generic_a.name ?? ''} + ${detail.generic_b?.name ?? ''}` : ''));

  return (
    <Drawer anchor="right" open={id !== null} onClose={onClose} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 560 }, p: 2 } } }}>
      <Stack direction="row" spacing={1} sx={{ alignItems: 'center', mb: 1 }}>
        <Typography variant="h6" component="h2" sx={{ flexGrow: 1, minWidth: 0 }} noWrap>{title || t('super.catalog.drawer.title')}</Typography>
        {loading ? <CircularProgress size={18} /> : null}
        <IconButton aria-label={t('super.actions.close')} onClick={onClose}><CloseIcon /></IconButton>
      </Stack>

      {error ? <Alert severity="error">{error}</Alert> : null}

      {detail !== null ? (
        <Stack spacing={2} divider={<Divider flexItem />}>
          <Stack direction="row" spacing={1} useFlexGap sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
            <Chip size="small" variant="outlined" label={t(`super.catalog.tabs.${detail.kind}`)} />
            <Chip size="small" color={detail.is_active ? 'success' : 'default'} label={detail.is_active ? t('super.catalog.drawer.active') : t('super.catalog.drawer.inactive')} />
            {detail.needs_review ? <Chip size="small" color="warning" label={t('super.catalog.drawer.needs_review')} /> : null}
            {detail.version ? <Chip size="small" variant="outlined" label={t('super.catalog.drawer.version', { version: detail.version.version })} /> : null}
            <Box sx={{ flexGrow: 1 }} />
            <ActiveSwitch kind={kind} detail={detail} busy={busy} setBusy={setBusy} />
          </Stack>

          {detail.kind === 'generics' ? (
            <>
              <Stack direction="row" spacing={3} useFlexGap sx={{ flexWrap: 'wrap' }}>
                <Field label={t('super.catalog.columns.name_bn')} value={detail.name_bn} bn />
                <Field label={t('super.catalog.columns.slug')} value={detail.slug} />
                <Field label={t('super.catalog.columns.atc')} value={detail.atc_code} />
                <Field label={t('super.catalog.columns.class')} value={detail.therapeutic_class} />
                <Field label={t('super.catalog.columns.aliases')} value={(detail.aliases ?? []).join(', ')} bn />
                <Field label={t('super.catalog.drawer.controlled')} value={detail.is_controlled ? t('super.settings.bool_on') : t('super.settings.bool_off')} />
                <Field label={t('super.catalog.drawer.weight_based')} value={detail.is_pediatric_weight_based ? t('super.settings.bool_on') : t('super.settings.bool_off')} />
              </Stack>
              {detail.components && detail.components.length > 0 ? (
                <Section title={t('super.catalog.drawer.components')}>
                  <Typography variant="body2">{detail.components.map((c) => `${c.name ?? c.generic_id}${c.mg === null ? '' : ` ${c.mg} mg`}`).join(' + ')}</Typography>
                </Section>
              ) : null}
              <Section title={t('super.catalog.drawer.brands', { count: n(detail.brands?.length ?? 0) })}>
                <Table size="small">
                  <TableHead><TableRow><TableCell>{t('super.catalog.columns.brand')}</TableCell><TableCell>{t('super.catalog.columns.manufacturer')}</TableCell><TableCell align="right">{t('super.catalog.columns.strengths')}</TableCell><TableCell>{t('super.catalog.columns.status')}</TableCell></TableRow></TableHead>
                  <TableBody>
                    {(detail.brands ?? []).map((b) => (
                      <TableRow key={b.id}><TableCell>{b.name}</TableCell><TableCell>{b.manufacturer ?? '—'}</TableCell><TableCell align="right">{n(b.strengths_count)}</TableCell><TableCell>{b.is_active ? t('super.catalog.drawer.active') : t('super.catalog.drawer.inactive')}</TableCell></TableRow>
                    ))}
                  </TableBody>
                </Table>
              </Section>
              <Section title={t('super.catalog.drawer.cautions')}>
                <Stack spacing={0.5}>
                  {(detail.pregnancy ?? []).map((p, i) => <Typography key={`p${i}`} variant="body2">{t('super.catalog.drawer.pregnancy', { trimester: p.trimester ?? t('super.catalog.drawer.all_trimesters'), category: p.category, lactation: p.lactation })}{p.notes ? ` — ${p.notes}` : ''}</Typography>)}
                  {(detail.renal ?? []).map((c, i) => <Typography key={`r${i}`} variant="body2">{t('super.catalog.drawer.renal', { level: c.level, egfr: c.egfr_below ?? '—' })} — {c.advice}</Typography>)}
                  {(detail.hepatic ?? []).map((c, i) => <Typography key={`h${i}`} variant="body2">{t('super.catalog.drawer.hepatic', { level: c.level, cls: c.child_pugh_class ?? '—' })} — {c.advice}</Typography>)}
                  {(detail.pregnancy?.length ?? 0) + (detail.renal?.length ?? 0) + (detail.hepatic?.length ?? 0) === 0 ? <Typography variant="body2" color="text.secondary">{t('super.catalog.drawer.none')}</Typography> : null}
                </Stack>
              </Section>
              <Section title={t('super.catalog.drawer.max_doses')}>
                {(detail.max_doses ?? []).length === 0 ? <Typography variant="body2" color="text.secondary">{t('super.catalog.drawer.none')}</Typography> : (
                  <Table size="small">
                    <TableHead><TableRow><TableCell>{t('super.catalog.drawer.population')}</TableCell><TableCell>{t('super.catalog.drawer.route')}</TableCell><TableCell align="right">mg/day</TableCell><TableCell align="right">mg/kg/day</TableCell><TableCell align="right">mg/dose</TableCell><TableCell>{t('super.catalog.drawer.age')}</TableCell></TableRow></TableHead>
                    <TableBody>
                      {(detail.max_doses ?? []).map((d, i) => (
                        <TableRow key={i}><TableCell>{d.population}</TableCell><TableCell>{d.route ?? '—'}</TableCell><TableCell align="right">{n(d.max_mg_per_day)}</TableCell><TableCell align="right">{n(d.max_mg_per_kg_per_day)}</TableCell><TableCell align="right">{n(d.max_mg_per_dose)}</TableCell><TableCell>{d.min_age_months === null && d.max_age_months === null ? '—' : `${n(d.min_age_months)}–${n(d.max_age_months)} ${t('super.catalog.drawer.months')}`}</TableCell></TableRow>
                      ))}
                    </TableBody>
                  </Table>
                )}
              </Section>
              <Section title={t('super.catalog.drawer.interactions', { count: n(detail.interactions?.length ?? 0) })}>
                {(detail.interactions ?? []).length === 0 ? <Typography variant="body2" color="text.secondary">{t('super.catalog.drawer.none')}</Typography> : (
                  <Stack spacing={0.5}>
                    {(detail.interactions ?? []).map((x) => (
                      <Typography key={x.id} variant="body2"><strong>{x.with.name ?? x.with.id}</strong> · {t(`super.catalog.severity.${x.severity}`, { defaultValue: x.severity })} — {x.effect}{x.management ? ` (${x.management})` : ''}</Typography>
                    ))}
                  </Stack>
                )}
              </Section>
              <Section title={t('super.catalog.drawer.allergy_classes')}>
                <Stack direction="row" spacing={0.5} useFlexGap sx={{ flexWrap: 'wrap' }}>
                  {(detail.allergy_classes ?? []).length === 0 ? <Typography variant="body2" color="text.secondary">{t('super.catalog.drawer.none')}</Typography> : (detail.allergy_classes ?? []).map((a) => <Chip key={a.id} size="small" label={a.name} />)}
                </Stack>
              </Section>
              <Section title={t('super.catalog.drawer.information')}>
                <InformationForm key={`${detail.id}:${detail.information?.published_at ?? 'draft'}:${detail.information?.public_slug ?? ''}`} detail={detail} busy={busy} setBusy={setBusy} />
              </Section>
            </>
          ) : null}

          {detail.kind === 'brands' ? (
            <>
              <Stack direction="row" spacing={3} useFlexGap sx={{ flexWrap: 'wrap' }}>
                <Field label={t('super.catalog.columns.generic')} value={detail.generic?.name} />
                <Field label={t('super.catalog.columns.manufacturer')} value={detail.manufacturer} />
                <Field label={t('super.catalog.columns.dar')} value={detail.dar_number} />
                <Field label={t('super.catalog.columns.popularity')} value={detail.popularity} />
                <Field label={t('super.catalog.columns.slug')} value={detail.slug} />
                <Field label={t('super.catalog.columns.aliases')} value={(detail.aliases ?? []).join(', ')} bn />
                <Field label={t('super.catalog.drawer.discontinued_at')} value={detail.discontinued_at ? formatDhaka(detail.discontinued_at, 'D MMM YYYY', locale) : null} />
              </Stack>
              <Section title={t('super.catalog.drawer.strengths', { count: n(detail.strengths?.length ?? 0) })}>
                <Table size="small">
                  <TableHead><TableRow><TableCell>{t('super.catalog.columns.strength')}</TableCell><TableCell>{t('super.catalog.columns.form')}</TableCell><TableCell>{t('super.catalog.columns.route')}</TableCell><TableCell>{t('super.catalog.columns.pack')}</TableCell><TableCell>{t('super.catalog.columns.status')}</TableCell></TableRow></TableHead>
                  <TableBody>
                    {(detail.strengths ?? []).map((s) => (
                      <TableRow key={s.id}><TableCell>{s.strength_label}</TableCell><TableCell>{s.form ?? '—'}</TableCell><TableCell>{s.route ?? '—'}</TableCell><TableCell>{s.pack_size ?? '—'}</TableCell><TableCell>{s.is_active ? t('super.catalog.drawer.active') : t('super.catalog.drawer.inactive')}</TableCell></TableRow>
                    ))}
                  </TableBody>
                </Table>
              </Section>
            </>
          ) : null}

          {detail.kind === 'strengths' ? (
            <Stack direction="row" spacing={3} useFlexGap sx={{ flexWrap: 'wrap' }}>
              <Field label={t('super.catalog.columns.brand')} value={detail.brand?.name} />
              <Field label={t('super.catalog.columns.generic')} value={detail.generic?.name} />
              <Field label={t('super.catalog.columns.form')} value={detail.form?.name} />
              <Field label={t('super.catalog.columns.route')} value={detail.route?.name} />
              <Field label={t('super.catalog.columns.strength')} value={detail.strength_label} />
              <Field label="mg" value={detail.strength_mg} />
              <Field label="mg/ml" value={detail.per_ml} />
              <Field label={t('super.catalog.columns.pack')} value={detail.pack_size} />
              <Field label={t('super.catalog.columns.price')} value={detail.unit_price_paisa === null || detail.unit_price_paisa === undefined ? null : detail.unit_price_paisa / 100} />
            </Stack>
          ) : null}

          {detail.kind === 'icd10' ? (
            <>
              <Stack direction="row" spacing={3} useFlexGap sx={{ flexWrap: 'wrap' }}>
                <Field label={t('super.catalog.columns.title')} value={detail.title} />
                <Field label={t('super.catalog.columns.chapter')} value={detail.chapter} />
                <Field label={t('super.catalog.columns.block')} value={detail.block} />
                <Field label={t('super.catalog.drawer.parent')} value={detail.parent ? `${detail.parent.code} ${detail.parent.title}` : null} />
                <Field label={t('super.catalog.columns.billable')} value={detail.is_billable ? t('super.settings.bool_on') : t('super.settings.bool_off')} />
              </Stack>
              {detail.children && detail.children.length > 0 ? (
                <Section title={t('super.catalog.drawer.children')}>
                  <Stack spacing={0.25}>{detail.children.map((c) => <Typography key={c.id} variant="body2">{c.code} — {c.title}</Typography>)}</Stack>
                </Section>
              ) : null}
              <Section title={t('super.catalog.drawer.icd10.edit')}>
                <AliasesForm key={`${detail.id}:${(detail.aliases ?? []).join('|')}:${detail.title_bn ?? ''}`} detail={detail} busy={busy} setBusy={setBusy} />
              </Section>
            </>
          ) : null}

          {detail.kind === 'interactions' ? (
            <Stack direction="row" spacing={3} useFlexGap sx={{ flexWrap: 'wrap' }}>
              <Field label={t('super.catalog.columns.severity')} value={t(`super.catalog.severity.${detail.severity ?? ''}`, { defaultValue: detail.severity ?? '' })} />
              <Field label={t('super.catalog.columns.effect')} value={detail.effect} />
              <Field label={t('super.catalog.drawer.mechanism')} value={detail.mechanism} />
              <Field label={t('super.catalog.drawer.management')} value={detail.management} />
              <Field label={t('super.catalog.drawer.evidence')} value={detail.evidence_level} />
              <Field label={t('super.catalog.drawer.source')} value={detail.source} />
            </Stack>
          ) : null}

          {detail.kind === 'allergy_classes' ? (
            <>
              <Stack direction="row" spacing={3} useFlexGap sx={{ flexWrap: 'wrap' }}>
                <Field label={t('super.catalog.columns.slug')} value={detail.slug} />
                <Field label={t('super.catalog.drawer.description')} value={detail.description} />
                <Field label={t('super.catalog.drawer.cross_reacts')} value={(detail.cross_reacts_with ?? []).map((c) => `${c.name ?? c.allergy_class_id}${c.probability_pct === null ? '' : ` (${c.probability_pct}%)`}`).join(', ')} />
              </Stack>
              <Section title={t('super.catalog.drawer.members', { count: n(detail.members?.length ?? 0) })}>
                <Stack direction="row" spacing={0.5} useFlexGap sx={{ flexWrap: 'wrap' }}>
                  {(detail.members ?? []).map((m) => <Chip key={m.id} size="small" variant={m.is_active ? 'filled' : 'outlined'} label={m.name} />)}
                </Stack>
              </Section>
            </>
          ) : null}
        </Stack>
      ) : null}
    </Drawer>
  );
}
