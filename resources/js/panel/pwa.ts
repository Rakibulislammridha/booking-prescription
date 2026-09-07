// Service-worker registration for the desk PWA (docs/ARCHITECTURE.md §7.2, OFFLINE.md §11). Imported only by
// panel/app.tsx. registerType is 'prompt': the shell is never swapped mid-shift — the layout shows
// "Update ready — apply when the desk is idle" and the update is applied only when pendingEvents === 0.
import { registerSW } from 'virtual:pwa-register';
import { create } from 'zustand';
import { useConnection } from '@shared/connection/store';

export interface PwaState {
  registered: boolean;
  needRefresh: boolean;
  offlineReady: boolean;
  waitingForIdle: boolean;
  /** Apply the waiting service worker now, or as soon as the event log is empty. */
  applyUpdate(): void;
  dismiss(): void;
}

let updateSW: ((reloadPage?: boolean) => Promise<void>) | null = null;
let idleUnsubscribe: (() => void) | null = null;

export const usePwa = create<PwaState>()((set, get) => ({
  registered: false,
  needRefresh: false,
  offlineReady: false,
  waitingForIdle: false,
  applyUpdate: () => {
    if (!updateSW || !get().needRefresh) return;
    const apply = (): void => {
      idleUnsubscribe?.();
      idleUnsubscribe = null;
      set({ waitingForIdle: false, needRefresh: false });
      void updateSW?.(true);
    };
    if (useConnection.getState().pendingEvents === 0) { apply(); return; }
    set({ waitingForIdle: true });
    idleUnsubscribe = useConnection.subscribe((s) => s.pendingEvents, (pending) => { if (pending === 0) apply(); });
  },
  dismiss: () => set({ needRefresh: false, offlineReady: false }),
}));

export function registerPanelServiceWorker(): void {
  if (typeof navigator === 'undefined' || !('serviceWorker' in navigator)) return;
  if (usePwa.getState().registered) return;
  usePwa.setState({ registered: true });
  updateSW = registerSW({
    immediate: true,
    onNeedRefresh: () => usePwa.setState({ needRefresh: true }),
    onOfflineReady: () => usePwa.setState({ offlineReady: true }),
    onRegisterError: (error: unknown) => { console.warn('[pwa] service worker registration failed', error); },
  });
}
