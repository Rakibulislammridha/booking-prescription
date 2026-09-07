// The public live queue (Inertia::render('Queue/Today'), BRIEF §5.E, REALTIME.md §8): now serving, your serial,
// patients ahead and the estimated call time — no login, no MUI, no date library, ≤ 12 KB of page code.
// The first paint uses the QueueState embedded in the props; after that useQueueState() keeps it live (WebSocket
// first, 5 s ETag poll when degraded, 30 s when hidden, version dedupe in both directions).
import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { SiteLayout } from '@site/Layouts/SiteLayout';
import { roomLabel } from './room';
import { useQueueState } from '@shared/realtime/useQueueState';
import type { QueueSerial, QueueState } from '@shared/realtime/types';
import { formatBn } from '@shared/format/number';
import { formatTimeDhaka } from '@shared/format/date';
import { getLocale } from '@shared/locale';
import type { PageProps } from '@shared/types/inertia';
import type { QueueDoctor, QueueSessionSummary } from '@shared/types/models';

type Props = PageProps<{
  tenant_public_id: string | null;
  channel: string | null;
  doctor: QueueDoctor;
  date: string;
  session_id: string | null;
  state: QueueState | null;
  sessions: QueueSessionSummary[];
  serial: string | null;
  notify_ahead: number;
}>;

const storageKey = (slug: string): string => `bp.queue.serial.${slug}`;

function readStored(slug: string): string | null {
  try {
    return window.localStorage.getItem(storageKey(slug));
  } catch {
    return null;
  }
}

function writeStored(slug: string, value: string | null): void {
  try {
    if (value === null) window.localStorage.removeItem(storageKey(slug));
    else window.localStorage.setItem(storageKey(slug), value);
  } catch {
    /* private mode: the ?s= link still works */
  }
}

/** A pin is a serial public id, a display code typed by the patient, or a `local:` id from an offline slip. */
function findMine(state: QueueState | null, pin: string | null): QueueSerial | null {
  if (!state || !pin) return null;
  const needle = pin.trim().toUpperCase();
  return state.serials.find((s) => s.id === pin || s.c.toUpperCase() === needle) ?? null;
}

