// Investigations (§4.5), advice (§4.7), follow-up (§4.8) and referral (§4.9). Each is a compact strip in the centre
// pane: one click from the right-hand quick-pick inserts a row, everything stays editable in place.
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Checkbox from '@mui/material/Checkbox';
import Chip from '@mui/material/Chip';
import FormControlLabel from '@mui/material/FormControlLabel';
import IconButton from '@mui/material/IconButton';
import InputBase from '@mui/material/InputBase';
import ListItemButton from '@mui/material/ListItemButton';
import MenuItem from '@mui/material/MenuItem';
import Paper from '@mui/material/Paper';
import Popper from '@mui/material/Popper';
import Select from '@mui/material/Select';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import BookmarkIcon from '@mui/icons-material/BookmarkAdd';
import CloseIcon from '@mui/icons-material/Close';
import PriorityIcon from '@mui/icons-material/PriorityHigh';
import { formatBdt } from '@shared/format/money';
import { ulid } from '@shared/ulid';
import type { AdviceLineRow, ExternalCentreBrief, InvestigationLineRow, ReferralRow } from '@shared/types/models';
import { saveSnippet, searchDoctors, type DoctorHit } from '@panel/api/prescription';
import { useDebouncedSearch } from '@panel/hooks/prescription/useDebouncedSearch';
import { DictationButton } from './DictationButton';

function SectionShell({ label, children, containerRef }: { label: string; children: React.ReactNode; containerRef?: React.RefObject<HTMLDivElement | null> }) {
  return (
    <Paper variant="outlined" sx={{ px: 1, py: 0.5 }} ref={containerRef} tabIndex={-1}>
      <Box sx={{ display: 'flex', alignItems: 'flex-start', gap: 0.5 }}>
        <Typography variant="caption" sx={{ fontWeight: 700, minWidth: 92, pt: 0.5, color: 'text.secondary', flexShrink: 0 }}>
          {label}
        </Typography>
        <Box sx={{ flexGrow: 1, minWidth: 0 }}>{children}</Box>
      </Box>
    </Paper>
  );
}

// ---- investigations ------------------------------------------------------------------------------------------

export interface InvestigationsSectionProps {
  rows: InvestigationLineRow[];
  centres: ExternalCentreBrief[];
  onChange: (rows: InvestigationLineRow[]) => void;
  containerRef?: React.RefObject<HTMLDivElement | null>;
  inputRef?: React.RefObject<HTMLInputElement | null>;
}

export function InvestigationsSection({ rows, centres, onChange, containerRef, inputRef }: InvestigationsSectionProps) {
  const { t } = useTranslation();
  const [text, setText] = useState('');
  const total = rows.reduce((sum, r) => sum + (r.price_paisa ?? 0), 0);

  const addAdhoc = (): void => {
    if (text.trim() === '') return;
    onChange([...rows, { key: ulid(), id: 0, sort_order: rows.length, investigation_catalog_id: null, name: text.trim(), name_bn: null, price_paisa: null, external_diagnostic_centre_id: null, referral_note: null, is_urgent: false }]);
    setText('');
  };

  const patch = (key: string, next: Partial<InvestigationLineRow>): void => onChange(rows.map((r) => (r.key === key ? { ...r, ...next } : r)));

  return (
    <SectionShell label={t('prescriptions.writer.zones.investigations')} containerRef={containerRef}>
      <Box sx={{ display: 'flex', gap: 0.5, flexWrap: 'wrap', alignItems: 'center' }}>
        {rows.map((row) => (
          <Chip
            key={row.key}
            size="small"
            sx={{ height: 22 }}
            color={row.is_urgent ? 'error' : 'default'}
            icon={row.is_urgent ? <PriorityIcon sx={{ fontSize: 14 }} /> : undefined}
            label={`${row.name}${row.price_paisa != null ? ` · ${formatBdt(row.price_paisa)}` : ''}`}
            onClick={() => patch(row.key, { is_urgent: !row.is_urgent })}
            onDelete={() => onChange(rows.filter((r) => r.key !== row.key))}
          />
        ))}
        <InputBase
          inputRef={inputRef}
          value={text}
          onChange={(e) => setText(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              addAdhoc();
            }
          }}
          onBlur={addAdhoc}
          placeholder={rows.length === 0 ? t('prescriptions.investigations.placeholder') : ''}
          inputProps={{ 'aria-label': t('prescriptions.writer.zones.investigations'), autoComplete: 'off' }}
          sx={{ flexGrow: 1, minWidth: 140, fontSize: 14 }}
        />
        {total > 0 ? (
          <Typography variant="caption" sx={{ fontWeight: 700 }}>
            {t('prescriptions.investigations.total', { amount: formatBdt(total) })}
          </Typography>
        ) : null}
      </Box>
      {rows.length > 0 && centres.length > 0 ? (
        <Box sx={{ display: 'flex', gap: 1, alignItems: 'center', mt: 0.25 }}>
          <Typography variant="caption" color="text.secondary">
            {t('prescriptions.investigations.centre')}
          </Typography>
          <Select
            size="small"
            variant="standard"
            displayEmpty
            value={String(rows[0]?.external_diagnostic_centre_id ?? '')}
            onChange={(e) => onChange(rows.map((r) => ({ ...r, external_diagnostic_centre_id: e.target.value === '' ? null : Number(e.target.value) })))}
            sx={{ fontSize: 13, minWidth: 160 }}
            inputProps={{ 'aria-label': t('prescriptions.investigations.centre') }}
          >
            <MenuItem value="">{t('common.status.none')}</MenuItem>
            {centres.map((c) => (
              <MenuItem key={c.id} value={String(c.id)}>
                {c.name}
              </MenuItem>
            ))}
          </Select>
        </Box>
      ) : null}
    </SectionShell>
  );
}

