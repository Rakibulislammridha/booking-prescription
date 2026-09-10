// A numbered step title for the booking flow — the numbers are the sequence the patient walks through
// (day → session → details), so they carry information rather than decoration.
import type { ReactNode } from 'react';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';

interface Props {
  step: number;
  title: string;
  /** Right-aligned context, e.g. the month the strip shows or the day the sessions belong to. */
  aside?: ReactNode;
  id?: string;
}

export function StepHeading({ step, title, aside, id }: Props) {
  return (
    <h2 id={id} className="flex items-center gap-2.5 text-base font-bold text-slate-900">
      <span aria-hidden="true" className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-bold text-on-primary">{formatBn(step, getLocale())}</span>
      <span className="min-w-0">{title}</span>
      {aside ? <span className="ml-auto truncate text-sm font-normal text-slate-500">{aside}</span> : null}
    </h2>
  );
}
