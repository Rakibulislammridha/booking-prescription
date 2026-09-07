// Unauthenticated panel shell (login, password reset): centred card with the clinic identity.
import type { ReactNode } from 'react';
import { Head, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Box from '@mui/material/Box';
import Paper from '@mui/material/Paper';
import Typography from '@mui/material/Typography';
import Button from '@mui/material/Button';
import Avatar from '@mui/material/Avatar';
import Alert from '@mui/material/Alert';
import { ConnectionIndicator } from '@shared/connection/ConnectionIndicator';
import { useSharedProps } from '@shared/inertia';
import { hasRoute, route } from '@shared/routes';

export interface GuestLayoutProps {
  title?: string; // i18n key
  children: ReactNode;
}

export function GuestLayout({ title, children }: GuestLayoutProps) {
  const { t } = useTranslation();
  const shared = useSharedProps();
  const pageTitle = title ? t(title) : undefined;
  const nextLocale = shared.locale === 'bn' ? 'en' : 'bn';
  const canSwitch = hasRoute('panel.locale');
  const domainError = shared.errors.domain; // DomainException → back()->withErrors(['domain' => …]) (ARCHITECTURE §2)

  return (
    <Box sx={{ minHeight: '100vh', display: 'flex', flexDirection: 'column', bgcolor: 'grey.100' }}>
      {pageTitle ? <Head title={pageTitle} /> : null}
      <ConnectionIndicator variant="quiet" />
      <Box sx={{ flexGrow: 1, display: 'flex', alignItems: 'center', justifyContent: 'center', p: 2 }}>
        <Paper elevation={1} sx={{ width: '100%', maxWidth: 420, p: { xs: 3, sm: 4 } }}>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, mb: 3 }}>
            {shared.tenant?.logo_url ? (
              <Avatar src={shared.tenant.logo_url} alt="" variant="rounded" sx={{ width: 44, height: 44 }} />
            ) : (
              <Avatar variant="rounded" sx={{ width: 44, height: 44, bgcolor: 'primary.main' }}>{(shared.tenant?.name ?? shared.app.name).slice(0, 1)}</Avatar>
            )}
            <Box>
              <Typography variant="h6" component="p" sx={{ lineHeight: 1.2 }}>{shared.tenant?.name ?? shared.app.name}</Typography>
              {pageTitle ? <Typography variant="body2" color="text.secondary">{pageTitle}</Typography> : null}
            </Box>
          </Box>
          {domainError ? <Alert severity="error" sx={{ mb: 2 }}>{domainError}</Alert> : null}
          {children}
          {canSwitch ? (
            <Box sx={{ mt: 3, textAlign: 'center' }}>
              <Button size="small" onClick={() => router.patch(route('panel.locale'), { locale: nextLocale })}>
                {nextLocale === 'bn' ? 'বাংলা' : 'English'}
              </Button>
            </Box>
          ) : null}
        </Paper>
      </Box>
    </Box>
  );
}

export default GuestLayout;
