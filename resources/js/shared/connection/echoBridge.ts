// Wires the browser and laravel-echo into the store (docs/OFFLINE.md §3.3). Components never bind to Echo or
// navigator.onLine themselves (CONVENTIONS §7.2). `ConnectionStatus` is laravel-echo 2.4's union
// ('connected' | 'disconnected' | 'connecting' | 'reconnecting' | 'failed'); only its type is imported here.
import type { ConnectionStatus } from 'laravel-echo';
import { useConnection } from './store';

export interface EchoConnectorLike {
  connectionStatus(): ConnectionStatus;
  onConnectionChange(callback: (status: ConnectionStatus) => void): () => void;
}

export interface EchoLike {
  connector: EchoConnectorLike;
}

/** Feed an Echo instance's connection status into the store. Returns the unsubscribe function. */
export function bridgeEcho(echo: EchoLike): () => void {
  const { setWsState } = useConnection.getState();
  setWsState(echo.connector.connectionStatus());
  return echo.connector.onConnectionChange((status) => setWsState(status));
}

let browserBound = false;
let unbindBrowser: (() => void) | null = null;

/** window online/offline + document.visibilitychange → store (idempotent). Returns the unbind function. */
export function bindBrowserEvents(win: Window = window, doc: Document = document): () => void {
  if (browserBound && unbindBrowser) return unbindBrowser;
  const store = useConnection.getState();
  const onOnline = (): void => store.setBrowserOnline(true);   // true only triggers a heartbeat (heartbeat.ts subscribes)
  const onOffline = (): void => store.setBrowserOnline(false); // false ⇒ immediate offline
  const onVisibility = (): void => store.setTabVisible(doc.visibilityState !== 'hidden');
  win.addEventListener('online', onOnline);
  win.addEventListener('offline', onOffline);
  doc.addEventListener('visibilitychange', onVisibility);
  store.setBrowserOnline(typeof win.navigator === 'undefined' ? true : win.navigator.onLine);
  onVisibility();
  browserBound = true;
  unbindBrowser = () => {
    win.removeEventListener('online', onOnline);
    win.removeEventListener('offline', onOffline);
    doc.removeEventListener('visibilitychange', onVisibility);
    browserBound = false;
    unbindBrowser = null;
  };
  return unbindBrowser;
}
