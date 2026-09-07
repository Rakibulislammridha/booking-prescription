// The connection indicator (docs/OFFLINE.md §9) — one implementation for the panel and the site bundle.
// Plain React + CSS custom properties (no MUI/Emotion so the site may import it). Reads only useConnection.
// Colour is never the only signal: icon + text + document.title; state changes announce via aria-live.
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useConnection, type ConnectionMode, type ConnectionState } from './store';
import { bootConnection } from './boot';
import { formatTimeDhaka } from '../format/date';
import { formatBn } from '../format/number';
import type { Locale } from '../types/shared-props';
import './ConnectionIndicator.css';

export interface ConnectionIndicatorProps {
  /** `desk`: full banner above the app bar (reception). `quiet`: header form for the public queue page. */
  variant?: 'desk' | 'quiet';
  /** Opens the conflict-resolution cards (OFFLINE.md §8) — supplied by the reception module. */
  onConflictsClick?: () => void;
  /** Start the heartbeat if the app has not (the site boots it lazily). Default true. */
  boot?: boolean;
  className?: string;
}

export const OFFLINE_TITLE_PREFIX = '⛔ OFFLINE — ';
const SOUND_KEY = 'connection.sound';

type View = 'connecting' | 'online' | 'degraded' | 'offline' | 'syncing';

function readSoundPreference(): boolean {
  try {
    return typeof localStorage === 'undefined' ? true : localStorage.getItem(SOUND_KEY) !== 'off';
  } catch {
    return true;
  }
}

function writeSoundPreference(on: boolean): void {
  try {
    localStorage.setItem(SOUND_KEY, on ? 'on' : 'off');
  } catch {
    /* private mode */
  }
}

/** Two-tone alert on entering offline (mutable, default on). Silently no-ops where WebAudio is unavailable. */
export function playOfflineTone(): void {
  try {
    const Ctx = (globalThis as { AudioContext?: typeof AudioContext; webkitAudioContext?: typeof AudioContext }).AudioContext
      ?? (globalThis as { webkitAudioContext?: typeof AudioContext }).webkitAudioContext;
    if (!Ctx) return;
    const ctx = new Ctx();
    const tone = (freq: number, at: number, dur: number): void => {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.type = 'square';
      osc.frequency.value = freq;
      gain.gain.setValueAtTime(0.0001, at);
      gain.gain.exponentialRampToValueAtTime(0.2, at + 0.01);
      gain.gain.exponentialRampToValueAtTime(0.0001, at + dur);
      osc.connect(gain).connect(ctx.destination);
      osc.start(at);
      osc.stop(at + dur + 0.02);
    };
    const t = ctx.currentTime;
    tone(660, t, 0.16);
    tone(440, t + 0.18, 0.22);
    setTimeout(() => { void ctx.close(); }, 700);
  } catch {
    /* autoplay policy or no audio device */
  }
}

/** Which banner to show. Exported for tests. */
export function viewFor(s: Pick<ConnectionState, 'mode' | 'browserOnline' | 'lastHeartbeatOkAt' | 'consecutiveHeartbeatFailures' | 'pendingEvents' | 'syncPhase'>): View {
  if (s.mode === 'offline') {
    // boot state: the store starts in `offline` until the first heartbeat answers — do not alarm the desk yet
    const undecided = s.browserOnline && s.lastHeartbeatOkAt === null && s.consecutiveHeartbeatFailures === 0;
    return undecided ? 'connecting' : 'offline';
  }
  if (s.pendingEvents > 0 || s.syncPhase === 'syncing') return 'syncing';
  return s.mode;
}

const selectView = (s: ConnectionState): ConnectionState => s;

