// One call per app: bind browser events and start the heartbeat. Idempotent, so the site can bind events at
// boot and start the (traffic-generating) heartbeat only when a live consumer mounts (queue page, indicator).
import { bindBrowserEvents } from './echoBridge';
import { isHeartbeatRunning, startHeartbeat, stopHeartbeat } from './heartbeat';

export interface BootConnectionOptions {
  baseUrl?: string;
  heartbeat?: boolean; // default true
}

let unbind: (() => void) | null = null;

export function bootConnection(options: BootConnectionOptions = {}): () => void {
  if (typeof window !== 'undefined' && !unbind) unbind = bindBrowserEvents();
  if (options.heartbeat !== false && !isHeartbeatRunning()) startHeartbeat(options.baseUrl ?? '');
  return shutdownConnection;
}

export function isConnectionBooted(): boolean {
  return isHeartbeatRunning();
}

export function shutdownConnection(): void {
  stopHeartbeat();
  unbind?.();
  unbind = null;
}
