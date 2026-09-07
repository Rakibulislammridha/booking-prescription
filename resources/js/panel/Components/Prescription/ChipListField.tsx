// Chief complaints (§4.1) and on-examination findings (§4.3): chip lists, not paragraphs. Enter makes the next
// bullet, Backspace on an empty input edits the previous chip, the trailing duration token of "fever 3d" is split
// off automatically, and every field carries the dictation button.
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import InputBase from '@mui/material/InputBase';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import { complaintDuration, splitComplaint } from '@panel/lib/prescription/display';
import { DictationButton } from './DictationButton';

export interface ChipListFieldProps {
  label: string;
  placeholder: string;
  values: string[];
  /** Complaint mode splits a trailing duration token and shows it on the chip. */
  withDuration?: boolean;
  durations?: Array<string | null>;
  lang: 'bn' | 'en';
  dictationLang: string;
  suggestions?: string[];
  inputRef?: React.RefObject<HTMLInputElement | null>;
  onChange: (values: string[], durations: Array<string | null>) => void;
  onNextZone?: () => void;
}

export function ChipListField({ label, placeholder, values, withDuration = false, durations = [], lang, dictationLang, suggestions = [], inputRef, onChange, onNextZone }: ChipListFieldProps) {
  const { t } = useTranslation();
  const [text, setText] = useState('');
  const internalRef = useRef<HTMLInputElement | null>(null);
  const ref = inputRef ?? internalRef;

  const add = (raw: string): void => {
    const trimmed = raw.trim();
    if (trimmed === '') return;
    if (withDuration) {
      const { text: body, duration } = splitComplaint(trimmed);
      onChange([...values, body], [...durations, duration]);
    } else {
      onChange([...values, trimmed], [...durations, null]);
    }
    setText('');
  };

  const remove = (index: number): void => {
    onChange(values.filter((_, i) => i !== index), durations.filter((_, i) => i !== index));
  };

  const key = (event: React.KeyboardEvent<HTMLInputElement>): void => {
    if (event.key === 'Enter') {
      event.preventDefault();
      add(text);
      return;
    }
    if (event.key === 'Backspace' && text === '' && values.length > 0) {
      event.preventDefault();
      const last = values.length - 1;
      const duration = durations[last];
      setText(withDuration && duration ? `${values[last]} ${duration}` : values[last] ?? '');
      remove(last);
      return;
    }
    if (event.key === 'Tab' && !event.shiftKey && text.trim() !== '') {
      add(text);
      return;
    }
    if (event.key === 'Tab' && !event.shiftKey && onNextZone !== undefined) {
      // let the browser move focus; the parent tracks the zone
    }
  };

  return (
    <Paper variant="outlined" sx={{ px: 1, py: 0.5 }}>
      <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5, flexWrap: 'wrap' }}>
        <Typography variant="caption" sx={{ fontWeight: 700, minWidth: 92, color: 'text.secondary' }}>
          {label}
        </Typography>
        {values.map((value, index) => {
          const duration = durations[index] ?? null;
          const label2 = withDuration && duration ? `${value} — ${complaintDuration(duration)[lang]}` : value;
          return <Chip key={`${value}-${index}`} size="small" label={label2} onDelete={() => remove(index)} sx={{ height: 22 }} />;
        })}
        <InputBase
          inputRef={ref}
          value={text}
          onChange={(e) => setText(e.target.value)}
          onKeyDown={key}
          onBlur={() => add(text)}
          placeholder={values.length === 0 ? placeholder : ''}
          inputProps={{ 'aria-label': label, autoComplete: 'off' }}
          sx={{ flexGrow: 1, minWidth: 140, fontSize: 14 }}
        />
        <DictationButton lang={dictationLang} onText={(spoken) => add(spoken)} />
      </Box>
      {suggestions.length > 0 && text === '' ? (
        <Box sx={{ display: 'flex', gap: 0.5, pl: '92px', flexWrap: 'wrap', pb: 0.25 }}>
          {suggestions.slice(0, 6).map((s) => (
            <Chip key={s} size="small" variant="outlined" sx={{ height: 18, fontSize: 11 }} label={s} onClick={() => add(s)} />
          ))}
        </Box>
      ) : null}
      <Box sx={{ display: 'none' }}>{t('prescriptions.writer.zones.complaints')}</Box>
    </Paper>
  );
}

export default ChipListField;