export default function Today({ tenant_public_id, doctor, session_id, state: initial, sessions, serial, notify_ahead }: Props) {
  const { t } = useTranslation();
  const locale = getLocale();
  const [pin, setPin] = useState<string | null>(serial);
  const [typed, setTyped] = useState('');
  const [pendingLocal, setPendingLocal] = useState(false);
  const [sessionId, setSessionId] = useState<string | null>(session_id);
  const { state, mode, updatedAt } = useQueueState({
    tenantId: tenant_public_id ?? '',
    doctorSlug: doctor.slug,
    sessionId,
    initial,
    enabled: Boolean(tenant_public_id) && sessionId !== null,
  });

  useEffect(() => {
    if (serial) writeStored(doctor.slug, serial);
    else setPin((current) => current ?? readStored(doctor.slug));
  }, [serial, doctor.slug]);

  // An offline slip's `local:` id becomes a real serial once the desk syncs (OFFLINE §10); poll it while it does not.
  useEffect(() => {
    if (!pin || !pin.toLowerCase().startsWith('local:')) return undefined;
    let stopped = false;
    setPendingLocal(true);
    const tick = (): void => {
      // axios (~20 KB gzip) stays out of the first load: only a `local:` slip ever needs it (REALTIME §8 budget).
      void import('@site/api/queue').then(({ resolveLocalSerial }) => resolveLocalSerial(pin))
        .then((r) => {
          if (stopped || !r.resolved || !r.serial) return;
          setPendingLocal(false);
          setPin(r.serial.public_id);
          writeStored(doctor.slug, r.serial.public_id);
          if (r.session) setSessionId(r.session.public_id);
        })
        .catch(() => undefined);
    };
    tick();
    const id = window.setInterval(tick, 30_000);
    return () => {
      stopped = true;
      window.clearInterval(id);
    };
  }, [pin, doctor.slug]);

  const mine = useMemo(() => findMine(state, pin), [state, pin]);
  const nowServing = state?.now_serving ?? null;
  const status = state?.session.status ?? null;
  const delay = state?.session.delay_minutes ?? 0;
  const upNext = useMemo(() => (state?.serials ?? []).filter((s) => s.s !== 'i').slice(0, 3), [state]);

  const applyTyped = useCallback((): void => {
    const value = typed.trim();
    if (value === '') return;
    setPin(value);
    writeStored(doctor.slug, value);
    setTyped('');
  }, [typed, doctor.slug]);

  const forget = useCallback((): void => {
    setPin(null);
    writeStored(doctor.slug, null);
  }, [doctor.slug]);

  const doctorName = locale === 'bn' && doctor.name_bn ? doctor.name_bn : doctor.name;
  const connection = mode === 'online' ? t('queue.page.live') : mode === 'degraded' ? t('queue.page.polling') : t('queue.page.offline');

  return (
    <div className="mx-auto grid max-w-md gap-4">
      <header className="text-center">
        <h1 className="text-xl font-bold">{doctorName}</h1>
        <p className="text-sm text-slate-600">
          {roomLabel(doctor.room, locale) ? t('queue.display.room', { room: roomLabel(doctor.room, locale) }) : null}
          {state ? ` · ${t('queue.page.session_switch')} ${formatBn(state.session.code, locale)}` : null}
        </p>
      </header>

      {delay > 0 ? (
        <p role="status" className="rounded-xl bg-amber-100 p-3 text-center text-base font-semibold text-amber-900">
          {t('queue.page.delay_banner', { minutes: formatBn(delay, locale) })}
        </p>
      ) : null}

      {status === 'paused' ? <p role="status" className="rounded-xl bg-slate-200 p-3 text-center font-semibold">{t('queue.page.paused')}</p> : null}
      {status === 'closed' || status === 'cancelled' ? <p role="status" className="rounded-xl bg-slate-200 p-3 text-center font-semibold">{t('queue.page.session_ended')}</p> : null}

      <section className="rounded-2xl bg-white p-6 text-center shadow-sm" aria-live="polite">
        <p className="text-sm font-semibold uppercase tracking-wide text-slate-500">{t('queue.page.now_serving')}</p>
        <p className="mt-1 text-6xl font-black tracking-wider text-primary" data-testid="now-serving">
          {nowServing ? formatBn(nowServing.c, locale) : formatBn('—', locale)}
        </p>
        {!nowServing && state ? <p className="mt-1 text-sm text-slate-500">{t('queue.page.not_started')}</p> : null}
      </section>

      {mine ? (
        <section className={`rounded-2xl p-5 text-center shadow-sm ${mine.s === 'i' ? 'bg-green-600 text-white' : 'bg-white'}`} data-testid="my-serial">
          <p className="text-sm font-semibold uppercase tracking-wide opacity-80">{t('queue.page.your_serial')}</p>
          <p className="mt-1 text-5xl font-black tracking-wider">{formatBn(mine.c, locale)}</p>
          {mine.s === 'i' ? (
            <p className="mt-2 text-lg font-bold">{t('queue.page.your_turn')}</p>
          ) : (
            <>
              <p className="mt-2 text-lg font-semibold">{t('queue.page.ahead', { count: formatBn(mine.ahead, locale) })}</p>
              <p className="mt-1 text-base">
                {mine.eta ? t('queue.page.eta', { time: formatTimeDhaka(mine.eta, locale) }) : t('queue.page.eta_unknown')}
              </p>
              {state?.eta_confidence === 'low' ? <p className="mt-1 text-xs opacity-70">{t('queue.page.eta_low')}</p> : null}
              {mine.ahead <= notify_ahead ? <p className="mt-2 rounded-lg bg-amber-100 p-2 text-sm font-semibold text-amber-900">{t('queue.page.almost')}</p> : null}
            </>
          )}
          <button type="button" onClick={forget} className="mt-3 min-h-11 text-sm underline opacity-80">{t('queue.page.clear')}</button>
        </section>
      ) : pendingLocal ? (
        <p className="rounded-2xl bg-white p-4 text-center text-sm shadow-sm">{t('queue.page.local_pending')}</p>
      ) : (
        <section className="rounded-2xl bg-white p-5 shadow-sm">
          <label htmlFor="serial-input" className="block text-sm font-semibold">{t('queue.page.enter_serial')}</label>
          <p className="mt-1 text-xs text-slate-500">{t('queue.page.enter_serial_help')}</p>
          <div className="mt-2 flex gap-2">
            <input
              id="serial-input"
              value={typed}
              onChange={(e) => setTyped(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') applyTyped(); }}
              inputMode="text"
              autoComplete="off"
              className="min-h-12 flex-1 rounded-lg border border-slate-300 px-3 text-lg"
              placeholder="A-042"
            />
            <button type="button" onClick={applyTyped} className="min-h-12 rounded-lg bg-primary px-4 text-base font-semibold text-on-primary">
              {t('queue.page.pin')}
            </button>
          </div>
        </section>
      )}

      {state ? (
        <section className="rounded-2xl bg-white p-5 shadow-sm">
          <div className="flex items-baseline justify-between">
            <h2 className="text-sm font-semibold uppercase tracking-wide text-slate-500">{t('queue.page.list_title')}</h2>
            <p className="text-xs text-slate-500">
              {t('queue.page.waiting')} {formatBn(state.counts.waiting, locale)} · {t('queue.page.done')} {formatBn(state.counts.completed, locale)}
            </p>
          </div>
          <p className="mt-2 text-sm text-slate-600">
            {t('queue.page.next_serials')}: {upNext.length === 0 ? '—' : upNext.map((s) => formatBn(s.c, locale)).join(' · ')}
          </p>
          <ul className="mt-3 grid gap-1">
            {state.serials.slice(0, 40).map((s) => (
              <li key={s.id} className={`flex items-center justify-between rounded-lg px-2 py-2 text-sm ${s.id === mine?.id ? 'bg-primary/10 font-bold' : ''}`}>
                <span className="font-semibold tracking-wide">{formatBn(s.c, locale)}</span>
                <span className="text-slate-500">{t(`queue.status.${s.s === 'b' ? 'booked' : s.s === 'c' ? 'checked_in' : 'in_consultation'}`)}</span>
                <span className="text-slate-600">{s.eta ? formatTimeDhaka(s.eta, locale) : '—'}</span>
              </li>
            ))}
          </ul>
        </section>
      ) : (
        <p className="rounded-2xl bg-white p-5 text-center text-sm shadow-sm">{t('queue.page.no_session')}</p>
      )}

      {sessions.length > 1 ? (
        <nav className="flex flex-wrap justify-center gap-2" aria-label={t('queue.page.session_switch')}>
          {sessions.map((s) => (
            <button
              key={s.public_id}
              type="button"
              onClick={() => setSessionId(s.public_id)}
              className={`min-h-11 rounded-full border px-4 text-sm font-semibold ${s.public_id === (state?.session.id ?? sessionId) ? 'border-primary bg-primary text-on-primary' : 'border-slate-300 bg-white'}`}
            >
              {formatBn(s.code, locale)} · {formatTimeDhaka(s.expected_start_at, locale)}
            </button>
          ))}
        </nav>
      ) : null}

      <footer className="flex items-center justify-center gap-2 text-xs text-slate-500">
        <span className={`inline-block h-2 w-2 rounded-full ${mode === 'online' ? 'bg-green-500' : mode === 'degraded' ? 'bg-amber-500' : 'bg-slate-400'}`} aria-hidden="true" />
        <span>{connection}</span>
        {updatedAt ? <span>· {t('queue.page.updated_at', { time: formatTimeDhaka(updatedAt, locale) })}</span> : null}
      </footer>
    </div>
  );
}

Today.layout = (page: ReactNode) => <SiteLayout title="queue.page.title">{page}</SiteLayout>;
