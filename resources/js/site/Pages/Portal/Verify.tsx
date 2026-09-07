// Patient portal login, step 2 (GET site.portal.verify): enter the 6-digit code (5 attempts, 300 s TTL, 60 s resend throttle — §6.3).
// Routes: site.portal.otp.verify (POST {mobile, code}), site.portal.otp.request (POST {mobile}, resend).
import { useEffect, useState, type FormEvent, type ReactNode } from 'react';
import { router, useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { SiteLayout } from '@site/Layouts/SiteLayout';
import { route } from '@shared/routes';
import { formatBn, toAsciiDigits } from '@shared/format/number';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{ mobile: string; resend_after?: number; status?: string }>;

export default function Verify({ mobile, resend_after = 60, status }: Props) {
  const { t, i18n } = useTranslation();
  const form = useForm({ mobile, code: '' });
  const [secondsLeft, setSecondsLeft] = useState(resend_after);
  const locale = i18n.language === 'bn' ? 'bn' : 'en';

  useEffect(() => {
    if (secondsLeft <= 0) return undefined;
    const id = setInterval(() => setSecondsLeft((s) => Math.max(0, s - 1)), 1000);
    return () => clearInterval(id);
  }, [secondsLeft]);

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    form.transform((data) => ({ ...data, code: toAsciiDigits(data.code).replace(/\D/g, '') }));
    form.post(route('site.portal.otp.verify'), { onError: () => form.reset('code') });
  };

  const resend = (): void => {
    router.post(route('site.portal.otp.request'), { mobile }, { preserveState: true, onSuccess: () => setSecondsLeft(resend_after) });
  };

  const masked = mobile.replace(/^(\+?88)?(01\d)\d{5}(\d{3})$/, '$2*****$3');

  return (
    <form onSubmit={submit} noValidate className="mx-auto max-w-sm rounded-xl bg-white p-6 shadow-sm grid gap-4">
      <h1 className="text-xl font-bold">{t('auth.otp.verify_title')}</h1>
      <p className="text-sm text-slate-600">{t('auth.otp.sent_to', { mobile: formatBn(masked, locale) })}</p>
      {status ? <p className="rounded bg-green-50 p-2 text-sm text-green-800" role="status">{status}</p> : null}
      <label className="grid gap-1 text-sm font-medium">
        {t('auth.otp.code')}
        <input
          type="text"
          name="code"
          inputMode="numeric"
          autoComplete="one-time-code"
          pattern="[0-9]*"
          maxLength={6}
          autoFocus
          required
          value={form.data.code}
          onChange={(e) => form.setData('code', e.target.value)}
          aria-invalid={Boolean(form.errors.code)}
          aria-describedby={form.errors.code ? 'code-error' : undefined}
          className="rounded-lg border border-slate-300 px-3 py-2 text-center text-2xl tracking-[0.5em] focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/30"
        />
        {form.errors.code ? <span id="code-error" className="text-xs text-red-700">{form.errors.code}</span> : null}
        {form.errors.mobile ? <span className="text-xs text-red-700">{form.errors.mobile}</span> : null}
      </label>
      <button type="submit" disabled={form.processing || toAsciiDigits(form.data.code).replace(/\D/g, '').length !== 6} className="rounded-lg bg-primary px-4 py-2.5 font-semibold text-on-primary disabled:opacity-50">
        {t('auth.otp.verify')}
      </button>
      <div className="text-center text-sm text-slate-600">
        {secondsLeft > 0 ? (
          <span>{t('auth.otp.resend_in', { seconds: formatBn(secondsLeft, locale) })}</span>
        ) : (
          <button type="button" onClick={resend} className="font-semibold text-primary underline">{t('auth.otp.resend')}</button>
        )}
      </div>
    </form>
  );
}

Verify.layout = (page: ReactNode) => <SiteLayout title="auth.otp.verify_title">{page}</SiteLayout>;
