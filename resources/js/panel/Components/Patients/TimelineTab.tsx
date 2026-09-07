// Timeline tab: the merged, cursor-paginated patient timeline (PRESCRIPTION.md §8). First page arrives as a prop;
// "Load older" fetches the next keyset page over XHR (panel/api/patients.ts).
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import List from '@mui/material/List';
import ListItem from '@mui/material/ListItem';
import ListItemText from '@mui/material/ListItemText';
import Chip from '@mui/material/Chip';
import Button from '@mui/material/Button';
import Typography from '@mui/material/Typography';
import Stack from '@mui/material/Stack';
import Alert from '@mui/material/Alert';
import { fetchTimeline } from '@panel/api/patients';
import { formatDhaka } from '@shared/format/date';
import { isApiError } from '@shared/http';
import type { TimelineEntry, TimelinePage } from '@shared/types/models';
import type { Locale } from '@shared/types/shared-props';

export interface TimelineTabProps {
  patient: string;          // public id
  initial: TimelinePage;
  kinds: string[];
}

export function TimelineTab({ patient, initial, kinds }: TimelineTabProps) {
  const { t, i18n } = useTranslation();
  const locale: Locale = i18n.language === 'bn' ? 'bn' : 'en';
  const [entries, setEntries] = useState<TimelineEntry[]>(initial.data);
  const [cursor, setCursor] = useState<string | null>(initial.meta.next_cursor);
  const [filter, setFilter] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = async (reset: boolean, kind: string | null): Promise<void> => {
    setBusy(true);
    setError(null);
    try {
      const page = await fetchTimeline(patient, reset ? null : cursor, 25, kind ? [kind] : undefined);
      setEntries((prev) => (reset ? page.data : [...prev, ...page.data]));
      setCursor(page.meta.next_cursor);
    } catch (e) {
      setError(isApiError(e) ? e.message : t('common.status.error'));
    } finally {
      setBusy(false);
    }
  };

  const toggleFilter = (kind: string): void => {
    const next = filter === kind ? null : kind;
    setFilter(next);
    void load(true, next);
  };

  return (
    <Stack spacing={2}>
      <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap' }}>
        {kinds.map((kind) => (
          <Chip key={kind} label={t(`patients.timeline.kinds.${kind}`, { defaultValue: kind })} size="small" color={filter === kind ? 'primary' : 'default'} onClick={() => toggleFilter(kind)} />
        ))}
      </Stack>
      {error ? <Alert severity="error">{error}</Alert> : null}
      {entries.length === 0 && !busy ? (
        <Typography variant="body2" color="text.secondary">{t('patients.show.timeline_empty')}</Typography>
      ) : (
        <List dense disablePadding>
          {entries.map((entry) => (
            <ListItem key={`${entry.kind}-${entry.id}`} divider alignItems="flex-start">
              <Chip size="small" variant="outlined" label={t(`patients.timeline.kinds.${entry.kind}`, { defaultValue: entry.kind })} sx={{ mr: 1.5, mt: 0.5, minWidth: 96 }} />
              <ListItemText
                primary={entry.title}
                secondary={[entry.subtitle, formatDhaka(entry.occurred_at, 'D MMM YYYY, h:mm a', locale)].filter(Boolean).join(' · ')}
              />
            </ListItem>
          ))}
        </List>
      )}
      {cursor ? (
        <Button onClick={() => void load(false, filter)} disabled={busy} size="small" sx={{ alignSelf: 'flex-start' }}>
          {busy ? t('common.loading') : t('patients.show.load_more')}
        </Button>
      ) : null}
    </Stack>
  );
}
