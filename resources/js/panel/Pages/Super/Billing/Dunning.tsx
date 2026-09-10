// What the next `saas:dun` run will do, clinic by clinic — the reminders it will send and the suspensions it will
// make — and a button that does one clinic's share of it now, through the same Actions. The ladder is explained
// in words from the server's constants so this page can never disagree with the schedule.
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
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import { StatusChip } from '@panel/Components/Super/StatusChip';
import { BillingNav } from '@panel/Components/Super/Billing/BillingNav';
import { ConfirmActionDialog } from '@panel/Components/Super/Billing/ConfirmActionDialog';
import { InvoiceStatusChip } from '@panel/Components/Super/Billing/InvoiceStatusChip';
import type { DunningGroup, DunningInvoicePreview, DunningScheduleInfo, DunningTotals } from '@panel/Components/Super/Billing/types';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { hasRoute, route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  queue: DunningGroup[];
  totals: DunningTotals;
  schedule: DunningScheduleInfo;
}>;

const DATE = 'D MMM YYYY';

export default function Dunning({ queue, totals, schedule }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const n = (value: number): string => formatNumber(value, locale);
  const [running, setRunning] = useState<DunningGroup | null>(null);
  const [busy, setBusy] = useState(false);

  const run = (group: DunningGroup): void => {
    setBusy(true);
    router.post(route('super.billing.dunning.run', { tenant: group.tenant.public_id }), {}, { preserveScroll: true, onFinish: () => { setBusy(false); setRunning(null); } });
  };

  const columns: SuperColumn<DunningInvoicePreview>[] = [
    { key: 'number', label: t('super.billing.column.number'), render: (row) => <Typography variant="body2" sx={{ fontFamily: 'monospace', fontWeight: 600 }}>{row.number}</Typography> },
    { key: 'status', label: t('super.billing.column.status'), render: (row) => <InvoiceStatusChip status={row.status} pastDue={row.days_overdue >= 0} /> },
    { key: 'due_at', label: t('super.billing.column.due_at'), render: (row) => `${formatDhaka(row.due_at, DATE, locale)} · ${t('super.billing.dunning.days_overdue', { count: n(row.days_overdue) })}` },
    { key: 'due', label: t('super.billing.column.due'), align: 'right', render: (row) => <Typography variant="body2" sx={{ color: 'error.main', fontWeight: 600, fontVariantNumeric: 'tabular-nums' }}>{formatBdt(row.due_paisa, locale)}</Typography> },
    { key: 'step', label: t('super.billing.dunning.column_step'), align: 'right', render: (row) => `${n(row.dunning_step)} / ${n(schedule.steps.length)}` },
    {
      key: 'next',
      label: t('super.billing.dunning.column_next'),
      render: (row) => (
        <Stack direction="row" spacing={0.5} useFlexGap sx={{ flexWrap: 'wrap' }}>
          {row.will_suspend ? <Chip size="small" color="error" label={t('super.billing.dunning.will_suspend_chip')} /> : null}
          {row.will_notify ? <Chip size="small" color="warning" label={row.is_final_notice ? t('super.billing.dunning.final_notice', { step: n(row.due_step) }) : t('super.billing.dunning.reminder', { step: n(row.due_step) })} /> : null}
          {!row.will_notify && !row.will_suspend ? (
            <Typography variant="caption" color="text.secondary">
              {row.next_step_at === null ? '—' : t('super.billing.dunning.nothing_until', { date: formatDhaka(row.next_step_at, DATE, locale) })}
            </Typography>
          ) : null}
        </Stack>
      ),
    },
  ];

  return (
    <Box>
      <SuperNav />
      <BillingNav />

      <Stack spacing={2}>
        <Alert severity="info">
          {t('super.billing.dunning.schedule', {
            net: n(schedule.net_days),
            steps: schedule.steps.map((d) => n(d)).join(', '),
            grace: n(schedule.grace_days),
          })}
        </Alert>

        <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap', alignItems: 'center' }}>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {totals.tenants === 0
              ? t('super.billing.dunning.calm')
              : t('super.billing.dunning.summary', { tenants: n(totals.tenants), notices: n(totals.notices), suspensions: n(totals.suspensions), amount: formatBdt(totals.arrears_paisa, locale) })}
          </Typography>
        </Stack>

        {queue.map((group) => (
          <Card key={group.tenant.public_id} variant="outlined" sx={{ borderColor: group.will_suspend ? 'error.main' : group.will_notify > 0 ? 'warning.main' : 'divider' }}>
            <CardContent sx={{ pb: 1 }}>
              <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap' }} useFlexGap>
                <Box>
                  <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                    {hasRoute('super.tenants.show') ? (
                      <Box component={RouterLink} href={route('super.tenants.show', { tenant: group.tenant.public_id })} lang="bn" sx={{ color: 'primary.main', fontWeight: 600, textDecoration: 'none', fontSize: 16 }}>{group.tenant.name}</Box>
                    ) : <Typography variant="subtitle1" lang="bn">{group.tenant.name}</Typography>}
                    <StatusChip status={group.tenant.status} />
                  </Stack>
                  <Typography variant="caption" color="text.secondary">
                    {group.tenant.slug}{group.tenant.owner_email ? ` · ${group.tenant.owner_email}` : ''} · {t('super.billing.arrears', { amount: formatBdt(group.arrears_paisa, locale), count: n(group.invoices.length) })}
                  </Typography>
                </Box>
                <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                  {group.will_suspend ? <Chip size="small" color="error" label={t('super.billing.dunning.will_suspend')} /> : null}
                  {group.will_notify > 0 ? <Chip size="small" color="warning" label={t('super.billing.dunning.will_notify', { count: n(group.will_notify) })} /> : null}
                  {group.grace_deadline !== null && !group.will_suspend ? <Chip size="small" variant="outlined" label={t('super.billing.dunning.suspends_on', { date: formatDhaka(group.grace_deadline, DATE, locale) })} /> : null}
                  <Button size="small" variant="outlined" color={group.will_suspend ? 'error' : 'warning'} onClick={() => setRunning(group)} disabled={!hasRoute('super.billing.dunning.run')}>
                    {t('super.billing.dunning.run_now')}
                  </Button>
                </Stack>
              </Stack>
            </CardContent>
            <SuperTable columns={columns} rows={group.invoices} rowKey={(row) => row.public_id} empty={t('super.billing.invoices.empty')} label={group.tenant.name} />
          </Card>
        ))}

        {queue.length === 0 ? (
          <Card variant="outlined"><CardContent><Typography variant="body2" color="text.secondary">{t('super.billing.dunning.empty')}</Typography></CardContent></Card>
        ) : null}
      </Stack>

      <ConfirmActionDialog
        open={running !== null}
        title={t('super.billing.dunning.run_title', { clinic: running?.tenant.name ?? '' })}
        body={running?.will_suspend ? t('super.billing.dunning.run_body_suspend') : t('super.billing.dunning.run_body')}
        confirmLabel={t('super.billing.dunning.run_now')}
        color={running?.will_suspend ? 'error' : 'warning'}
        busy={busy}
        onClose={() => setRunning(null)}
        onConfirm={() => { if (running !== null) run(running); }}
      />
    </Box>
  );
}

Dunning.layout = (page: ReactNode) => <PanelLayout title="super.billing.page_title">{page}</PanelLayout>;
