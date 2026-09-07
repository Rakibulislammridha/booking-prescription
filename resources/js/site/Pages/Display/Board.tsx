// Waiting-room display (Inertia::render('Display/Board'), REALTIME.md §9): a TV grid of doctor tiles with ≥ 160 px
// digits on a dark ground, the delay badge, the Bangla/English call-out and kiosk self-healing. Codes only — the
// display never shows a patient name. No MUI, no icon fonts: this runs on a 2016 Android TV box.
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { useQueueState } from '@shared/realtime/useQueueState';
import { useChannel } from '@shared/realtime/useChannel';
import { createDeviceEcho, loadEcho, type ReverbEcho } from '@shared/realtime/echo';
import { QUEUE_EVENTS } from '@shared/realtime/types';
import { formatBn } from '@shared/format/number';
import { getLocale } from '@shared/locale';
import { roomLabel } from '../Queue/room';
import type { PageProps } from '@shared/types/inertia';
import type { CallNextFrame, DisplayTile } from '@shared/types/models';
import type { QueueState } from '@shared/realtime/types';
import { Announcer, browserAdapter, unlockAudio, type VoiceMode } from './voice';
import { ERROR_COOLDOWN_MS, ERROR_RELOAD_MS, ROTATE_MS, TILES_PER_PAGE, gridColumns, pageCount, paginate, reloadReason } from './kiosk';

type Props = PageProps<{
  tenant_public_id: string | null;
  channel: string | null;
  branch: { public_id: string; slug: string; name: string };
  date: string;
  tiles: DisplayTile[];
  states: Record<string, QueueState>;
  device_token: string | null;
  kiosk: boolean;
  voice: VoiceMode;
}>;

const TILES_REFRESH_MS = 600_000;   // §9.3: re-read the branch's sessions every 10 min

let deviceEcho: Promise<ReverbEcho | null> | null = null;
const echoFor = (token: string | null) => (): Promise<ReverbEcho | null> => {
  if (!token) return loadEcho();
  deviceEcho ??= createDeviceEcho(token);
  return deviceEcho;
};

function Tile({ tenantId, tile, initial, onUpdate, flash }: {
  tenantId: string;
  tile: DisplayTile;
  initial: QueueState | null;
  onUpdate: (at: number) => void;
  flash: boolean;
}) {
  const { t } = useTranslation();
  const locale = getLocale();
  const { state } = useQueueState({ tenantId, doctorSlug: tile.doctor.slug, sessionId: tile.id, initial });

  useEffect(() => { if (state) onUpdate(Date.now()); }, [state?.version]); // eslint-disable-line react-hooks/exhaustive-deps

  const name = locale === 'bn' && tile.doctor.name_bn ? tile.doctor.name_bn : tile.doctor.name;
  const second = locale === 'bn' ? tile.doctor.name : tile.doctor.name_bn;
  const upNext = (state?.serials ?? []).filter((s) => s.s !== 'i').slice(0, 3);
  const delay = state?.session.delay_minutes ?? 0;
  const status = state?.session.status ?? 'scheduled';
  const dim = status === 'paused' || status === 'closed' || status === 'cancelled';

  return (
    <section className={`flex flex-col rounded-2xl border border-white/10 p-4 ${dim ? 'bg-white/5 text-white/50' : 'bg-white/10 text-white'} ${flash ? 'ring-4 ring-amber-300' : ''}`}>
      <header className="flex items-baseline justify-between gap-2">
        <div className="min-w-0">
          <h2 className="truncate text-[clamp(18px,2.4vw,34px)] font-bold leading-tight">{name}</h2>
          {second ? <p className="truncate text-[clamp(11px,1.2vw,18px)] opacity-70">{second}</p> : null}
        </div>
        {roomLabel(tile.doctor.room, locale) ? <p className="shrink-0 text-[clamp(12px,1.4vw,22px)] opacity-80">{t('queue.display.room', { room: roomLabel(tile.doctor.room, locale) })}</p> : null}
      </header>

      <p className="flex flex-1 items-center justify-center text-center font-black leading-none tracking-wider text-[clamp(96px,12vw,220px)]" data-testid={`tile-${tile.id}`}>
        {state?.now_serving ? formatBn(state.now_serving.c, locale) : '—'}
      </p>

      <p className="text-center text-[clamp(12px,1.4vw,22px)] opacity-80">
        {t('queue.display.next')}: {upNext.length === 0 ? '—' : upNext.map((s) => formatBn(s.c, locale)).join(' ')}
      </p>

      <footer className="mt-auto flex items-center justify-between pt-2 text-[clamp(11px,1.2vw,18px)] opacity-80">
        <span>{t('queue.display.waiting')} {formatBn(state?.counts.waiting ?? 0, locale)} · {t('queue.display.done')} {formatBn(state?.counts.completed ?? 0, locale)}</span>
        {delay > 0 ? <span className="rounded-full bg-amber-400 px-3 py-1 font-bold text-black">{t('queue.display.late', { minutes: formatBn(delay, locale) })}</span> : null}
        {status === 'paused' ? <span className="rounded-full bg-white/20 px-3 py-1">{t('queue.display.paused')}</span> : null}
      </footer>
    </section>
  );
}

