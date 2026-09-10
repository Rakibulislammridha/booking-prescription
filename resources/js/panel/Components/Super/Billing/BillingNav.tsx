// The billing desk's own strip, under the console's tabs: overview, subscriptions, invoices, payments, dunning.
// Same shape as SuperNav — an entry whose route is missing is dropped, never shown dead.
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { hasRoute, isRoute, route } from '@shared/routes';

interface Item {
  key: string;
  routeName: string;
  pattern: string;
  label: string;
}

const ITEMS: Item[] = [
  { key: 'overview', routeName: 'super.billing.index', pattern: 'super.billing.index', label: 'super.billing.nav.overview' },
  { key: 'subscriptions', routeName: 'super.billing.subscriptions.index', pattern: 'super.billing.subscriptions.*', label: 'super.billing.nav.subscriptions' },
  { key: 'invoices', routeName: 'super.billing.invoices.index', pattern: 'super.billing.invoices.*', label: 'super.billing.nav.invoices' },
  { key: 'payments', routeName: 'super.billing.payments.index', pattern: 'super.billing.payments.*', label: 'super.billing.nav.payments' },
  { key: 'dunning', routeName: 'super.billing.dunning.index', pattern: 'super.billing.dunning.*', label: 'super.billing.nav.dunning' },
];

export function BillingNav() {
  const { t } = useTranslation();
  const items = ITEMS.filter((item) => hasRoute(item.routeName));
  const current = items.find((item) => isRoute(item.pattern));

  if (items.length === 0) return null;

  return (
    <Box sx={{ mb: 2 }}>
      <Tabs value={current ? current.key : false} variant="scrollable" scrollButtons="auto" allowScrollButtonsMobile aria-label={t('super.billing.nav.label')} sx={{ minHeight: 36 }}>
        {items.map((item) => (
          <Tab key={item.key} value={item.key} label={t(item.label)} component={RouterLink} href={route(item.routeName)} sx={{ minHeight: 36, py: 0.5, textTransform: 'none' }} />
        ))}
      </Tabs>
    </Box>
  );
}
