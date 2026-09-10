// @lang-module super
//
// The console's navigation — ONE list, in the order the drawer shows it. Read by the sidebar
// (Components/Super/SuperSidebar.tsx, which PanelLayout mounts only on the super surface) and by
// Tests\Feature\SaaS\SuperNavRoutesTest, which checks every `routeName:` below is a registered GET route on the
// super surface and every `label:` a key in both languages. Entries whose route the surface has not shipped are
// dropped by the sidebar rather than shown disabled: the platform team is ten people who know what exists, and a
// dead entry is just noise.
//
// The `@lang-module super` pragma above tells lang/__tests__/bundles.test.ts that this file's copy renders only on
// the console's pages, so a clinic route is not asked to carry `super.nav.*` (ARCHITECTURE §7.5).
import type { ReactNode } from 'react';
import AdminPanelSettingsIcon from '@mui/icons-material/AdminPanelSettings';
import AuditIcon from '@mui/icons-material/History';
import BillingIcon from '@mui/icons-material/Payments';
import CatalogIcon from '@mui/icons-material/Medication';
import ClinicsIcon from '@mui/icons-material/LocalHospital';
import DashboardIcon from '@mui/icons-material/Dashboard';
import ImportsIcon from '@mui/icons-material/CloudUpload';
import NotificationsIcon from '@mui/icons-material/NotificationsActive';
import PlansIcon from '@mui/icons-material/LocalOffer';
import ReconciliationIcon from '@mui/icons-material/CompareArrows';
import ReviewIcon from '@mui/icons-material/FactCheck';
import SecurityIcon from '@mui/icons-material/Security';
import SettingsIcon from '@mui/icons-material/Settings';
import UsageIcon from '@mui/icons-material/DataUsage';

export interface SuperNavChild {
  key: string;
  routeName: string;
  /** isRoute() pattern for the selected state. */
  pattern: string;
  /** i18n key of the label. */
  label: string;
}

export interface SuperNavItem extends SuperNavChild {
  icon: ReactNode;
  /** Shown indented under the entry while the entry's own `pattern` is the current route (Billing's desks). */
  children?: SuperNavChild[];
}

export interface SuperNavSection {
  key: string;
  /** i18n key of the section header. */
  label: string;
  items: SuperNavItem[];
}

/**
 * The billing desk's five screens. They were a second tab strip under the console's tabs; in the drawer they sit
 * under Billing and unfold while any of them is open, so the ledger's screens stay one click apart without
 * costing every other console page five rows.
 */
const BILLING: SuperNavChild[] = [
  { key: 'billing_overview', routeName: 'super.billing.index', pattern: 'super.billing.index', label: 'super.billing.nav.overview' },
  { key: 'billing_subscriptions', routeName: 'super.billing.subscriptions.index', pattern: 'super.billing.subscriptions.*', label: 'super.billing.nav.subscriptions' },
  { key: 'billing_invoices', routeName: 'super.billing.invoices.index', pattern: 'super.billing.invoices.*', label: 'super.billing.nav.invoices' },
  { key: 'billing_payments', routeName: 'super.billing.payments.index', pattern: 'super.billing.payments.*', label: 'super.billing.nav.payments' },
  { key: 'billing_dunning', routeName: 'super.billing.dunning.index', pattern: 'super.billing.dunning.*', label: 'super.billing.nav.dunning' },
];

export const SUPER_NAV_SECTIONS: SuperNavSection[] = [
  {
    key: 'overview',
    label: 'super.nav.section.overview',
    items: [
      { key: 'dashboard', routeName: 'super.dashboard', pattern: 'super.dashboard', label: 'super.nav.dashboard', icon: <DashboardIcon /> },
    ],
  },
  {
    key: 'clinics',
    label: 'super.nav.section.clinics',
    items: [
      { key: 'tenants', routeName: 'super.tenants.index', pattern: 'super.tenants.*', label: 'super.nav.tenants', icon: <ClinicsIcon /> },
      { key: 'usage', routeName: 'super.usage.index', pattern: 'super.usage.*', label: 'super.nav.usage', icon: <UsageIcon /> },
    ],
  },
  {
    key: 'revenue',
    label: 'super.nav.section.revenue',
    items: [
      { key: 'plans', routeName: 'super.plans.index', pattern: 'super.plans.*', label: 'super.nav.plans', icon: <PlansIcon /> },
      { key: 'billing', routeName: 'super.billing.index', pattern: 'super.billing.*', label: 'super.nav.billing', icon: <BillingIcon />, children: BILLING },
    ],
  },
  {
    key: 'catalogue',
    label: 'super.nav.section.catalogue',
    items: [
      { key: 'catalog', routeName: 'super.catalog.index', pattern: 'super.catalog.index', label: 'super.nav.catalog', icon: <CatalogIcon /> },
      { key: 'imports', routeName: 'super.catalog.imports.index', pattern: 'super.catalog.imports.*', label: 'super.nav.imports', icon: <ImportsIcon /> },
      { key: 'review', routeName: 'super.catalog.review', pattern: 'super.catalog.review', label: 'super.nav.review', icon: <ReviewIcon /> },
      { key: 'reconciliation', routeName: 'super.catalog.reconciliation.index', pattern: 'super.catalog.reconciliation.*', label: 'super.nav.reconciliation', icon: <ReconciliationIcon /> },
    ],
  },
  {
    key: 'platform',
    label: 'super.nav.section.platform',
    items: [
      { key: 'admins', routeName: 'super.admins.index', pattern: 'super.admins.*', label: 'super.nav.admins', icon: <AdminPanelSettingsIcon /> },
      { key: 'notifications', routeName: 'super.notifications.index', pattern: 'super.notifications.*', label: 'super.nav.notifications', icon: <NotificationsIcon /> },
      { key: 'settings', routeName: 'super.settings.index', pattern: 'super.settings.*', label: 'super.nav.settings', icon: <SettingsIcon /> },
      { key: 'audit', routeName: 'super.audit.index', pattern: 'super.audit.*', label: 'super.nav.audit', icon: <AuditIcon /> },
    ],
  },
  {
    key: 'account',
    label: 'super.nav.section.account',
    items: [
      { key: 'security', routeName: 'super.two-factor.show', pattern: 'super.two-factor.*', label: 'super.nav.security', icon: <SecurityIcon /> },
    ],
  },
];

/** The fourteen top-level entries, flat, in drawer order. */
export const SUPER_NAV: SuperNavItem[] = SUPER_NAV_SECTIONS.flatMap((section) => section.items);