export default function Board({ tenant_public_id, channel, branch, tiles: initialTiles, states, device_token, kiosk, voice }: Props) {
  const { t } = useTranslation();
  const [tiles, setTiles] = useState<DisplayTile[]>(initialTiles);
  const [page, setPage] = useState(0);
  const [sound, setSound] = useState(false);
  const [flashed, setFlashed] = useState<string | null>(null);
  const bootedAt = useRef(Date.now());
  const lastUpdateAt = useRef<number | null>(Date.now());
  const lastCallAt = useRef<number | null>(null);
  const lastErrorReload = useRef(0);
  const announcer = useRef<Announcer | null>(null);
  const tenantId = tenant_public_id ?? '';
  const getEcho = useMemo(() => echoFor(device_token), [device_token]);

  useEffect(() => {
    try { setSound(window.sessionStorage.getItem('bp.display.sound') === 'on'); } catch { /* kiosk relaunch re-prompts */ }
  }, []);

  useEffect(() => {
    announcer.current = new Announcer(browserAdapter(), voice);
  }, [voice]);

  const enable = useCallback((): void => {
    void unlockAudio();
    setSound(true);
    try { window.sessionStorage.setItem('bp.display.sound', 'on'); } catch { /* ignore */ }
    if (kiosk && document.documentElement.requestFullscreen) void document.documentElement.requestFullscreen().catch(() => undefined);
    if (kiosk && 'wakeLock' in navigator) void (navigator as Navigator & { wakeLock?: { request(t: string): Promise<unknown> } }).wakeLock?.request('screen').catch(() => undefined);
  }, [kiosk]);

  useChannel(channel, {
    [QUEUE_EVENTS.callNext]: (payload: unknown) => {
      const frame = payload as CallNextFrame;
      lastCallAt.current = Date.now();
      setFlashed(frame.session);
      window.setTimeout(() => setFlashed((s) => (s === frame.session ? null : s)), 2_000);
      if (sound) announcer.current?.enqueue({ key: `${frame.serial.id}:${frame.version}`, doctor: frame.doctor, code: frame.serial.code, room: frame.room, speak: frame.speak });
    },
    [QUEUE_EVENTS.sessionDelayed]: () => { lastUpdateAt.current = Date.now(); },
    [QUEUE_EVENTS.sessionCancelled]: () => { lastUpdateAt.current = Date.now(); },
    [QUEUE_EVENTS.doctorArrived]: () => { lastUpdateAt.current = Date.now(); },
  }, { echo: getEcho, enabled: Boolean(channel) && Boolean(tenantId) });

  // §9.3 — newly materialised sessions appear without a reload
  useEffect(() => {
    const id = window.setInterval(() => {
      void import('@site/api/queue')
        .then(({ fetchDisplayTiles }) => fetchDisplayTiles(branch.public_id, device_token))
        .then((r) => { setTiles(r.tiles); lastUpdateAt.current = Date.now(); })
        .catch(() => undefined);
    }, TILES_REFRESH_MS);
    return () => window.clearInterval(id);
  }, [branch.public_id, device_token]);

  // §9.1 — more than 9 doctors paginate every 12 s
  useEffect(() => {
    if (pageCount(tiles.length) < 2) { setPage(0); return undefined; }
    const id = window.setInterval(() => setPage((p) => (p + 1) % pageCount(tiles.length)), ROTATE_MS);
    return () => window.clearInterval(id);
  }, [tiles.length]);

  // §9.3 — self-healing reloads
  useEffect(() => {
    const id = window.setInterval(() => {
      const reason = reloadReason({
        now: Date.now(), bootedAt: bootedAt.current, lastUpdateAt: lastUpdateAt.current,
        lastCallAt: lastCallAt.current, anyRunning: tiles.length > 0,
      });
      if (reason !== null) window.location.reload();
    }, 30_000);
    return () => window.clearInterval(id);
  }, [tiles.length]);

  useEffect(() => {
    const onError = (): void => {
      const now = Date.now();
      if (now - lastErrorReload.current < ERROR_COOLDOWN_MS) return;
      lastErrorReload.current = now;
      window.setTimeout(() => window.location.reload(), ERROR_RELOAD_MS);
    };
    window.addEventListener('error', onError);
    window.addEventListener('unhandledrejection', onError);
    return () => {
      window.removeEventListener('error', onError);
      window.removeEventListener('unhandledrejection', onError);
    };
  }, []);

  const visible = paginate(tiles, page, TILES_PER_PAGE);
  const columns = gridColumns(visible.length);

  return (
    <div
      className={`flex min-h-screen flex-col bg-[#0b1220] p-4 text-white ${kiosk ? 'cursor-none select-none' : ''}`}
      onClick={sound ? undefined : enable}
      data-testid="display-board"
    >
      <Head title={`${branch.name} — ${t('queue.display.title')}`} />
      <h1 className="sr-only">{branch.name} — {t('queue.display.title')}</h1>

      {!sound ? (
        <p className="mb-3 rounded-xl bg-amber-400 p-3 text-center text-lg font-bold text-black">{t('queue.display.enable_sound')}</p>
      ) : null}

      {visible.length === 0 ? (
        <p className="flex flex-1 items-center justify-center text-center text-[clamp(24px,4vw,64px)] font-bold opacity-70">{t('queue.display.no_sessions')}</p>
      ) : (
        <div className="grid flex-1 gap-4" style={{ gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))`, gridAutoRows: '1fr' }}>
          {visible.map((tile) => (
            <Tile
              key={tile.id}
              tenantId={tenantId}
              tile={tile}
              initial={states[tile.id] ?? null}
              onUpdate={(at) => { lastUpdateAt.current = at; }}
              flash={flashed === tile.id}
            />
          ))}
        </div>
      )}

      {pageCount(tiles.length) > 1 ? (
        <p className="mt-3 text-center text-sm opacity-60">{formatBn(page + 1, getLocale())} / {formatBn(pageCount(tiles.length), getLocale())}</p>
      ) : null}
    </div>
  );
}
