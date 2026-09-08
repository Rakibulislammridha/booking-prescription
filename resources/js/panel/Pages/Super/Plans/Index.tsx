// The plan table IS the price list and the enforcement table at once (PlanCatalog): what is edited here is what
// the pricing page advertises and what PlanLimits enforces, so a cap can never be sold and not applied.
//
// Prices are typed in TAKA and converted to paisa on the way out — the operator thinks in taka, the wire is
// integer paisa, and nothing in between is ever a float.
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
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
import DialogTitle from '@mui/material/DialogTitle';
import Divider from '@mui/material/Divider';
import FormControlLabel from '@mui/material/FormControlLabel';
import Stack from '@mui/material/Stack';
import Switch from '@mui/material/Switch';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import AddIcon from '@mui/icons-material/Add';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { SuperTable, type SuperColumn } from '@panel/Components/Super/SuperTable';
import type { SuperPlan } from '@panel/Components/Super/types';
import { formatBdt, parseBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  plans: SuperPlan[];
  subscriptions: Record<string, number>;
  feature_labels: Record<string, string>;
  limit_keys: string[];
  toggle_keys: string[];
}>;

interface PlanFormData {
  code: string;
  name: string;
  description: string;
  price_monthly_paisa: number;
  price_yearly_paisa: number;
  trial_days: number;
  is_public: boolean;
  is_addon: boolean;
  sort_order: number;
  limits: Record<string, number | null>;
  toggles: Record<string, boolean>;
}

function emptyPlan(limitKeys: string[], toggleKeys: string[], sortOrder: number): PlanFormData {
  const limits: Record<string, number | null> = {};
  const toggles: Record<string, boolean> = {};
  for (const key of limitKeys) limits[key] = null;
  for (const key of toggleKeys) toggles[key] = false;

  return {
    code: '', name: '', description: '', price_monthly_paisa: 0, price_yearly_paisa: 0, trial_days: 0,
    is_public: true, is_addon: false, sort_order: sortOrder, limits, toggles,
  };
}

function fromPlan(plan: SuperPlan, limitKeys: string[], toggleKeys: string[], fallbackOrder: number): PlanFormData {
  const limits: Record<string, number | null> = {};
  const toggles: Record<string, boolean> = {};
  for (const key of limitKeys) limits[key] = plan.limits.find((row) => row.key === key)?.value ?? null;
  for (const key of toggleKeys) toggles[key] = plan.toggles.find((row) => row.key === key)?.enabled ?? false;

  return {
    code: plan.code,
    name: plan.name,
    description: plan.description ?? '',
    price_monthly_paisa: plan.price_monthly_paisa,
    price_yearly_paisa: plan.price_yearly_paisa,
    trial_days: plan.trial_days,
    is_public: plan.is_public ?? true,
    is_addon: plan.is_addon,
    sort_order: plan.sort_order ?? fallbackOrder,
    limits,
    toggles,
  };
}

