// Patient portal home (GET /portal, site.portal.home): the household on this mobile, the member being acted for
// (ARCHITECTURE §6.3 session('patient.acting_for')) and their timeline. Tailwind only — no MUI on the site.
import type { ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { SiteLayout } from '@site/Layouts/SiteLayout';
import { route } from '@shared/routes';
import { formatBn } from '@shared/format/number';
import { formatDhaka } from '@shared/format/date';
import type { PageProps } from '@shared/types/inertia';
import type { FamilyMember, PatientSummary, TimelinePage } from '@shared/types/models';

type Props = PageProps<{ patient: PatientSummary; family: FamilyMember[]; acting_for: PatientSummary; timeline: TimelinePage }>;

export default function Home({ patient, family, acting_for, timeline }: Props) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language === 'bn' ? 'bn' : 'en';

  const switchTo = (publicId: string): void => {
    if (publicId !== acting_for.public_id) router.patch(route('site.portal.acting_for'), { patient: publicId }, { preserveScroll: true });
  };

  return (
    <div className="grid gap-4">
      <section className="rounded-xl bg-white p-5 shadow-sm">
        <h1 className="text-xl font-bold">{t('portal.home.welcome', { name: patient.name })}</h1>
        <p className="mt-1 text-sm text-slate-600">
          {formatBn(patient.mobile_local, locale)} · {patient.patient_code}
        </p>
      </section>

      <section className="rounded-xl bg-white p-5 shadow-sm">
        <h2 className="text-base font-semibold">{t('portal.home.family')}</h2>
        <ul className="mt-3 grid gap-2">
          {family.map((member) => {
            const active = member.public_id === acting_for.public_id;
            const isSelf = member.public_id === patient.public_id;
            return (
              <li key={member.public_id}>
                <button
                  type="button"
                  onClick={() => switchTo(member.public_id)}
                  aria-pressed={active}
                  className={`flex w-full items-center justify-between rounded-lg border px-3 py-2 text-left ${active ? 'border-primary bg-primary/10' : 'border-slate-200 hover:bg-slate-50'}`}
                >
                  <span>
                    <span className="font-medium">{member.name}</span>
                    <span className="ml-2 text-xs text-slate-500">
                      {isSelf ? t('portal.home.you') : member.relation ? t(`patients.relation.${member.relation}`) : t('portal.home.member')}
                      {member.age_text ? ` · ${formatBn(member.age_text, locale)}` : ''}
                    </span>
                  </span>
                  {active ? <span className="text-xs font-semibold text-primary">{t('portal.home.acting_for')}</span> : <span className="text-xs text-slate-500">{t('portal.home.switch')}</span>}
                </button>
              </li>
            );
          })}
        </ul>
      </section>

      <section className="rounded-xl bg-white p-5 shadow-sm">
        <h2 className="text-base font-semibold">
          {t('portal.home.timeline')} — {acting_for.name}
        </h2>
        {timeline.data.length === 0 ? (
          <p className="mt-2 text-sm text-slate-600">{t('portal.home.timeline_empty')}</p>
        ) : (
          <ol className="mt-3 grid gap-2">
            {timeline.data.map((entry) => (
              <li key={`${entry.kind}-${entry.id}`} className="rounded-lg border border-slate-200 px-3 py-2">
                <div className="flex items-center justify-between gap-2 text-xs text-slate-500">
                  <span className="rounded bg-slate-100 px-1.5 py-0.5">{t(`patients.timeline.kinds.${entry.kind}`, { defaultValue: entry.kind })}</span>
                  <time dateTime={entry.occurred_at}>{formatDhaka(entry.occurred_at, 'D MMM YYYY', locale)}</time>
                </div>
                <p className="mt-1 font-medium">{entry.title}</p>
                {entry.subtitle ? <p className="text-sm text-slate-600">{entry.subtitle}</p> : null}
              </li>
            ))}
          </ol>
        )}
      </section>
    </div>
  );
}

Home.layout = (page: ReactNode) => <SiteLayout title="portal.home.title">{page}</SiteLayout>;
