// @lang-module super
//
// The console's drawer: the fourteen entries of Components/Super/nav.tsx under their section headers, with the
// billing desk's screens unfolded under Billing while one of them is open. PanelLayout mounts this — lazily, as
// its own chunk — only when the page was served on the super surface (SharedProps.surface === 'super'), so a
// clinic desk never downloads these entries or their icons, and the clinic's own drawer never shows them.
//
// `@lang-module super` above: this file's copy (`super.nav.*`, `super.billing.nav.*`) renders only on console
// pages, and lang/__tests__/bundles.test.ts attributes it to the panel-super slice for that reason.
import { Fragment } from 'react';
import { useTranslation } from 'react-i18next';
import Collapse from '@mui/material/Collapse';
import List from '@mui/material/List';
import ListItemButton from '@mui/material/ListItemButton';
import ListItemIcon from '@mui/material/ListItemIcon';
import ListItemText from '@mui/material/ListItemText';
import ListSubheader from '@mui/material/ListSubheader';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { hasRoute, isRoute, route } from '@shared/routes';
import { SUPER_NAV_SECTIONS, type SuperNavItem } from './nav';

export interface SuperSidebarProps {
  /** Called after an entry is chosen — the layout closes the temporary drawer on a phone. */
  onNavigate?: () => void;
}

export default function SuperSidebar({ onNavigate }: SuperSidebarProps) {
  const { t } = useTranslation();
  // A section whose every route is missing disappears with its entries; there is nothing to head.
  const sections = SUPER_NAV_SECTIONS
    .map((section) => ({ ...section, items: section.items.filter((item) => hasRoute(item.routeName)) }))
    .filter((section) => section.items.length > 0);

  const entry = (item: SuperNavItem) => {
    const selected = isRoute(item.pattern);
    const children = (item.children ?? []).filter((child) => hasRoute(child.routeName));

    return (
      <Fragment key={item.key}>
        <ListItemButton component={RouterLink} href={route(item.routeName)} selected={selected} onClick={onNavigate} data-testid={`super-nav-${item.key}`}>
          <ListItemIcon>{item.icon}</ListItemIcon>
          <ListItemText primary={t(item.label)} />
        </ListItemButton>
        {children.length > 0 ? (
          <Collapse in={selected} timeout="auto" unmountOnExit>
            <List component="div" disablePadding aria-label={t('super.billing.nav.label')}>
              {children.map((child) => (
                <ListItemButton
                  key={child.key}
                  component={RouterLink}
                  href={route(child.routeName)}
                  selected={isRoute(child.pattern)}
                  onClick={onNavigate}
                  sx={{ pl: 9 }}
                  data-testid={`super-nav-${child.key}`}
                >
                  <ListItemText primary={t(child.label)} slotProps={{ primary: { variant: 'body2' } }} />
                </ListItemButton>
              ))}
            </List>
          </Collapse>
        ) : null}
      </Fragment>
    );
  };

  return (
    <List aria-label={t('super.nav.label')} sx={{ pt: 0 }}>
      {sections.map((section) => (
        <Fragment key={section.key}>
          <ListSubheader
            disableSticky
            sx={{ lineHeight: '36px', mt: 1, fontSize: 11, fontWeight: 700, letterSpacing: '0.08em', textTransform: 'uppercase', color: 'text.secondary', bgcolor: 'transparent' }}
          >
            {t(section.label)}
          </ListSubheader>
          {section.items.map(entry)}
        </Fragment>
      ))}
    </List>
  );
}
