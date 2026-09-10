// The plan editor — create and edit are the same form. Every PlanFeatureKey is on it: the five numeric caps
// each with an "unlimited" switch (storage typed in MB, stored in bytes), the eight module toggles as switches,
// the two prices typed in TAKA and sent as integer paisa, trial days, sort order, visibility and the add-on flag.
//
// Saving a plan that clinics are ALREADY ON is not a plain save: the form shows what will change first and says
// the two things the operator must know — entitlements move the moment it saves, the price moves at each
// clinic's next renewal (the documented no-proration rule). The request carries `acknowledge` only after that.
import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Chip from '@mui/material/Chip';
import Dialog from '@mui/material/Dialog';
import DialogActions from '@mui/material/DialogActions';
import DialogContent from '@mui/material/DialogContent';
import DialogContentText from '@mui/material/DialogContentText';
import DialogTitle from '@mui/material/DialogTitle';
import Divider from '@mui/material/Divider';
import FormControlLabel from '@mui/material/FormControlLabel';
import InputAdornment from '@mui/material/InputAdornment';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import Table from '@mui/material/Table';
import TableBody from '@mui/material/TableBody';
import TableCell from '@mui/material/TableCell';
import TableHead from '@mui/material/TableHead';
import TableRow from '@mui/material/TableRow';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import type { ConsolePlan } from '@panel/Components/Super/Billing/types';
import { bytesParts } from '@panel/Components/Super/UsageBars';
import {
  bytesToMb, diffPlan, emptyPlanForm, isBytesKey, mbToBytes, paisaToTaka, parseCount, planToForm, takaToPaisa,
  type PlanChange, type PlanFormData,
} from '@panel/lib/super/planForm';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';
import type { Locale } from '@shared/types/shared-props';

type Props = PageProps<{
  plan: ConsolePlan | null;
  subscribers: number;
  next_sort_order: number;
  feature_labels: Record<string, string>;
  limit_keys: string[];
  toggle_keys: string[];
}>;

/** A limit as the row shows it: the count, the storage size, or "unlimited". */
function limitText(key: string, value: number | null, t: (k: string, o?: Record<string, string>) => string, locale: Locale): string {
  if (value === null) return t('saas.pricing.unlimited');
  if (isBytesKey(key)) { const parts = bytesParts(value, locale); return t(parts.key, { size: parts.size }); }
  return formatNumber(value, locale);
}

function LimitRow({ label, value, help, isBytes, onChange }: {
  label: string;
  value: number | null;
  help: string;
  isBytes: boolean;
  onChange: (next: number | null) => void;
}) {
  const { t } = useTranslation();
  const unlimited = value === null;
  const shown = value === null ? '' : String(isBytes ? bytesToMb(value) : value);

  return (
    <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' } }}>
      <Box sx={{ minWidth: 220 }}>
        <Typography variant="body2" sx={{ fontWeight: 600 }}>{label}</Typography>
        <Typography variant="caption" color="text.secondary">{help}</Typography>
      </Box>
      <FormControlLabel
        control={<Switch size="small" checked={unlimited} onChange={(e) => onChange(e.target.checked ? null : 0)} />}
        label={<Typography variant="body2">{t('super.plans.unlimited')}</Typography>}
        sx={{ mr: 1 }}
      />
      <TextField
        size="small"
        value={shown}
        disabled={unlimited}
        onChange={(e) => { const count = parseCount(e.target.value); onChange(count === null ? 0 : (isBytes ? mbToBytes(count) : count)); }}
        placeholder={unlimited ? t('super.plans.unlimited') : '0'}
        slotProps={{
          htmlInput: { inputMode: 'numeric', 'aria-label': label },
          input: isBytes ? { endAdornment: <InputAdornment position="end">MB</InputAdornment> } : undefined,
        }}
        sx={{ width: 200 }}
      />
      {!unlimited && value === 0 ? <Chip size="small" variant="outlined" label={t('super.plans.not_included')} /> : null}
    </Stack>
  );
}

