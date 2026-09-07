// The Reverb Echo client (docs/ARCHITECTURE.md §7.7, REALTIME.md §6): created lazily (the site page loads it after
// first paint), configured from SharedProps.app.reverb with VITE_REVERB_* as the fallback, and bridged into the
// connection store the moment it exists. The device Echo (reception PWA) authenticates with a bearer token
// against /api/device/broadcasting/auth (OFFLINE.md §2).
import type Echo from 'laravel-echo';
import { bridgeEcho } from '../connection/echoBridge';
import type { ReverbConfig } from '../types/shared-props';

export type ReverbEcho = Echo<'reverb'>;

export interface EchoConfig extends ReverbConfig {
  authEndpoint?: string;
  bearerToken?: string | null;
}

let config: EchoConfig | null = null;
let instance: ReverbEcho | null = null;
let loading: Promise<ReverbEcho | null> | null = null;
let unbridge: (() => void) | null = null;

export function echoConfigFromEnv(): EchoConfig | null {
  const env = import.meta.env;
  if (!env.VITE_REVERB_APP_KEY || !env.VITE_REVERB_HOST) return null;
  const scheme = env.VITE_REVERB_SCHEME === 'https' ? 'https' : 'http';
  return { key: env.VITE_REVERB_APP_KEY, host: env.VITE_REVERB_HOST, port: Number(env.VITE_REVERB_PORT ?? (scheme === 'https' ? 443 : 80)), scheme };
}

/** Called once at boot from bootShared(props) — shared props win over env. Re-configuring after creation is ignored. */
export function configureEcho(next: Partial<ReverbConfig> | null | undefined): void {
  const fallback = echoConfigFromEnv();
  const merged: EchoConfig | null = next && next.key && next.host
    ? { key: next.key, host: next.host, port: Number(next.port ?? (next.scheme === 'https' ? 443 : 80)), scheme: next.scheme === 'https' ? 'https' : 'http' }
    : fallback;
  if (!instance) config = merged;
}

export function getEchoConfig(): EchoConfig | null {
  return config;
}

/** Build an Echo (REALTIME §6). Imports laravel-echo + pusher-js on demand and bridges the instance into the store. */
export async function createEcho(opts: EchoConfig): Promise<ReverbEcho> {
  const [{ default: EchoCtor }, { default: Pusher }] = await Promise.all([import('laravel-echo'), import('pusher-js')]);
  const forceTLS = opts.scheme === 'https';
  const echo = new EchoCtor({
    broadcaster: 'reverb',
    key: opts.key,
    wsHost: opts.host,
    wsPort: opts.port,
    wssPort: opts.port,
    forceTLS,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: opts.authEndpoint ?? '/broadcasting/auth',
    bearerToken: opts.bearerToken ?? null,
    Pusher,
    activityTimeout: 30_000,
    pongTimeout: 10_000,
    unavailableTimeout: 5_000,
  }) as ReverbEcho;
  return echo;
}

/** The app's Echo singleton, or null when Reverb is not configured. Safe to call repeatedly. */
export function loadEcho(): Promise<ReverbEcho | null> {
  if (instance) return Promise.resolve(instance);
  if (loading) return loading;
  if (!config) return Promise.resolve(null);
  const cfg = config;
  loading = createEcho(cfg)
    .then((echo) => {
      instance = echo;
      unbridge = bridgeEcho(echo);
      return echo;
    })
    .catch(() => null)
    .finally(() => { loading = null; });
  return loading;
}

export function getEcho(): ReverbEcho | null {
  return instance;
}

export function disconnectEcho(): void {
  unbridge?.();
  unbridge = null;
  instance?.disconnect();
  instance = null;
}

/** A second Echo for the reception device guard (Sanctum device token) — used by the offline module. Not bridged twice. */
export function createDeviceEcho(bearerToken: string): Promise<ReverbEcho | null> {
  if (!config) return Promise.resolve(null);
  return createEcho({ ...config, bearerToken, authEndpoint: '/api/device/broadcasting/auth' });
}

/** Test seam. */
export function resetEchoForTests(): void {
  disconnectEcho();
  config = null;
  loading = null;
}
