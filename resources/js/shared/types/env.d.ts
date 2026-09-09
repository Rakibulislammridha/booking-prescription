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

// Per-(bundle, locale) translation bundles emitted by the `bp:lang-bundles` Vite plugin (vite.config.ts): the
// query picks that bundle's slice of resources/lang/<locale>.json. Loaded from @shared/lang/{site,panel}.ts.
// `?panel` is the panel's small base; `?panel-<module>` is one module's extra keys, loaded in parallel with the
// page chunk. The list mirrors PANEL_MODULES in @shared/lang/surfaces.ts — a new module adds two lines here.
declare module '@lang/en.json?site' { const messages: Record<string, string>; export default messages; }
declare module '@lang/bn.json?site' { const messages: Record<string, string>; export default messages; }
declare module '@lang/en.json?panel' { const messages: Record<string, string>; export default messages; }
declare module '@lang/bn.json?panel' { const messages: Record<string, string>; export default messages; }
declare module '@lang/en.json?panel-billing' { const messages: Record<string, string>; export default messages; }
declare module '@lang/bn.json?panel-billing' { const messages: Record<string, string>; export default messages; }
declare module '@lang/en.json?panel-catalog' { const messages: Record<string, string>; export default messages; }
declare module '@lang/bn.json?panel-catalog' { const messages: Record<string, string>; export default messages; }
declare module '@lang/en.json?panel-clinic' { const messages: Record<string, string>; export default messages; }
declare module '@lang/bn.json?panel-clinic' { const messages: Record<string, string>; export default messages; }
declare module '@lang/en.json?panel-notifications' { const messages: Record<string, string>; export default messages; }
declare module '@lang/bn.json?panel-notifications' { const messages: Record<string, string>; export default messages; }
declare module '@lang/en.json?panel-patients' { const messages: Record<string, string>; export default messages; }
declare module '@lang/bn.json?panel-patients' { const messages: Record<string, string>; export default messages; }
declare module '@lang/en.json?panel-prescription' { const messages: Record<string, string>; export default messages; }
declare module '@lang/bn.json?panel-prescription' { const messages: Record<string, string>; export default messages; }
declare module '@lang/en.json?panel-queue' { const messages: Record<string, string>; export default messages; }
declare module '@lang/bn.json?panel-queue' { const messages: Record<string, string>; export default messages; }
declare module '@lang/en.json?panel-reception' { const messages: Record<string, string>; export default messages; }
declare module '@lang/bn.json?panel-reception' { const messages: Record<string, string>; export default messages; }
declare module '@lang/en.json?panel-reports' { const messages: Record<string, string>; export default messages; }
declare module '@lang/bn.json?panel-reports' { const messages: Record<string, string>; export default messages; }
declare module '@lang/en.json?panel-saas' { const messages: Record<string, string>; export default messages; }
declare module '@lang/bn.json?panel-saas' { const messages: Record<string, string>; export default messages; }
declare module '@lang/en.json?panel-scheduling' { const messages: Record<string, string>; export default messages; }
declare module '@lang/bn.json?panel-scheduling' { const messages: Record<string, string>; export default messages; }
declare module '@lang/en.json?panel-super' { const messages: Record<string, string>; export default messages; }
declare module '@lang/bn.json?panel-super' { const messages: Record<string, string>; export default messages; }
declare module '@lang/en.json?panel-telemedicine' { const messages: Record<string, string>; export default messages; }
declare module '@lang/bn.json?panel-telemedicine' { const messages: Record<string, string>; export default messages; }
