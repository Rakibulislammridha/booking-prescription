// Server-rendered copy for the central marketing surface.
//
// Why these pages do NOT use `t()`. The site bundle ships ONE translation chunk per locale, shared by every
// site route (vite.config.ts `bp:lang-bundles`, surfaces.ts). Adding the marketing, pricing, sign-up and invoice
// strings to it would put ~4.5 KB gzip of copy that only the central host ever renders into the first load of
// every tenant page — and the worst tenant route already sits at 90.7 KB of the 95 KB budget (REALTIME.md §8),
// so it would break the build outright.
//
// The strings are therefore translated in PHP (`App\Domain\SaaS\Support\CentralCopy`) and arrive as a page prop,
// which is exactly what the docs page already does with its bodies. `php artisan lang:check` still validates
// every key, because every one of them is a literal `__('saas.…')` in the copy catalogue.
//
// Keys arrive WITHOUT the `saas.` prefix (`marketing.hero_title`), because the whole map is `saas.*` by
// construction and repeating it 190 times is payload for nothing.

export type Copy = Record<string, string>;

export type CopyReplacements = Record<string, string | number>;

/** `c('onboarding.step_of', { current: 2, total: 4 })` — Laravel `:name` placeholders, resolved on the client. */
export type CopyFn = (key: string, replace?: CopyReplacements) => string;

/**
 * An unknown key returns the key itself, exactly like i18next's fallback: a missing string shows up as
 * `marketing.hero_title` in the page rather than as an empty element or a crash.
 */
export function makeCopy(copy: Copy): CopyFn {
  return (key, replace) => {
    let value = copy[key] ?? key;

    if (replace !== undefined) {
      for (const [name, replacement] of Object.entries(replace)) {
        value = value.split(`:${name}`).join(String(replacement));
      }
    }

    return value;
  };
}