// ---- advice ---------------------------------------------------------------------------------------------------

export interface AdviceSectionProps {
  rows: AdviceLineRow[];
  dictationLang: string;
  onChange: (rows: AdviceLineRow[]) => void;
  onSnippetSaved?: () => void;
  containerRef?: React.RefObject<HTMLDivElement | null>;
  inputRef?: React.RefObject<HTMLInputElement | null>;
}

export function AdviceSection({ rows, dictationLang, onChange, onSnippetSaved, containerRef, inputRef }: AdviceSectionProps) {
  const { t } = useTranslation();
  const [text, setText] = useState('');

  const add = (value: string): void => {
    const trimmed = value.trim();
    if (trimmed === '') return;
    onChange([...rows, { key: ulid(), id: 0, sort_order: rows.length, advice_snippet_id: null, text: trimmed, text_bn: /[ঀ-৿]/.test(trimmed) ? trimmed : null }]);
    setText('');
  };

  return (
    <SectionShell label={t('prescriptions.writer.zones.advice')} containerRef={containerRef}>
      <Box sx={{ display: 'flex', gap: 0.5, flexWrap: 'wrap', alignItems: 'center' }}>
        {rows.map((row) => (
          <Chip
            key={row.key}
            size="small"
            sx={{ height: 22 }}
            label={row.text_bn ?? row.text}
            onDelete={() => onChange(rows.filter((r) => r.key !== row.key))}
            deleteIcon={<CloseIcon />}
          />
        ))}
        <InputBase
          inputRef={inputRef}
          value={text}
          onChange={(e) => setText(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault();
              add(text);
            }
          }}
          onBlur={() => add(text)}
          placeholder={rows.length === 0 ? t('prescriptions.advice.placeholder') : ''}
          inputProps={{ 'aria-label': t('prescriptions.writer.zones.advice'), autoComplete: 'off' }}
          sx={{ flexGrow: 1, minWidth: 160, fontSize: 14 }}
        />
        <DictationButton lang={dictationLang} onText={add} />
        {rows.length > 0 ? (
          <Tooltip title={t('prescriptions.advice.save_snippet')}>
            <IconButton
              size="small"
              aria-label={t('prescriptions.advice.save_snippet')}
              onClick={() => {
                const last = rows[rows.length - 1];
                if (last === undefined) return;
                void saveSnippet({ text: last.text, text_bn: last.text_bn }).then(() => onSnippetSaved?.());
              }}
            >
              <BookmarkIcon fontSize="inherit" />
            </IconButton>
          </Tooltip>
        ) : null}
      </Box>
    </SectionShell>
  );
}

// ---- follow-up ------------------------------------------------------------------------------------------------

export const FOLLOW_UP_PRESETS = [3, 7, 15, 30];

export interface FollowUpSectionProps {
  on: string | null;
  days: number | null;
  note: string | null;
  createBooking: boolean;
  onChange: (next: { on?: string | null; days?: number | null; note?: string | null; create_booking?: boolean }) => void;
  containerRef?: React.RefObject<HTMLDivElement | null>;
}

export function FollowUpSection({ on, days, note, createBooking, onChange, containerRef }: FollowUpSectionProps) {
  const { t } = useTranslation();

  const pick = (value: number): void => {
    const date = new Date();
    date.setDate(date.getDate() + value);
    onChange({ days: value, on: date.toISOString().slice(0, 10) });
  };

  return (
    <SectionShell label={t('prescriptions.writer.zones.follow_up')} containerRef={containerRef}>
      <Box sx={{ display: 'flex', gap: 0.5, alignItems: 'center', flexWrap: 'wrap' }}>
        {FOLLOW_UP_PRESETS.map((value) => (
          <Chip key={value} size="small" sx={{ height: 22 }} color={days === value ? 'primary' : 'default'} label={t('prescriptions.follow_up.days', { count: value })} onClick={() => pick(value)} />
        ))}
        <TextField
          type="date"
          size="small"
          variant="standard"
          value={on ?? ''}
          onChange={(e) => onChange({ on: e.target.value === '' ? null : e.target.value, days: null })}
          sx={{ width: 150 }}
          slotProps={{ inputLabel: { shrink: true }, htmlInput: { 'aria-label': t('prescriptions.follow_up.date') } }}
        />
        <InputBase value={note ?? ''} onChange={(e) => onChange({ note: e.target.value === '' ? null : e.target.value })} placeholder={t('prescriptions.follow_up.note')} inputProps={{ 'aria-label': t('prescriptions.follow_up.note') }} sx={{ flexGrow: 1, minWidth: 120, fontSize: 14 }} />
        <FormControlLabel
          control={<Checkbox size="small" checked={createBooking} onChange={(e) => onChange({ create_booking: e.target.checked })} />}
          label={<Typography variant="caption">{t('prescriptions.follow_up.create_booking')}</Typography>}
          sx={{ mr: 0 }}
        />
      </Box>
    </SectionShell>
  );
}

