// Booking a video consultation. Deliberately the same three steps as the in-person form — doctor, session,
// mobile + OTP — because it posts to the same `BookAppointment` action with `BookingChannel::Telemedicine`, and
// a patient who has booked once at this clinic should recognise the screen. The availability calendar and the
// OTP endpoint are the Booking module's own (`@site/api/booking`); nothing is duplicated but the layout.
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { SiteLayout } from '@site/Layouts/SiteLayout';
import { formatBdt } from '@shared/format/money';
import { formatBn } from '@shared/format/number';
import { formatDateDhaka, formatTimeDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import { route } from '@shared/routes';
import { ulid } from '@shared/ulid';
import type { PageProps } from '@shared/types/inertia';
import type { AvailabilityDay, TelemedicineDoctor } from '@shared/types/models';

type Props = PageProps<{
  doctors: TelemedicineDoctor[];
  from: string;
  to: string;
  otp_required: boolean;
  otp_resend_seconds: number;
}>;

interface Slot { public_id: string; code: string; date: string; planned_start_at: string; online_remaining: number }

export default function Book({ doctors, from, to, otp_required, otp_resend_seconds }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [doctor, setDoctor] = useState<TelemedicineDoctor | null>(doctors[0] ?? null);
  const [days, setDays] = useState<AvailabilityDay[]>([]);
  const [loading, setLoading] = useState(false);
  const [otpSent, setOtpSent] = useState(false);
  const [secondsLeft, setSecondsLeft] = useState(0);

  const form = useForm({ session: '', mobile: '', otp: '', name: '', sex: '', age_years: '', notes: '', client_event_id: ulid() });

  useEffect(() => {
    if (!doctor) return;
    setLoading(true);
    void import('@site/api/booking')
      .then(({ fetchAvailability }) => fetchAvailability(doctor.slug, from, to))
      .then((r) => setDays(r.days))
      .catch(() => setDays([]))
      .finally(() => setLoading(false));
  }, [doctor, from, to]);

  useEffect(() => {
    if (secondsLeft <= 0) return undefined;
    const timer = setInterval(() => setSecondsLeft((s) => Math.max(0, s - 1)), 1000);
    return () => clearInterval(timer);
  }, [secondsLeft]);

  const slots = useMemo<Slot[]>(
    () => days.flatMap((d) => d.sessions.filter((s) => s.status === 'scheduled' || s.status === 'running').map((s) => ({ public_id: s.public_id, code: s.code, date: d.date, planned_start_at: s.planned_start_at, online_remaining: s.online_remaining }))),
    [days],
  );

  const mobileValid = /^01\d{9}$/.test(form.data.mobile.replace(/\s/g, ''));
  const canSubmit = form.data.session !== '' && mobileValid && form.data.name.trim() !== '' && (!otp_required || form.data.otp.length >= 4) && !form.processing;
  const errors = form.errors as Record<string, string | undefined>;

  const sendOtp = async (): Promise<void> => {
    const { requestBookingOtp } = await import('@site/api/booking');
    await requestBookingOtp(form.data.mobile).then(() => { setOtpSent(true); setSecondsLeft(otp_resend_seconds); }).catch(() => undefined);
  };

  const submit = (event: FormEvent): void => {
    event.preventDefault();
    form.post(route('site.telemedicine.book.store'));
  };

  return (
    <div className="grid gap-4">
      <header className="rounded-xl bg-white p-5 shadow-sm">
        <h1 className="text-2xl font-bold text-slate-900">{t('telemedicine.book.title')}</h1>
        <p className="mt-1 text-sm text-slate-600">{t('telemedicine.book.intro')}</p>
      </header>

      {doctors.length === 0 ? (
        <p className="rounded-xl bg-white p-6 text-center text-slate-600 shadow-sm">{t('telemedicine.book.no_doctors')}</p>
      ) : (
        <section className="rounded-xl bg-white p-5 shadow-sm">
          <h2 className="text-lg font-semibold">{t('telemedicine.book.pick_doctor')}</h2>
          <div className="mt-3 flex flex-wrap gap-2">
            {doctors.map((d) => (
              <button
                key={d.public_id}
                type="button"
                aria-pressed={doctor?.public_id === d.public_id}
                onClick={() => { setDoctor(d); form.setData('session', ''); }}
                data-testid={`doctor-${d.slug}`}
                className={`rounded-lg border px-3 py-2 text-left text-sm ${doctor?.public_id === d.public_id ? 'border-primary bg-primary text-on-primary' : 'border-slate-300 bg-white'}`}
              >
                <span className="block font-semibold">{locale === 'bn' && d.name_bn ? d.name_bn : d.name}</span>
                <span className="block text-xs">{formatBdt(d.fee_paisa)}</span>
              </button>
            ))}
          </div>
        </section>
      )}

      <section className="rounded-xl bg-white p-5 shadow-sm">
        <h2 className="text-lg font-semibold">{t('telemedicine.book.pick_session')}</h2>
        {loading ? <p className="mt-2 text-sm text-slate-500">{t('common.loading')}</p> : null}
        {!loading && slots.length === 0 ? <p className="mt-2 text-sm text-slate-600">{t('telemedicine.book.no_sessions')}</p> : null}
        <div className="mt-3 flex flex-wrap gap-2">
          {slots.map((s) => (
            <button
              key={s.public_id}
              type="button"
              disabled={s.online_remaining <= 0}
              aria-pressed={form.data.session === s.public_id}
              onClick={() => form.setData('session', s.public_id)}
              data-testid={`slot-${s.public_id}`}
              className={`rounded-lg border px-3 py-2 text-left text-sm ${form.data.session === s.public_id ? 'border-primary bg-primary text-on-primary' : 'border-slate-300 bg-white'} disabled:opacity-40`}
            >
              <span className="block font-semibold">{formatDateDhaka(s.date)} · {formatTimeDhaka(s.planned_start_at)}</span>
              <span className="block text-xs">{t('telemedicine.book.remaining', { count: formatBn(s.online_remaining, locale) })}</span>
            </button>
          ))}
        </div>
        {errors.session ? <p className="mt-2 text-xs text-red-700">{errors.session}</p> : null}
      </section>

      <form onSubmit={submit} noValidate className="grid gap-3 rounded-xl bg-white p-5 shadow-sm">
        <h2 className="text-lg font-semibold">{t('telemedicine.book.your_details')}</h2>
        <label className="grid gap-1 text-sm font-medium">
          {t('auth.mobile')}
          <div className="flex gap-2">
            <input type="tel" inputMode="tel" required placeholder="01XXXXXXXXX" value={form.data.mobile} onChange={(e) => form.setData('mobile', e.target.value)} data-testid="mobile" className="min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-base" />
            {otp_required ? (
              <button type="button" disabled={!mobileValid || secondsLeft > 0} onClick={() => void sendOtp()} className="rounded-lg border border-primary px-3 py-2 text-sm font-semibold text-primary disabled:opacity-50">
                {secondsLeft > 0 ? t('auth.otp.resend_in', { seconds: formatBn(secondsLeft, locale) }) : otpSent ? t('auth.otp.resend') : t('auth.otp.send')}
              </button>
            ) : null}
          </div>
          {errors.mobile ? <span className="text-xs text-red-700">{errors.mobile}</span> : null}
        </label>
        {otp_required ? (
          <label className="grid gap-1 text-sm font-medium">
            {t('auth.otp.code')}
            <input type="text" inputMode="numeric" value={form.data.otp} onChange={(e) => form.setData('otp', e.target.value)} data-testid="otp" className="rounded-lg border border-slate-300 px-3 py-2 text-base" />
            {errors.otp ? <span className="text-xs text-red-700">{errors.otp}</span> : null}
          </label>
        ) : null}
        <label className="grid gap-1 text-sm font-medium">
          {t('booking.form.name')}
          <input type="text" required value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} data-testid="name" className="rounded-lg border border-slate-300 px-3 py-2 text-base" />
          {errors.name ? <span className="text-xs text-red-700">{errors.name}</span> : null}
        </label>
        <button type="submit" disabled={!canSubmit} data-testid="book-submit" className="rounded-lg bg-primary px-4 py-3 text-base font-semibold text-on-primary disabled:opacity-50">
          {t('telemedicine.book.submit')}
        </button>
      </form>
    </div>
  );
}

Book.layout = (page: ReactNode) => <SiteLayout title="telemedicine.book.title">{page}</SiteLayout>;
