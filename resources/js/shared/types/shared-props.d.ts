// Shared-props contract: HandleInertiaRequests::share() → this file (docs/ARCHITECTURE.md §7.4).
// Foundation-owned. Modules request new fields via a `[foundation] <module>: <need>` PR.
import type { Config as ZiggyConfig } from 'ziggy-js';

export type Locale = 'bn' | 'en';
export type Guard = 'web' | 'patient' | 'super';
/** The route group that served the page (ARCHITECTURE §2): `super` is the console, `panel` the clinic's staff panel. */
export type Surface = 'panel' | 'super' | 'site' | 'central';

export interface AuthUser {
  id: number;
  name: string;
  roles: string[];
  permissions: string[];
  doctor_id: number | null;
}

export interface SharedAuth {
  guard: Guard | null;
  user: AuthUser | null;
  impersonating: boolean;
}

export interface SharedTenant {
  id: number;
  slug: string;
  name: string;
  locale: Locale;
  timezone: string;
  logo_url: string | null;
  theme: Record<string, string>;
  modules: string[];
}

export interface SharedBranch {
  id: number;
  name: string;
  code: string;
}

export interface BranchOption {
  id: number;
  name: string;
}

/** Session flash (`redirect()->with('flash.success', …)` / `session()->now('flash.warning', …)`); null when unset. */
export interface FlashProps {
  success: string | null;
  error: string | null;
  warning: string | null;
  info: string | null;
}

export interface ReverbConfig {
  key: string;
  host: string;
  port: number;
  scheme: 'http' | 'https';
}

export interface SharedApp {
  name: string;
  env: string;
  version: string;
  reverb: ReverbConfig;
}

export type SharedProps = {
  surface: Surface;                     // which surface served the page; PanelLayout picks its navigation by it
  auth: SharedAuth;
  tenant: SharedTenant | null;
  branch: SharedBranch | null;          // staff's active branch (SetActiveBranch); null on site/super
  branches: BranchOption[];             // for the branch switcher; [] elsewhere
  locale: Locale;
  flash: FlashProps;                    // session keys flash.{success,error,warning,info}
  features: Record<string, boolean>;    // Pennant values for the current tenant (Inertia::once)
  ziggy: ZiggyConfig;                   // Inertia::once, per surface group
  csrf_token: string;
  app: SharedApp;
  errors: Record<string, string>;
};

// Teach Inertia's usePage()/createInertiaApp() the shared-props shape once, for both bundles. Flash travels as a
// shared prop (`flash`), not through Inertia::flash(), so no flashDataType is declared.
declare module '@inertiajs/core' {
  interface InertiaConfig {
    sharedPageProps: SharedProps;
    errorValueType: string;
  }
}