function PlanDialog({ open, plan, limitKeys, toggleKeys, featureLabels, nextOrder, onClose }: {
  open: boolean;
  plan: SuperPlan | null;
  limitKeys: string[];
  toggleKeys: string[];
  featureLabels: Record<string, string>;
  nextOrder: number;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const form = useForm<PlanFormData>(emptyPlan(limitKeys, toggleKeys, nextOrder));
  const [monthly, setMonthly] = useState('0');
  const [yearly, setYearly] = useState('0');

  useEffect(() => {
    if (!open) return;
    const next = plan === null ? emptyPlan(limitKeys, toggleKeys, nextOrder) : fromPlan(plan, limitKeys, toggleKeys, nextOrder);
    form.setData(next);
    form.clearErrors();
    setMonthly(String(next.price_monthly_paisa / 100));
    setYearly(String(next.price_yearly_paisa / 100));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, plan]);

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    const options = { preserveScroll: true, onSuccess: onClose };
    if (plan === null) form.post(route('super.plans.store'), options);
    else form.put(route('super.plans.update', { plan: plan.code }), options);
  };

  const setLimit = (key: string, raw: string): void => {
    const trimmed = raw.trim();
    const value = trimmed === '' ? null : Math.max(0, Math.trunc(Number(trimmed)));
    form.setData('limits', { ...form.data.limits, [key]: Number.isFinite(value ?? 0) ? value : null });
  };

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="md">
      <Box component="form" onSubmit={submit} noValidate>
        <DialogTitle>{plan === null ? t('super.plans.create_title') : t('super.plans.edit_title', { name: plan.name })}</DialogTitle>
        <DialogContent sx={{ display: 'grid', gap: 2, pt: 1 }}>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField
              label={t('super.plans.field.code')} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)}
              error={Boolean(form.errors.code)} helperText={form.errors.code ?? t('super.plans.field.code_help')}
              required autoFocus fullWidth slotProps={{ htmlInput: { maxLength: 40, spellCheck: false } }}
            />
            <TextField
              label={t('super.plans.field.name')} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)}
              error={Boolean(form.errors.name)} helperText={form.errors.name} required fullWidth
              slotProps={{ htmlInput: { maxLength: 80 } }}
            />
          </Stack>

          <TextField
            label={t('super.plans.field.description')} value={form.data.description}
            onChange={(e) => form.setData('description', e.target.value)}
            error={Boolean(form.errors.description)} helperText={form.errors.description}
            multiline minRows={2} slotProps={{ htmlInput: { maxLength: 2000 } }}
          />

          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField
              label={t('super.plans.field.monthly')} value={monthly}
              onChange={(e) => { setMonthly(e.target.value); form.setData('price_monthly_paisa', parseBdt(e.target.value) ?? 0); }}
              error={Boolean(form.errors.price_monthly_paisa)} helperText={form.errors.price_monthly_paisa ?? t('super.plans.field.taka_help')}
              fullWidth slotProps={{ htmlInput: { inputMode: 'decimal' } }}
            />
            <TextField
              label={t('super.plans.field.yearly')} value={yearly}
              onChange={(e) => { setYearly(e.target.value); form.setData('price_yearly_paisa', parseBdt(e.target.value) ?? 0); }}
              error={Boolean(form.errors.price_yearly_paisa)} helperText={form.errors.price_yearly_paisa ?? t('super.plans.field.taka_help')}
              fullWidth slotProps={{ htmlInput: { inputMode: 'decimal' } }}
            />
            <TextField
              label={t('super.plans.field.trial_days')} value={String(form.data.trial_days)}
              onChange={(e) => form.setData('trial_days', Math.max(0, Math.trunc(Number(e.target.value) || 0)))}
              error={Boolean(form.errors.trial_days)} helperText={form.errors.trial_days}
              fullWidth slotProps={{ htmlInput: { inputMode: 'numeric' } }}
            />
            <TextField
              label={t('super.plans.field.sort_order')} value={String(form.data.sort_order)}
              onChange={(e) => form.setData('sort_order', Math.max(0, Math.trunc(Number(e.target.value) || 0)))}
              error={Boolean(form.errors.sort_order)} helperText={form.errors.sort_order}
              fullWidth slotProps={{ htmlInput: { inputMode: 'numeric' } }}
            />
          </Stack>

          <Stack direction="row" spacing={2}>
            <FormControlLabel
              control={<Switch checked={form.data.is_public} onChange={(e) => form.setData('is_public', e.target.checked)} />}
              label={t('super.plans.field.is_public')}
            />
            <FormControlLabel
              control={<Switch checked={form.data.is_addon} onChange={(e) => form.setData('is_addon', e.target.checked)} />}
              label={t('super.plans.field.is_addon')}
            />
          </Stack>

          <Divider />

          <Box>
            <Typography variant="subtitle2">{t('super.plans.limits_title')}</Typography>
            <Typography variant="caption" color="text.secondary">{t('super.plans.limits_help')}</Typography>
            <Box sx={{ display: 'grid', gap: 1.5, mt: 1, gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)' } }}>
              {limitKeys.map((key) => (
                <TextField
                  key={key}
                  size="small"
                  label={featureLabels[key] ?? key}
                  value={form.data.limits[key] === null || form.data.limits[key] === undefined ? '' : String(form.data.limits[key])}
                  onChange={(e) => setLimit(key, e.target.value)}
                  placeholder={t('super.plans.unlimited_placeholder')}
                  slotProps={{ htmlInput: { inputMode: 'numeric' } }}
                />
              ))}
            </Box>
          </Box>

          <Divider />

          <Box>
            <Typography variant="subtitle2">{t('super.plans.modules_title')}</Typography>
            <Box sx={{ display: 'grid', gap: 0.5, mt: 1, gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)' } }}>
              {toggleKeys.map((key) => (
                <FormControlLabel
                  key={key}
                  control={(
                    <Switch
                      checked={form.data.toggles[key] ?? false}
                      onChange={(e) => form.setData('toggles', { ...form.data.toggles, [key]: e.target.checked })}
                    />
                  )}
                  label={featureLabels[key] ?? key}
                />
              ))}
            </Box>
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={onClose}>{t('super.actions.cancel')}</Button>
          <Button type="submit" variant="contained" disabled={form.processing || form.data.code === '' || form.data.name === ''}>
            {t('super.actions.save')}
          </Button>
        </DialogActions>
      </Box>
    </Dialog>
  );
}

