// Signup wizard (Inertia::render('Central/Onboarding/Signup')): four client-side steps over ONE useForm that
// posts exactly once, to `links.signup_store`. Provisioning a tenant takes several seconds (schema + seed), so
// the submit button says so and stays disabled while `form.processing`.
//
// Validation is belt and braces: each step refuses to advance while a required field on it is empty (or the two
// passwords differ), and when the server sends its own errors back the wizard jumps to the earliest step that
// owns one — otherwise a rejected email on step 1 would be invisible from step 4.
import { useEffect, useRef, useState, type FormEvent, type MouseEvent, type ReactNode } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { CentralLayout } from '@site/Components/Central/CentralLayout';
import { makeCopy, type Copy } from '@site/Components/Central/copy';
import type { CentralLinks, PlatformProps, PricingPlan } from '@site/Components/Central/types';
import { formatBdt } from '@shared/format/money';
import { formatNumber } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import type { Locale } from '@shared/types/shared-props';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  copy: Copy;
  plans: PricingPlan[];
  links: CentralLinks;
  platform?: PlatformProps;
  central_domain: string;
  selected_plan: string | null;
  slug_suggestion: string;
  /** The platform's defaults for the fields most clinics never touch (`onboarding.default_*`, `onboarding.trial_days`). */
  defaults?: { locale: Locale; timezone: string; trial_days: number };
}>;

interface SignupForm {
  owner_name: string;
  owner_email: string;
  owner_mobile: string;
  password: string;
  password_confirmation: string;
  clinic_name: string;
  slug: string;
  branch_name: string;
  locale: Locale;
  timezone: string;
  plan: string;
  demo: boolean;
  [key: string]: string | boolean;
}

type FieldName = 'owner_name' | 'owner_email' | 'owner_mobile' | 'password' | 'password_confirmation'
  | 'clinic_name' | 'slug' | 'branch_name' | 'locale' | 'timezone' | 'plan' | 'demo';

const TOTAL_STEPS = 4;
const BYTES_PER_GB = 1024 * 1024 * 1024;

const STEP_TITLES = ['onboarding.step.account', 'onboarding.step.clinic', 'onboarding.step.plan', 'onboarding.step.review'] as const;

/** Which fields each step owns — used both to route server errors and to validate before Next. */
const STEP_FIELDS: readonly (readonly FieldName[])[] = [
  ['owner_name', 'owner_email', 'owner_mobile', 'password', 'password_confirmation'],
  ['clinic_name', 'slug', 'branch_name', 'locale', 'timezone'],
  ['plan', 'demo'],
  [],
];

const REQUIRED_FIELDS: readonly (readonly FieldName[])[] = [
  ['owner_name', 'owner_email', 'owner_mobile', 'password', 'password_confirmation'],
  ['clinic_name', 'slug', 'branch_name'],
  ['plan'],
  [],
];

const INPUT = 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-base text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30 aria-[invalid=true]:border-red-500';

/** "Dhanmondi Medical Centre" → "dhanmondi-medical-centre". Latin/digits only; Bangla names yield "". */
function slugify(value: string): string {
  return value
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 40);
}

