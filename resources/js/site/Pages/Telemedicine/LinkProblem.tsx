// A join link that no longer works. One screen, four reasons, always with a way forward.
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { SiteLayout } from '@site/Layouts/SiteLayout';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{ reason: string; message: string }>;

export default function LinkProblem({ message }: Props) {
  const { t } = useTranslation();

  return (
    <section className="rounded-xl bg-white p-6 text-center shadow-sm" data-testid="link-problem">
      <h1 className="text-xl font-bold text-slate-900">{t('telemedicine.join.title')}</h1>
      <p className="mt-2 text-sm text-slate-600">{message}</p>
      <p className="mt-2 text-xs text-slate-500">{t('telemedicine.join.next_step')}</p>
    </section>
  );
}

LinkProblem.layout = (page: ReactNode) => <SiteLayout title="telemedicine.join.title">{page}</SiteLayout>;
