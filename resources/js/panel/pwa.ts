// Service-worker registration for the desk PWA (docs/ARCHITECTURE.md §7.2, OFFLINE.md §11). Imported only by
// panel/app.tsx. registerType is 'prompt': the shell is never swapped mid-shift — the layout shows
// "Update ready — apply when the desk is idle" and the update is applied only when pendingEvents === 0.
//
// A PROMPT ALONE IS NOT ENOUGH ON A KIOSKED TABLET, which is what a front desk is: one page, opened in the
// morning, never closed, never reloaded, nobody looking for toasts. Two things follow from that, and both are
// implemented below because the worker this release replaces is the one that cached an authenticated board
// (sw.ts header) — it has to stop running on every tablet, not on the attentive ones.
//
//   1. A tablet that never navigates never asks whether there is a new worker. The browser re-checks sw.js on
//      navigation, and at most once a day; a page that is never reloaded can therefore sit on the old worker
//      indefinitely. So the registration polls `registration.update()` hourly instead.
//   2. Nobody clicks "Apply". So the update applies ITSELF once the desk is genuinely idle — no pending events
//      to lose and no hands on the glass (or the screen is off). The manual button still works and still wins;
//      the automatic path only removes the requirement that somebody be watching. It is deliberately never
//      immediate: `updateSW(true)` reloads the page, and a reload through a half-typed patient name is its own
//      small outage.
//
// We also purge the two leaky caches from HERE, not only from the worker. Cache Storage is same-origin and the
// page can reach it, so the previous user's board dies the first time the desk is opened after this deploy —
// without waiting for the new worker to activate, and whichever worker is currently in control. The old worker
// will re-fill `shell-v1` on its next navigation, which is exactly why (1) and (2) above exist: this narrows the
// window, the swap closes it.
import { registerSW } from 'virtual:pwa-register';
import { create } from 'zustand';
import { useConnection } from '@shared/connection/store';

/** Named in resources/js/panel/sw.ts as LEGACY_CACHES; repeated here because importing sw.ts would drag workbox into the panel entry. */
const LEGACY_CACHES = ['shell-v1', 'api-patients'] as const;

/** The offline shell (public/offline.html). `ignoreSearch` because workbox precaches it under a `__WB_REVISION__` key. */
const OFFLINE_SHELL_URL = '/offline.html';

/** How long the glass must go untouched before a waiting update applies itself. A shift has gaps this long between patients. */
const IDLE_BEFORE_AUTO_APPLY_MS = 5 * 60_000;

/** How often a tablet that is never reloaded asks whether there is a newer worker. */
const UPDATE_CHECK_INTERVAL_MS = 60 * 60_000;

/** How often the auto-apply timer re-asks "is the desk idle yet". */
const IDLE_POLL_MS = 30_000;

export interface PwaState {
  registered: boolean;
  needRefresh: boolean;
  /**
   * The precache has landed AND the offline shell is in it — i.e. the desk will OPEN without a network and
   * explain itself, and the work already in Dexie stays safe.
   *
   * It is NOT the old promise that the desk cold-boots into the board: that needs a cached authenticated
   * document, which is precisely what was removed (sw.ts header, OFFLINE §11.1). Anything rendered from this
   * flag must say the narrower thing.
   */
  offlineReady: boolean;
  waitingForIdle: boolean;
  /** Apply the waiting service worker now, or as soon as the event log is empty. */
  applyUpdate(): void;
  dismiss(): void;
}

let updateSW: ((reloadPage?: boolean) => Promise<void>) | null = null;
/**
 * A worker is waiting. Kept apart from `needRefresh`, which is only whether the TOAST is on screen: dismissing
 * the toast must not also dismiss the update, or the one gesture a tired receptionist makes at a nagging
 * snackbar would pin the tablet to the worker this release exists to replace.
 */
let updateWaiting = false;
let idleUnsubscribe: (() => void) | null = null;
let autoApplyTimer: ReturnType<typeof setInterval> | null = null;
let lastInteractionAt = Date.now();

const noteInteraction = (): void => { lastInteractionAt = Date.now(); };

