// Previous / next over the console's paginator meta — the same footer the audit log renders.
import { useTranslation } from 'react-i18next';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import { formatNumber } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { ConsoleMeta } from './types';

export function Pager({ meta, onPage }: { meta: ConsoleMeta; onPage: (page: number) => void }) {
  const { t } = useTranslation();
  const locale = getLocale();

  return (
    <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'flex-end', p: 1.5 }}>
      <Typography variant="body2" color="text.secondary">
        {t('super.tenants.page_of', {
          current: formatNumber(meta.current_page, locale),
          last: formatNumber(Math.max(1, meta.last_page), locale),
          total: formatNumber(meta.total, locale),
        })}
      </Typography>
      <Button size="small" startIcon={<ChevronLeftIcon />} disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>
        {t('super.actions.prev')}
      </Button>
      <Button size="small" endIcon={<ChevronRightIcon />} disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>
        {t('super.actions.next')}
      </Button>
    </Stack>
  );
}
