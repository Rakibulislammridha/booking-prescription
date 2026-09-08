// 402, not 404. The clinic simply does not have the video add-on; the patient is told so and given the phone.
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { SiteLayout } from '@site/Layouts/SiteLayout';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{ message: string }>;

export default function Unavailable({ message }: Props) {
  const { t } = useTranslation();

  return (
    <section className="rounded-xl bg-white p-6 text-center shadow-sm" data-testid="telemedicine-unavailable">
      <h1 className="text-xl font-bold text-slate-900">{t('telemedicine.unavailable.title')}</h1>
      <p className="mt-2 text-sm text-slate-600">{message}</p>
    </section>
  );
}

Unavailable.layout = (page: ReactNode) => <SiteLayout title="telemedicine.unavailable.title">{page}</SiteLayout>;
