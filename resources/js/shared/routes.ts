// Ziggy wrapper (docs/ARCHITECTURE.md §7.4): the config is captured from the first page load with
// setZiggy(props.ziggy) in each app.tsx. Components call route('panel.serials.reorder', { serial })
// and never build URL strings by hand (CONVENTIONS §7.1). No `@routes` Blade directive.
import { route as ziggyRoute } from 'ziggy-js';
import type { Config as ZiggyConfig } from 'ziggy-js';

type RouteName = NonNullable<Parameters<typeof ziggyRoute>[0]>;
type RouteParamsOf<T extends RouteName> = Parameters<typeof ziggyRoute<T>>[1];

let config: ZiggyConfig | undefined;

export function setZiggy(next: ZiggyConfig | undefined | null): void {
  if (next && typeof next === 'object' && next.routes) config = next;
}

export function getZiggy(): ZiggyConfig | undefined {
  return config;
}

/** Resolve a named route to a URL. Throws when the name is unknown to the current surface group. */
export function route<T extends RouteName>(name: T, params?: RouteParamsOf<T>, absolute = false): string {
  if (!config) throw new Error('Ziggy config missing: call setZiggy(props.ziggy) at boot before route()');
  return String(ziggyRoute(name, params, absolute, config));
}

/** True when the route exists in the surface's Ziggy group — use it to hide navigation to modules not yet shipped. */
export function hasRoute(name: string): boolean {
  return config ? ziggyRoute(undefined, undefined, false, config).has(name) : false;
}

/** Name of the current route (pattern match, e.g. isRoute('panel.reception.*')). */
export function isRoute(name: string): boolean {
  return config ? ziggyRoute(undefined, undefined, false, config).current(name) : false;
}

export function currentRoute(): string | undefined {
  return config ? ziggyRoute(undefined, undefined, false, config).current() : undefined;
}
