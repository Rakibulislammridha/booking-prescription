// Onboarding a clinic from the console — the operator's copy of the public sign-up wizard, on one page, with the
// things an operator knows that a customer does not: a Bangla name, a hand-negotiated trial, a domain agreed in
// advance, and how the owner gets their first password. It posts to the same `ProvisionTenant` path the wizard
// uses; there is no second way to make a schema.
import { useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import Alert from '@mui/material/Alert';
import Autocomplete from '@mui/material/Autocomplete';
import Box from '@mui/material/Box';
import Button from '@mui/material/Button';
import Card from '@mui/material/Card';
import CardContent from '@mui/material/CardContent';
import Checkbox from '@mui/material/Checkbox';
import FormControlLabel from '@mui/material/FormControlLabel';
import MenuItem from '@mui/material/MenuItem';
import Stack from '@mui/material/Stack';
import TextField from '@mui/material/TextField';
import Typography from '@mui/material/Typography';
import { PanelLayout } from '@panel/Layouts/PanelLayout';
import { RouterLink } from '@panel/Layouts/RouterLink';
import { SuperNav } from '@panel/Components/Super/SuperNav';
import { CredentialChoice } from '@panel/Components/Super/Tenants/CredentialChoice';
import { SlugField, normaliseSlug } from '@panel/Components/Super/Tenants/SlugField';
import type { CredentialKind, SlugAvailability } from '@panel/Components/Super/Tenants/types';
import type { SuperPlan } from '@panel/Components/Super/types';
import { formatNumber } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  plans: SuperPlan[];
  central_domain: string;
  reserved_slugs: string[];
  timezones: string[];
  roles: string[];
}>;

interface CreateForm {
  name: string;
  name_bn: string;
  slug: string;
  plan: string;
  trial_days: string;
  locale: 'bn' | 'en';
  timezone: string;
  branch_name: string;
  owner_name: string;
  owner_email: string;
  owner_mobile: string;
  admin_name: string;
  admin_email: string;
  credential: CredentialKind;
  password: string;
  demo: boolean;
  custom_domain: string;
  notes: string;
}

function Section({ title, help, children }: { title: string; help?: string; children: ReactNode }) {
  return (
    <Card variant="outlined">
      <CardContent sx={{ display: 'grid', gap: 2 }}>
        <Box>
          <Typography variant="subtitle1" component="h3">{title}</Typography>
          {help ? <Typography variant="caption" color="text.secondary">{help}</Typography> : null}
        </Box>
        {children}
      </CardContent>
    </Card>
  );
}

