// Central changelog (Inertia::render('Central/Changelog')): what shipped, newest first.
// `changes[].text` arrives already translated from the server; only the chrome and the kind badges use t().
import type { ReactNode } from 'react';
import { CentralLayout } from '@site/Components/Central/CentralLayout';
import { makeCopy, type Copy } from '@site/Components/Central/copy';
import type { ChangeKind, ChangelogEntry, CentralLinks } from '@site/Components/Central/types';
import { formatDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  copy: Copy;
  entries: ChangelogEntry[];
  links: CentralLinks;
}>;

const BADGE: Record<ChangeKind, string> = {
  added: 'bg-primary/10 text-primary',
  improved: 'bg-slate-200 text-slate-700',
  fixed: 'bg-accent/25 text-slate-800',
};

export default function Changelog({ entries, copy }: Props) {
  const c = makeCopy(copy);
  const locale = getLocale();

  return (
    <div className="mx-auto max-w-3xl px-4 py-10 md:py-14">
      <header className="max-w-2xl">
        <h1 className="text-3xl font-black tracking-tight md:text-4xl">{c('changelog.title')}</h1>
        <p className="mt-3 text-base leading-relaxed text-slate-600">{c('changelog.subtitle')}</p>
      </header>

      {entries.length === 0 ? (
        <p className="mt-8 rounded-xl border border-slate-200 bg-white p-6 text-slate-600">{c('changelog.empty')}</p>
      ) : (
        <ol className="mt-10 grid gap-8 border-l-2 border-slate-200 pl-5 md:pl-6">
          {entries.map((entry) => (
            <li key={entry.version} className="relative">
              <span aria-hidden="true" className="absolute -left-[1.6rem] top-1.5 h-3 w-3 rounded-full border-2 border-white bg-primary md:-left-[1.85rem]" />
              <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                <h2 className="text-lg font-black tracking-tight">{c('changelog.version', { version: entry.version })}</h2>
                <time dateTime={entry.date} className="text-sm text-slate-500">
                  {formatDhaka(entry.date, 'D MMM YYYY', locale)}
                </time>
              </div>
              <ul className="mt-3 grid gap-2.5">
                {entry.changes.map((change, index) => (
                  <li key={index} className="flex flex-wrap items-start gap-2 rounded-xl border border-slate-200 bg-white p-3">
                    <span className={`shrink-0 rounded-full px-2 py-0.5 text-xs font-bold ${BADGE[change.kind]}`}>
                      {c(`changelog.kind.${change.kind}`)}
                    </span>
                    <span className="min-w-0 flex-1 text-[0.95rem] leading-relaxed text-slate-700">{change.text}</span>
                  </li>
                ))}
              </ul>
            </li>
          ))}
        </ol>
      )}
    </div>
  );
}

Changelog.layout = (page: ReactNode): ReactNode => <CentralLayout title="changelog.title">{page}</CentralLayout>;
