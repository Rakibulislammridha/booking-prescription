// Diagnosis chips (§4.4): ICD-10 in plain language — the index carries colloquial aliases in both scripts, so
// "sugar" finds E11.9. Free text with no code is allowed and prints as typed. Each chip toggles provisional/final.
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import InputBase from '@mui/material/InputBase';
import ListItemButton from '@mui/material/ListItemButton';
import Paper from '@mui/material/Paper';
import Popper from '@mui/material/Popper';
import Typography from '@mui/material/Typography';
import { ulid } from '@shared/ulid';
import type { Diagnosis, Icd10SearchHit } from '@shared/types/models';
import { searchIcd } from '@panel/api/prescription';
import { useDebouncedSearch } from '@panel/hooks/prescription/useDebouncedSearch';

export interface DiagnosisFieldProps {
  label: string;
  values: Diagnosis[];
  lang: 'bn' | 'en';
  inputRef?: React.RefObject<HTMLInputElement | null>;
  onChange: (values: Diagnosis[]) => void;
}

export function DiagnosisField({ label, values, lang, inputRef, onChange }: DiagnosisFieldProps) {
  const { t } = useTranslation();
  const internalRef = useRef<HTMLInputElement | null>(null);
  const ref = inputRef ?? internalRef;
  const anchor = useRef<HTMLDivElement | null>(null);
  const [text, setText] = useState('');
  const search = useDebouncedSearch<Icd10SearchHit>(async (q, signal) => (await searchIcd(q, 10, signal)).hits);

  useEffect(() => {
    search.setQuery(text);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [text]);

  const push = (diagnosis: Omit<Diagnosis, 'sort' | 'key'>): void => {
    onChange([...values, { key: ulid(), sort: values.length, ...diagnosis }]);
    setText('');
    search.reset();
  };

  const key = (event: React.KeyboardEvent<HTMLInputElement>): void => {
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      if (search.results.length === 0) return;
      event.preventDefault();
      search.moveHighlight(event.key === 'ArrowDown' ? 1 : -1);
      return;
    }
    if (event.key === 'Enter') {
      event.preventDefault();
      const hit = search.results[search.highlight];
      if (hit !== undefined) push({ icd10_code: hit.code, title: hit.title, kind: 'provisional' });
      else if (text.trim() !== '') push({ icd10_code: null, title: text.trim(), kind: 'provisional' });
      return;
    }
    if (event.key === 'Escape' && search.results.length > 0) {
      event.preventDefault();
      search.reset();
      setText(text);
      return;
    }
    if (event.key === 'Backspace' && text === '' && values.length > 0) {
      event.preventDefault();
      onChange(values.slice(0, -1));
    }
  };

  const toggleKind = (index: number): void => {
    onChange(values.map((d, i) => (i === index ? { ...d, kind: d.kind === 'final' ? 'provisional' : 'final' } : d)));
  };

  return (
    <Paper variant="outlined" sx={{ px: 1, py: 0.5 }} ref={anchor}>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5, flexWrap: 'wrap' }}>
        <Typography variant="caption" sx={{ fontWeight: 700, minWidth: 92, color: 'text.secondary' }}>
          {label}
        </Typography>
        {values.map((d, index) => (
          <Chip
            key={d.key ?? `${d.title}-${index}`}
            size="small"
            sx={{ height: 22 }}
            color={d.kind === 'final' ? 'primary' : 'default'}
            label={
              <Box component="span" sx={{ display: 'inline-flex', alignItems: 'center', gap: 0.5 }}>
                {d.title}
                {d.icd10_code !== null ? <Box component="span" sx={{ opacity: 0.7, fontFamily: 'monospace' }}>{d.icd10_code}</Box> : null}
                <Box component="span" sx={{ fontSize: 10, textTransform: 'uppercase' }}>{t(`prescriptions.dx.${d.kind}`)}</Box>
              </Box>
            }
            onClick={() => toggleKind(index)}
            onDelete={() => onChange(values.filter((_, i) => i !== index))}
          />
        ))}
        <InputBase
          inputRef={ref}
          value={text}
          onChange={(e) => setText(e.target.value)}
          onKeyDown={key}
          placeholder={values.length === 0 ? t('prescriptions.dx.placeholder') : ''}
          inputProps={{ 'aria-label': label, autoComplete: 'off' }}
          sx={{ flexGrow: 1, minWidth: 160, fontSize: 14 }}
        />
      </Box>

      <Popper open={search.results.length > 0} anchorEl={anchor.current} placement="bottom-start" style={{ zIndex: 1300 }}>
        <Paper elevation={8} sx={{ width: 460, maxHeight: 300, overflowY: 'auto' }} role="listbox">
          {search.results.map((hit, index) => (
            <ListItemButton key={hit.code} dense selected={index === search.highlight} role="option" aria-selected={index === search.highlight} onMouseDown={(e) => e.preventDefault()} onClick={() => push({ icd10_code: hit.code, title: hit.title, kind: 'provisional' })}>
              <Box sx={{ minWidth: 0 }}>
                <Typography variant="body2" noWrap>
                  {lang === 'bn' && hit.title_bn ? hit.title_bn : hit.title}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {hit.code}
                  {hit.aliases.length > 0 ? ` · ${hit.aliases.slice(0, 4).join(', ')}` : ''}
                  {hit.usage > 0 ? ` · ${t('prescriptions.rx.used_times', { count: hit.usage })}` : ''}
                </Typography>
              </Box>
            </ListItemButton>
          ))}
        </Paper>
      </Popper>
    </Paper>
  );
}

export default DiagnosisField;