function ChangeList({ changes, labels, locale }: { changes: PlanChange[]; labels: Record<string, string>; locale: Locale }) {
  const { t } = useTranslation();
  const name = (change: PlanChange): string => {
    if (change.kind === 'price') return change.key === 'price_monthly_paisa' ? t('super.plans.field.monthly') : t('super.plans.field.yearly');
    if (change.kind === 'limit' || change.kind === 'toggle') return labels[change.key] ?? change.key;
    const field: Record<string, string> = {
      code: t('super.plans.field.code'), name: t('super.plans.field.name'), description: t('super.plans.field.description'),
      trial_days: t('super.plans.field.trial_days'), is_public: t('super.plans.field.is_public'), is_addon: t('super.plans.field.is_addon'), sort_order: t('super.plans.field.sort_order'),
    };
    return field[change.key] ?? change.key;
  };
  const value = (change: PlanChange, v: string | number | boolean | null): string => {
    if (change.kind === 'price') return formatBdt(typeof v === 'number' ? v : 0, locale);
    if (change.kind === 'limit') return limitText(change.key, typeof v === 'number' ? v : null, t, locale);
    if (change.kind === 'toggle' || typeof v === 'boolean') return v ? t('saas.pricing.yes') : t('saas.pricing.no');
    return v === null || v === '' ? '—' : String(v);
  };
  const timing = (change: PlanChange): string => (change.kind === 'price' ? t('super.plans.diff.next_renewal') : change.kind === 'field' ? t('super.plans.diff.cosmetic') : t('super.plans.diff.immediately'));

  return (
    <Table size="small" aria-label={t('super.plans.diff.title')}>
      <TableHead>
        <TableRow>
          <TableCell>{t('super.plans.diff.field')}</TableCell>
          <TableCell>{t('super.audit.before')}</TableCell>
          <TableCell>{t('super.audit.after')}</TableCell>
          <TableCell>{t('super.plans.diff.when')}</TableCell>
        </TableRow>
      </TableHead>
      <TableBody>
        {changes.map((change) => (
          <TableRow key={`${change.kind}:${change.key}`}>
            <TableCell>{name(change)}</TableCell>
            <TableCell sx={{ color: 'text.secondary' }}>{value(change, change.before)}</TableCell>
            <TableCell sx={{ fontWeight: 600 }}>{value(change, change.after)}</TableCell>
            <TableCell>
              <Chip size="small" variant="outlined" color={change.kind === 'price' ? 'info' : change.kind === 'field' ? 'default' : 'warning'} label={timing(change)} />
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}

export default function Edit({ plan, subscribers, next_sort_order, feature_labels, limit_keys, toggle_keys }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const n = (value: number): string => formatNumber(value, locale);
  const original = useMemo<PlanFormData>(
    () => (plan === null ? emptyPlanForm(limit_keys, toggle_keys, next_sort_order) : planToForm(plan, limit_keys, toggle_keys, next_sort_order)),
    [plan, limit_keys, toggle_keys, next_sort_order],
  );
  const form = useForm<PlanFormData>(original);
  const [monthly, setMonthly] = useState(paisaToTaka(original.price_monthly_paisa));
  const [yearly, setYearly] = useState(paisaToTaka(original.price_yearly_paisa));
  const [confirmOpen, setConfirmOpen] = useState(false);
  const editing = plan !== null;
  const changes = useMemo(() => diffPlan(original, form.data), [original, form.data]);

  const help: Record<string, string> = {
    branches: t('super.plans.limit_help.branches'),
    doctors: t('super.plans.limit_help.doctors'),
    appointments_monthly: t('super.plans.limit_help.appointments_monthly'),
    sms_credits_monthly: t('super.plans.limit_help.sms_credits_monthly'),
    storage_bytes: t('super.plans.limit_help.storage_bytes'),
  };

  const send = (acknowledge: boolean): void => {
    form.transform((data) => ({ ...data, acknowledge }));
    const options = { preserveScroll: true, onSuccess: () => setConfirmOpen(false) };
    if (plan === null) form.post(route('super.plans.store'), options);
    else form.put(route('super.plans.update', { plan: plan.code }), options);
  };

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    if (editing && subscribers > 0 && changes.length > 0) { setConfirmOpen(true); return; }
    send(false);
  };

  const setLimit = (key: string, next: number | null): void => form.setData('limits', { ...form.data.limits, [key]: next });
  const setToggle = (key: string, next: boolean): void => form.setData('toggles', { ...form.data.toggles, [key]: next });
  const yearlySaving = form.data.price_monthly_paisa * 12 - form.data.price_yearly_paisa;

  return (
    <Box>
      <Box component="form" onSubmit={submit} noValidate>
        <Stack spacing={2}>
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap' }} useFlexGap>
            <Box>
              <Typography variant="subtitle1" component="h2">{editing ? t('super.plans.edit_title', { name: plan.name }) : t('super.plans.create_title')}</Typography>
              {editing ? (
                <Typography variant="caption" color={subscribers > 0 ? 'warning.main' : 'text.secondary'}>
                  {subscribers > 0 ? t('super.plans.subscribers_note', { count: n(subscribers) }) : t('super.plans.no_subscribers_note')}
                </Typography>
              ) : null}
            </Box>
            <Stack direction="row" spacing={1}>
              <Button component={RouterLink} href={route('super.plans.index')}>{t('super.actions.cancel')}</Button>
              <Button type="submit" variant="contained" disabled={form.processing || form.data.code === '' || form.data.name === ''}>
                {editing ? t('super.actions.save') : t('super.plans.create')}
              </Button>
            </Stack>
          </Stack>

          {form.errors.acknowledge ? <Alert severity="warning">{form.errors.acknowledge}</Alert> : null}
          {plan?.archived_at ? <Alert severity="info">{t('super.plans.editing_archived')}</Alert> : null}

          <Card variant="outlined">
            <CardContent sx={{ display: 'grid', gap: 2 }}>
              <Typography variant="subtitle2">{t('super.plans.section.identity')}</Typography>
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                <TextField
                  label={t('super.plans.field.code')} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toLowerCase())}
                  error={Boolean(form.errors.code)} helperText={form.errors.code ?? t('super.plans.field.code_help')}
                  required autoFocus={!editing} fullWidth slotProps={{ htmlInput: { maxLength: 40, spellCheck: false } }}
                />
                <TextField
                  label={t('super.plans.field.name')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)}
                  error={Boolean(form.errors.name)} helperText={form.errors.name} required fullWidth slotProps={{ htmlInput: { maxLength: 80 } }}
                />
              </Stack>
              <TextField
                label={t('super.plans.field.description')} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)}
                error={Boolean(form.errors.description)} helperText={form.errors.description ?? t('super.plans.field.description_help')}
                multiline minRows={2} slotProps={{ htmlInput: { maxLength: 2000 } }}
              />
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} useFlexGap sx={{ flexWrap: 'wrap' }}>
                <FormControlLabel control={<Switch checked={form.data.is_public} onChange={(e) => form.setData('is_public', e.target.checked)} />} label={t('super.plans.field.is_public')} />
                <FormControlLabel control={<Switch checked={form.data.is_addon} onChange={(e) => form.setData('is_addon', e.target.checked)} />} label={t('super.plans.field.is_addon')} />
              </Stack>
              <Typography variant="caption" color="text.secondary">{t('super.plans.field.is_addon_help')}</Typography>
            </CardContent>
          </Card>

          <Card variant="outlined">
            <CardContent sx={{ display: 'grid', gap: 2 }}>
              <Typography variant="subtitle2">{t('super.plans.section.pricing')}</Typography>
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                <TextField
                  label={t('super.plans.field.monthly')} value={monthly}
                  onChange={(e) => { setMonthly(e.target.value); form.setData('price_monthly_paisa', takaToPaisa(e.target.value)); }}
                  error={Boolean(form.errors.price_monthly_paisa)}
                  helperText={form.errors.price_monthly_paisa ?? t('super.plans.field.stored_as', { amount: formatBdt(form.data.price_monthly_paisa, locale) })}
                  fullWidth slotProps={{ htmlInput: { inputMode: 'decimal' }, input: { startAdornment: <InputAdornment position="start">৳</InputAdornment> } }}
                />
                <TextField
                  label={t('super.plans.field.yearly')} value={yearly}
                  onChange={(e) => { setYearly(e.target.value); form.setData('price_yearly_paisa', takaToPaisa(e.target.value)); }}
                  error={Boolean(form.errors.price_yearly_paisa)}
                  helperText={form.errors.price_yearly_paisa ?? (yearlySaving > 0 ? t('super.plans.field.yearly_saves', { amount: formatBdt(yearlySaving, locale) }) : t('super.plans.field.stored_as', { amount: formatBdt(form.data.price_yearly_paisa, locale) }))}
                  fullWidth slotProps={{ htmlInput: { inputMode: 'decimal' }, input: { startAdornment: <InputAdornment position="start">৳</InputAdornment> } }}
                />
                <TextField
                  label={t('super.plans.field.trial_days')} value={String(form.data.trial_days)}
                  onChange={(e) => form.setData('trial_days', parseCount(e.target.value) ?? 0)}
                  error={Boolean(form.errors.trial_days)} helperText={form.errors.trial_days ?? t('super.plans.field.trial_help')}
                  fullWidth slotProps={{ htmlInput: { inputMode: 'numeric' } }}
                />
                <TextField
                  label={t('super.plans.field.sort_order')} value={String(form.data.sort_order)}
                  onChange={(e) => form.setData('sort_order', parseCount(e.target.value) ?? 0)}
                  error={Boolean(form.errors.sort_order)} helperText={form.errors.sort_order ?? t('super.plans.field.sort_help')}
                  fullWidth slotProps={{ htmlInput: { inputMode: 'numeric' } }}
                />
              </Stack>
              <Typography variant="caption" color="text.secondary">{t('super.plans.field.taka_help')}</Typography>
            </CardContent>
          </Card>

          <Card variant="outlined">
            <CardContent sx={{ display: 'grid', gap: 2 }}>
              <Box>
                <Typography variant="subtitle2">{t('super.plans.limits_title')}</Typography>
                <Typography variant="caption" color="text.secondary">{t('super.plans.limits_help')}</Typography>
              </Box>
              <Stack spacing={1.5} divider={<Divider flexItem />}>
                {limit_keys.map((key) => (
                  <LimitRow
                    key={key}
                    label={feature_labels[key] ?? key}
                    help={help[key] ?? ''}
                    value={form.data.limits[key] ?? null}
                    isBytes={isBytesKey(key)}
                    onChange={(next) => setLimit(key, next)}
                  />
                ))}
              </Stack>
            </CardContent>
          </Card>

          <Card variant="outlined">
            <CardContent sx={{ display: 'grid', gap: 1 }}>
              <Box>
                <Typography variant="subtitle2">{t('super.plans.modules_title')}</Typography>
                <Typography variant="caption" color="text.secondary">{t('super.plans.modules_help')}</Typography>
              </Box>
              <Box sx={{ display: 'grid', gap: 0.5, gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)', md: 'repeat(3, 1fr)' } }}>
                {toggle_keys.map((key) => (
                  <FormControlLabel
                    key={key}
                    control={<Switch checked={form.data.toggles[key] ?? false} onChange={(e) => setToggle(key, e.target.checked)} />}
                    label={feature_labels[key] ?? key}
                  />
                ))}
              </Box>
            </CardContent>
          </Card>

          <Card variant="outlined">
            <CardContent>
              <Typography variant="caption" color="text.secondary">{t('super.plans.limits_legend')}</Typography>
            </CardContent>
          </Card>
        </Stack>
      </Box>

      <Dialog open={confirmOpen} onClose={form.processing ? undefined : () => setConfirmOpen(false)} fullWidth maxWidth="md">
        <DialogTitle>{t('super.plans.diff.title')}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <DialogContentText>{t('super.plans.diff.body', { count: n(subscribers), name: plan?.name ?? '' })}</DialogContentText>
          <Alert severity="warning">{t('super.plans.diff.rule')}</Alert>
          <Box sx={{ overflowX: 'auto' }}>
            <ChangeList changes={changes} labels={feature_labels} locale={locale} />
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setConfirmOpen(false)} disabled={form.processing}>{t('super.actions.cancel')}</Button>
          <Button variant="contained" color="warning" onClick={() => send(true)} disabled={form.processing}>{t('super.plans.diff.confirm')}</Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
}

Edit.layout = (page: ReactNode) => <PanelLayout title="super.plans.title">{page}</PanelLayout>;
