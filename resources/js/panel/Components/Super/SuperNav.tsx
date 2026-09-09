// The console's own navigation. PanelLayout's drawer is built for clinic staff (reception, queue, prescriptions)
// and resolves to nothing on the super host, so every console page renders this strip at the top instead.
// Entries whose route the surface has not shipped are dropped rather than shown disabled: the platform team is
// ten people who know what exists, and a dead tab is just noise.
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Tab from '@mui/material/Tab';
import Tabs from '@mui/material/Tabs';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { hasRoute, isRoute, route } from '@shared/routes';

interface NavItem {
  key: string;
  routeName: string;
  /** isRoute() pattern for the selected state. */
  pattern: string;
  label: string;
}

const ITEMS: NavItem[] = [
  { key: 'dashboard', routeName: 'super.dashboard', pattern: 'super.dashboard', label: 'super.nav.dashboard' },
  { key: 'tenants', routeName: 'super.tenants.index', pattern: 'super.tenants.*', label: 'super.nav.tenants' },
  { key: 'plans', routeName: 'super.plans.index', pattern: 'super.plans.*', label: 'super.nav.plans' },
  { key: 'usage', routeName: 'super.usage.index', pattern: 'super.usage.*', label: 'super.nav.usage' },
  { key: 'review', routeName: 'super.catalog.review', pattern: 'super.catalog.review', label: 'super.nav.review' },
  { key: 'reconciliation', routeName: 'super.catalog.reconciliation.index', pattern: 'super.catalog.reconciliation.*', label: 'super.nav.reconciliation' },
  { key: 'audit', routeName: 'super.audit.index', pattern: 'super.audit.*', label: 'super.nav.audit' },
  { key: 'settings', routeName: 'super.settings.index', pattern: 'super.settings.*', label: 'super.nav.settings' },
  { key: 'security', routeName: 'super.two-factor.show', pattern: 'super.two-factor.*', label: 'super.nav.security' },
];

export function SuperNav() {
  const { t } = useTranslation();
  const items = ITEMS.filter((item) => hasRoute(item.routeName));
  const current = items.find((item) => isRoute(item.pattern));

  if (items.length === 0) return null;

  return (
    <Box sx={{ borderBottom: 1, borderColor: 'divider', mb: 2 }}>
      <Tabs
        value={current ? current.key : false}
        variant="scrollable"
        scrollButtons="auto"
        allowScrollButtonsMobile
        aria-label={t('super.nav.label')}
      >
        {items.map((item) => (
          <Tab
            key={item.key}
            value={item.key}
            label={t(item.label)}
            component={RouterLink}
            href={route(item.routeName)}
            sx={{ minHeight: 44, textTransform: 'none' }}
          />
        ))}
      </Tabs>
    </Box>
  );
}
