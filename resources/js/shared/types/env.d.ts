// Vite env vars read by the client (.env: VITE_*). Shared props (`app.reverb`) take precedence at runtime.
interface ImportMetaEnv {
  readonly VITE_APP_NAME?: string;
  readonly VITE_REVERB_APP_KEY?: string;
  readonly VITE_REVERB_HOST?: string;
  readonly VITE_REVERB_PORT?: string;
  readonly VITE_REVERB_SCHEME?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}

// Per-(surface, locale) translation bundles emitted by the `bp:lang-bundles` Vite plugin (vite.config.ts):
// the query picks the surface's slice of resources/lang/<locale>.json. Loaded from @shared/lang/{site,panel}.ts.
declare module '@lang/en.json?site' { const messages: Record<string, string>; export default messages; }
declare module '@lang/bn.json?site' { const messages: Record<string, string>; export default messages; }
declare module '@lang/en.json?panel' { const messages: Record<string, string>; export default messages; }
declare module '@lang/bn.json?panel' { const messages: Record<string, string>; export default messages; }