// ---- referral -------------------------------------------------------------------------------------------------

export interface ReferralSectionProps {
  rows: ReferralRow[];
  centres: ExternalCentreBrief[];
  onChange: (rows: ReferralRow[]) => void;
  containerRef?: React.RefObject<HTMLDivElement | null>;
}

export function ReferralSection({ rows, centres, onChange, containerRef }: ReferralSectionProps) {
  const { t } = useTranslation();
  const [text, setText] = useState('');
  const anchor = useState<HTMLDivElement | null>(null)[0];
  const search = useDebouncedSearch<DoctorHit>(async (q, signal) => searchDoctors(q, signal));

  const addDoctor = (hit: DoctorHit): void => {
    onChange([...rows, { key: ulid(), id: 0, type: 'doctor', referred_to_doctor_id: hit.id, external_diagnostic_centre_id: null, referred_to_name: hit.name, referred_to_specialty: hit.specialty, note: null, is_urgent: false }]);
    setText('');
    search.reset();
  };

  const addFreeText = (): void => {
    if (text.trim() === '') return;
    onChange([...rows, { key: ulid(), id: 0, type: 'hospital', referred_to_doctor_id: null, external_diagnostic_centre_id: null, referred_to_name: text.trim(), referred_to_specialty: null, note: null, is_urgent: false }]);
    setText('');
  };

  return (
    <SectionShell label={t('prescriptions.writer.zones.referral')} containerRef={containerRef}>
      <Box sx={{ display: 'flex', gap: 0.5, flexWrap: 'wrap', alignItems: 'center' }}>
        {rows.map((row) => (
          <Chip key={row.key} size="small" sx={{ height: 22 }} label={`${row.referred_to_name}${row.referred_to_specialty ? ` (${row.referred_to_specialty})` : ''}`} onDelete={() => onChange(rows.filter((r) => r.key !== row.key))} />
        ))}
        <Box sx={{ position: 'relative', flexGrow: 1, minWidth: 160 }}>
          <InputBase
            value={text}
            onChange={(e) => {
              setText(e.target.value);
              search.setQuery(e.target.value);
            }}
            onKeyDown={(e) => {
              if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                if (search.results.length === 0) return;
                e.preventDefault();
                search.moveHighlight(e.key === 'ArrowDown' ? 1 : -1);
              }
              if (e.key === 'Enter') {
                e.preventDefault();
                const hit = search.results[search.highlight];
                if (hit !== undefined) addDoctor(hit);
                else addFreeText();
              }
            }}
            placeholder={rows.length === 0 ? t('prescriptions.referral.placeholder') : ''}
            inputProps={{ 'aria-label': t('prescriptions.writer.zones.referral'), autoComplete: 'off' }}
            sx={{ width: '100%', fontSize: 14 }}
          />
          <Popper open={search.results.length > 0} anchorEl={anchor} placement="bottom-start" style={{ zIndex: 1300 }}>
            <Paper elevation={8} sx={{ width: 320, maxHeight: 260, overflowY: 'auto' }}>
              {search.results.map((hit, index) => (
                <ListItemButton key={hit.id} dense selected={index === search.highlight} onMouseDown={(e) => e.preventDefault()} onClick={() => addDoctor(hit)}>
                  <Typography variant="body2">
                    {hit.name}
                    {hit.specialty ? ` · ${hit.specialty}` : ''}
                  </Typography>
                </ListItemButton>
              ))}
            </Paper>
          </Popper>
        </Box>
        {centres.length > 0 ? (
          <Button
            size="small"
            onClick={() => {
              const centre = centres[0];
              if (centre === undefined) return;
              onChange([...rows, { key: ulid(), id: 0, type: 'diagnostic_centre', referred_to_doctor_id: null, external_diagnostic_centre_id: centre.id, referred_to_name: centre.name, referred_to_specialty: null, note: null, is_urgent: false }]);
            }}
          >
            {t('prescriptions.referral.add_centre')}
          </Button>
        ) : null}
      </Box>
    </SectionShell>
  );
}
