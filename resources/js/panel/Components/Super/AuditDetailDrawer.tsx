// One audit row in full: who, when, from where (ip, user agent, request id), what it was about, and the
// before/after as a diff table with the rows that moved highlighted. A right-hand drawer rather than a dialog,
// so the list stays visible and an operator can step from row to row while reading.
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import Drawer from '@mui/material/Drawer';
import IconButton from '@mui/material/IconButton';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Typography from '@mui/material/Typography';
import CloseIcon from '@mui/icons-material/Close';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import { diffRows, printValue } from './auditDiff';
import type { AuditDetailRow } from './types';

/** A label above its value — the console's whole layout vocabulary for "here is a fact". */
function Field({ label, children, mono = false }: { label: string; children: React.ReactNode; mono?: boolean }) {
  return (
    <Box sx={{ minWidth: 0 }}>
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{label}</Typography>
      <Typography variant="body2" component="div" sx={{ wordBreak: 'break-word', fontFamily: mono ? 'monospace' : undefined }}>{children}</Typography>
    </Box>
  );
}

export interface AuditDetailDrawerProps {
  row: AuditDetailRow | null;
  onClose: () => void;
}

export function AuditDetailDrawer({ row, onClose }: AuditDetailDrawerProps) {
  const { t } = useTranslation();
  const locale = getLocale();
  const rows = row === null ? [] : diffRows(row.before, row.after);
  const changed = rows.filter((r) => r.changed).length;
  const impersonation = row !== null && (row.action === 'impersonate' || row.action === 'impersonate_end');

  return (
    <Drawer anchor="right" open={row !== null} onClose={onClose} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 520 }, maxWidth: '100%' } } }}>
      {row === null ? null : (
        <Box sx={{ p: 2 }} data-testid="audit-drawer">
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', mb: 1 }}>
            <Chip size="small" color="primary" variant="outlined" label={t(`super.audit.action.${row.action}`, { defaultValue: row.action })} />
            <Typography variant="subtitle1" component="h2" sx={{ flexGrow: 1 }}>{t('super.audit.detail.title', { id: formatNumber(row.id, locale) })}</Typography>
            <IconButton size="small" onClick={onClose} aria-label={t('super.actions.close')}><CloseIcon fontSize="small" /></IconButton>
          </Stack>

          <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: 'repeat(2, 1fr)' }}>
            <Field label={t('super.audit.detail.when')}>{row.occurred_at === null ? '—' : formatDhaka(row.occurred_at, 'D MMM YYYY, h:mm:ss a', locale)}</Field>
            <Field label={t('super.audit.detail.actor')}>
              {row.actor ?? t('super.audit.system')}
              {row.actor_id !== null ? <Typography variant="caption" color="text.secondary" component="div">#{formatNumber(row.actor_id, locale)}</Typography> : null}
            </Field>
            <Field label={t('super.audit.detail.tenant')}>
              {row.tenant === null ? t('super.audit.no_tenant') : hasRoute('super.tenants.show') ? (
                <Box component={RouterLink} href={route('super.tenants.show', { tenant: row.tenant.public_id })} lang="bn" sx={{ color: 'primary.main', textDecoration: 'none' }}>
                  {row.tenant.name}
                </Box>
              ) : <span lang="bn">{row.tenant.name}</span>}
            </Field>
            <Field label={t('super.audit.detail.target')}>
              {row.auditable_type ? `${row.auditable_type.split('\\').pop() ?? row.auditable_type}${row.auditable_id === null ? '' : ` #${formatNumber(row.auditable_id, locale)}`}` : '—'}
            </Field>
          </Box>

          <Divider sx={{ my: 2 }} />

          <Typography variant="overline" color="text.secondary">{t('super.audit.detail.request')}</Typography>
          <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: 'repeat(2, 1fr)', mt: 0.5 }}>
            <Field label={t('super.audit.detail.ip')} mono>{row.ip ?? '—'}</Field>
            <Field label={t('super.audit.detail.request_id')} mono>{row.request_id ?? '—'}</Field>
            <Box sx={{ gridColumn: '1 / -1' }}>
              <Field label={t('super.audit.detail.user_agent')}>
                <Typography variant="caption" component="div" sx={{ fontFamily: 'monospace', wordBreak: 'break-all' }}>{row.user_agent ?? '—'}</Typography>
              </Field>
            </Box>
            {impersonation ? (
              <Box sx={{ gridColumn: '1 / -1' }}>
                <Field label={t('super.audit.detail.impersonator')}>
                  {t('super.audit.detail.impersonator_note', { actor: row.actor ?? t('super.audit.system'), tenant: row.tenant?.name ?? '—' })}
                </Field>
              </Box>
            ) : null}
          </Box>

          <Divider sx={{ my: 2 }} />

          <Stack direction="row" spacing={1} sx={{ alignItems: 'baseline', mb: 0.5 }}>
            <Typography variant="overline" color="text.secondary">{t('super.audit.detail.changes')}</Typography>
            {rows.length > 0 ? <Typography variant="caption" color="text.secondary">{t('super.audit.detail.changed_count', { count: formatNumber(changed, locale) })}</Typography> : null}
          </Stack>
          {rows.length === 0 ? (
            <Typography variant="body2" color="text.secondary">{t('super.audit.detail.no_changes')}</Typography>
          ) : (
            <Box sx={{ overflowX: 'auto' }}>
              <Table size="small" aria-label={t('super.audit.detail.changes')}>
                <TableHead>
                  <TableRow>
                    <TableCell>{t('super.audit.detail.field')}</TableCell>
                    <TableCell>{t('super.audit.before')}</TableCell>
                    <TableCell>{t('super.audit.after')}</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {rows.map((r) => (
                    <TableRow key={r.key} sx={r.changed ? { bgcolor: 'action.hover' } : undefined} data-changed={r.changed ? '1' : '0'}>
                      <TableCell sx={{ fontFamily: 'monospace', fontWeight: r.changed ? 600 : 400, whiteSpace: 'nowrap' }}>{r.key}</TableCell>
                      <TableCell sx={{ fontFamily: 'monospace', fontSize: 12, wordBreak: 'break-all', color: r.changed ? 'error.main' : 'text.secondary' }}>{printValue(r.before)}</TableCell>
                      <TableCell sx={{ fontFamily: 'monospace', fontSize: 12, wordBreak: 'break-all', color: r.changed ? 'success.main' : 'text.secondary' }}>{printValue(r.after)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </Box>
          )}
        </Box>
      )}
    </Drawer>
  );
}