export function ConnectionIndicator({ variant = 'desk', onConflictsClick, boot = true, className }: ConnectionIndicatorProps) {
  const { t, i18n } = useTranslation();
  const locale = (i18n.language === 'bn' ? 'bn' : 'en') as Locale;
  const s = useConnection(selectView);
  const view = viewFor(s);
  const [soundOn, setSoundOn] = useState<boolean>(readSoundPreference);
  const [syncTotal, setSyncTotal] = useState(0);
  const prevMode = useRef<ConnectionMode | null>(null);
  const baseTitle = useRef<string | null>(null);

  useEffect(() => {
    if (boot) bootConnection();
  }, [boot]);

  // offline extras: title prefix, viewport outline, entry sound — only on a real transition into offline
  useEffect(() => {
    if (typeof document === 'undefined') return;
    if (view === 'offline') {
      document.documentElement.dataset.connection = 'offline';
      const entered = prevMode.current !== null && prevMode.current !== 'offline';
      if (entered && soundOn) playOfflineTone();
      if (!document.title.startsWith(OFFLINE_TITLE_PREFIX)) {
        baseTitle.current = document.title;
        document.title = OFFLINE_TITLE_PREFIX + document.title;
      }
      // Inertia <Head> rewrites the title on navigation; keep the prefix while offline
      const titleEl = document.querySelector('title');
      const observer = titleEl && typeof MutationObserver !== 'undefined'
        ? new MutationObserver(() => {
            if (!document.title.startsWith(OFFLINE_TITLE_PREFIX)) {
              baseTitle.current = document.title;
              document.title = OFFLINE_TITLE_PREFIX + document.title;
            }
          })
        : null;
      observer?.observe(titleEl as Node, { childList: true, characterData: true, subtree: true });
      return () => observer?.disconnect();
    }
    document.documentElement.dataset.connection = view === 'connecting' ? 'connecting' : s.mode;
    if (document.title.startsWith(OFFLINE_TITLE_PREFIX)) {
      document.title = baseTitle.current ?? document.title.slice(OFFLINE_TITLE_PREFIX.length);
    }
    return undefined;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [view]);

  useEffect(() => {
    if (view !== 'connecting') prevMode.current = s.mode;
  }, [view, s.mode]);

  // "syncing accepted/total": total = pending count observed when we came back; accepted = total - pending
  useEffect(() => {
    if (view === 'syncing') setSyncTotal((total) => Math.max(total, s.pendingEvents));
    else setSyncTotal(0);
  }, [view, s.pendingEvents]);

  const toggleSound = (): void => {
    const next = !soundOn;
    setSoundOn(next);
    writeSoundPreference(next);
  };

  const since = formatTimeDhaka(s.since, locale);
  const conflicts = s.conflicts > 0 ? (
    <button type="button" className="ci__badge" onClick={onConflictsClick} data-testid="ci-conflicts">
      {t('connection.conflicts', { count: formatBn(s.conflicts, locale) })}
    </button>
  ) : null;

  const rootClass = ['ci', `ci--${view}`, `ci--${variant}`, className].filter(Boolean).join(' ');

  if (view === 'online' || view === 'connecting') {
    const label = view === 'online' ? t('connection.online') : t('connection.connecting');
    return (
      <div className={rootClass} data-testid="connection-indicator" data-view={view} data-mode={s.mode}>
        {variant === 'desk' ? <div className="ci__strip" aria-hidden="true" /> : null}
        <div className="ci__status" role="status" aria-live="polite">
          <span className="ci__dot" aria-hidden="true" />
          <span>{label}</span>
          {conflicts}
        </div>
      </div>
    );
  }

  if (view === 'degraded') {
    return (
      <div className={rootClass} data-testid="connection-indicator" data-view={view} data-mode={s.mode}>
        <div className="ci__bar" role="status" aria-live="polite">
          <span className="ci__spinner" aria-hidden="true" />
          <span className="ci__text">
            {t('connection.degraded')}
            <span className="ci__sep" aria-hidden="true">·</span>
            <span className="ci__since">{t('connection.since', { time: since })}</span>
          </span>
          {conflicts}
        </div>
      </div>
    );
  }

  if (view === 'syncing') {
    const accepted = Math.max(0, syncTotal - s.pendingEvents);
    return (
      <div className={rootClass} data-testid="connection-indicator" data-view={view} data-mode={s.mode}>
        <div className="ci__bar" role="status" aria-live="polite">
          <span className="ci__spinner" aria-hidden="true" />
          <span className="ci__text">{t('connection.back_online_syncing', { count: formatBn(s.pendingEvents, locale) })}</span>
          {syncTotal > 0 ? <span className="ci__progress">{formatBn(`${accepted}/${syncTotal}`, locale)}</span> : null}
          {conflicts}
        </div>
      </div>
    );
  }

  // offline
  const block = s.activeBlock;
  return (
    <div className={rootClass} data-testid="connection-indicator" data-view={view} data-mode={s.mode}>
      <div className="ci__bar" role="alert" aria-live="assertive">
        <span className="ci__icon" aria-hidden="true">⛔</span>
        <span className="ci__text">
          {t('connection.offline_since', { time: since })}
          {block ? (
            <>
              <span className="ci__sep" aria-hidden="true">·</span>
              {t('connection.issuing_from_block', { from: block.displayFrom, to: block.displayTo, remaining: formatBn(block.remaining, locale) })}
            </>
          ) : null}
          {s.pendingEvents > 0 ? (
            <>
              <span className="ci__sep" aria-hidden="true">·</span>
              {t('connection.actions_queued', { count: formatBn(s.pendingEvents, locale) })}
            </>
          ) : null}
        </span>
        {conflicts}
        <button type="button" className="ci__mute" onClick={toggleSound} aria-pressed={!soundOn} data-testid="ci-mute">
          {soundOn ? t('connection.sound_mute') : t('connection.sound_unmute')}
        </button>
      </div>
    </div>
  );
}
