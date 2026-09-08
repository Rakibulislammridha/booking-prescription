// `public.audit_logs_central`, newest first: who did what to which clinic, from where. Read-only by design —
// there is no action on this page, and that is the point of an audit trail.
import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Collapse from '@mui/material/Collapse';
import Divider from '@mui/material/Divider';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import ChevronLeftIcon from '@mui/icons-material/ChevronLeft';
import ChevronRightIcon from '@mui/icons-material/ChevronRight';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import type { AuditRow, ConsoleMeta } from '@panel/Components/Super/types';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  logs: AuditRow[];
  meta: ConsoleMeta;
  filters: { action: string; tenant: string };
  actions: string[];
}>;

function Diff({ label, value }: { label: string; value: unknown }) {
  if (value === null || value === undefined) return null;

  return (
    <Box sx={{ mt: 0.5, minWidth: 0, flexGrow: 1 }}>
      <Typography variant="caption" color="text.secondary">{label}</Typography>
      <Box component="pre" sx={{ m: 0, p: 1, bgcolor: 'action.hover', borderRadius: 1, fontSize: 11, overflowX: 'auto', maxHeight: 260 }}>
        {JSON.stringify(value, null, 2)}
      </Box>
    </Box>
  );
}

export default function Index({ logs, meta, filters, actions }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [open, setOpen] = useState<number | null>(null);

  const go = (params: { action?: string; tenant?: string; page?: number }): void => {
    const query: Record<string, string | number> = {};
    const action = params.action ?? filters.action;
    const tenant = params.tenant ?? filters.tenant;
    if (action !== '') query.action = action;
    if (tenant !== '') query.tenant = tenant;
    if (params.page !== undefined && params.page > 1) query.page = params.page;
    router.get(route('super.audit.index'), query, { preserveState: true, replace: true, preserveScroll: true });
  };

  const tenantName = logs.find((row) => row.tenant !== null)?.tenant?.name ?? filters.tenant;

  return (
    <Box>
      <SuperNav />

      <Stack spacing={2}>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' } }}>
          <TextField
            select size="small" value={filters.action} label={t('super.audit.filter_action')}
            onChange={(e) => go({ action: e.target.value, page: 1 })} sx={{ minWidth: 240 }}
          >
            <MenuItem value="">{t('super.audit.any_action')}</MenuItem>
            {actions.map((action) => (
              <MenuItem key={action} value={action}>{t(`super.audit.action.${action}`, { defaultValue: action })}</MenuItem>
            ))}
          </TextField>
          {filters.tenant !== '' ? (
            <Chip
              size="small"
              color="info"
              variant="outlined"
              label={t('super.audit.filtered_tenant', { name: tenantName })}
              onDelete={() => go({ tenant: '', page: 1 })}
            />
          ) : null}
          <Typography variant="body2" color="text.secondary">
            {t('super.audit.total', { count: formatNumber(meta.total, locale) })}
          </Typography>
        </Stack>

        <Card variant="outlined">
          <CardContent>
            {logs.length === 0 ? (
              <Typography variant="body2" color="text.secondary" sx={{ py: 3, textAlign: 'center' }}>{t('super.audit.empty')}</Typography>
            ) : (
              <Stack divider={<Divider flexItem />}>
                {logs.map((row) => (
                  <Box key={row.id} sx={{ py: 1 }}>
                    <Stack direction="row" spacing={1} useFlexGap sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
                      <Chip size="small" variant="outlined" label={t(`super.audit.action.${row.action}`, { defaultValue: row.action })} />
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>{row.actor ?? t('super.audit.system')}</Typography>
                      {row.tenant !== null ? (
                        hasRoute('super.tenants.show') ? (
                          <Box component={RouterLink} href={route('super.tenants.show', { tenant: row.tenant.public_id })} lang="bn" sx={{ color: 'primary.main', textDecoration: 'none' }}>
                            {row.tenant.name}
                          </Box>
                        ) : <Typography variant="body2" lang="bn">{row.tenant.name}</Typography>
                      ) : <Typography variant="body2" color="text.secondary">{t('super.audit.no_tenant')}</Typography>}
                      <Typography variant="caption" color="text.secondary">{row.occurred_at === null ? '—' : formatDhaka(row.occurred_at, 'D MMM YYYY, h:mm a', locale)}</Typography>
                      {row.ip ? <Typography variant="caption" color="text.secondary" sx={{ fontFamily: 'monospace' }}>{row.ip}</Typography> : null}
                      {row.auditable_type ? (
                        <Typography variant="caption" color="text.secondary">
                          {row.auditable_type}{row.auditable_id === null ? '' : ` #${formatNumber(row.auditable_id, locale)}`}
                        </Typography>
                      ) : null}
                      <Button size="small" onClick={() => setOpen(open === row.id ? null : row.id)}>
                        {open === row.id ? t('super.audit.hide_diff') : t('super.audit.show_diff')}
                      </Button>
                    </Stack>
                    <Collapse in={open === row.id} unmountOnExit>
                      <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5}>
                        <Diff label={t('super.audit.before')} value={row.before} />
                        <Diff label={t('super.audit.after')} value={row.after} />
                      </Stack>
                    </Collapse>
                  </Box>
                ))}
              </Stack>
            )}
          </CardContent>

          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'flex-end', p: 1.5 }}>
            <Typography variant="body2" color="text.secondary">
              {t('super.tenants.page_of', {
                current: formatNumber(meta.current_page, locale),
                last: formatNumber(meta.last_page, locale),
                total: formatNumber(meta.total, locale),
              })}
            </Typography>
            <Button size="small" startIcon={<ChevronLeftIcon />} disabled={meta.current_page <= 1} onClick={() => go({ page: meta.current_page - 1 })}>
              {t('super.actions.prev')}
            </Button>
            <Button size="small" endIcon={<ChevronRightIcon />} disabled={meta.current_page >= meta.last_page} onClick={() => go({ page: meta.current_page + 1 })}>
              {t('super.actions.next')}
            </Button>
          </Stack>
        </Card>
      </Stack>
    </Box>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="super.audit.title">{page}</PanelLayout>;
