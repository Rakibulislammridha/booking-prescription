// Booking site, steps 2–4 (Inertia::render('Booking/Doctor')): calendar with online serials remaining
// (api.scheduling.availability → AvailabilityCalendar, materialised on demand) → mobile + OTP (kiosk.otp_required)
// → name/sex/age → POST site.booking.store. The kiosk QR lands here too (`kiosk` prop: today's sessions prefilled,
// channel kiosk). Payment is "pay at counter" unless the OnlinePaymentGateway is enabled (extension point).
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { SiteLayout } from '@site/Layouts/SiteLayout';
import { route } from '@shared/routes';
import { ulid } from '@shared/ulid';
import { isApiError } from '@shared/apiError';
import { formatBn, toAsciiDigits } from '@shared/format/number';
import { formatDateDhaka, formatTimeDhaka } from '@shared/format/date';
import { formatBdt } from '@shared/format/money';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { AvailabilityDay, KioskSession } from '@shared/types/models';

interface DoctorInfo { public_id: string; slug: string; name: string; name_bn: string | null; degrees: string | null; degrees_bn: string | null; room: string | null; fee_paisa: number; followup_fee_paisa: number; free_followup_within_days: number }

type Props = PageProps<{
  doctor: DoctorInfo | null;
  branch: { public_id: string; slug: string; name: string; phone: string | null };
  from: string;
  to: string;
  otp_required: boolean;
  otp_resend_seconds: number;
  online_payment_enabled: boolean;
  channel: 'online' | 'kiosk';
  kiosk: { branch: string; sessions: KioskSession[] } | null;
}>;

interface PickedSession { public_id: string; code: string; date: string; planned_start_at: string; online_remaining: number; doctorName: string }

const BD_MOBILE = /^(\+?88)?01[3-9]\d{8}$/;

