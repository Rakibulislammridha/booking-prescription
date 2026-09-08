// The clinic's custom-domain screen: add a hostname, publish the TXT record we show, press Verify.
//
// Nothing here can break the clinic's site: TenantResolver only ever joins on `verification_status = 'verified'`,
// so an unverified row exists but routes nothing, and the clinic's default address keeps working throughout.
import { useState, type FormEvent, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import AlertTitle from '@mui/material/AlertTitle';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import IconButton from '@mui/material/IconButton';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Tooltip from '@mui/material/Tooltip';
import Typography from '@mui/material/Typography';
import AddIcon from '@mui/icons-material/Add';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import DeleteOutlineIcon from '@mui/icons-material/DeleteOutlined';
import StarIcon from '@mui/icons-material/Star';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import type { DomainRow } from '@panel/Components/Super/types';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  allowed: boolean;
  plan_name: string;
  central_domain: string;
  domains: DomainRow[];
}>;

const DATETIME = 'D MMM YYYY, h:mm a';

function CopyRow({ label, value }: { label: string; value: string }) {
  const { t } = useTranslation();
  const [copied, setCopied] = useState(false);

  const copy = (): void => {
    void navigator.clipboard?.writeText(value).then(() => {
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    }).catch(() => undefined);
  };

  return (
    <Box>
      <Typography variant="caption" color="text.secondary" sx={{ display: 'block' }}>{label}</Typography>
      <Stack direction="row" spacing={0.5} sx={{ alignItems: 'center' }}>
        <Box sx={{ fontFamily: 'monospace', fontSize: 13, bgcolor: 'action.hover', px: 1, py: 0.5, borderRadius: 1, overflowX: 'auto', flexGrow: 1 }}>
          {value}
        </Box>
        <Tooltip title={copied ? t('saas.domains.copied') : t('saas.domains.copy')}>
          <IconButton size="small" onClick={copy} aria-label={t('saas.domains.copy')}>
            <ContentCopyIcon fontSize="small" />
          </IconButton>
        </Tooltip>
      </Stack>
    </Box>
  );
}

export default function Domains({ allowed, plan_name, central_domain, domains }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const form = useForm({ domain: '' });
  const when = (iso: string | null): string => (iso === null ? '—' : formatDhaka(iso, DATETIME, locale));

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    form.post(route('panel.saas.domains.store'), { preserveScroll: true, onSuccess: () => form.reset('domain') });
  };

  if (!allowed) {
    return (
      <Alert severity="info" sx={{ maxWidth: 720 }}>
        <AlertTitle>{t('saas.domains.upgrade_title')}</AlertTitle>
        {t('saas.domains.upgrade_body', { plan: plan_name })}
      </Alert>
    );
  }

  return (
    <Stack spacing={2} sx={{ maxWidth: 900 }}>
      <Box>
        <Typography variant="body2" color="text.secondary">
          {t('saas.domains.subtitle', { domain: central_domain })}
        </Typography>
      </Box>

      <Card variant="outlined">
        <CardContent>
          <Box component="form" onSubmit={submit} noValidate>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'flex-start' } }}>
              <TextField
                size="small"
                fullWidth
                label={t('saas.domains.hostname')}
                value={form.data.domain}
                onChange={(e) => form.setData('domain', e.target.value)}
                error={Boolean(form.errors.domain)}
                helperText={form.errors.domain ?? t('saas.domains.hostname_help')}
                required
                slotProps={{ htmlInput: { maxLength: 253, spellCheck: false, autoCapitalize: 'none' } }}
              />
              <Button type="submit" variant="contained" startIcon={<AddIcon />} disabled={form.processing || form.data.domain.trim() === ''} sx={{ flexShrink: 0 }}>
                {t('saas.domains.add')}
              </Button>
            </Stack>
          </Box>
        </CardContent>
      </Card>

      {domains.length === 0 ? (
        <Alert severity="info">{t('saas.domains.empty')}</Alert>
      ) : domains.map((domain) => (
        <Card key={domain.id} variant="outlined">
          <CardContent>
            <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
              <Box sx={{ flexGrow: 1, minWidth: 0 }}>
                <Stack direction="row" spacing={1} useFlexGap sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
                  <Typography variant="subtitle1" sx={{ fontFamily: 'monospace' }}>{domain.domain}</Typography>
                  <Chip
                    size="small"
                    color={domain.verification_status === 'verified' ? 'success' : domain.verification_status === 'failed' ? 'error' : 'warning'}
                    label={t(`saas.domains.status.${domain.verification_status}`, { defaultValue: domain.verification_status })}
                  />
                  {domain.is_primary ? <Chip size="small" color="primary" icon={<StarIcon />} label={t('saas.domains.primary')} /> : null}
                </Stack>
                <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
                  {t('saas.domains.checked', { verified: when(domain.verified_at), checked: when(domain.last_checked_at) })}
                </Typography>

                {domain.verification_status === 'verified' ? null : (
                  <Stack spacing={1} sx={{ mt: 1.5 }}>
                    <Typography variant="body2">{t('saas.domains.txt_help')}</Typography>
                    <CopyRow label={t('saas.domains.txt_name')} value={domain.instructions.txt_name} />
                    <CopyRow label={t('saas.domains.txt_value')} value={domain.instructions.txt_value} />
                    <Typography variant="body2" color="text.secondary">
                      {t('saas.domains.record_help', {
                        kind: domain.instructions.record_kind,
                        name: domain.instructions.record_name,
                        value: domain.instructions.record_value === '' ? t('saas.domains.record_value_missing') : domain.instructions.record_value,
                      })}
                    </Typography>
                  </Stack>
                )}
              </Box>

              <Stack spacing={1} sx={{ flexShrink: 0 }}>
                <Button
                  size="small"
                  variant="outlined"
                  onClick={() => router.post(route('panel.saas.domains.verify', { domain: domain.id }), {}, { preserveScroll: true })}
                >
                  {t('saas.domains.verify')}
                </Button>
                <Button
                  size="small"
                  onClick={() => router.post(route('panel.saas.domains.primary', { domain: domain.id }), {}, { preserveScroll: true })}
                  disabled={domain.is_primary || domain.verification_status !== 'verified'}
                >
                  {t('saas.domains.make_primary')}
                </Button>
                <Button
                  size="small"
                  color="error"
                  startIcon={<DeleteOutlineIcon />}
                  onClick={() => router.delete(route('panel.saas.domains.destroy', { domain: domain.id }), { preserveScroll: true })}
                  disabled={domain.is_primary}
                >
                  {t('saas.domains.remove')}
                </Button>
              </Stack>
            </Stack>
          </CardContent>
        </Card>
      ))}
    </Stack>
  );
}

Domains.layout = (page: ReactNode) => <PanelLayout title="saas.domains.title">{page}</PanelLayout>;
