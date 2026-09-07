// Patient portal login, step 1 (site/Pages/Portal, owner E): enter mobile → OTP is sent (ARCHITECTURE §6.3, guard `patient`).
// Routes: site.portal.login (GET, this page) → POST site.portal.otp.request {mobile} → redirect to site.portal.verify.
import type { FormEvent, ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { SiteLayout } from '@site/Layouts/SiteLayout';
import { route } from '@shared/routes';
import { toAsciiDigits } from '@shared/format/number';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{ status?: string }>;

const BD_MOBILE = /^(\+?88)?01[3-9]\d{8}$/;

export default function Login({ status }: Props) {
  const { t } = useTranslation();
  const form = useForm({ mobile: '' });
  const valid = BD_MOBILE.test(toAsciiDigits(form.data.mobile).replace(/[\s-]/g, ''));

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    form.transform((data) => ({ mobile: toAsciiDigits(data.mobile).replace(/[\s-]/g, '') }));
    form.post(route('site.portal.otp.request'));
  };

  return (
    <form onSubmit={submit} noValidate className="mx-auto max-w-sm rounded-xl bg-white p-6 shadow-sm grid gap-4">
      <h1 className="text-xl font-bold">{t('auth.otp.title')}</h1>
      <p className="text-sm text-slate-600">{t('auth.otp.help')}</p>
      {status ? <p className="rounded bg-green-50 p-2 text-sm text-green-800" role="status">{status}</p> : null}
      <label className="grid gap-1 text-sm font-medium">
        {t('auth.mobile')}
        <input
          type="tel"
          name="mobile"
          inputMode="tel"
          autoComplete="tel"
          autoFocus
          required
          placeholder="01XXXXXXXXX"
          value={form.data.mobile}
          onChange={(e) => form.setData('mobile', e.target.value)}
          aria-invalid={Boolean(form.errors.mobile)}
          aria-describedby={form.errors.mobile ? 'mobile-error' : undefined}
          className="rounded-lg border border-slate-300 px-3 py-2 text-base focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
        />
        {form.errors.mobile ? <span id="mobile-error" className="text-xs text-red-700">{form.errors.mobile}</span> : null}
      </label>
      <button type="submit" disabled={form.processing || !valid} className="rounded-lg bg-primary px-4 py-2.5 font-semibold text-on-primary disabled:opacity-50">
        {t('auth.otp.send')}
      </button>
    </form>
  );
}

Login.layout = (page: ReactNode) => <SiteLayout title="auth.otp.title">{page}</SiteLayout>;