export default function Create({ plans, central_domain, timezones }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [slugTouched, setSlugTouched] = useState(false);
  const [availability, setAvailability] = useState<SlugAvailability>('idle');
  const defaultPlan = plans[0]?.code ?? '';
  const form = useForm<CreateForm>({
    name: '', name_bn: '', slug: '', plan: defaultPlan, trial_days: '', locale: 'bn', timezone: 'Asia/Dhaka', branch_name: '',
    owner_name: '', owner_email: '', owner_mobile: '', admin_name: '', admin_email: '', credential: 'link', password: '',
    demo: false, custom_domain: '', notes: '',
  });
  const plan = useMemo(() => plans.find((p) => p.code === form.data.plan), [plans, form.data.plan]);

  // The slug follows the English name until the operator edits it by hand.
  const onName = (name: string): void => {
    form.setData((data) => ({ ...data, name, slug: slugTouched ? data.slug : normaliseSlug(name).replace(/^-+|-+$/g, '').slice(0, 63) }));
  };

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    form.post(route('super.tenants.store'));
  };

  const blocked = availability === 'taken' || availability === 'reserved' || availability === 'invalid' || availability === 'checking';

  return (
    <Box>
      <SuperNav />
      <Box component="form" onSubmit={submit} noValidate data-testid="create-tenant-form">
        <Stack spacing={2}>
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'space-between', flexWrap: 'wrap' }} useFlexGap>
            <Box>
              <Typography variant="h5" component="h2">{t('super.tenants.create_title')}</Typography>
              <Typography variant="body2" color="text.secondary">{t('super.tenants.create_intro')}</Typography>
            </Box>
            <Button component={RouterLink} href={route('super.tenants.index')} color="inherit">{t('super.tenants.back_to_list')}</Button>
          </Stack>

          {form.errors.name && form.errors.name === t('saas.onboarding.failed') ? <Alert severity="error">{form.errors.name}</Alert> : null}

          <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', lg: '1fr 1fr' } }}>
            <Section title={t('super.tenants.form.section.clinic')}>
              <TextField label={t('super.tenants.form.name')} value={form.data.name} onChange={(e) => onName(e.target.value)} error={Boolean(form.errors.name)} helperText={form.errors.name} required autoFocus slotProps={{ htmlInput: { maxLength: 160, 'data-testid': 'name-input' } }} />
              <TextField label={t('super.tenants.form.name_bn')} value={form.data.name_bn} onChange={(e) => form.setData('name_bn', e.target.value)} error={Boolean(form.errors.name_bn)} helperText={form.errors.name_bn ?? t('super.tenants.form.name_bn_help')} slotProps={{ htmlInput: { maxLength: 200, lang: 'bn' } }} />
              <SlugField value={form.data.slug} onChange={(slug) => { setSlugTouched(true); form.setData('slug', slug); }} centralDomain={central_domain} error={form.errors.slug} onAvailability={setAvailability} />
              <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                <TextField select label={t('super.tenants.form.locale')} value={form.data.locale} onChange={(e) => form.setData('locale', e.target.value as 'bn' | 'en')} fullWidth>
                  <MenuItem value="bn">বাংলা</MenuItem>
                  <MenuItem value="en">English</MenuItem>
                </TextField>
                <Autocomplete
                  options={timezones}
                  value={form.data.timezone}
                  onChange={(_e, value) => form.setData('timezone', value ?? 'Asia/Dhaka')}
                  fullWidth
                  disableClearable
                  renderInput={(params) => <TextField {...params} label={t('super.tenants.form.timezone')} error={Boolean(form.errors.timezone)} helperText={form.errors.timezone} />}
                />
              </Stack>
              <TextField label={t('super.tenants.form.branch_name')} value={form.data.branch_name} onChange={(e) => form.setData('branch_name', e.target.value)} error={Boolean(form.errors.branch_name)} helperText={form.errors.branch_name ?? t('super.tenants.form.branch_name_help')} slotProps={{ htmlInput: { maxLength: 160, lang: 'bn' } }} />
            </Section>

            <Section title={t('super.tenants.form.section.plan')}>
              <TextField select label={t('super.tenants.form.plan')} value={form.data.plan} onChange={(e) => form.setData('plan', e.target.value)} error={Boolean(form.errors.plan)} helperText={form.errors.plan ?? (plan ? t('super.tenants.form.plan_trial', { days: formatNumber(plan.trial_days, locale) }) : undefined)} required slotProps={{ htmlInput: { 'data-testid': 'plan-select' } }}>
                {plans.map((p) => <MenuItem key={p.code} value={p.code}>{p.name}{p.is_public === false ? ` · ${t('super.tenants.form.plan_private')}` : ''}</MenuItem>)}
              </TextField>
              <TextField label={t('super.tenants.form.trial_days')} value={form.data.trial_days} onChange={(e) => form.setData('trial_days', e.target.value.replace(/[^0-9]/g, ''))} error={Boolean(form.errors.trial_days)} helperText={form.errors.trial_days ?? t('super.tenants.form.trial_days_help', { days: formatNumber(plan?.trial_days ?? 0, locale) })} slotProps={{ htmlInput: { inputMode: 'numeric', maxLength: 3 } }} />
              <FormControlLabel control={<Checkbox checked={form.data.demo} onChange={(e) => form.setData('demo', e.target.checked)} />} label={t('super.tenants.form.demo')} />
              <Typography variant="caption" color="text.secondary" sx={{ mt: -1.5 }}>{t('super.tenants.form.demo_help')}</Typography>
            </Section>

            <Section title={t('super.tenants.form.section.owner')} help={t('super.tenants.form.section.owner_help')}>
              <TextField label={t('super.tenants.form.owner_name')} value={form.data.owner_name} onChange={(e) => form.setData('owner_name', e.target.value)} error={Boolean(form.errors.owner_name)} helperText={form.errors.owner_name} required slotProps={{ htmlInput: { maxLength: 160, lang: 'bn', 'data-testid': 'owner-name' } }} />
              <TextField label={t('super.tenants.form.owner_email')} type="email" value={form.data.owner_email} onChange={(e) => form.setData('owner_email', e.target.value)} error={Boolean(form.errors.owner_email)} helperText={form.errors.owner_email} required slotProps={{ htmlInput: { maxLength: 255, 'data-testid': 'owner-email' } }} />
              <TextField label={t('super.tenants.form.owner_mobile')} value={form.data.owner_mobile} onChange={(e) => form.setData('owner_mobile', e.target.value)} error={Boolean(form.errors.owner_mobile)} helperText={form.errors.owner_mobile ?? t('super.tenants.form.mobile_help')} required slotProps={{ htmlInput: { maxLength: 20, inputMode: 'tel', 'data-testid': 'owner-mobile' } }} />
            </Section>

            <Section title={t('super.tenants.form.section.admin')} help={t('super.tenants.form.section.admin_help')}>
              <TextField label={t('super.tenants.form.admin_name')} value={form.data.admin_name} onChange={(e) => form.setData('admin_name', e.target.value)} error={Boolean(form.errors.admin_name)} helperText={form.errors.admin_name ?? t('super.tenants.form.admin_name_help')} slotProps={{ htmlInput: { maxLength: 160, lang: 'bn' } }} />
              <TextField label={t('super.tenants.form.admin_email')} type="email" value={form.data.admin_email} onChange={(e) => form.setData('admin_email', e.target.value)} error={Boolean(form.errors.admin_email)} helperText={form.errors.admin_email ?? t('super.tenants.form.admin_email_help')} slotProps={{ htmlInput: { maxLength: 255 } }} />
              <CredentialChoice kind={form.data.credential} onKind={(kind) => form.setData('credential', kind)} password={form.data.password} onPassword={(p) => form.setData('password', p)} error={form.errors.password} />
            </Section>

            <Section title={t('super.tenants.form.section.domain')} help={t('super.tenants.form.section.domain_help')}>
              <TextField label={t('super.tenants.form.custom_domain')} value={form.data.custom_domain} onChange={(e) => form.setData('custom_domain', e.target.value)} error={Boolean(form.errors.custom_domain)} helperText={form.errors.custom_domain ?? t('super.tenants.form.custom_domain_help')} slotProps={{ htmlInput: { maxLength: 253, spellCheck: false, autoCapitalize: 'none' } }} />
            </Section>

            <Section title={t('super.tenants.form.section.notes')}>
              <TextField label={t('super.tenants.form.notes')} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} error={Boolean(form.errors.notes)} helperText={form.errors.notes ?? t('super.tenants.form.notes_help')} multiline minRows={3} slotProps={{ htmlInput: { maxLength: 5000 } }} />
            </Section>
          </Box>

          <Stack direction="row" spacing={1} sx={{ justifyContent: 'flex-end' }}>
            <Button component={RouterLink} href={route('super.tenants.index')} color="inherit">{t('super.actions.cancel')}</Button>
            <Button type="submit" variant="contained" size="large" disabled={form.processing || blocked || form.data.name.trim() === '' || form.data.slug.trim() === ''} data-testid="create-submit">
              {t(form.processing ? 'super.tenants.form.creating' : 'super.tenants.form.create')}
            </Button>
          </Stack>
        </Stack>
      </Box>
    </Box>
  );
}

Create.layout = (page: ReactNode) => <PanelLayout title="super.tenants.create_title">{page}</PanelLayout>;
