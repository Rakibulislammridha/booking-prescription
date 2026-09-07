// The discoverable in-app cheat-sheet (§2.14): generated from keywords.json, never stale. F2 / Ctrl+/ / the `?` on
// the Rx header opens it. Every example is tappable — it goes straight into the focused Rx line — and the "try it"
// box shows the live parse, so the grammar is learned by using it. The server copy (GET /panel/help/shorthand) is
// fetched once so a keyword release is picked up without a redeploy; the bundled table is the fallback.
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import Drawer from '@mui/material/Drawer';
import IconButton from '@mui/material/IconButton';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import CloseIcon from '@mui/icons-material/Close';
import type { ParseContext } from '@shared/types/models';
import { fetchShorthandHelp } from '@panel/api/prescription';
import { itemDisplay } from '@panel/lib/prescription/display';
import { KEYWORDS, type KeywordExample, type KeywordTable } from '@panel/lib/prescription/shorthand/keywords';
import { parseLine } from '@panel/lib/prescription/shorthand/parse';

const TRY_CONTEXT: ParseContext = {
  form_code: 'tab',
  default_unit: 'tab',
  pack_size: null,
  pack_unit: null,
  strength_mg: 500,
  per_ml: null,
  is_liquid: false,
  cont_days: 30,
  locale: 'en',
  route_code: 'po',
  strength_label: '500 mg',
  form_label: 'tablet',
};

const COLUMNS: Array<{ key: string; groups: string[] }> = [
  { key: 'schedule', groups: ['schedule'] },
  { key: 'duration', groups: ['duration', 'timing'] },
  { key: 'other', groups: ['route', 'quantity', 'instruction'] },
];

export interface CheatsheetProps {
  open: boolean;
  lang: 'bn' | 'en';
  onClose: () => void;
  onInsert: (example: string) => void;
}

export function Cheatsheet({ open, lang, onClose, onInsert }: CheatsheetProps) {
  const { t } = useTranslation();
  const [table, setTable] = useState<KeywordTable>(KEYWORDS);
  const [attempt, setAttempt] = useState('1+0+1 10d af');

  useEffect(() => {
    if (!open) return;
    let cancelled = false;
    fetchShorthandHelp()
      .then((help) => {
        if (!cancelled && help.keywords) setTable(help.keywords);
      })
      .catch(() => undefined);
    return () => {
      cancelled = true;
    };
  }, [open]);

  const parsed = useMemo(() => parseLine(attempt, TRY_CONTEXT), [attempt]);
  const interpretation = itemDisplay(lang, parsed).interpretation;
  const byGroup = useMemo(() => {
    const map = new Map<string, KeywordExample[]>();
    for (const example of table.examples ?? []) {
      const list = map.get(example.group) ?? [];
      list.push(example);
      map.set(example.group, list);
    }
    return map;
  }, [table]);

  return (
    <Drawer anchor="right" open={open} onClose={onClose} slotProps={{ paper: { sx: { width: { xs: '100%', md: 560 }, p: 2 } } }}>
      <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
        <Typography variant="h6" sx={{ flexGrow: 1 }}>
          {t('prescriptions.cheatsheet.title')}
        </Typography>
        <IconButton onClick={onClose} aria-label={t('common.actions.close')}>
          <CloseIcon />
        </IconButton>
      </Box>

      <TextField
        size="small"
        fullWidth
        label={t('prescriptions.cheatsheet.try')}
        value={attempt}
        onChange={(e) => setAttempt(e.target.value)}
        slotProps={{ htmlInput: { spellCheck: false, style: { fontFamily: 'monospace' }, 'aria-label': t('prescriptions.cheatsheet.try') } }}
      />
      <Box sx={{ my: 1, p: 1, bgcolor: 'action.hover', borderRadius: 1 }}>
        <Typography variant="body2" data-testid="cheatsheet-interpretation">
          {interpretation === '' ? '—' : interpretation}
        </Typography>
        {parsed.issues.map((issue, i) => (
          <Typography key={i} variant="caption" color={issue.severity === 'error' ? 'error' : issue.severity === 'warning' ? 'warning.main' : 'text.secondary'} component="div">
            {lang === 'bn' ? issue.message_bn : issue.message}
          </Typography>
        ))}
      </Box>

      <Divider sx={{ my: 1 }} />

      <Stack spacing={1.5}>
        {COLUMNS.map((column) => (
          <Box key={column.key}>
            <Typography variant="caption" sx={{ fontWeight: 700, color: 'text.secondary' }}>
              {t(`prescriptions.cheatsheet.columns.${column.key}`)}
            </Typography>
            <Stack spacing={0.25} sx={{ mt: 0.5 }}>
              {column.groups.flatMap((group) => byGroup.get(group) ?? []).map((example) => (
                <Box key={example.input} sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                  <Chip size="small" sx={{ fontFamily: 'monospace', height: 22, minWidth: 150, justifyContent: 'flex-start' }} label={example.input} onClick={() => onInsert(example.input)} />
                  <Typography variant="caption" color="text.secondary">
                    {lang === 'bn' ? example.bn : example.en}
                  </Typography>
                </Box>
              ))}
            </Stack>
          </Box>
        ))}
      </Stack>

      <Divider sx={{ my: 1 }} />
      <Typography variant="caption" color="text.secondary">
        {t('prescriptions.cheatsheet.footer')}
      </Typography>
    </Drawer>
  );
}

export default Cheatsheet;
