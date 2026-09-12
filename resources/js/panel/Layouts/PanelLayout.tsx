// The authenticated panel shell: ConnectionIndicator above the app bar, nav drawer, branch switcher, user menu,
// flash + PWA-update snackbars. Pages assign it statically (CONVENTIONS §7.1):
//   Board.layout = (page) => <PanelLayout title="reception.board.title">{page}</PanelLayout>
//
// One shell, two surfaces (ARCHITECTURE §7.4 `surface`): on the clinic's panel the drawer is NAV + SETUP below,
// gated by permission / feature / shipped route; on the super console it is Components/Super/SuperSidebar.tsx —
// the console's own entries, in their own chunk — and none of the clinic's entries, greyed or otherwise. The app
// bar, user menu, impersonation banner, connection indicator and snackbars are the same on both.
import { Suspense, lazy, useEffect, useState, type ReactNode } from 'react';
import { Head, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import AppBar from '@mui/material/AppBar';
import Toolbar from '@mui/material/Toolbar';
import Typography from '@mui/material/Typography';
import IconButton from '@mui/material/IconButton';
import Drawer from '@mui/material/Drawer';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemIcon from '@mui/material/ListItemIcon';
import ListItemText from '@mui/material/ListItemText';
import Box from '@mui/material/Box';
import Menu from '@mui/material/Menu';
import MenuItem from '@mui/material/MenuItem';
import Avatar from '@mui/material/Avatar';
import Select, { type SelectChangeEvent } from '@mui/material/Select';
import Snackbar from '@mui/material/Snackbar';
import Alert from '@mui/material/Alert';
import Button from '@mui/material/Button';
import Chip from '@mui/material/Chip';
import Divider from '@mui/material/Divider';
import Tooltip from '@mui/material/Tooltip';
import useMediaQuery from '@mui/material/useMediaQuery';
import { useTheme } from '@mui/material/styles';
import MenuIcon from '@mui/icons-material/Menu';
import DashboardIcon from '@mui/icons-material/Dashboard';
import DeskIcon from '@mui/icons-material/PointOfSale';
import QueueIcon from '@mui/icons-material/Groups';
import TodaySessionIcon from '@mui/icons-material/EventNote';
import ScheduleIcon from '@mui/icons-material/CalendarMonth';
import PatientsIcon from '@mui/icons-material/People';
import PrescriptionIcon from '@mui/icons-material/Description';
import BillingIcon from '@mui/icons-material/Payments';
import NotificationsIcon from '@mui/icons-material/NotificationsActive';
import ReportsIcon from '@mui/icons-material/BarChart';
import TelemedicineIcon from '@mui/icons-material/VideoCall';
import SettingsIcon from '@mui/icons-material/Settings';
import SetupIcon from '@mui/icons-material/Tune';
import BranchIcon from '@mui/icons-material/Business';
import DepartmentIcon from '@mui/icons-material/AccountTree';
import SpecialtyIcon from '@mui/icons-material/LocalHospital';
import StaffIcon from '@mui/icons-material/Badge';
import DoctorIcon from '@mui/icons-material/MedicalServices';
import HolidayIcon from '@mui/icons-material/EventAvailable';
import LeaveIcon from '@mui/icons-material/EventBusy';
import ExpandLessIcon from '@mui/icons-material/ExpandLess';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import Collapse from '@mui/material/Collapse';
import CustomBrandsIcon from '@mui/icons-material/Medication';
import TranslateIcon from '@mui/icons-material/Translate';
import { ConnectionIndicator } from '@shared/connection/ConnectionIndicator';
import { useSharedProps } from '@shared/inertia';
import { loadEcho } from '@shared/realtime/echo';
import { hasRoute, isRoute, route } from '@shared/routes';
import { formatBn } from '@shared/format/number';
import { useTodaySessionBadge } from '@panel/hooks/queue/useTodaySessionBadge';
import { usePwa } from '../pwa';
import { RouterLink } from './RouterLink';

export const DRAWER_WIDTH = 240;

// The console's drawer content is loaded only where it is rendered: a reception desk never downloads the super
// entries or their icons (scripts/check-panel-budget.sh), and panel/app.tsx requests the chunk in the same tick
// as a `Super/*` page so the console sees no empty drawer on a cold load.
const SuperSidebar = lazy(() => import('@panel/Components/Super/SuperSidebar'));

interface NavItem {
  key: string;            // i18n key nav.<key>
  routeName: string;      // module route; rendered disabled until the module ships it
  icon: ReactNode;
  pattern: string;        // isRoute() pattern for the selected state
  permission?: string;    // App\Domain\Clinic\Enums\Permission value; the entry is hidden without it
  feature?: string;       // SharedProps.features key; an add-on module's entry is hidden without the plan (BRIEF §5.M)
  doctor?: boolean;       // true: only a user with a doctors row sees it; false: hidden from such a user (one queue entry per user)
  badge?: 'today_session'; // the live count pill on the entry (useTodaySessionBadge, seeded by the `today_session` shared prop)
}

/**
 * The Setup group (BRIEF §5.A): the screens that configure the clinic itself. Each entry carries its own
 * permission, so a doctor sees only the two they own (their leave and their pad) while a hospital admin sees all of
 * it; the group header disappears entirely when a user holds none of them.
 */
const SETUP: NavItem[] = [
  { key: 'setup_branches', routeName: 'panel.clinic.branches.index', icon: <BranchIcon />, pattern: 'panel.clinic.branches.*', permission: 'clinic.branches.manage' },
  { key: 'setup_departments', routeName: 'panel.clinic.departments.index', icon: <DepartmentIcon />, pattern: 'panel.clinic.departments.*', permission: 'clinic.departments.manage' },
  { key: 'setup_specialties', routeName: 'panel.clinic.specialties.index', icon: <SpecialtyIcon />, pattern: 'panel.clinic.specialties.*', permission: 'clinic.specialties.manage' },
  { key: 'setup_staff', routeName: 'panel.clinic.staff.index', icon: <StaffIcon />, pattern: 'panel.clinic.staff.*', permission: 'clinic.users.manage' },
  { key: 'setup_doctors', routeName: 'panel.clinic.doctors.index', icon: <DoctorIcon />, pattern: 'panel.clinic.doctors.*', permission: 'clinic.pad.design' },
  { key: 'setup_holidays', routeName: 'panel.clinic.holidays.index', icon: <HolidayIcon />, pattern: 'panel.clinic.holidays.*', permission: 'clinic.holidays.manage' },
  { key: 'setup_leaves', routeName: 'panel.clinic.leaves.index', icon: <LeaveIcon />, pattern: 'panel.clinic.leaves.*', permission: 'clinic.leaves.manage' },
  { key: 'settings', routeName: 'panel.clinic.settings.index', icon: <SettingsIcon />, pattern: 'panel.clinic.settings.*', permission: 'clinic.settings.manage' },
];

const NAV: NavItem[] = [
  { key: 'dashboard', routeName: 'panel.dashboard', icon: <DashboardIcon />, pattern: 'panel.dashboard' },
  { key: 'reception', routeName: 'panel.reception.board', icon: <DeskIcon />, pattern: 'panel.reception.*', permission: 'serials.issue.counter' },
  // One queue entry per user. A doctor gets "Today's session" — their own session page (`panel.queue.doctor`, the
  // very page `panel.queue.index` would redirect them to) with the checked-in count on it; everyone else keeps
  // "Live queue", the branch overview. Two labels for one route family, never both in one drawer.
  { key: 'today_session', routeName: 'panel.queue.doctor', icon: <TodaySessionIcon />, pattern: 'panel.queue.*', doctor: true, badge: 'today_session' },
  { key: 'queue', routeName: 'panel.queue.index', icon: <QueueIcon />, pattern: 'panel.queue.*', doctor: false },
  { key: 'scheduling', routeName: 'panel.scheduling.index', icon: <ScheduleIcon />, pattern: 'panel.scheduling.*', permission: 'scheduling.schedules.manage' },
  { key: 'patients', routeName: 'panel.patients.index', icon: <PatientsIcon />, pattern: 'panel.patients.*', permission: 'patients.view' },
  // `panel.prescription*`: the list is `panel.prescriptions.index`, the writer / show / templates are `panel.prescription.*`.
  { key: 'prescriptions', routeName: 'panel.prescriptions.index', icon: <PrescriptionIcon />, pattern: 'panel.prescription*' },
  { key: 'custom_brands', routeName: 'panel.catalog.custom-brands.index', icon: <CustomBrandsIcon />, pattern: 'panel.catalog.*', permission: 'catalog.custom-brands.manage' },
  { key: 'billing', routeName: 'panel.billing.index', icon: <BillingIcon />, pattern: 'panel.billing.*', permission: 'billing.invoices.view' },
  { key: 'notifications', routeName: 'panel.notifications.index', icon: <NotificationsIcon />, pattern: 'panel.notifications.*', permission: 'notifications.templates.manage' },
  { key: 'reports', routeName: 'panel.reports.index', icon: <ReportsIcon />, pattern: 'panel.reports.*', permission: 'reports.view' },
  { key: 'telemedicine', routeName: 'panel.telemedicine.index', icon: <TelemedicineIcon />, pattern: 'panel.telemedicine.*', feature: 'telemedicine' },
];

export interface PanelLayoutProps {
  /** i18n key of the page title (also the document title). */
  title?: string;
  children: ReactNode;
}

export function PanelLayout({ title, children }: PanelLayoutProps) {
  const { t } = useTranslation();
  const shared = useSharedProps();
  const theme = useTheme();
  const desktop = useMediaQuery(theme.breakpoints.up('md'));
  const [mobileOpen, setMobileOpen] = useState(false);
  const [userAnchor, setUserAnchor] = useState<HTMLElement | null>(null);
  const [flashOpen, setFlashOpen] = useState(true);
  const [setupOpen, setSetupOpen] = useState(() => isRoute('panel.clinic.*'));
  const pwa = usePwa();
  const pageTitle = title ? t(title) : undefined;
  const user = shared.auth.user;
  const isSuper = shared.surface === 'super';
  // Entries a user lacks the permission for are hidden; entries whose module has not shipped its route stay visible but disabled.
  const isDoctor = user?.doctor_id != null;
  const allowed = (item: NavItem): boolean =>
    (!item.permission || (user?.permissions.includes(item.permission) ?? false))
    && (!item.feature || shared.features[item.feature] === true)
    && (item.doctor === undefined || item.doctor === isDoctor);
  const nav = NAV.filter(allowed);
  // The "Today's session" count: seeded from the shared prop on every visit, kept live by Queue/Doctor's own
  // queue subscription while that page is open (useTodaySessionBadge).
  const seed = shared.today_session;
  const waiting = useTodaySessionBadge((s) => s.waiting);
  useEffect(() => { useTodaySessionBadge.getState().set(seed?.session_id ?? null, seed?.waiting ?? null); }, [seed?.session_id, seed?.waiting]);
  const setup = SETUP.filter(allowed);

  const flash = (['error', 'warning', 'success', 'info'] as const).map((k) => ({ severity: k, message: shared.flash[k] })).find((f) => f.message);
  const nextLocale = shared.locale === 'bn' ? 'en' : 'bn';
  const logoutRoute = hasRoute('panel.logout') ? 'panel.logout' : hasRoute('super.logout') ? 'super.logout' : null;

  // The WebSocket is what lets the connection store reach `online` (OFFLINE.md §3): open it once the shell mounts.
  useEffect(() => { void loadEcho(); }, []);

  const switchLocale = (): void => {
    if (hasRoute('panel.locale')) router.patch(route('panel.locale'), { locale: nextLocale }, { preserveScroll: true });
  };
  const switchBranch = (event: SelectChangeEvent<string>): void => {
    if (hasRoute('panel.branch.switch')) router.patch(route('panel.branch.switch'), { branch_id: Number(event.target.value) });
  };
  const logout = (): void => {
    setUserAnchor(null);
    if (logoutRoute) router.post(route(logoutRoute));
  };

  // The clinic's drawer: NAV + the Setup group, each entry hidden without its permission / feature and greyed
  // until its module ships the route (the sweep in Tests\Feature\Panel\PanelNavRoutesTest keeps that list empty).
  const clinicNav = (
    <List>
      {nav.map((item) => {
        const available = hasRoute(item.routeName);
        const selected = available && isRoute(item.pattern);
        const button = (
          <ListItemButton
            key={item.key}
            component={available ? RouterLink : 'div'}
            href={available ? route(item.routeName) : undefined}
            selected={selected}
            disabled={!available}
            onClick={() => setMobileOpen(false)}
          >
            <ListItemIcon>{item.icon}</ListItemIcon>
            <ListItemText primary={t(`nav.${item.key}`)} />
            {item.badge === 'today_session' && waiting !== null && waiting > 0 ? (
              <Chip size="small" color="secondary" label={formatBn(waiting, shared.locale)} aria-label={t('nav.today_session_waiting', { count: formatBn(waiting, shared.locale) })} data-testid="today-session-badge" sx={{ height: 20, fontWeight: 700 }} />
            ) : null}
          </ListItemButton>
        );
        return available ? button : (
          <Tooltip key={item.key} title={t('common.status.coming_soon')} placement="right">
            <span>{button}</span>
          </Tooltip>
        );
      })}

      {setup.length > 0 ? (
        <>
          <Divider sx={{ my: 1 }} />
          <ListItemButton onClick={() => setSetupOpen((open) => !open)} aria-expanded={setupOpen} selected={isRoute('panel.clinic.*')}>
            <ListItemIcon><SetupIcon /></ListItemIcon>
            <ListItemText primary={t('nav.setup')} />
            {setupOpen ? <ExpandLessIcon /> : <ExpandMoreIcon />}
          </ListItemButton>
          <Collapse in={setupOpen} timeout="auto" unmountOnExit>
            <List component="div" disablePadding>
              {setup.map((item) => (
                <ListItemButton
                  key={item.key}
                  component={hasRoute(item.routeName) ? RouterLink : 'div'}
                  href={hasRoute(item.routeName) ? route(item.routeName) : undefined}
                  selected={hasRoute(item.routeName) && isRoute(item.pattern)}
                  disabled={!hasRoute(item.routeName)}
                  onClick={() => setMobileOpen(false)}
                  sx={{ pl: 4 }}
                >
                  <ListItemIcon sx={{ minWidth: 36 }}>{item.icon}</ListItemIcon>
                  <ListItemText primary={t(`nav.${item.key}`)} slotProps={{ primary: { variant: 'body2' } }} />
                </ListItemButton>
              ))}
            </List>
          </Collapse>
        </>
      ) : null}
    </List>
  );

  const drawer = (
    <Box role="navigation" aria-label={t('nav.menu')} sx={{ width: DRAWER_WIDTH }}>
      <Toolbar>
        <Typography variant="subtitle1" noWrap sx={{ fontWeight: 700 }}>{shared.tenant?.name ?? shared.app.name}</Typography>
      </Toolbar>
      <Divider />
      {isSuper ? (
        <Suspense fallback={<List sx={{ minHeight: 320 }} aria-busy="true" />}>
          <SuperSidebar onNavigate={() => setMobileOpen(false)} />
        </Suspense>
      ) : clinicNav}
    </Box>
  );

  return (
    <Box sx={{ display: 'flex', minHeight: '100vh', bgcolor: 'background.default' }}>
      {pageTitle ? <Head title={pageTitle} /> : null}

      <Box component="nav" sx={{ width: { md: DRAWER_WIDTH }, flexShrink: { md: 0 } }}>
        {desktop ? (
          <Drawer variant="permanent" open sx={{ '& .MuiDrawer-paper': { width: DRAWER_WIDTH, boxSizing: 'border-box' } }}>{drawer}</Drawer>
        ) : (
          <Drawer variant="temporary" open={mobileOpen} onClose={() => setMobileOpen(false)} ModalProps={{ keepMounted: true }} sx={{ '& .MuiDrawer-paper': { width: DRAWER_WIDTH } }}>{drawer}</Drawer>
        )}
      </Box>

      {/* Header + content share one column: the header is sticky (in flow), so the main area is never clipped
          behind it whatever the indicator / impersonation banner adds to its height. */}
      <Box sx={{ flexGrow: 1, minWidth: 0, display: 'flex', flexDirection: 'column' }}>
        <Box component="header" sx={{ position: 'sticky', top: 0, zIndex: (th) => th.zIndex.appBar }}>
          <ConnectionIndicator variant="desk" />
          {shared.auth.impersonating ? (
            <Alert severity="warning" variant="filled" square sx={{ py: 0 }}>{t('auth.impersonating')}</Alert>
          ) : null}
          <AppBar position="static" color="primary" enableColorOnDark>
            <Toolbar>
              {!desktop ? (
                <IconButton color="inherit" edge="start" aria-label={t('nav.menu')} onClick={() => setMobileOpen(true)} sx={{ mr: 1 }}>
                  <MenuIcon />
                </IconButton>
              ) : null}
              <Typography variant="h6" component="h1" noWrap sx={{ flexGrow: 1 }}>{pageTitle ?? shared.app.name}</Typography>
              {shared.branches.length > 1 && shared.branch ? (
                <Select
                  size="small"
                  value={String(shared.branch.id)}
                  onChange={switchBranch}
                  inputProps={{ 'aria-label': t('nav.branch') }}
                  sx={{ mr: 1, color: 'inherit', '& .MuiOutlinedInput-notchedOutline': { borderColor: 'rgba(255,255,255,0.5)' }, '& .MuiSvgIcon-root': { color: 'inherit' } }}
                >
                  {shared.branches.map((b) => <MenuItem key={b.id} value={String(b.id)}>{b.name}</MenuItem>)}
                </Select>
              ) : shared.branch ? (
                <Chip label={shared.branch.name} size="small" sx={{ mr: 1, color: 'inherit', borderColor: 'rgba(255,255,255,0.5)' }} variant="outlined" />
              ) : null}
              {hasRoute('panel.locale') ? (
                <Tooltip title={t('common.language.switch')}>
                  <IconButton color="inherit" onClick={switchLocale} aria-label={t('common.language.switch')}>
                    <TranslateIcon />
                  </IconButton>
                </Tooltip>
              ) : null}
              {user ? (
                <>
                  <IconButton color="inherit" onClick={(e) => setUserAnchor(e.currentTarget)} aria-label={t('nav.account')} aria-haspopup="menu">
                    <Avatar sx={{ width: 32, height: 32, bgcolor: 'primary.dark', fontSize: 14 }}>{user.name.slice(0, 1).toUpperCase()}</Avatar>
                  </IconButton>
                  <Menu anchorEl={userAnchor} open={Boolean(userAnchor)} onClose={() => setUserAnchor(null)}>
                    <MenuItem disabled>
                      <ListItemText primary={user.name} secondary={user.roles.map((r) => t(`roles.${r}`, { defaultValue: r })).join(', ')} />
                    </MenuItem>
                    <Divider />
                    {hasRoute('super.profile.show') ? (
                      <MenuItem component={RouterLink} href={route('super.profile.show')} onClick={() => setUserAnchor(null)}>{t('nav.account')}</MenuItem>
                    ) : null}
                    <MenuItem onClick={logout} disabled={!logoutRoute}>{t('auth.logout')}</MenuItem>
                  </Menu>
                </>
              ) : null}
            </Toolbar>
          </AppBar>
        </Box>

        <Box component="main" sx={{ flexGrow: 1, p: { xs: 2, md: 3 } }}>
          {children}
        </Box>
      </Box>

      {flash ? (
        <Snackbar open={flashOpen} autoHideDuration={6000} onClose={() => setFlashOpen(false)} anchorOrigin={{ vertical: 'bottom', horizontal: 'center' }}>
          <Alert severity={flash.severity} onClose={() => setFlashOpen(false)} variant="filled">{flash.message}</Alert>
        </Snackbar>
      ) : null}

      <Snackbar open={pwa.needRefresh} anchorOrigin={{ vertical: 'bottom', horizontal: 'left' }}>
        <Alert
          severity="info"
          action={<Button color="inherit" size="small" onClick={pwa.applyUpdate} disabled={pwa.waitingForIdle}>{t('pwa.apply')}</Button>}
          onClose={pwa.dismiss}
        >
          {pwa.waitingForIdle ? t('pwa.waiting_for_sync') : t('pwa.update_ready')}
        </Alert>
      </Snackbar>
    </Box>
  );
}

export default PanelLayout;
