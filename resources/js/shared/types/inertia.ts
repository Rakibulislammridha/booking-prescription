import type { SharedProps } from './shared-props';

/**
 * Page props as seen by a page component: the shared props plus the page's own.
 *   export default function Board({ sessions }: PageProps<{ sessions: SessionSummary[] }>) { … }
 *
 * The page's own keys WIN. A plain `SharedProps & T` intersects a colliding key into
 * `BranchOption[] & MyType`, which is not what the server sends and not what the page reads — a page prop
 * named `branches` would silently type as the branch-switcher list. `Omit` drops the shared key instead, so a
 * page that declares one gets exactly its own type and the shared value for that key (which Inertia no longer
 * sends, page props overwrite shared ones on the wire) stops being visible — matching runtime behaviour.
 * Shared props a page does not redeclare are unchanged, and `useSharedProps()` still sees the full contract.
 */
export type PageProps<T extends object = Record<never, never>> = Omit<SharedProps, keyof T> & T;

export type { SharedProps, Locale, Guard, AuthUser, SharedTenant, SharedBranch, FlashProps, ReverbConfig } from './shared-props';
