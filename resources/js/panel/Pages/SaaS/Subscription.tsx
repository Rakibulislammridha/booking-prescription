// The clinic's own view of what it is paying for. Written for a hospital manager, not for the platform team:
// plan, what is included, how much of each cap is used, and the invoices — with a pay link that keeps working
// even when the account is suspended, because that is exactly the day it matters.
import { type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import AlertTitle from '@mui/material/AlertTitle';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import Stack from '@mui/material/Stack';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import Typography from '@mui/material/Typography';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import RemoveCircleOutlineIcon from '@mui/icons-material/RemoveCircleOutlined';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { UsageBars } from '@panel/Components/Super/UsageBars';
import type { LimitStatus, SuperPlan, TenantInvoiceRow } from '@panel/Components/Super/types';
import { formatBdt } from '@shared/format/money';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  tenant: { name: string; status: string; trial_ends_at: string | null };
  subscription: {
    status: string;
    plan_code: string;
    plan_name: string;
    billing_cycle: string;
    price_paisa: number;
    current_period_end: string;
    grace_until: string | null;
    cancel_at_period_end: boolean;
  } | null;
  entitlements: { plan_name: string; toggles: Record<string, boolean> };
  usage: LimitStatus[];
  metric_labels: Record<string, string>;
  feature_labels: Record<string, string>;
  plans: SuperPlan[];
  invoices: TenantInvoiceRow[];
}>;

const DATE = 'D MMM YYYY';

