// Public booking site, step 1 (Inertia::render('Booking/Index')): find a doctor by specialty, name or day (BRIEF §5.C).
// Tailwind only, no MUI; must work on a low-end Android over poor wifi (CONVENTIONS §7.4).
import { useState, type FormEvent, type ReactNode } from 'react';
import { Link, router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { SiteLayout } from '@site/Layouts/SiteLayout';
import { route } from '@shared/routes';
import { formatBdt } from '@shared/format/money';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { BookingDoctorCard } from '@shared/types/models';

type Props = PageProps<{
  filters: { specialty: string; q: string; day: number | null };
  specialties: Array<{ slug: string; name: string; name_bn: string | null }>;
  doctors: BookingDoctorCard[];
}>;

const WEEKDAYS = [0, 1, 2, 3, 4, 5, 6] as const;

export default function Index({ filters, specialties, doctors }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [q, setQ] = useState(filters.q);
  const [specialty, setSpecialty] = useState(filters.specialty);
  const [day, setDay] = useState<string>(filters.day === null ? '' : String(filters.day));
  const bn = (en: string, bnText: string | null): string => (locale === 'bn' && bnText ? bnText : en);

  const search = (e?: FormEvent<HTMLFormElement>): void => {
    e?.preventDefault();
    router.get(route('site.booking.index'), { q: q || undefined, specialty: specialty || undefined, day: day === '' ? undefined : Number(day) }, { preserveState: true, replace: true });
  };

  return (
    <div className="grid gap-4">
      <form onSubmit={search} className="rounded-xl bg-white p-4 shadow-sm grid gap-3 sm:grid-cols-4" role="search" aria-label={t('booking.search.title')}>
        <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('booking.search.name')} className="sm:col-span-2 rounded-lg border border-slate-300 px-3 py-2 text-base focus:border-primary focus:outline-none" />
        <select value={specialty} onChange={(e) => setSpecialty(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-base" aria-label={t('booking.search.specialty')}>
          <option value="">{t('booking.search.any_specialty')}</option>
          {specialties.map((s) => <option key={s.slug} value={s.slug}>{bn(s.name, s.name_bn)}</option>)}
        </select>
        <select value={day} onChange={(e) => setDay(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-base" aria-label={t('booking.search.day')}>
          <option value="">{t('booking.search.any_day')}</option>
          {WEEKDAYS.map((d) => <option key={d} value={d}>{t(`booking.weekday.${d}`)}</option>)}
        </select>
        <button type="submit" className="sm:col-span-4 rounded-lg bg-primary px-4 py-2.5 font-semibold text-on-primary">{t('common.actions.search')}</button>
      </form>

      {doctors.length === 0 ? (
        <p className="rounded-xl bg-white p-6 text-center text-slate-600 shadow-sm">{t('booking.search.empty')}</p>
      ) : (
        <ul className="grid gap-3">
          {doctors.map((d) => (
            <li key={d.public_id} className="rounded-xl bg-white p-4 shadow-sm">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <h2 className="text-lg font-bold text-slate-900">{bn(d.name, d.name_bn)}</h2>
                  {d.degrees ? <p className="text-sm text-slate-600">{bn(d.degrees, d.degrees_bn)}{d.designation ? ` · ${d.designation}` : ''}</p> : null}
                  <p className="mt-1 flex flex-wrap gap-1 text-xs">
                    {d.specialties.map((s) => <span key={s.slug} className="rounded-full bg-slate-100 px-2 py-0.5 text-slate-700">{bn(s.name, s.name_bn)}</span>)}
                  </p>
                  <p className="mt-1 text-xs text-slate-500">{t('booking.card.days')}: {d.weekdays.length === 7 ? t('booking.card.every_day') : d.weekdays.map((w) => t(`booking.weekday_short.${w}`)).join(', ')}</p>
                </div>
                <div className="text-right">
                  <p className="text-sm text-slate-600">{t('booking.card.fee')}</p>
                  <p className="text-lg font-bold text-primary">{formatBdt(d.fee_paisa)}</p>
                  <Link href={route('site.booking.doctor', { doctor: d.slug })} className="mt-2 inline-block rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-on-primary">{t('booking.card.book')}</Link>
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

Index.layout = (page: ReactNode) => <SiteLayout title="booking.search.title">{page}</SiteLayout>;
