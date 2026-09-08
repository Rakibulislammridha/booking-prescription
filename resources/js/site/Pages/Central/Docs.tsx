// Central documentation shell (Inertia::render('Central/Docs')).
//
// The section titles and every block string arrive ALREADY TRANSLATED from the server — the site bundle only
// carries the prefixes in `shared/lang/surfaces.ts`, and shipping a whole manual through it would blow the
// first-load budget (CONVENTIONS §7.4, §7.5). So `t()` is used for the chrome only; `blocks` render verbatim.
import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { CentralLayout } from '@site/Components/Central/CentralLayout';
import { makeCopy, type Copy, type CopyFn } from '@site/Components/Central/copy';
import type { CentralLinks, DocBlock, DocSection } from '@site/Components/Central/types';
import type { PageProps } from '@shared/types/inertia';

type Props = PageProps<{
  copy: Copy;
  sections: DocSection[];
  current: DocSection;
  links: CentralLinks;
}>;

function Block({ block, index, c }: { block: DocBlock; index: number; c: CopyFn }) {
  const items = block.items ?? [];

  if (block.kind === 'steps') {
    return (
      <ol className="my-4 grid gap-2.5 pl-0">
        {items.map((item, n) => (
          <li key={`${index}-${n}`} className="flex gap-3">
            <span aria-hidden="true" className="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">
              {n + 1}
            </span>
            <span className="text-[0.95rem] leading-relaxed text-slate-700">{item}</span>
          </li>
        ))}
      </ol>
    );
  }

  if (block.kind === 'note') {
    return (
      <aside className="my-4 rounded-xl border border-accent/40 bg-accent/10 p-4">
        <p className="text-xs font-bold uppercase tracking-wide text-slate-700">{c('docs.note_label')}</p>
        {block.text ? <p className="mt-1 text-[0.95rem] leading-relaxed text-slate-800">{block.text}</p> : null}
        {items.length > 0 ? (
          <ul className="mt-2 grid list-disc gap-1 pl-5 text-[0.95rem] leading-relaxed text-slate-800">
            {items.map((item, n) => <li key={`${index}-${n}`}>{item}</li>)}
          </ul>
        ) : null}
      </aside>
    );
  }

  return (
    <>
      {block.text ? <p className="my-4 text-[0.95rem] leading-relaxed text-slate-700">{block.text}</p> : null}
      {items.length > 0 ? (
        <ul className="my-4 grid list-disc gap-1.5 pl-5 text-[0.95rem] leading-relaxed text-slate-700">
          {items.map((item, n) => <li key={`${index}-${n}`}>{item}</li>)}
        </ul>
      ) : null}
    </>
  );
}

function SectionNav({ sections, current, id }: { sections: DocSection[]; current: DocSection; id: string }) {
  return (
    <ul id={id} className="grid gap-0.5 text-sm">
      {sections.map((section) => {
        const active = section.slug === current.slug;
        return (
          <li key={section.slug}>
            <Link
              href={section.url}
              aria-current={active ? 'page' : undefined}
              className={`block rounded-lg px-3 py-2 ${active ? 'bg-primary/10 font-bold text-primary' : 'text-slate-700 hover:bg-slate-100'}`}
            >
              {section.title}
            </Link>
          </li>
        );
      })}
    </ul>
  );
}

export default function Docs({ sections, current, links, copy }: Props) {
  const c = makeCopy(copy);

  return (
    <div className="mx-auto max-w-5xl px-4 py-8 md:py-12">
      <div className="md:grid md:grid-cols-[15rem_1fr] md:gap-10">
        <nav aria-label={c('docs.nav_title')} className="md:sticky md:top-24 md:self-start">
          <details className="rounded-xl border border-slate-200 bg-white md:hidden">
            <summary className="cursor-pointer list-none px-4 py-3 text-sm font-semibold text-slate-800">
              {c('docs.nav_toggle')}
            </summary>
            <div className="border-t border-slate-200 p-2">
              <SectionNav sections={sections} current={current} id="docs-nav-mobile" />
            </div>
          </details>
          <div className="hidden md:block">
            <p className="px-3 pb-2 text-xs font-bold uppercase tracking-wide text-slate-500">{c('docs.nav_title')}</p>
            <SectionNav sections={sections} current={current} id="docs-nav" />
          </div>
        </nav>

        <article className="mt-6 min-w-0 md:mt-0">
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-primary">{c('docs.eyebrow')}</p>
          <h1 className="mt-2 text-2xl font-black tracking-tight md:text-3xl">{current.title}</h1>
          <div className="mt-4">
            {current.blocks.length === 0 ? (
              <p className="rounded-xl border border-slate-200 bg-white p-6 text-slate-600">{c('docs.empty')}</p>
            ) : (
              current.blocks.map((block, index) => <Block key={index} block={block} index={index} c={c} />)
            )}
          </div>

          <p className="mt-10 border-t border-slate-200 pt-6 text-sm text-slate-600">
            {c('docs.help')}{' '}
            <Link href={links.signup} className="font-semibold text-primary underline-offset-4 hover:underline">
              {c('nav.signup')}
            </Link>
          </p>
        </article>
      </div>
    </div>
  );
}

Docs.layout = (page: ReactNode): ReactNode => <CentralLayout title="docs.title">{page}</CentralLayout>;
