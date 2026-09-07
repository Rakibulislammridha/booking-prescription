// The metric definitions, printed under the report they belong to. BRIEF §5.L: a report that shows a wrong
// number is worse than no report, so every ambiguous metric ("what is a no-show?") states its definition on
// the page rather than in a document nobody opens.
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Typography from '@mui/material/Typography';

export function Footnotes({ keys }: { keys: string[] }) {
  const { t } = useTranslation();

  if (keys.length === 0) return null;

  return (
    <Box component="section" aria-label={t('reports.notes.title')} sx={{ mt: 1 }}>
      <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 600 }}>{t('reports.notes.title')}</Typography>
      <Box component="ul" sx={{ m: 0, mt: 0.5, pl: 2.5 }}>
        {keys.map((key) => (
          <Typography key={key} component="li" variant="caption" color="text.secondary" sx={{ display: 'list-item' }}>
            {t(key)}
          </Typography>
        ))}
      </Box>
    </Box>
  );
}
