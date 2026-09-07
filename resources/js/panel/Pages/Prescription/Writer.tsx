// Prescription/Writer — the three-pane writer of PRESCRIPTION.md §1. Everything on screen came from the single
// `GET /panel/visits/{visit}/prescribe` request; only searches, template bodies and favourites-for-a-diagnosis are
// fetched later. The whole routine path is keyboard-only:
//
//   complaints ⏎ ⏎ → Tab findings → Tab dx (type, ⏎) → Tab Rx: `nap` ⏎ `1+0+1 5d af` ⏎ `cet` ⏎ `0+0+1 5d` ⏎
//   → Ctrl+Enter → ⏎  (issued)
//
// Layout: left history 260px, centre ≥ 640px, right quick-pick 280px. Below 1536px the history pane becomes an
// overlay sheet so the centre keeps its width on the 1366×768 laptops clinics actually use; the quick-pick stays
// docked because it is the one-click path.
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import CircularProgress from '@mui/material/CircularProgress';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogTitle from '@mui/material/DialogTitle';
import Drawer from '@mui/material/Drawer';
import IconButton from '@mui/material/IconButton';
import MenuItem from '@mui/material/MenuItem';
import Select from '@mui/material/Select';
import Snackbar from '@mui/material/Snackbar';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import useMediaQuery from '@mui/material/useMediaQuery';
import { useTheme } from '@mui/material/styles';
import BrushIcon from '@mui/icons-material/Brush';
import GestureIcon from '@mui/icons-material/Gesture';
import HistoryIcon from '@mui/icons-material/History';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { AlertsStrip } from '@panel/Components/Prescription/AlertsStrip';
import { Cheatsheet } from '@panel/Components/Prescription/Cheatsheet';
import { ChipListField } from '@panel/Components/Prescription/ChipListField';
import { AdviceSection, FollowUpSection, InvestigationsSection, ReferralSection } from '@panel/Components/Prescription/ClinicalSections';
import { DiagnosisField } from '@panel/Components/Prescription/DiagnosisField';
import { DrawingDialog } from '@panel/Components/Prescription/DrawingDialog';
import { HandwritingPanel } from '@panel/Components/Prescription/HandwritingPanel';
import { HistoryPane } from '@panel/Components/Prescription/HistoryPane';
import { IssueDialog } from '@panel/Components/Prescription/IssueDialog';
import { QuickPickPane } from '@panel/Components/Prescription/QuickPickPane';
import { RxSection } from '@panel/Components/Prescription/RxSection';
import { VitalsCard } from '@panel/Components/Prescription/VitalsCard';
import { WriterStoreProvider, useWriter, useWriterStoreApi } from '@panel/hooks/prescription/useWriterStore';
import { fetchPrescription, saveTemplate } from '@panel/api/prescription';
import { blockingAlerts, issueReadiness, type FocusZone } from '@panel/lib/prescription/store/writerStore';
import { resolveGlobalKey, nextZone } from '@panel/lib/prescription/keyboard';
import { formatTimeDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { ulid } from '@shared/ulid';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { AdviceSnippet, DrawingJson, InvestigationCatalogRow, TemplateBrief, TopDrug, VisitBrief, WriterPageProps } from '@shared/types/models';

type Props = PageProps<WriterPageProps>;

export default function Writer(props: Props) {
  return (
    <WriterStoreProvider props={props}>
      <WriterScreen {...props} />
    </WriterStoreProvider>
  );
}

Writer.layout = (page: ReactNode) => <PanelLayout title="prescriptions.writer.title">{page}</PanelLayout>;

function WriterScreen({ visit, patient, recent_visits, doctor, quick_pick, features }: Props) {
  const { t } = useTranslation();
  const theme = useTheme();
  const locale = getLocale();
  const store = useWriterStoreApi();
  const wide = useMediaQuery(theme.breakpoints.up('xl'));

  const state = useWriter((s) => s);
  const padLang: 'bn' | 'en' = state.language === 'en' ? 'en' : 'bn';
  const dxCodes = useMemo(() => state.diagnoses.map((d) => d.icd10_code).filter((c): c is string => c !== null), [state.diagnoses]);
  const readiness = issueReadiness(state);
  const blocking = blockingAlerts(state);

  const [historyOpen, setHistoryOpen] = useState(false);
  const [cheatsheet, setCheatsheet] = useState(false);
  const [issueOpen, setIssueOpen] = useState(false);
  const [issuing, setIssuing] = useState(false);
  const [drawing, setDrawing] = useState(false);
  const [handwriting, setHandwriting] = useState(false);
  const [focusedAlert, setFocusedAlert] = useState<string | null>(null);
  const [templateOpen, setTemplateOpen] = useState(false);
  const [templateName, setTemplateName] = useState('');
  const [drawingJson, setDrawingJson] = useState<DrawingJson | null>(null);
  const [toast, setToast] = useState<string | null>(null);

  const complaintsRef = useRef<HTMLInputElement | null>(null);
  const findingsRef = useRef<HTMLInputElement | null>(null);
  const diagnosisRef = useRef<HTMLInputElement | null>(null);
  const investigationsRef = useRef<HTMLInputElement | null>(null);
  const adviceRef = useRef<HTMLInputElement | null>(null);
  const followUpRef = useRef<HTMLDivElement | null>(null);
  const referralRef = useRef<HTMLDivElement | null>(null);
  const vitalsRef = useRef<HTMLDivElement | null>(null);
  const rxRef = useRef<HTMLDivElement | null>(null);
  const quickPickRef = useRef<HTMLInputElement | null>(null);
  const issueRef = useRef<HTMLButtonElement | null>(null);
  const insertIntoLine = useRef<(text: string) => void>(() => undefined);

  const focusZone = useCallback(
    (zone: FocusZone) => {
      store.getState().setFocus(zone, zone === 'rx' ? store.getState().focus.itemKey ?? store.getState().items[store.getState().items.length - 1]?.key : undefined);
      switch (zone) {
        case 'complaints':
          complaintsRef.current?.focus();
          break;
        case 'findings':
          findingsRef.current?.focus();
          break;
        case 'diagnosis':
          diagnosisRef.current?.focus();
          break;
        case 'investigations':
          investigationsRef.current?.focus();
          break;
        case 'advice':
          adviceRef.current?.focus();
          break;
        case 'follow_up':
          followUpRef.current?.focus();
          break;
        case 'referral':
          referralRef.current?.focus();
          break;
        case 'vitals':
          vitalsRef.current?.focus();
          break;
        case 'issue':
          issueRef.current?.focus();
          break;
        case 'rx':
          rxRef.current?.focus();
          break;
      }
    },
    [store],
  );

  // Initial focus (§1.3): complaints when empty, otherwise the first empty Rx line.
  useEffect(() => {
    const s = store.getState();
    if (s.complaints.length === 0) {
      complaintsRef.current?.focus();
      return;
    }
    if (s.items.length === 0) s.addItem(undefined, undefined, { focus: false });
    else s.setFocus('rx', s.items[0]?.key);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // Always keep one empty line at the bottom so `Enter` always has somewhere to go.
  useEffect(() => {
    if (state.items.length === 0) store.getState().addItem(undefined, undefined, { focus: false });
  }, [state.items.length, store]);

  useEffect(() => {
    const handler = (event: KeyboardEvent): void => {
      const action = resolveGlobalKey(event, { inRightPane: document.activeElement === quickPickRef.current, dialogOpen: issueOpen || cheatsheet });
      if (action === null) return;
      event.preventDefault();
      switch (action.type) {
        case 'zone':
          focusZone(action.zone);
          break;
        case 'quick_pick':
          quickPickRef.current?.focus();
          break;
        case 'issue_dialog':
          setIssueOpen(true);
          break;
        case 'print_preview':
          window.print();
          break;
        case 'cheatsheet':
          setCheatsheet((c) => !c);
          break;
        case 'handwriting':
          if (features.handwriting) setHandwriting((h) => !h);
          break;
        case 'dictation':
          break;
        case 'back_to_rx':
          setCheatsheet(false);
          setIssueOpen(false);
          focusZone('rx');
          break;
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [cheatsheet, features.handwriting, focusZone, issueOpen]);

  // ---- quick-pick insertions (each is exactly one click) -------------------------------------------------------

  const insertTopDrug = (row: TopDrug): void => {
    const s = store.getState();
    const empty = s.items.find((i) => i.drug === null && i.shorthand === '');
    const key = empty?.key ?? s.addItem();
    // TopDrug carries ids + label, not the presentation metadata the client ParseContext wants: the shorthand is
    // parsed against defaults for a moment and the server echo (≤ 400 ms) replaces it with the authoritative parse.
    s.setDrug(key, {
      kind: row.drug.kind,
      generic_id: row.drug.generic_id ?? 0,
      brand_id: row.drug.brand_id ?? null,
      custom_brand_id: row.drug.custom_brand_id ?? null,
      strength_id: row.drug.strength_id ?? null,
      generic_name: row.label,
      brand_name: null,
      strength: null,
      form: null,
      route: null,
    });
    if (row.default_dose.shorthand) s.setShorthand(key, row.default_dose.shorthand);
    s.setFocus('rx', key);
  };

  const insertInvestigation = (row: InvestigationCatalogRow): void => {
    const s = store.getState();
    if (s.investigations.some((x) => x.investigation_catalog_id === row.id)) return;
    s.setInvestigations([
      ...s.investigations,
      { key: ulid(), id: 0, sort_order: s.investigations.length, investigation_catalog_id: row.id, name: row.name, name_bn: row.name_bn, price_paisa: row.price_paisa, external_diagnostic_centre_id: null, referral_note: null, is_urgent: false },
    ]);
  };

  const insertAdvice = (snippet: AdviceSnippet): void => {
    const s = store.getState();
    s.setAdvice([...s.advice, { key: ulid(), id: 0, sort_order: s.advice.length, advice_snippet_id: snippet.id, text: snippet.text, text_bn: snippet.text_bn }]);
  };

  const applyTemplate = (template: TemplateBrief): void => {
    void store
      .getState()
      .applyTemplate(template.id)
      .then(() => setToast(t('prescriptions.templates.applied', { name: template.name })))
      .catch(() => setToast(t('common.status.error')));
  };

  const copyVisit = (brief: VisitBrief): void => {
    if (brief.prescription_id === null) return;
    void fetchPrescription(brief.prescription_id)
      .then(({ prescription }) => {
        const s = store.getState();
        for (const snap of prescription.snapshot.items ?? []) {
          const key = s.addItem();
          s.setDrug(key, {
            kind: snap.custom_brand_id != null ? 'custom' : snap.strength_id != null ? 'presentation' : 'generic',
            generic_id: snap.generic_id ?? 0,
            brand_id: snap.brand_id ?? null,
            custom_brand_id: snap.custom_brand_id ?? null,
            strength_id: snap.strength_id ?? null,
            generic_name: snap.generic_name,
            brand_name: snap.brand_name,
            strength: snap.strength,
            form: snap.form,
            route: snap.route,
          });
          s.setShorthand(key, String(snap.dose_json && 'normalized' in snap.dose_json ? snap.dose_json.normalized : ''));
        }
        setToast(t('prescriptions.history.copied', { date: brief.date }));
      })
      .catch(() => setToast(t('common.status.error')));
  };

  const doIssue = (print: boolean): void => {
    setIssuing(true);
    void store
      .getState()
      .issue({ print })
      .then((result) => {
        setIssueOpen(false);
        if (print && result.print_url !== null) window.open(result.print_url, '_blank');
        router.visit(route('panel.prescription.prescriptions.show', { prescription: result.prescription.id }), { data: print ? { print: 1 } : {} });
      })
      .catch(() => setToast(t('prescriptions.issue.failed')))
      .finally(() => setIssuing(false));
  };

  const storeTemplate = (): void => {
    void saveTemplate({ name: templateName, from_prescription_id: state.prescriptionId, icd10_code: dxCodes[0] ?? null, diagnosis_title: state.diagnoses[0]?.title ?? null })
      .then(() => {
        setTemplateOpen(false);
        setTemplateName('');
        setToast(t('prescriptions.templates.saved'));
      })
      .catch(() => setToast(t('common.status.error')));
  };

  const saveStatus =
    state.saveError !== null ? (
      <Chip size="small" color="error" sx={{ height: 22 }} label={t('prescriptions.writer.not_saved')} onClick={() => void store.getState().save(true)} />
    ) : state.saving || state.dirty ? (
      <Chip size="small" sx={{ height: 22 }} icon={<CircularProgress size={10} />} label={t('prescriptions.writer.saving')} />
    ) : state.lastSavedAt !== null ? (
      <Chip size="small" variant="outlined" sx={{ height: 22 }} label={t('prescriptions.writer.saved_at', { time: formatTimeDhaka(state.lastSavedAt, locale) })} />
    ) : null;

  const history = <HistoryPane patient={patient} recentVisits={recent_visits} onCopyVisit={copyVisit} />;

  return (
    <Box sx={{ mx: { xs: -2, md: -3 }, mt: { xs: -2, md: -3 }, mb: { xs: -2, md: -3 }, px: 1, py: 1, display: 'flex', gap: 1, height: { xs: 'auto', md: 'calc(100vh - 104px)' }, minHeight: 520, overflow: 'hidden' }}>
      {wide ? <Box sx={{ width: 260, flexShrink: 0, minHeight: 0 }}>{history}</Box> : null}

      <Box sx={{ flexGrow: 1, minWidth: 0, display: 'flex', flexDirection: 'column', minHeight: 0 }}>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, pb: 0.5, flexWrap: 'wrap' }}>
          {!wide ? (
            <Tooltip title={t('prescriptions.history.title')}>
              <IconButton size="small" onClick={() => setHistoryOpen(true)} aria-label={t('prescriptions.history.title')}>
                <HistoryIcon fontSize="inherit" />
              </IconButton>
            </Tooltip>
          ) : null}
          <Typography variant="subtitle2" sx={{ fontWeight: 700 }}>
            {patient.name}
          </Typography>
          <Typography variant="caption" color="text.secondary">
            {[patient.age_text, patient.sex, visit.serial_display ?? visit.type].filter(Boolean).join(' · ')}
          </Typography>
          {patient.allergies.length > 0 ? <Chip size="small" color="error" sx={{ height: 20 }} label={patient.allergies.map((a) => a.allergen_name).join(', ')} /> : null}
          <Box sx={{ flexGrow: 1 }} />
          {saveStatus}
          <Select size="small" variant="standard" value={state.language} onChange={(e) => store.getState().setLanguage(e.target.value as typeof state.language)} sx={{ fontSize: 12 }} inputProps={{ 'aria-label': t('prescriptions.writer.language') }}>
            <MenuItem value="both">{t('prescriptions.language.both')}</MenuItem>
            <MenuItem value="bn">{t('prescriptions.language.bn')}</MenuItem>
            <MenuItem value="en">{t('prescriptions.language.en')}</MenuItem>
          </Select>
          {features.handwriting ? (
            <Tooltip title={t('prescriptions.handwriting.toggle')}>
              <IconButton size="small" color={handwriting ? 'primary' : 'default'} onClick={() => setHandwriting((h) => !h)} aria-label={t('prescriptions.handwriting.toggle')}>
                <GestureIcon fontSize="inherit" />
              </IconButton>
            </Tooltip>
          ) : null}
          <Tooltip title={t('prescriptions.drawing.title')}>
            <IconButton size="small" onClick={() => setDrawing(true)} aria-label={t('prescriptions.drawing.title')}>
              <BrushIcon fontSize="inherit" />
            </IconButton>
          </Tooltip>
        </Box>

        {state.conflict !== null ? (
          <Alert severity="warning" sx={{ mb: 0.5, py: 0 }} action={<Button size="small" onClick={() => router.reload()}>{t('common.actions.retry')}</Button>}>
            <Typography variant="caption">{t('prescriptions.writer.conflict')}</Typography>
          </Alert>
        ) : null}

        {state.templateSkipped.length > 0 ? (
          <Alert severity="info" sx={{ mb: 0.5, py: 0 }} onClose={() => store.setState({ templateSkipped: [] })}>
            <Typography variant="caption">{t('prescriptions.templates.skipped', { names: state.templateSkipped.join(', ') })}</Typography>
          </Alert>
        ) : null}

        <AlertsStrip
          alerts={state.alerts}
          lang={padLang}
          focused={focusedAlert}
          onOverride={(fingerprint, reason) => store.getState().override(fingerprint, reason)}
          onRepick={(keys) => keys.forEach((key) => store.getState().setDrug(key, null))}
          onFocusItem={(key) => store.getState().setFocus('rx', key)}
        />

        <Stack spacing={0.5} sx={{ flexGrow: 1, overflowY: 'auto', minHeight: 0, pr: 0.5 }}>
          <ChipListField
            label={t('prescriptions.writer.zones.complaints')}
            placeholder={t('prescriptions.complaints.placeholder')}
            values={state.complaints.map((c) => c.text)}
            durations={state.complaints.map((c) => c.duration)}
            withDuration
            lang={padLang}
            dictationLang={doctor.prefs.dictation_lang}
            inputRef={complaintsRef}
            onChange={(values, durations) => store.getState().setComplaints(values.map((text, i) => ({ key: state.complaints[i]?.key ?? ulid(), text, text_bn: null, duration: durations[i] ?? null, sort: i })))}
          />

          <VitalsCard
            visitId={visit.id}
            vitals={state.vitals}
            reviewed={state.vitalsReviewed}
            ageYears={patient.age_years}
            containerRef={vitalsRef}
            onChange={(row) => store.getState().setVitals(row)}
            onReviewed={() => store.getState().markVitalsReviewed()}
          />

          <ChipListField
            label={t('prescriptions.writer.zones.findings')}
            placeholder={t('prescriptions.findings.placeholder')}
            values={state.findings === '' ? [] : state.findings.split('; ')}
            lang={padLang}
            dictationLang={doctor.prefs.dictation_lang}
            inputRef={findingsRef}
            onChange={(values) => store.getState().setFindings(values.join('; '))}
          />

          <DiagnosisField label={t('prescriptions.writer.zones.diagnosis')} values={state.diagnoses} lang={padLang} inputRef={diagnosisRef} onChange={(values) => store.getState().setDiagnoses(values)} />

          {handwriting ? (
            <HandwritingPanel prescriptionId={state.prescriptionId} paperSize={doctor.pad.paper_size} width={620} onSaved={() => setToast(t('prescriptions.handwriting.saved'))} />
          ) : null}

          <RxSection
            items={state.items}
            alerts={state.alerts}
            dxCodes={dxCodes}
            lang={padLang}
            focusKey={state.focus.zone === 'rx' ? state.focus.itemKey : undefined}
            containerRef={rxRef}
            onNextZone={() => focusZone(nextZone('rx', 1))}
            onCheatsheet={() => setCheatsheet(true)}
            onSaveTemplate={() => setTemplateOpen(true)}
            onFocusAlert={(fingerprint) => setFocusedAlert(fingerprint)}
            registerInsert={(insert) => {
              insertIntoLine.current = insert;
            }}
          />

          <InvestigationsSection rows={state.investigations} centres={quick_pick.external_centres} inputRef={investigationsRef} onChange={(rows) => store.getState().setInvestigations(rows)} />

          <AdviceSection rows={state.advice} dictationLang={doctor.prefs.dictation_lang} inputRef={adviceRef} onChange={(rows) => store.getState().setAdvice(rows)} />

          <FollowUpSection on={state.followUp.on} days={state.followUp.days} note={state.followUp.note} createBooking={state.followUp.create_booking} containerRef={followUpRef} onChange={(next) => store.getState().setFollowUp(next)} />

          <ReferralSection rows={state.referrals} centres={quick_pick.external_centres} containerRef={referralRef} onChange={(rows) => store.getState().setReferrals(rows)} />
        </Stack>

        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, pt: 0.5, borderTop: 1, borderColor: 'divider' }}>
          <Typography variant="caption" color="text.secondary">
            {t('prescriptions.writer.shortcut_hint')}
          </Typography>
          <Box sx={{ flexGrow: 1 }} />
          {blocking.length > 0 ? (
            <Button color="error" variant="outlined" size="small" onClick={() => setFocusedAlert(blocking[0]?.fingerprint ?? null)}>
              {t('prescriptions.alerts.review_critical', { count: blocking.length })}
            </Button>
          ) : null}
          <Button ref={issueRef} variant="contained" size="small" disabled={!readiness.ready} onClick={() => setIssueOpen(true)} data-testid="issue-button">
            {t('prescriptions.issue.and_print')} · Ctrl+↵
          </Button>
        </Box>
      </Box>

      <Box sx={{ width: { xs: 240, lg: 280 }, flexShrink: 0, display: { xs: 'none', md: 'block' }, minHeight: 0 }}>
        <QuickPickPane
          topDrugs={quick_pick.top_drugs}
          templates={quick_pick.templates}
          snippets={quick_pick.snippets}
          investigations={quick_pick.investigations}
          dxCodes={dxCodes}
          searchRef={quickPickRef}
          onInsertDrug={insertTopDrug}
          onApplyTemplate={applyTemplate}
          onInsertInvestigation={insertInvestigation}
          onInsertAdvice={insertAdvice}
          onEscape={() => focusZone('rx')}
        />
      </Box>

      <Drawer anchor="left" open={historyOpen && !wide} onClose={() => setHistoryOpen(false)} slotProps={{ paper: { sx: { width: 300, p: 1 } } }}>
        {history}
      </Drawer>

      <Cheatsheet open={cheatsheet} lang={padLang} onClose={() => setCheatsheet(false)} onInsert={(example) => insertIntoLine.current(example)} />

      <IssueDialog
        open={issueOpen}
        busy={issuing}
        lang={padLang}
        items={state.items}
        investigationCount={state.investigations.length}
        adviceCount={state.advice.length}
        followUpOn={state.followUp.on}
        alerts={state.alerts}
        acknowledged={state.acknowledged}
        blocking={blocking}
        handwriting={handwriting}
        onAcknowledge={(fingerprint) => store.getState().acknowledge(fingerprint)}
        onClose={() => setIssueOpen(false)}
        onIssue={doIssue}
      />

      <DrawingDialog open={drawing} prescriptionId={state.prescriptionId} templates={features.drawing_backgrounds} initial={drawingJson} onClose={() => setDrawing(false)} onSaved={setDrawingJson} />

      <Dialog open={templateOpen} onClose={() => setTemplateOpen(false)} maxWidth="xs" fullWidth>
        <DialogTitle sx={{ py: 1 }}>{t('prescriptions.templates.save_as')}</DialogTitle>
        <DialogContent>
          <TextField autoFocus fullWidth size="small" label={t('prescriptions.templates.name')} value={templateName} onChange={(e) => setTemplateName(e.target.value)} sx={{ mt: 1 }} />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setTemplateOpen(false)}>{t('common.actions.cancel')}</Button>
          <Button variant="contained" disabled={templateName.trim() === ''} onClick={storeTemplate}>
            {t('common.actions.save')}
          </Button>
        </DialogActions>
      </Dialog>

      <Snackbar open={toast !== null} autoHideDuration={4000} onClose={() => setToast(null)} message={toast ?? ''} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }} />
    </Box>
  );
}