/** Idle = the screen is off / the tab is in the background, or nobody has touched it for IDLE_BEFORE_AUTO_APPLY_MS. */
function deskIsIdle(): boolean {
  if (typeof document !== 'undefined' && document.visibilityState === 'hidden') return true;

  return Date.now() - lastInteractionAt >= IDLE_BEFORE_AUTO_APPLY_MS;
}

function stopAutoApply(): void {
  if (autoApplyTimer !== null) clearInterval(autoApplyTimer);
  autoApplyTimer = null;
}

export const usePwa = create<PwaState>()((set, get) => ({
  registered: false,
  needRefresh: false,
  offlineReady: false,
  waitingForIdle: false,
  applyUpdate: () => {
    // `waitingForIdle` means one subscription is already armed. The auto-apply poller calls this every 30 s, so
    // without the guard each tick would leave another live subscription behind and they would all fire at once.
    if (!updateSW || !updateWaiting || get().waitingForIdle) return;
    const apply = (): void => {
      idleUnsubscribe?.();
      idleUnsubscribe = null;
      stopAutoApply();
      updateWaiting = false;
      set({ waitingForIdle: false, needRefresh: false });
      void updateSW?.(true);
    };
    if (useConnection.getState().pendingEvents === 0) { apply(); return; }
    set({ waitingForIdle: true });
    idleUnsubscribe = useConnection.subscribe((s) => s.pendingEvents, (pending) => { if (pending === 0) apply(); });
  },
  // Hides the toast only. It does not veto the automatic apply below: "not now" is an answer about the toast,
  // not a decision to keep serving the worker this release exists to replace.
  dismiss: () => set({ needRefresh: false, offlineReady: false }),
}));

/** Arms the unattended path: once the desk is idle, applyUpdate() runs itself (and then still waits for sync). */
function armAutoApply(): void {
  if (autoApplyTimer !== null) return;
  autoApplyTimer = setInterval(() => {
    if (!updateWaiting) { stopAutoApply(); return; }
    if (deskIsIdle()) usePwa.getState().applyUpdate();
  }, IDLE_POLL_MS);
}

/**
 * Confirms the shell is really there before claiming the desk opens offline. `onOfflineReady` fires when workbox
 * finishes precaching; it does not know that one of those entries is the page a failed navigation lands on. If the
 * glob ever stops matching public/offline.html the flag stays false and the warning says why, rather than the desk
 * promising an offline boot it can no longer perform.
 */
async function confirmOfflineShell(): Promise<void> {
  if (typeof caches === 'undefined') return;
  try {
    const shell = await caches.match(OFFLINE_SHELL_URL, { ignoreSearch: true });
    if (shell) { usePwa.setState({ offlineReady: true }); return; }
    console.warn(`[pwa] precache landed without ${OFFLINE_SHELL_URL} — the desk will NOT open offline`);
  } catch (error) {
    console.warn('[pwa] could not verify the offline shell', error);
  }
}

export function registerPanelServiceWorker(): void {
  if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return;
  if (usePwa.getState().registered) return;
  usePwa.setState({ registered: true });

  // Same-origin, works whichever worker is in control, and destroys no work: both caches are HTTP responses,
  // never the Dexie event log.
  if (typeof caches !== 'undefined') {
    for (const name of LEGACY_CACHES) void caches.delete(name).catch(() => undefined);
  }

  if (typeof document !== 'undefined') {
    for (const event of ['pointerdown', 'keydown'] as const) {
      document.addEventListener(event, noteInteraction, { passive: true, capture: true });
    }
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') noteInteraction(); });
  }

  updateSW = registerSW({
    immediate: true,
    onNeedRefresh: () => { updateWaiting = true; usePwa.setState({ needRefresh: true }); armAutoApply(); },
    onOfflineReady: () => { void confirmOfflineShell(); },
    onRegisteredSW: (_swUrl: string, registration: ServiceWorkerRegistration | undefined) => {
      if (!registration) return;
      // The kiosk's only chance to hear about a new worker: it never navigates, so nothing else asks.
      setInterval(() => {
        if (typeof navigator !== 'undefined' && navigator.onLine === false) return;
        void registration.update().catch(() => undefined);
      }, UPDATE_CHECK_INTERVAL_MS);
    },
    onRegisterError: (error: unknown) => { console.warn('[pwa] service worker registration failed', error); },
  });
}