export default function Subscription({ tenant, subscription, entitlements, usage, metric_labels, feature_labels, plans, invoices }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const when = (iso: string | null): string => (iso === null ? '—' : formatDhaka(iso, DATE, locale));
  const included = Object.entries(entitlements.toggles);

  return (
    <Stack spacing={2}>
      {tenant.status === 'past_due' ? (
        <Alert severity="warning" variant="filled">
          <AlertTitle>{t('saas.subscription.past_due_title')}</AlertTitle>
          {subscription?.grace_until
            ? t('saas.subscription.past_due_grace', { date: when(subscription.grace_until) })
            : t('saas.subscription.past_due_body')}
        </Alert>
      ) : null}

      {tenant.status === 'suspended' ? (
        <Alert severity="error" variant="filled">{t('saas.subscription.suspended')}</Alert>
      ) : null}

      {subscription?.cancel_at_period_end ? (
        <Alert severity="info">{t('saas.cancel.period_end')}</Alert>
      ) : null}

      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } }}>
        <Card variant="outlined">
          <CardContent>
            <Typography variant="overline" color="text.secondary">{t('saas.subscription.plan_title')}</Typography>
            {subscription === null ? (
              <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>{t('saas.subscription.no_subscription')}</Typography>
            ) : (
              <>
                <Stack direction="row" spacing={1} sx={{ alignItems: 'baseline', flexWrap: 'wrap' }} useFlexGap>
                  <Typography variant="h5" component="h2">{subscription.plan_name}</Typography>
                  <Typography variant="body1" color="text.secondary">
                    {formatBdt(subscription.price_paisa, locale)} · {subscription.billing_cycle === 'yearly' ? t('saas.pricing.per_year') : t('saas.pricing.per_month')}
                  </Typography>
                </Stack>
                <Stack direction="row" spacing={3} sx={{ mt: 2, flexWrap: 'wrap' }} useFlexGap>
                  <Box>
                    <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('saas.subscription.renews_on')}</Typography>
                    <Typography variant="body2">{when(subscription.current_period_end)}</Typography>
                  </Box>
                  {tenant.trial_ends_at ? (
                    <Box>
                      <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('saas.pricing.trial_row')}</Typography>
                      <Typography variant="body2">{t('saas.onboarding.trial_ends', { date: when(tenant.trial_ends_at) })}</Typography>
                    </Box>
                  ) : null}
                  {subscription.grace_until ? (
                    <Box>
                      <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{t('saas.subscription.grace_until')}</Typography>
                      <Typography variant="body2" sx={{ color: 'warning.main' }}>{when(subscription.grace_until)}</Typography>
                    </Box>
                  ) : null}
                </Stack>
              </>
            )}
          </CardContent>
        </Card>

        <Card variant="outlined">
          <CardContent>
            <Typography variant="overline" color="text.secondary">{t('saas.subscription.usage_title')}</Typography>
            <Box sx={{ mt: 1 }}>
              <UsageBars usage={usage} metric_labels={metric_labels} />
            </Box>
          </CardContent>
        </Card>
      </Box>

      <Card variant="outlined">
        <CardContent>
          <Typography variant="overline" color="text.secondary">{t('saas.subscription.modules_title')}</Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>
            {t('saas.subscription.modules_help', { plan: entitlements.plan_name })}
          </Typography>
          <Box sx={{ display: 'grid', gap: 1, gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)', md: 'repeat(3, 1fr)' } }}>
            {included.map(([key, on]) => (
              <Stack key={key} direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                {on
                  ? <CheckCircleIcon fontSize="small" sx={{ color: 'success.main' }} />
                  : <RemoveCircleOutlineIcon fontSize="small" sx={{ color: 'text.disabled' }} />}
                <Typography variant="body2" sx={{ color: on ? 'text.primary' : 'text.disabled' }}>
                  {feature_labels[key] ?? key}
                </Typography>
              </Stack>
            ))}
          </Box>
        </CardContent>

        {plans.length > 0 ? (
          <>
            <Divider />
            <CardContent>
              <Typography variant="overline" color="text.secondary">{t('saas.subscription.other_plans')}</Typography>
              <Stack direction="row" spacing={1} useFlexGap sx={{ flexWrap: 'wrap', mt: 1 }}>
                {plans.map((plan) => (
                  <Chip
                    key={plan.code}
                    size="small"
                    variant={plan.code === subscription?.plan_code ? 'filled' : 'outlined'}
                    color={plan.code === subscription?.plan_code ? 'primary' : 'default'}
                    label={`${plan.name} · ${formatBdt(plan.price_monthly_paisa, locale)}`}
                  />
                ))}
              </Stack>
              <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>
                {t('saas.subscription.change_plan_help')}
              </Typography>
            </CardContent>
          </>
        ) : null}
      </Card>

      <Card variant="outlined">
        <CardContent sx={{ pb: 0 }}>
          <Typography variant="overline" color="text.secondary">{t('saas.subscription.invoices_title')}</Typography>
        </CardContent>
        <Box sx={{ overflowX: 'auto' }}>
          <Table size="small" aria-label={t('saas.subscription.invoices_title')}>
            <TableHead>
              <TableRow>
                <TableCell>{t('saas.subscription.invoice_number')}</TableCell>
                <TableCell>{t('saas.subscription.status')}</TableCell>
                <TableCell>{t('saas.invoice.issued_at')}</TableCell>
                <TableCell>{t('saas.invoice.due_at')}</TableCell>
                <TableCell align="right">{t('saas.invoice.total')}</TableCell>
                <TableCell align="right">{t('saas.invoice.due')}</TableCell>
                <TableCell />
              </TableRow>
            </TableHead>
            <TableBody>
              {invoices.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={7}>
                    <Typography variant="body2" color="text.secondary" sx={{ py: 3, textAlign: 'center' }}>{t('saas.subscription.invoices_empty')}</Typography>
                  </TableCell>
                </TableRow>
              ) : invoices.map((invoice) => (
                <TableRow key={invoice.public_id} hover>
                  <TableCell sx={{ fontFamily: 'monospace' }}>{invoice.number}</TableCell>
                  <TableCell>
                    <Chip
                      size="small"
                      color={invoice.status === 'paid' ? 'success' : invoice.status === 'overdue' ? 'error' : invoice.status === 'issued' ? 'warning' : 'default'}
                      variant={invoice.status === 'void' || invoice.status === 'draft' ? 'outlined' : 'filled'}
                      label={t(`saas.invoice.status.${invoice.status}`, { defaultValue: invoice.status })}
                    />
                  </TableCell>
                  <TableCell sx={{ whiteSpace: 'nowrap' }}>{when(invoice.issued_at)}</TableCell>
                  <TableCell sx={{ whiteSpace: 'nowrap' }}>{when(invoice.due_at)}</TableCell>
                  <TableCell align="right" sx={{ fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' }}>{formatBdt(invoice.total_paisa, locale)}</TableCell>
                  <TableCell align="right" sx={{ fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap', color: invoice.due_paisa > 0 ? 'error.main' : 'text.secondary' }}>
                    {formatBdt(invoice.due_paisa, locale)}
                  </TableCell>
                  <TableCell align="right">
                    {invoice.pay_url === null ? null : (
                      // A signed URL on the CENTRAL host — never an Inertia <Link>, which would try to fetch it
                      // as a page of this panel.
                      <Button component="a" href={invoice.pay_url} size="small" variant="contained">
                        {t('saas.invoice.pay_now')}
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Box>
      </Card>
    </Stack>
  );
}

Subscription.layout = (page: ReactNode) => <PanelLayout title="saas.subscription.title">{page}</PanelLayout>;
