// Rendered by EnsureTenantIsActive (HTTP 402, root view `site`) when the tenant is suspended. Tailwind only.
import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{ tenant: { name: string | null } }>;

export default function Suspended({ tenant }: Props) {
  const { t } = useTranslation();

  return (
    <div className="min-h-screen flex items-center justify-center p-4">
      <Head title={t('tenancy.suspended_title')} />
      <section className="max-w-md rounded-xl bg-white p-6 shadow-sm">
        <h1 className="text-xl font-bold">{tenant.name ?? t('tenancy.suspended_title')}</h1>
        <p className="mt-2 text-slate-700">{t('tenancy.suspended')}</p>
        <p className="mt-1 text-sm text-slate-500">{t('tenancy.suspended_help')}</p>
      </section>
    </div>
  );
}