export default function Signup({ plans, links, platform, central_domain, selected_plan, slug_suggestion, defaults, copy }: Props) {
  const c = makeCopy(copy);
  const locale = getLocale();
  const signupOpen = platform?.signup_open ?? true;
  const choosable = plans.filter((plan) => !plan.is_addon);
  const preselected = choosable.find((plan) => plan.code === selected_plan)
    ?? choosable.find((plan) => plan.is_featured)
    ?? choosable[0];

  const form = useForm<SignupForm>({
    owner_name: '',
    owner_email: '',
    owner_mobile: '',
    password: '',
    password_confirmation: '',
    clinic_name: '',
    slug: slug_suggestion,
    branch_name: '',
    // The clinic's own language defaults to the platform's `onboarding.default_locale`, not the visitor's toggle:
    // a manager reading the marketing page in English usually still runs a Bangla front desk.
    locale: defaults?.locale ?? locale,
    timezone: defaults?.timezone ?? 'Asia/Dhaka',
    plan: preselected?.code ?? '',
    demo: false,
  });

  const [step, setStep] = useState(0);
  const [slugTouched, setSlugTouched] = useState(slug_suggestion !== '');
  const [localErrors, setLocalErrors] = useState<Partial<Record<FieldName, string>>>({});
  const headingRef = useRef<HTMLHeadingElement>(null);

  const serverErrorKeys = Object.keys(form.errors).join(',');

  // Server-side rejection: land on the earliest step that owns a failing field.
  useEffect(() => {
    if (serverErrorKeys === '') return;
    const keys = serverErrorKeys.split(',');
    const target = STEP_FIELDS.findIndex((fields) => fields.some((field) => keys.includes(field)));
    if (target >= 0) setStep(target);
  }, [serverErrorKeys]);

  const errorFor = (field: FieldName): string | undefined => localErrors[field] ?? form.errors[field];

  const described = (field: FieldName, hasHint: boolean): string | undefined => {
    const ids: string[] = [];
    if (hasHint) ids.push(`${field}-hint`);
    if (errorFor(field) !== undefined) ids.push(`${field}-error`);
    return ids.length > 0 ? ids.join(' ') : undefined;
  };

  const setField = (field: FieldName, value: string | boolean): void => {
    form.setData(field, value);
    setLocalErrors((current) => {
      if (current[field] === undefined) return current;
      const next = { ...current };
      delete next[field];
      return next;
    });
  };

  const onClinicName = (value: string): void => {
    setField('clinic_name', value);
    if (!slugTouched) setField('slug', slugify(value));
  };

  const validate = (index: number): boolean => {
    const found: Partial<Record<FieldName, string>> = {};
    for (const field of REQUIRED_FIELDS[index] ?? []) {
      if (String(form.data[field] ?? '').trim() === '') found[field] = c('onboarding.error.required');
    }
    if (index === 0 && found.password === undefined && found.password_confirmation === undefined
      && form.data.password !== form.data.password_confirmation) {
      found.password_confirmation = c('onboarding.error.password_mismatch');
    }
    setLocalErrors(found);
    return Object.keys(found).length === 0;
  };

  const goTo = (index: number): void => {
    setStep(index);
    window.requestAnimationFrame(() => headingRef.current?.focus());
  };

  /**
   * `event.preventDefault()` is load-bearing, not decoration. React reconciles the Next button and the Submit
   * button as the SAME DOM node (same position, same tag), so advancing to the last step flips that node's
   * `type` from `button` to `submit` while the browser is still processing the very click that advanced it —
   * and the click's default action then submits the form, skipping the review step entirely. Preventing the
   * default on that click, and giving the two buttons distinct keys so React mounts a fresh node, both close it.
   */
  const next = (event?: MouseEvent<HTMLButtonElement>): void => {
    event?.preventDefault();

    if (validate(step)) goTo(Math.min(step + 1, TOTAL_STEPS - 1));
  };

  const back = (): void => {
    setLocalErrors({});
    goTo(Math.max(step - 1, 0));
  };

  const submit = (event: FormEvent<HTMLFormElement>): void => {
    event.preventDefault();
    if (step < TOTAL_STEPS - 1) { next(); return; }
    for (let index = 0; index < TOTAL_STEPS; index += 1) {
      if (!validate(index)) { goTo(index); return; }
    }
    setLocalErrors({});
    form.post(links.signup_store);
  };

  const planLimit = (limit: PricingPlan['limits'][number]): string => {
    if (limit.value === null) return c('pricing.unlimited');
    if (limit.value === 0) return c('pricing.not_included');
    if (limit.is_bytes) return c('pricing.gb', { size: formatNumber(limit.value / BYTES_PER_GB, locale) });
    return formatNumber(limit.value, locale);
  };

  const chosen = choosable.find((plan) => plan.code === form.data.plan);
  const address = `${form.data.slug === '' ? c('onboarding.slug_placeholder') : form.data.slug}.${central_domain}`;

  // A plain function rather than a nested component: a component declared in render is a new type every
  // render, and React would remount the message each keystroke.
  const fieldError = (field: FieldName): ReactNode => {
    const message = errorFor(field);
    return message === undefined ? null : <p id={`${field}-error`} className="text-xs font-semibold text-red-700">{message}</p>;
  };

  if (!signupOpen) {
    // `onboarding.signup_open` is off: the operator's message replaces the wizard (the POST is refused too).
    return (
      <div className="mx-auto max-w-2xl px-4 py-8 md:py-12">
        <header>
          <h1 className="text-2xl font-black tracking-tight md:text-3xl">{c('onboarding.title')}</h1>
        </header>
        <p role="status" data-testid="signup-closed" className="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-base text-amber-900">
          {platform?.signup_closed_message ?? ''}
        </p>
        <p className="mt-4 text-sm text-slate-600">
          <Link href={links.pricing} className="font-semibold text-primary underline-offset-4 hover:underline">{c('nav.pricing')}</Link>
        </p>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-2xl px-4 py-8 md:py-12">
      <header>
        <h1 className="text-2xl font-black tracking-tight md:text-3xl">{c('onboarding.title')}</h1>
        <p className="mt-2 text-slate-600">{c('onboarding.subtitle')}</p>
      </header>

      <ol className="mt-6 grid grid-cols-4 gap-1.5" aria-label={c('onboarding.progress_label')}>
        {STEP_TITLES.map((key, index) => (
          <li
            key={key}
            aria-current={index === step ? 'step' : undefined}
            className={`border-t-4 pt-2 text-xs font-semibold ${index === step ? 'border-primary text-primary' : index < step ? 'border-primary/40 text-slate-600' : 'border-slate-200 text-slate-400'}`}
          >
            <span className="block">{formatNumber(index + 1, locale)}</span>
            <span className="block leading-snug">{c(key)}</span>
          </li>
        ))}
      </ol>
      <p className="mt-2 text-xs text-slate-500" aria-live="polite">
        {c('onboarding.step_of', { current: formatNumber(step + 1, locale), total: formatNumber(TOTAL_STEPS, locale) })}
      </p>

      <form onSubmit={submit} noValidate className="mt-5 rounded-2xl border border-slate-200 bg-white p-5 md:p-6">
        <h2 ref={headingRef} tabIndex={-1} className="text-lg font-bold outline-none">{c(STEP_TITLES[step] ?? STEP_TITLES[0])}</h2>

        {serverErrorKeys !== '' ? (
          <p role="alert" className="mt-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-800">
            {c('onboarding.error.fix_below')}
          </p>
        ) : null}

        {step === 0 ? (
          <div className="mt-4 grid gap-4">
            <div className="grid gap-1.5">
              <label htmlFor="owner_name" className="text-sm font-semibold text-slate-800">{c('onboarding.field.owner_name')}</label>
              <input id="owner_name" name="owner_name" type="text" autoComplete="name" className={INPUT}
                value={form.data.owner_name} onChange={(e) => setField('owner_name', e.target.value)}
                aria-invalid={errorFor('owner_name') !== undefined} aria-describedby={described('owner_name', false)} />
              {fieldError('owner_name')}
            </div>

            <div className="grid gap-1.5">
              <label htmlFor="owner_email" className="text-sm font-semibold text-slate-800">{c('onboarding.field.owner_email')}</label>
              <input id="owner_email" name="owner_email" type="email" inputMode="email" autoComplete="email" className={INPUT}
                value={form.data.owner_email} onChange={(e) => setField('owner_email', e.target.value)}
                aria-invalid={errorFor('owner_email') !== undefined} aria-describedby={described('owner_email', true)} />
              <p id="owner_email-hint" className="text-xs text-slate-500">{c('onboarding.help.owner_email')}</p>
              {fieldError('owner_email')}
            </div>

            <div className="grid gap-1.5">
              <label htmlFor="owner_mobile" className="text-sm font-semibold text-slate-800">{c('onboarding.field.owner_mobile')}</label>
              <input id="owner_mobile" name="owner_mobile" type="tel" inputMode="tel" autoComplete="tel" placeholder="+8801XXXXXXXXX" className={INPUT}
                value={form.data.owner_mobile} onChange={(e) => setField('owner_mobile', e.target.value)}
                aria-invalid={errorFor('owner_mobile') !== undefined} aria-describedby={described('owner_mobile', true)} />
              <p id="owner_mobile-hint" className="text-xs text-slate-500">{c('onboarding.help.owner_mobile')}</p>
              {fieldError('owner_mobile')}
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
              <div className="grid gap-1.5">
                <label htmlFor="password" className="text-sm font-semibold text-slate-800">{c('onboarding.field.password')}</label>
                <input id="password" name="password" type="password" autoComplete="new-password" className={INPUT}
                  value={form.data.password} onChange={(e) => setField('password', e.target.value)}
                  aria-invalid={errorFor('password') !== undefined} aria-describedby={described('password', true)} />
                <p id="password-hint" className="text-xs text-slate-500">{c('onboarding.help.password')}</p>
                {fieldError('password')}
              </div>
              <div className="grid gap-1.5">
                <label htmlFor="password_confirmation" className="text-sm font-semibold text-slate-800">{c('onboarding.field.password_confirmation')}</label>
                <input id="password_confirmation" name="password_confirmation" type="password" autoComplete="new-password" className={INPUT}
                  value={form.data.password_confirmation} onChange={(e) => setField('password_confirmation', e.target.value)}
                  aria-invalid={errorFor('password_confirmation') !== undefined} aria-describedby={described('password_confirmation', false)} />
                {fieldError('password_confirmation')}
              </div>
            </div>
          </div>
        ) : null}

        {step === 1 ? (
          <div className="mt-4 grid gap-4">
            <div className="grid gap-1.5">
              <label htmlFor="clinic_name" className="text-sm font-semibold text-slate-800">{c('onboarding.field.clinic_name')}</label>
              <input id="clinic_name" name="clinic_name" type="text" autoComplete="organization" className={INPUT}
                value={form.data.clinic_name} onChange={(e) => onClinicName(e.target.value)}
                aria-invalid={errorFor('clinic_name') !== undefined} aria-describedby={described('clinic_name', false)} />
              {fieldError('clinic_name')}
            </div>

            <div className="grid gap-1.5">
              <label htmlFor="slug" className="text-sm font-semibold text-slate-800">{c('onboarding.field.slug')}</label>
              <input id="slug" name="slug" type="text" inputMode="url" autoCapitalize="none" autoCorrect="off" spellCheck={false} className={INPUT}
                value={form.data.slug}
                onChange={(e) => { setSlugTouched(true); setField('slug', slugify(e.target.value)); }}
                aria-invalid={errorFor('slug') !== undefined} aria-describedby={described('slug', true)} />
              <p id="slug-hint" className="text-xs text-slate-500">{c('onboarding.help.slug')}</p>
              <p className="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-700">
                {c('onboarding.address_preview')}{' '}
                <span dir="ltr" className="font-bold text-slate-900">{address}</span>
              </p>
              {fieldError('slug')}
            </div>

            <div className="grid gap-1.5">
              <label htmlFor="branch_name" className="text-sm font-semibold text-slate-800">{c('onboarding.field.branch_name')}</label>
              <input id="branch_name" name="branch_name" type="text" className={INPUT}
                value={form.data.branch_name} onChange={(e) => setField('branch_name', e.target.value)}
                aria-invalid={errorFor('branch_name') !== undefined} aria-describedby={described('branch_name', true)} />
              <p id="branch_name-hint" className="text-xs text-slate-500">{c('onboarding.help.branch_name')}</p>
              {fieldError('branch_name')}
            </div>

            <fieldset className="grid gap-2">
              <legend className="text-sm font-semibold text-slate-800">{c('onboarding.field.locale')}</legend>
              <div className="grid gap-2 sm:grid-cols-2">
                {(['bn', 'en'] as const).map((option) => (
                  <label
                    key={option}
                    htmlFor={`locale-${option}`}
                    className={`flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-2.5 text-sm ${form.data.locale === option ? 'border-primary bg-primary/5 font-semibold text-primary' : 'border-slate-300 text-slate-700'}`}
                  >
                    <input id={`locale-${option}`} type="radio" name="locale" value={option} className="h-4 w-4 accent-slate-900"
                      checked={form.data.locale === option} onChange={() => setField('locale', option)} />
                    <span lang={option}>{c(`onboarding.locale.${option}`)}</span>
                  </label>
                ))}
              </div>
              <p className="text-xs text-slate-500">{c('onboarding.help.locale')}</p>
            </fieldset>

            <input type="hidden" name="timezone" value={form.data.timezone} />
          </div>
        ) : null}

        {step === 2 ? (
          <div className="mt-4 grid gap-4">
            <fieldset className="grid gap-3">
              <legend className="sr-only">{c('onboarding.step.plan')}</legend>
              {choosable.map((plan) => (
                <label
                  key={plan.code}
                  htmlFor={`plan-${plan.code}`}
                  className={`flex cursor-pointer gap-3 rounded-xl border p-4 ${form.data.plan === plan.code ? 'border-primary ring-2 ring-primary/40' : 'border-slate-300'}`}
                >
                  <input id={`plan-${plan.code}`} type="radio" name="plan" value={plan.code} className="mt-1 h-4 w-4 accent-slate-900"
                    checked={form.data.plan === plan.code} onChange={() => setField('plan', plan.code)}
                    aria-invalid={errorFor('plan') !== undefined} aria-describedby={errorFor('plan') !== undefined ? 'plan-error' : undefined} />
                  <span className="min-w-0 flex-1">
                    <span className="flex flex-wrap items-baseline justify-between gap-2">
                      <span className="text-base font-bold text-slate-900">{plan.name}</span>
                      <span className="text-base font-black text-slate-900">
                        {plan.price_monthly_paisa === 0 ? c('pricing.free') : formatBdt(plan.price_monthly_paisa, locale)}
                        <span className="ml-1 text-xs font-normal text-slate-500">{plan.price_monthly_paisa === 0 ? '' : c('pricing.per_month')}</span>
                      </span>
                    </span>
                    {plan.description ? <span className="mt-1 block text-xs text-slate-600">{plan.description}</span> : null}
                    <span className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-600">
                      {plan.limits.slice(0, 3).map((limit) => (
                        <span key={limit.key}>{c(`feature.${limit.key}`)}: <b className="font-semibold text-slate-800">{planLimit(limit)}</b></span>
                      ))}
                    </span>
                    {plan.trial_days > 0 ? (
                      <span className="mt-2 inline-block rounded-full bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">
                        {c('pricing.trial', { days: formatNumber(plan.trial_days, locale) })}
                      </span>
                    ) : null}
                  </span>
                </label>
              ))}
              {fieldError('plan')}
            </fieldset>

            <div className="rounded-xl border border-slate-200 bg-slate-50 p-4">
              <label htmlFor="demo" className="flex cursor-pointer items-start gap-3">
                <input id="demo" name="demo" type="checkbox" className="mt-0.5 h-4 w-4 accent-slate-900"
                  checked={form.data.demo} onChange={(e) => setField('demo', e.target.checked)}
                  aria-describedby="demo-hint" />
                <span>
                  <span className="block text-sm font-semibold text-slate-800">{c('onboarding.field.demo')}</span>
                  <span id="demo-hint" className="mt-0.5 block text-xs text-slate-600">{c('onboarding.demo_help')}</span>
                </span>
              </label>
            </div>
          </div>
        ) : null}

        {step === 3 ? (
          <div className="mt-4 grid gap-4">
            <section className="rounded-xl border border-slate-200 p-4">
              <h3 className="text-sm font-bold text-slate-800">{c('onboarding.step.account')}</h3>
              <dl className="mt-2 grid gap-1.5 text-sm">
                <div className="flex justify-between gap-3"><dt className="text-slate-600">{c('onboarding.field.owner_name')}</dt><dd className="text-right font-medium">{form.data.owner_name}</dd></div>
                <div className="flex justify-between gap-3"><dt className="text-slate-600">{c('onboarding.field.owner_email')}</dt><dd dir="ltr" className="text-right font-medium">{form.data.owner_email}</dd></div>
                <div className="flex justify-between gap-3"><dt className="text-slate-600">{c('onboarding.field.owner_mobile')}</dt><dd dir="ltr" className="text-right font-medium">{form.data.owner_mobile}</dd></div>
              </dl>
            </section>

            <section className="rounded-xl border border-slate-200 p-4">
              <h3 className="text-sm font-bold text-slate-800">{c('onboarding.step.clinic')}</h3>
              <dl className="mt-2 grid gap-1.5 text-sm">
                <div className="flex justify-between gap-3"><dt className="text-slate-600">{c('onboarding.field.clinic_name')}</dt><dd className="text-right font-medium">{form.data.clinic_name}</dd></div>
                <div className="flex justify-between gap-3"><dt className="text-slate-600">{c('onboarding.field.slug')}</dt><dd dir="ltr" className="text-right font-medium">{address}</dd></div>
                <div className="flex justify-between gap-3"><dt className="text-slate-600">{c('onboarding.field.branch_name')}</dt><dd className="text-right font-medium">{form.data.branch_name}</dd></div>
                <div className="flex justify-between gap-3"><dt className="text-slate-600">{c('onboarding.field.locale')}</dt><dd className="text-right font-medium">{c(`onboarding.locale.${form.data.locale}`)}</dd></div>
              </dl>
            </section>

            <section className="rounded-xl border border-slate-200 p-4">
              <h3 className="text-sm font-bold text-slate-800">{c('onboarding.step.plan')}</h3>
              <dl className="mt-2 grid gap-1.5 text-sm">
                <div className="flex justify-between gap-3">
                  <dt className="text-slate-600">{c('onboarding.review.plan')}</dt>
                  <dd className="text-right font-medium">{chosen?.name ?? form.data.plan}</dd>
                </div>
                <div className="flex justify-between gap-3">
                  <dt className="text-slate-600">{c('pricing.per_month')}</dt>
                  <dd className="text-right font-medium">
                    {chosen === undefined || chosen.price_monthly_paisa === 0 ? c('pricing.free') : formatBdt(chosen.price_monthly_paisa, locale)}
                  </dd>
                </div>
                <div className="flex justify-between gap-3">
                  <dt className="text-slate-600">{c('onboarding.field.demo')}</dt>
                  <dd className="text-right font-medium">{form.data.demo ? c('onboarding.review.yes') : c('onboarding.review.no')}</dd>
                </div>
              </dl>
            </section>

            <p className="text-xs text-slate-500">{c('onboarding.provisioning_help')}</p>
          </div>
        ) : null}

        <div className="mt-6 flex flex-col-reverse gap-3 border-t border-slate-200 pt-5 sm:flex-row sm:justify-between">
          {step > 0 ? (
            <button type="button" onClick={back} disabled={form.processing}
              className="rounded-lg border border-slate-300 px-4 py-2.5 font-semibold text-slate-700 hover:bg-slate-100 disabled:opacity-50">
              {c('onboarding.back')}
            </button>
          ) : <span />}

          {step < TOTAL_STEPS - 1 ? (
            <button key="wizard-next" type="button" onClick={next}
              className="rounded-lg bg-primary px-5 py-2.5 font-bold text-on-primary hover:opacity-90">
              {c('onboarding.next')}
            </button>
          ) : (
            <button key="wizard-submit" type="submit" disabled={form.processing}
              className="rounded-lg bg-primary px-5 py-2.5 font-bold text-on-primary hover:opacity-90 disabled:opacity-60">
              {form.processing ? c('onboarding.provisioning') : c('onboarding.submit')}
            </button>
          )}
        </div>

        {form.processing ? (
          <p role="status" aria-live="polite" className="mt-3 text-sm font-medium text-slate-600">{c('onboarding.provisioning_wait')}</p>
        ) : null}
      </form>

      <p className="mt-4 text-xs text-slate-500">
        {c('onboarding.pricing_hint')}{' '}
        <Link href={links.pricing} className="font-semibold text-primary underline-offset-4 hover:underline">{c('nav.pricing')}</Link>
      </p>
    </div>
  );
}

Signup.layout = (page: ReactNode): ReactNode => <CentralLayout title="onboarding.title">{page}</CentralLayout>;
