// Patient quick-search by mobile / name / code with instant history (BRIEF §5.F) — the Patients module's lookup
// online (panel.reception.patients.lookup), the Dexie cache offline.
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import TextField from '@mui/material/TextField';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemText from '@mui/material/ListItemText';
import InputAdornment from '@mui/material/InputAdornment';
import Typography from '@mui/material/Typography';
import SearchIcon from '@mui/icons-material/Search';
import { patientLookup } from '@panel/api/reception';
import { formatBn } from '@shared/format/number';
import { formatDateDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import type { ConnectionMode } from '@shared/connection/store';
import type { DeskPatientLookup } from '@shared/types/models';

export interface QuickSearchHit { publicId: string; name: string; mobile: string; ageText?: string | null; history?: DeskPatientLookup['history'] }

export interface PatientQuickSearchProps {
  mode: ConnectionMode;
  cached(q: string): Promise<Array<{ publicId: string; name: string; mobile: string; ageText?: string | null }>>;
  onPick(hit: QuickSearchHit): void;
  autoFocus?: boolean;
}

export function PatientQuickSearch({ mode, cached, onPick, autoFocus }: PatientQuickSearchProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [q, setQ] = useState('');
  const [hits, setHits] = useState<QuickSearchHit[]>([]);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (q.trim().length < 2) { setHits([]); return undefined; }
    const controller = new AbortController();
    const timer = setTimeout(() => {
      setBusy(true);
      const run = mode === 'offline'
        ? cached(q).then((rows) => rows.map((r): QuickSearchHit => ({ publicId: r.publicId, name: r.name, mobile: r.mobile, ageText: r.ageText })))
        : patientLookup(q, controller.signal).then((r) => r.data.map((p): QuickSearchHit => ({ publicId: p.public_id, name: p.name, mobile: p.mobile_local, ageText: p.age_text, history: p.history })));
      run.then(setHits).catch(() => undefined).finally(() => setBusy(false));
    }, 250);
    return () => { clearTimeout(timer); controller.abort(); };
  }, [q, mode, cached]);

  return (
    <div>
      <TextField
        value={q}
        onChange={(e) => setQ(e.target.value)}
        placeholder={t('reception.search.placeholder')}
        size="small"
        fullWidth
        autoFocus={autoFocus}
        slotProps={{ htmlInput: { 'aria-label': t('common.actions.search'), lang: 'bn' }, input: { startAdornment: <InputAdornment position="start"><SearchIcon /></InputAdornment> } }}
      />
      {mode === 'offline' ? <Typography variant="caption" color="text.secondary">{t('reception.search.cached_only')}</Typography> : null}
      {hits.length > 0 ? (
        <List dense>
          {hits.map((h) => (
            <ListItemButton key={h.publicId} onClick={() => onPick(h)}>
              <ListItemText
                primary={<span lang="bn">{h.name}</span>}
                secondary={[formatBn(h.mobile, locale), h.ageText ? formatBn(h.ageText, locale) : null, h.history?.[0] ? t('reception.search.last_visit', { date: formatDateDhaka(h.history[0].date ?? ''), doctor: h.history[0].doctor }) : null].filter(Boolean).join(' · ')}
              />
            </ListItemButton>
          ))}
        </List>
      ) : q.trim().length >= 2 && !busy ? <Typography variant="body2" color="text.secondary" sx={{ p: 1 }}>{t('patients.index.empty')}</Typography> : null}
    </div>
  );
}
