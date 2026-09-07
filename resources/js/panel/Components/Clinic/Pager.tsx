// Server-side pagination for the setup tables (CONVENTIONS §13: `->paginate()`). It walks the paginator's own
// `links`, so filters and the sort survive a page change without this component knowing what they are.
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Button from '@mui/material/Button';
import Stack from '@mui/material/Stack';
import Typography from '@mui/material/Typography';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { Paginated } from '@shared/types/models';

interface Props<T> {
  page: Paginated<T>;
}

export function Pager<T>({ page }: Props<T>) {
  const { t } = useTranslation();
  const locale = getLocale();
  const { meta, links } = page;
  const go = (url: string | null): void => {
    if (url) router.visit(url, { preserveScroll: true, preserveState: true });
  };

  if (meta.last_page <= 1) return null;

  return (
    <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'flex-end', p: 1.5 }}>
      <Typography variant="body2" color="text.secondary">
        {t('clinic.common.page_of', { current: formatBn(meta.current_page, locale), last: formatBn(meta.last_page, locale), total: formatBn(meta.total, locale) })}
      </Typography>
      <Button size="small" startIcon={<ChevronLeftIcon />} disabled={!links.prev} onClick={() => go(links.prev)}>{t('common.actions.back')}</Button>
      <Button size="small" endIcon={<ChevronRightIcon />} disabled={!links.next} onClick={() => go(links.next)}>{t('common.actions.next')}</Button>
    </Stack>
  );
}
