// The plan table IS the price list and the enforcement table at once (PlanCatalog): what is edited here is what
// the pricing page advertises and what PlanLimits enforces, so a cap can never be sold and not applied.
//
// The list shows every plan, archived ones included, with the number of clinics living on each — the number
// that decides whether archiving is a click or a conversation (the guard dialog names it).
import { useState, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Stack from '@mui/material/Stack';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import AddIcon from '@mui/icons-material/Add';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import { ConfirmActionDialog } from '@panel/Components/Super/Billing/ConfirmActionDialog';
import type { ConsolePlan } from '@panel/Components/Super/Billing/types';
import { bytesParts } from '@panel/Components/Super/UsageBars';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  plans: ConsolePlan[];
  subscriptions: Record<string, number>;
  feature_labels: Record<string, string>;
  limit_keys: string[];
  toggle_keys: string[];
}>;

export default function Index({ plans, subscriptions, feature_labels, limit_keys }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const n = (value: number): string => formatNumber(value, locale);
  const [archiving, setArchiving] = useState<ConsolePlan | null>(null);
  const [busy, setBusy] = useState(false);

  const subscribers = (plan: ConsolePlan): number => subscriptions[plan.code] ?? 0;

  const archive = (plan: ConsolePlan): void => {
    setBusy(true);
    router.delete(route('super.plans.destroy', { plan: plan.code }), {
      data: { confirm: true },
      preserveScroll: true,
      onFinish: () => { setBusy(false); setArchiving(null); },
    });
  };
  const restore = (plan: ConsolePlan): void => {
    router.delete(route('super.plans.destroy', { plan: plan.code }), { data: { restore: true }, preserveScroll: true });
  };
  const clone = (plan: ConsolePlan): void => {
    router.post(route('super.plans.clone', { plan: plan.code }), {}, { preserveScroll: true });
  };

  const limitSummary = (plan: ConsolePlan): string => limit_keys.map((key) => {
    const row = plan.limits.find((limit) => limit.key === key);
    const label = feature_labels[key] ?? key;
    if (row === undefined || row.value === null) return `${label}: ${t('saas.pricing.unlimited')}`;
    if (row.is_bytes) { const parts = bytesParts(row.value, locale); return `${label}: ${t(parts.key, { size: parts.size })}`; }
    return `${label}: ${n(row.value)}`;
  }).join(' · ');

  const columns: SuperColumn<ConsolePlan>[] = [
    {
      key: 'plan',
      label: t('super.plans.column.plan'),
      render: (plan) => (
        <Box>
          <Box component={RouterLink} href={route('super.plans.edit', { plan: plan.code })} sx={{ color: 'primary.main', fontWeight: 600, textDecoration: 'none' }}>
            {plan.name}
          </Box>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', fontFamily: 'monospace' }}>{plan.code}</Typography>
        </Box>
      ),
    },
    {
      key: 'flags',
      label: t('super.plans.column.flags'),
      render: (plan) => (
        <Stack direction="row" spacing={0.5} useFlexGap sx={{ flexWrap: 'wrap' }}>
          {plan.is_public ? <Chip size="small" variant="outlined" color="success" label={t('super.plans.public')} /> : <Chip size="small" variant="outlined" label={t('super.plans.private')} />}
          {plan.is_addon ? <Chip size="small" variant="outlined" color="info" label={t('super.plans.addon')} /> : null}
          {plan.archived_at ? <Chip size="small" color="default" label={t('super.plans.archived')} /> : null}
        </Stack>
      ),
    },
    {
      key: 'limits',
      label: t('super.plans.column.limits'),
      render: (plan) => (
        <Tooltip title={limitSummary(plan)}>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', maxWidth: 320, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
            {limitSummary(plan)}
          </Typography>
        </Tooltip>
      ),
    },
    { key: 'modules', label: t('super.plans.column.modules'), align: 'right', render: (plan) => n(plan.toggles.filter((toggle) => toggle.enabled).length) },
    { key: 'monthly', label: t('super.plans.column.monthly'), align: 'right', render: (plan) => formatBdt(plan.price_monthly_paisa, locale) },
    { key: 'yearly', label: t('super.plans.column.yearly'), align: 'right', render: (plan) => formatBdt(plan.price_yearly_paisa, locale) },
    { key: 'trial', label: t('super.plans.column.trial'), align: 'right', render: (plan) => n(plan.trial_days) },
    {
      key: 'subs',
      label: t('super.plans.column.subscriptions'),
      align: 'right',
      render: (plan) => (
        <Typography variant="body2" sx={{ fontWeight: subscribers(plan) > 0 ? 600 : 400, fontVariantNumeric: 'tabular-nums' }}>{n(subscribers(plan))}</Typography>
      ),
    },
    {
      key: 'actions',
      label: t('super.plans.column.actions'),
      align: 'right',
      render: (plan) => (
        <Stack direction="row" spacing={0.5} sx={{ justifyContent: 'flex-end' }}>
          <Button size="small" component={RouterLink} href={route('super.plans.edit', { plan: plan.code })}>{t('super.actions.edit')}</Button>
          <Button size="small" startIcon={<ContentCopyIcon fontSize="small" />} onClick={() => clone(plan)}>{t('super.plans.clone')}</Button>
          {plan.archived_at ? (
            <Button size="small" color="success" onClick={() => restore(plan)}>{t('super.plans.restore')}</Button>
          ) : (
            <Button size="small" color="error" onClick={() => (subscribers(plan) > 0 ? setArchiving(plan) : archive(plan))}>{t('super.plans.archive')}</Button>
          )}
        </Stack>
      ),
    },
  ];

  return (
    <Box>
      <SuperNav />

      <Stack spacing={2}>
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'space-between' }}>
          <Typography variant="subtitle1" component="h2">{t('super.plans.title')}</Typography>
          <Button variant="contained" startIcon={<AddIcon />} component={RouterLink} href={route('super.plans.create')}>{t('super.plans.create')}</Button>
        </Stack>

        <Alert severity="info">{t('super.plans.archive_note')}</Alert>

        <Card variant="outlined">
          <SuperTable columns={columns} rows={plans} rowKey={(plan) => plan.code} empty={t('super.plans.empty')} label={t('super.plans.title')} />
        </Card>

        <Card variant="outlined">
          <CardContent>
            <Typography variant="caption" color="text.secondary">{t('super.plans.limits_legend')}</Typography>
          </CardContent>
        </Card>
      </Stack>

      <ConfirmActionDialog
        open={archiving !== null}
        title={t('super.plans.archive_title', { name: archiving?.name ?? '' })}
        body={t('super.plans.archive_guard', { count: n(archiving === null ? 0 : subscribers(archiving)) })}
        confirmLabel={t('super.plans.archive')}
        color="error"
        busy={busy}
        onClose={() => setArchiving(null)}
        onConfirm={() => { if (archiving !== null) archive(archiving); }}
      />
    </Box>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="super.plans.title">{page}</PanelLayout>;
