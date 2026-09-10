// Booking site, steps 2–4 (Inertia::render('Booking/Doctor')): the day strip and session cards (api.scheduling.
// availability → AvailabilityCalendar, materialised on demand) → mobile + name (+ sex/age) → POST site.booking.store.
// The kiosk QR lands here too (`kiosk` prop: today's sessions prefilled, channel kiosk, no calendar).
// There is no OTP step unless the clinic turned `kiosk.otp_required` on (`otp_required` prop): only then does the
// page show the send-code button and the code field, and only then does the payload carry an `otp` at all — the
// server ignores the field otherwise and never issues a code. Payment is "pay at counter" unless the
// OnlinePaymentGateway is enabled (extension point).
// `advance_payment_required` (BRIEF §5.C) is announced before the form is filled: the serial will be HELD until it
// is paid, or — with no gateway configured — self-booking is refused and the patient must phone the clinic.
// The page never depends on the WebSocket: the calendar is one XHR (with a retry when it fails), the booking an
// Inertia post — a degraded connection still books.
import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { useForm } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { SiteLayout } from '@site/Layouts/SiteLayout';
import { BookingSummary } from '@site/Components/Booking/BookingSummary';
import { DayStrip, stateLabelKey } from '@site/Components/Booking/DayStrip';
import { SessionCard } from '@site/Components/Booking/SessionCard';
import { StepHeading } from '@site/Components/Booking/StepHeading';
import { isBookable, kioskSessions, toDayCells, weekdayOf, type DayCell, type PickableSession } from '@site/Components/Booking/availability';
import { route } from '@shared/routes';
import { ulid } from '@shared/ulid';
import { isApiError } from '@shared/apiError';
import { formatBn, toAsciiDigits } from '@shared/format/number';
import { formatDateDhaka, formatDhaka } from '@shared/format/date';
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
  advance_payment_required: boolean;
  channel: 'online' | 'kiosk';
  kiosk: { branch: string; sessions: KioskSession[] } | null;
}>;

const BD_MOBILE = /^(\+?88)?01[3-9]\d{8}$/;