export default function Doctor({ doctor, branch, from, to, otp_required, otp_resend_seconds, online_payment_enabled, channel, kiosk }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [days, setDays] = useState<AvailabilityDay[]>([]);
  const [loading, setLoading] = useState(channel === 'online');
  const [picked, setPicked] = useState<PickedSession | null>(null);
  const [otpSent, setOtpSent] = useState(false);
  const [otpError, setOtpError] = useState<string | null>(null);
  const [secondsLeft, setSecondsLeft] = useState(0);
  const clientEventId = useMemo(() => ulid(), []);
  const form = useForm({ session: '', channel, mobile: '', otp: '', name: '', sex: '', age_years: '', notes: '', client_event_id: clientEventId, kiosk_branch: kiosk?.branch ?? '' });
  const name = (en: string, bnText: string | null): string => (locale === 'bn' && bnText ? bnText : en);

  useEffect(() => {
    if (channel !== 'online' || !doctor) return undefined;
    let cancelled = false;
    // axios lives behind import(): the calendar arrives after first paint, so the HTTP client is off the
    // critical path of this route (REALTIME.md §8 — same pattern as the queue page).
    void import('@site/api/booking')
      .then(({ fetchAvailability }) => fetchAvailability(doctor.slug, from, to, branch.slug))
      .then((r) => { if (!cancelled) setDays(r.days); })
      .catch(() => undefined)
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [channel, doctor, from, to, branch.slug]);

  useEffect(() => {
    if (secondsLeft <= 0) return undefined;
    const id = setInterval(() => setSecondsLeft((s) => Math.max(0, s - 1)), 1000);
    return () => clearInterval(id);
  }, [secondsLeft]);

  const pick = (s: PickedSession): void => { setPicked(s); form.setData('session', s.public_id); };
  const mobileValid = BD_MOBILE.test(toAsciiDigits(form.data.mobile).replace(/[\s-]/g, ''));

  const sendOtp = async (): Promise<void> => {
    setOtpError(null);
    try {
      const { requestBookingOtp } = await import('@site/api/booking');
      const r = await requestBookingOtp(toAsciiDigits(form.data.mobile).replace(/[\s-]/g, ''));
      setOtpSent(true);
      setSecondsLeft(r.resend_in ?? otp_resend_seconds);
    } catch (e) {
      setOtpError(isApiError(e) ? (e.fieldError('mobile') ?? e.message) : String(e));
    }
  };

  const submit = (e: FormEvent<HTMLFormElement>): void => {
    e.preventDefault();
    form.transform((d) => ({ ...d, mobile: toAsciiDigits(d.mobile).replace(/[\s-]/g, ''), otp: toAsciiDigits(d.otp).replace(/\D/g, ''), age_years: d.age_years === '' ? null : Number(toAsciiDigits(d.age_years)), sex: d.sex === '' ? null : d.sex }));
    form.post(route('site.booking.store'), { preserveScroll: true });
  };

  const sessions: PickedSession[] = channel === 'kiosk'
    ? (kiosk?.sessions ?? []).map((s) => ({ public_id: s.public_id, code: s.code, date: s.date, planned_start_at: s.planned_start_at, online_remaining: s.online_remaining, doctorName: name(s.doctor.name, s.doctor.name_bn) }))
    : days.flatMap((d) => d.sessions.filter((s) => s.status === 'scheduled' || s.status === 'running' || s.status === 'paused').map((s) => ({ public_id: s.public_id, code: s.code, date: d.date, planned_start_at: s.planned_start_at, online_remaining: s.online_remaining, doctorName: doctor ? name(doctor.name, doctor.name_bn) : '' })));
  const byDate = sessions.reduce<Record<string, PickedSession[]>>((acc, s) => { (acc[s.date] ??= []).push(s); return acc; }, {});
  const canSubmit = Boolean(form.data.session) && mobileValid && form.data.name.trim() !== '' && (!otp_required || form.data.otp.length >= 4) && !form.processing;
  const errors = form.errors as Record<string, string | undefined>;

  return (
    <div className="grid gap-4">
      {doctor ? (
        <section className="rounded-xl bg-white p-5 shadow-sm">
          <h1 className="text-2xl font-bold text-slate-900">{name(doctor.name, doctor.name_bn)}</h1>
          {doctor.degrees ? <p className="text-sm text-slate-600">{name(doctor.degrees, doctor.degrees_bn)}</p> : null}
          <p className="mt-1 text-sm text-slate-700">{branch.name}{doctor.room ? ` · ${doctor.room}` : ''}</p>
          <p className="mt-1 text-sm">{t('booking.card.fee')}: <strong className="text-primary">{formatBdt(doctor.fee_paisa)}</strong>{doctor.free_followup_within_days > 0 ? <span className="text-slate-600"> · {t('booking.doctor.free_followup', { days: formatBn(doctor.free_followup_within_days, locale) })}</span> : null}</p>
          {channel === 'kiosk' ? <p className="mt-2 rounded-lg bg-primary/10 p-2 text-sm text-slate-800">{t('booking.kiosk.hint')}</p> : null}
        </section>
      ) : (
        <p className="rounded-xl bg-white p-6 text-center text-slate-600 shadow-sm">{t('booking.kiosk.no_sessions')}</p>
      )}

      <section className="rounded-xl bg-white p-5 shadow-sm">
        <h2 className="text-lg font-semibold">{t('booking.doctor.pick_session')}</h2>
        {loading ? <p className="mt-2 text-sm text-slate-500">{t('common.loading')}</p> : null}
        {!loading && sessions.length === 0 ? <p className="mt-2 text-sm text-slate-600">{t('booking.doctor.no_sessions')}</p> : null}
        <div className="mt-3 grid gap-3">
          {Object.entries(byDate).map(([date, list]) => (
            <div key={date}>
              <p className="text-sm font-semibold text-slate-700">{formatDateDhaka(date)}</p>
              <div className="mt-1 flex flex-wrap gap-2">
                {list.map((s) => {
                  const full = s.online_remaining <= 0;
                  const active = picked?.public_id === s.public_id;
                  return (
                    <button key={s.public_id} type="button" disabled={full} onClick={() => pick(s)} aria-pressed={active}
                      className={`rounded-lg border px-3 py-2 text-left text-sm ${active ? 'border-primary bg-primary text-on-primary' : 'border-slate-300 bg-white'} disabled:opacity-40`}>
                      <span className="block font-semibold">{t('booking.session_label', { code: s.code })} · {formatTimeDhaka(s.planned_start_at)}</span>
                      {channel === 'kiosk' ? <span className="block text-xs">{s.doctorName}</span> : null}
                      <span className="block text-xs">{full ? t('booking.doctor.full') : t('booking.doctor.remaining', { count: formatBn(s.online_remaining, locale) })}</span>
                    </button>
                  );
                })}
              </div>
            </div>
          ))}
        </div>
        {sessions.length > 0 && sessions.every((s) => s.online_remaining <= 0) && branch.phone ? <p className="mt-3 text-sm text-amber-800">{t('booking.doctor.call_branch', { phone: formatBn(branch.phone, locale) })}</p> : null}
      </section>

      <form onSubmit={submit} noValidate className="rounded-xl bg-white p-5 shadow-sm grid gap-3">
        <h2 className="text-lg font-semibold">{t('booking.form.title')}</h2>
        <label className="grid gap-1 text-sm font-medium">
          {t('auth.mobile')}
          <div className="flex gap-2">
            <input type="tel" inputMode="tel" autoComplete="tel" required placeholder="01XXXXXXXXX" value={form.data.mobile} onChange={(e) => form.setData('mobile', e.target.value)} aria-invalid={Boolean(errors.mobile)} className="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-base" />
            {otp_required ? <button type="button" disabled={!mobileValid || secondsLeft > 0} onClick={() => void sendOtp()} className="rounded-lg border border-primary px-3 py-2 text-sm font-semibold text-primary disabled:opacity-50">{secondsLeft > 0 ? t('auth.otp.resend_in', { seconds: formatBn(secondsLeft, locale) }) : otpSent ? t('auth.otp.resend') : t('auth.otp.send')}</button> : null}
          </div>
          {errors.mobile ? <span className="text-xs text-red-700">{errors.mobile}</span> : null}
          {otpError ? <span className="text-xs text-red-700">{otpError}</span> : null}
        </label>
        {otp_required ? (
          <label className="grid gap-1 text-sm font-medium">
            {t('auth.otp.code')}
            <input type="text" inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={form.data.otp} onChange={(e) => form.setData('otp', e.target.value)} aria-invalid={Boolean(errors.otp)} className="rounded-lg border border-slate-300 px-3 py-2 text-center text-xl tracking-[0.4em]" />
            {errors.otp ? <span className="text-xs text-red-700">{errors.otp}</span> : null}
          </label>
        ) : null}
        <label className="grid gap-1 text-sm font-medium">
          {t('booking.form.name')}
          <input type="text" required lang="bn" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} aria-invalid={Boolean(errors.name)} className="rounded-lg border border-slate-300 px-3 py-2 text-base" />
          {errors.name ? <span className="text-xs text-red-700">{errors.name}</span> : null}
        </label>
        <div className="grid grid-cols-2 gap-3">
          <label className="grid gap-1 text-sm font-medium">
            {t('booking.form.sex')}
            <select value={form.data.sex} onChange={(e) => form.setData('sex', e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-base">
              <option value="">—</option>
              <option value="m">{t('patients.gender.male')}</option>
              <option value="f">{t('patients.gender.female')}</option>
              <option value="o">{t('patients.gender.other')}</option>
            </select>
          </label>
          <label className="grid gap-1 text-sm font-medium">
            {t('booking.form.age')}
            <input type="text" inputMode="numeric" value={form.data.age_years} onChange={(e) => form.setData('age_years', e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-base" />
          </label>
        </div>
        {errors.session ? <p className="text-xs text-red-700">{errors.session}</p> : null}
        {errors.domain ? <p className="rounded-lg bg-red-50 p-2 text-sm text-red-800">{errors.domain}</p> : null}
        <p className="text-xs text-slate-600">{online_payment_enabled ? t('booking.form.pay_online') : t('booking.form.pay_at_counter')}</p>
        <button type="submit" disabled={!canSubmit} className="rounded-lg bg-primary px-4 py-3 text-base font-semibold text-on-primary disabled:opacity-50">
          {picked ? t('booking.form.submit_for', { code: picked.code, date: formatDateDhaka(picked.date) }) : t('booking.form.submit')}
        </button>
      </form>
    </div>
  );
}

Doctor.layout = (page: ReactNode) => <SiteLayout title="booking.doctor.title">{page}</SiteLayout>;
