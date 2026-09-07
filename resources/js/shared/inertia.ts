// Runtime helpers around Inertia shared props: boot the shared singletons from the first page and keep them in
// sync on navigation (locale/ziggy can change after PATCH /locale or a branch switch).
import { router, usePage } from '@inertiajs/react';
import type { Page } from '@inertiajs/core';
import { setZiggy } from './routes';
import { ensureMessages, initI18n, setLocale } from './i18n';
import { setCsrfToken } from './csrf';
import { configureEcho } from './realtime/echo';
import type { SharedProps } from './types/shared-props';

export type { PageProps } from './types/inertia';

/** Call once in each app.tsx `setup()` with props.initialPage.props before rendering. */
export function bootShared(props: Partial<SharedProps>): void {
  setZiggy(props.ziggy);
  initI18n(props.locale);
  void ensureMessages(props.locale); // no-op when the entry already awaited it (it did); a safety net if not
  setCsrfToken(props.csrf_token);
  configureEcho(props.app?.reverb);
}

/** Keep locale / ziggy / csrf current across Inertia visits. Returns the unsubscribe function. */
export function syncSharedOnNavigate(): () => void {
  const sync = (page: Page<SharedProps>): void => {
    const props = page.props;
    if (props.ziggy) setZiggy(props.ziggy);
    if (props.locale) void setLocale(props.locale); // load the locale's messages, then switch: never a flash of keys
    if (props.csrf_token) setCsrfToken(props.csrf_token);
  };

  // Both events, because neither covers every visit: Inertia skips `navigate` when it replaces the history entry
  // (`if (!replace) fireNavigateEvent(...)` in @inertiajs/core), which is exactly what PATCH /locale → redirect
  // back to the same URL does — the language switch would otherwise never reach the client. `success` in turn
  // never fires for the initial render or for history back/forward. `sync` is idempotent, so overlap is free.
  const offNavigate = router.on('navigate', (event) => sync(event.detail.page as Page<SharedProps>));
  const offSuccess = router.on('success', (event) => sync(event.detail.page as Page<SharedProps>));

  return () => { offNavigate(); offSuccess(); };
}

/** Typed access to the shared props from any component. */
export function useSharedProps(): SharedProps {
  return usePage().props as SharedProps;
}

export function useAuthUser(): SharedProps['auth']['user'] {
  return useSharedProps().auth.user;
}

export function useCan(permission: string): boolean {
  const user = useAuthUser();
  return user?.permissions.includes(permission) ?? false;
}

export function useFeature(name: string): boolean {
  return useSharedProps().features[name] ?? false;
}