export default function Doctor({ doctor, branch, from, to, otp_required, otp_resend_seconds, online_payment_enabled, advance_payment_required, channel, kiosk }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [days, setDays] = useState<AvailabilityDay[]>([]);
  const [loading, setLoading] = useState(channel === 'online');
  const [failed, setFailed] = useState(false);
  const [attempt, setAttempt] = useState(0);
  const [selectedDate, setSelectedDate] = useState<string | null>(channel === 'kiosk' ? from : null);
  const [picked, setPicked] = useState<PickableSession | null>(null);
  const [notice, setNotice] = useState<DayCell | null>(null);
  const [otpSent, setOtpSent] = useState(false);
  const [otpError, setOtpError] = useState<string | null>(null);
  const [secondsLeft, setSecondsLeft] = useState(0);
  const clientEventId = useMemo(() => ulid(), []);
  const form = useForm({ session: '', channel, mobile: '', otp: '', name: '', sex: '', age_years: '', notes: '', client_event_id: clientEventId, kiosk_branch: kiosk?.branch ?? '' });
  const name = (en: string, bnText: string | null): string => (locale === 'bn' && bnText ? bnText : en);

  useEffect(() => {
    if (channel !== 'online' || !doctor) return undefined;
    let cancelled = false;
    setLoading(true);
    setFailed(false);
    // axios lives behind import(): the calendar arrives after first paint, so the HTTP client is off the
    // critical path of this route (REALTIME.md §8 — same pattern as the queue page).
    void import('@site/api/booking')
      .then(({ fetchAvailability }) => fetchAvailability(doctor.slug, from, to, branch.slug))
      .then((r) => { if (!cancelled) setDays(r.days); })
      .catch(() => { if (!cancelled) setFailed(true); })
      .finally(() => { if (!cancelled) setLoading(false); });
    return () => { cancelled = true; };
  }, [channel, doctor, from, to, branch.slug, attempt]);

  useEffect(() => {
    if (secondsLeft <= 0) return undefined;
    const id = setInterval(() => setSecondsLeft((s) => Math.max(0, s - 1)), 1000);
    return () => clearInterval(id);
  }, [secondsLeft]);

  // One shape for both channels: the calendar's days, or the kiosk's single day of prefilled sessions.
  const cells = useMemo<DayCell[]>(() => {
    if (channel === 'kiosk') {
      const sessions = kioskSessions(kiosk?.sessions ?? [], name);
      return [{ date: from, state: sessions.some(isBookable) ? 'open' : 'full', sessions }];
    }
    return toDayCells(days);
  }, [channel, kiosk, from, days, locale]);   // `name` reads only the locale, which is in the list

  const pick = (s: PickableSession): void => { setPicked(s); form.setData('session', s.public_id); };
  const clear = (): void => { setPicked(null); form.setData('session', ''); };

  // The first bookable day is chosen for the patient as soon as the calendar arrives (today, usually).
  useEffect(() => {
    if (cells.length === 0) return;
    if (selectedDate !== null && cells.some((c) => c.date === selectedDate)) return;
    setSelectedDate(cells.find((c) => c.state === 'open')?.date ?? cells[0]?.date ?? null);
  }, [cells]);   // when the calendar changes, not on every selection

  // A day with one bookable session needs no second tap: it is preselected. A pick from another day is dropped.
  useEffect(() => {
    const bookable = cells.find((c) => c.date === selectedDate)?.sessions.filter(isBookable) ?? [];
    if (bookable.length === 1 && bookable[0] && picked?.public_id !== bookable[0].public_id) pick(bookable[0]);
    else if (picked && picked.date !== selectedDate) clear();
  }, [selectedDate, cells]);   // `picked` is compared, not depended on; `form.setData` is stable (and a dep of it loops under jsdom)

  const selectDay = (date: string): void => { setNotice(null); setSelectedDate(date); };
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
    form.transform(({ otp, ...d }) => ({
      ...d,
      mobile: toAsciiDigits(d.mobile).replace(/[\s-]/g, ''),
      age_years: d.age_years === '' ? null : Number(toAsciiDigits(d.age_years)),
      sex: d.sex === '' ? null : d.sex,
      // Only a clinic that asks for verification gets a code in the payload; without it there is nothing to verify.
      ...(otp_required ? { otp: toAsciiDigits(otp).replace(/\D/g, '') } : {}),
    }));
    form.post(route('site.booking.store'), { preserveScroll: true });
  };

  const selectedCell = cells.find((c) => c.date === selectedDate) ?? null;
  const anyOpen = cells.some((c) => c.state === 'open');
  const dayLabel = (date: string): string => `${t(`booking.weekday_short.${weekdayOf(date)}`)} ${formatDhaka(date, 'D MMM', locale)}`;
  const monthLabel = formatDhaka(from, 'MMMM', locale) + (formatDhaka(from, 'MM', 'en') === formatDhaka(to, 'MM', 'en') ? '' : ` – ${formatDhaka(to, 'MMMM', locale)}`) + ` ${formatDhaka(to, 'YYYY', locale)}`;
  const stepOffset = channel === 'kiosk' ? 0 : 1;   // the kiosk has no calendar: session, then details
  // Advance payment with no working gateway cannot be completed at all; the server refuses it too
  // (AdvancePaymentUnavailable), so on the single-doctor online page the button says so up front.
  const advanceBlocked = advance_payment_required && !online_payment_enabled;
  const canSubmit = Boolean(form.data.session) && mobileValid && form.data.name.trim() !== '' && (!otp_required || form.data.otp.length >= 4) && !form.processing && !(channel === 'online' && advanceBlocked);
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

      <section className="grid gap-5 rounded-xl bg-white p-5 shadow-sm" aria-labelledby="bk-step-day">
        {channel === 'online' ? (
          <div className="grid gap-3">
            <StepHeading id="bk-step-day" step={1} title={t('booking.steps.day')} aside={monthLabel} />
            {failed && days.length === 0 ? (
              <div className="flex flex-wrap items-center gap-3 rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">
                <span className="flex-1">{t('booking.doctor.load_failed')}</span>
                <button type="button" onClick={() => setAttempt((n) => n + 1)} className="min-h-11 rounded-lg border border-red-300 bg-white px-4 font-semibold text-red-800">{t('common.actions.retry')}</button>
              </div>
            ) : (
              <DayStrip days={cells} selected={selectedDate} today={from} loading={loading} onSelect={selectDay} onExplain={setNotice} />
            )}
            {notice ? (
              <p role="status" className="rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600">
                {t('booking.day.unavailable', { date: dayLabel(notice.date), reason: t(stateLabelKey(notice.state === 'open' ? 'off' : notice.state)) })}
              </p>
            ) : null}
          </div>
        ) : null}

        <div className="grid gap-3">
          <StepHeading id={channel === 'online' ? undefined : 'bk-step-day'} step={1 + stepOffset} title={t('booking.steps.session')} aside={selectedCell && channel === 'online' ? dayLabel(selectedCell.date) : undefined} />
          {loading ? (
            <div aria-hidden="true" className="h-32 animate-pulse rounded-xl bg-slate-100 motion-reduce:animate-none" />
          ) : !anyOpen && !failed ? (
            <div className="grid gap-2 rounded-lg bg-slate-50 p-4 text-sm text-slate-700">
              <p>{channel === 'kiosk' ? t('booking.kiosk.no_sessions') : t('booking.doctor.empty', { date: formatDateDhaka(to, locale) })}</p>
              {branch.phone ? <p className="font-semibold text-amber-800">{t('booking.doctor.call_branch', { phone: formatBn(branch.phone, locale) })}</p> : null}
            </div>
          ) : selectedCell ? (
            <>
              <div className={`grid gap-3 ${selectedCell.sessions.length > 1 ? 'sm:grid-cols-2' : ''}`}>
                {selectedCell.sessions.map((s) => <SessionCard key={s.public_id} session={s} selected={picked?.public_id === s.public_id} onSelect={pick} />)}
              </div>
              {selectedCell.state === 'full' && branch.phone ? <p className="text-sm text-amber-800">{t('booking.doctor.call_branch', { phone: formatBn(branch.phone, locale) })}</p> : null}
            </>
          ) : null}
          {errors.session ? <p className="text-sm text-red-700">{errors.session}</p> : null}
        </div>
      </section>

      <BookingSummary picked={picked} feePaisa={picked?.fee_paisa ?? doctor?.fee_paisa ?? 0} className="sticky top-2 z-10" />

      <form onSubmit={submit} noValidate className="grid gap-3 rounded-xl bg-white p-5 shadow-sm" aria-labelledby="bk-step-details">
        <StepHeading id="bk-step-details" step={2 + stepOffset} title={t('booking.form.title')} />
        <label className="grid gap-1 text-sm font-medium">
          {t('auth.mobile')}
          <div className="flex gap-2">
            <input type="tel" inputMode="tel" autoComplete="tel" required placeholder="01XXXXXXXXX" value={form.data.mobile} onChange={(e) => form.setData('mobile', e.target.value)} aria-invalid={Boolean(errors.mobile)} className="min-h-11 min-w-0 flex-1 rounded-lg border border-slate-300 px-3 py-2 text-base" />
            {otp_required ? <button type="button" disabled={!mobileValid || secondsLeft > 0} onClick={() => void sendOtp()} className="min-h-11 rounded-lg border border-primary px-3 py-2 text-sm font-semibold text-primary disabled:opacity-50">{secondsLeft > 0 ? t('auth.otp.resend_in', { seconds: formatBn(secondsLeft, locale) }) : otpSent ? t('auth.otp.resend') : t('auth.otp.send')}</button> : null}
          </div>
          {errors.mobile ? <span className="text-xs text-red-700">{errors.mobile}</span> : null}
          {otpError ? <span className="text-xs text-red-700">{otpError}</span> : null}
        </label>
        {otp_required ? (
          <label className="grid gap-1 text-sm font-medium">
            {t('auth.otp.code')}
            <input type="text" inputMode="numeric" autoComplete="one-time-code" maxLength={6} value={form.data.otp} onChange={(e) => form.setData('otp', e.target.value)} aria-invalid={Boolean(errors.otp)} className="min-h-11 rounded-lg border border-slate-300 px-3 py-2 text-center text-xl tracking-[0.4em]" />
            {errors.otp ? <span className="text-xs text-red-700">{errors.otp}</span> : null}
          </label>
        ) : null}
        <label className="grid gap-1 text-sm font-medium">
          {t('booking.form.name')}
          <input type="text" required lang="bn" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} aria-invalid={Boolean(errors.name)} className="min-h-11 rounded-lg border border-slate-300 px-3 py-2 text-base" />
          {errors.name ? <span className="text-xs text-red-700">{errors.name}</span> : null}
        </label>
        <div className="grid grid-cols-2 gap-3">
          <label className="grid gap-1 text-sm font-medium">
            {t('booking.form.sex')}
            <select value={form.data.sex} onChange={(e) => form.setData('sex', e.target.value)} className="min-h-11 rounded-lg border border-slate-300 px-3 py-2 text-base">
              <option value="">—</option>
              <option value="m">{t('patients.gender.male')}</option>
              <option value="f">{t('patients.gender.female')}</option>
              <option value="o">{t('patients.gender.other')}</option>
            </select>
          </label>
          <label className="grid gap-1 text-sm font-medium">
            {t('booking.form.age')}
            <input type="text" inputMode="numeric" value={form.data.age_years} onChange={(e) => form.setData('age_years', e.target.value)} className="min-h-11 min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-base" />
          </label>
        </div>
        {errors.domain ? <p className="rounded-lg bg-red-50 p-2 text-sm text-red-800">{errors.domain}</p> : null}
        {advance_payment_required ? (
          <p className={`rounded-lg p-2 text-sm ${advanceBlocked ? 'bg-red-50 text-red-800' : 'bg-amber-50 text-amber-900'}`}>
            {advanceBlocked ? t('booking.form.advance_payment_unavailable') : t('booking.form.advance_payment')}
            {advanceBlocked && branch.phone ? ` ${formatBn(branch.phone, locale)}` : ''}
          </p>
        ) : null}
        <p className="text-xs text-slate-600">{online_payment_enabled ? t('booking.form.pay_online') : t('booking.form.pay_at_counter')}</p>
        <button type="submit" disabled={!canSubmit} className="min-h-12 rounded-lg bg-primary px-4 py-3 text-base font-semibold text-on-primary disabled:opacity-50">
          {picked ? t('booking.form.submit_for', { code: formatBn(picked.code, locale), date: formatDateDhaka(picked.date) }) : t('booking.form.submit')}
        </button>
      </form>
    </div>
  );
}

Doctor.layout = (page: ReactNode) => <SiteLayout title="booking.doctor.title">{page}</SiteLayout>;