export default function Index({ plans, subscriptions, feature_labels, limit_keys, toggle_keys }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [editing, setEditing] = useState<SuperPlan | null>(null);

  const openCreate = (): void => { setEditing(null); setDialogOpen(true); };
  const openEdit = (plan: SuperPlan): void => { setEditing(plan); setDialogOpen(true); };

  const archive = (plan: SuperPlan): void => {
    router.delete(route('super.plans.destroy', { plan: plan.code }), { preserveScroll: true });
  };
  const restore = (plan: SuperPlan): void => {
    router.delete(route('super.plans.destroy', { plan: plan.code }), { data: { restore: true }, preserveScroll: true });
  };

  const columns: SuperColumn<SuperPlan>[] = [
    {
      key: 'plan',
      label: t('super.plans.column.plan'),
      render: (plan) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>{plan.name}</Typography>
          <Typography variant="caption" color="text.secondary" sx={{ fontFamily: 'monospace' }}>{plan.code}</Typography>
        </Box>
      ),
    },
    {
      key: 'flags',
      label: t('super.plans.column.flags'),
      render: (plan) => (
        <Stack direction="row" spacing={0.5} useFlexGap sx={{ flexWrap: 'wrap' }}>
          {plan.is_public === false ? <Chip size="small" variant="outlined" label={t('super.plans.private')} /> : null}
          {plan.is_addon ? <Chip size="small" variant="outlined" color="info" label={t('super.plans.addon')} /> : null}
          {plan.archived_at ? <Chip size="small" color="default" label={t('super.plans.archived')} /> : null}
          {plan.is_featured ? <Chip size="small" color="primary" variant="outlined" label={t('super.plans.featured')} /> : null}
        </Stack>
      ),
    },
    { key: 'monthly', label: t('super.plans.column.monthly'), align: 'right', render: (plan) => formatBdt(plan.price_monthly_paisa, locale) },
    { key: 'yearly', label: t('super.plans.column.yearly'), align: 'right', render: (plan) => formatBdt(plan.price_yearly_paisa, locale) },
    { key: 'trial', label: t('super.plans.column.trial'), align: 'right', render: (plan) => formatNumber(plan.trial_days, locale) },
    { key: 'subs', label: t('super.plans.column.subscriptions'), align: 'right', render: (plan) => formatNumber(subscriptions[plan.code] ?? 0, locale) },
    {
      key: 'actions',
      label: t('super.plans.column.actions'),
      align: 'right',
      render: (plan) => (
        <Stack direction="row" spacing={0.5} sx={{ justifyContent: 'flex-end' }}>
          <Button size="small" onClick={() => openEdit(plan)}>{t('super.actions.edit')}</Button>
          {plan.archived_at ? (
            <Button size="small" color="success" onClick={() => restore(plan)}>{t('super.plans.restore')}</Button>
          ) : (
            <Button size="small" color="error" onClick={() => archive(plan)}>{t('super.plans.archive')}</Button>
          )}
        </Stack>
      ),
    },
  ];

  const nextOrder = plans.length * 10;

  return (
    <Box>
      <SuperNav />

      <Stack spacing={2}>
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'space-between' }}>
          <Typography variant="subtitle1" component="h2">{t('super.plans.title')}</Typography>
          <Button variant="contained" startIcon={<AddIcon />} onClick={openCreate}>{t('super.plans.create')}</Button>
        </Stack>

        <Alert severity="info">{t('super.plans.archive_note')}</Alert>

        <Card variant="outlined">
          <SuperTable columns={columns} rows={plans} rowKey={(plan) => plan.code} empty={t('super.plans.empty')} label={t('super.plans.title')} />
        </Card>

        <Card variant="outlined">
          <CardContent>
            <Typography variant="caption" color="text.secondary">{t('super.plans.limits_legend')}</Typography>
          </CardContent>
        </Card>
      </Stack>

      <PlanDialog
        open={dialogOpen}
        plan={editing}
        limitKeys={limit_keys}
        toggleKeys={toggle_keys}
        featureLabels={feature_labels}
        nextOrder={nextOrder}
        onClose={() => setDialogOpen(false)}
      />
    </Box>
  );
}

Index.layout = (page: ReactNode) => <PanelLayout title="super.plans.title">{page}</PanelLayout>;
