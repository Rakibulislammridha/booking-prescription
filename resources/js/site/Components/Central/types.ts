// Shapes the central (SaaS control-plane) marketing + onboarding pages receive from the server.
//
// These live here rather than in `shared/types/models.d.ts` on purpose: they are the *central host's*
// contract (marketing site, signup wizard, subscription invoice), not a tenant model, and only
// `site/Pages/Central/**` reads them.
//
// URLs always arrive as props. The `central.*` routes are not in the Ziggy `site` group, so a page here
// must never call `route()` from `@shared/routes` (CONVENTIONS §7.1's rule bends only because Ziggy
// genuinely does not know these names on this host).

/** Every URL the central shell and its pages need; built server-side with `route('central.…')`. */
export interface CentralLinks {
  home: string;
  pricing: string;
  docs: string;
  changelog: string;
  signup: string;
  /** POST target of the signup wizard. */
  signup_store: string;
  /** PATCH target of the language toggle; empty string hides the toggle. */
  locale: string;
}

/** A plan or an add-on as the pricing pages render it. Money is integer paisa (CONVENTIONS §13). */
export interface PricingPlan {
  code: string;
  name: string;
  description: string | null;
  price_monthly_paisa: number;
  price_yearly_paisa: number;
  trial_days: number;
  is_addon: boolean;
  is_featured: boolean;
  /** Numeric entitlements; `value: null` means unlimited, `0` means not included. */
  limits: { key: string; value: number | null; is_bytes: boolean }[];
  /** Boolean entitlements, rendered as a tick or a cross. */
  toggles: { key: string; enabled: boolean }[];
}

/** One documentation page. `title` and every block string arrive ALREADY TRANSLATED from the server. */
export interface DocSection {
  slug: string;
  title: string;
  url: string;
  blocks: DocBlock[];
}

export interface DocBlock {
  kind: 'para' | 'steps' | 'note';
  text?: string;
  items?: string[];
}

export type ChangeKind = 'added' | 'improved' | 'fixed';

/** One release. `date` is an ISO-8601 timestamp; `changes[].text` is already translated. */
export interface ChangelogEntry {
  version: string;
  date: string;
  changes: { kind: ChangeKind; text: string }[];
}
