import { defineConfig, type Plugin } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';
import fs from 'node:fs';
import path from 'node:path';
import { isLangSurface, messagesForSurface, type LangSurface } from './resources/js/shared/lang/surfaces';

// Foundation-owned; reproduced from docs/ARCHITECTURE.md §7.2. Modules never edit this file.
//
// `npm run build` runs this config TWICE — `BP_SURFACE=site` then `BP_SURFACE=panel` — because the two apps
// need different module graphs (REALTIME.md §8): the patient-facing site resolves react/react-dom to
// preact/compat and carries only its own vendor chunk, while the staff panel keeps React 19 + MUI. Both passes
// write into public/build and the panel pass merges the site's manifest entries back in, so Laravel still sees
// one public/build/manifest.json and `@vite([...])` is unchanged in both root views.
// `npm run dev` (BP_SURFACE unset) serves both entries from one server with real React — preact only ever
// enters the production site bundle, which is what `npm run build` gates.
const SURFACE = process.env.BP_SURFACE === 'site' ? 'site' : process.env.BP_SURFACE === 'panel' ? 'panel' : 'all';
const IS_SITE = SURFACE === 'site';
const HAS_PANEL = SURFACE !== 'site';
const SITE_MANIFEST = 'public/build/.manifest-site.json';

const PANEL_INPUT = ['resources/js/panel/app.tsx', 'resources/css/panel.css'];
const SITE_INPUT = ['resources/js/site/app.tsx', 'resources/css/site.css'];

// vite-plugin-pwa emits the web manifest into Vite's outDir (public/build/panel.webmanifest), but the service worker
// precaches it at its own root (`panel.webmanifest` → /panel.webmanifest) and panel.blade.php links it there.
// Copy it up after every build so the precache entry exists and the SW installs (ARCHITECTURE §7.2).
function copyPanelManifest(): Plugin {
  return {
    name: 'bp:copy-panel-manifest',
    apply: 'build',
    closeBundle: {
      sequential: true,
      order: 'post',
      handler() {
        const src = path.resolve('public/build/panel.webmanifest');
        if (fs.existsSync(src)) fs.copyFileSync(src, path.resolve('public/panel.webmanifest'));
      },
    },
  };
}

const LANG_REQUEST = /(?:^|[\\/])(en|bn)\.json\?(panel|site)$/;
const LANG_VIRTUAL = /^\0bp-lang\/lang-(panel|site)-(en|bn)$/;

/**
 * `@lang/<locale>.json?<surface>` → that surface's slice of the flat Laravel messages, as its own chunk
 * (resources/js/shared/lang/surfaces.ts holds the prefix lists). resources/lang/{en,bn}.json stay the single
 * source of truth for PHP and `php artisan lang:check`; nothing is generated on disk. Emitted as
 * `JSON.parse('…')` because the engine parses that faster, and smaller, than an object literal.
 */
function langBundles(): Plugin {
  return {
    name: 'bp:lang-bundles',
    enforce: 'pre',
    resolveId(source) {
      const match = LANG_REQUEST.exec(source);
      if (!match || !isLangSurface(match[2] as string)) return null;

      return `\0bp-lang/lang-${match[2]}-${match[1]}`; // virtual: keeps vite:json off a module that is now JS
    },
    load(id) {
      const match = LANG_VIRTUAL.exec(id);
      if (!match) return null;

      const [, surface, locale] = match;
      const all = JSON.parse(fs.readFileSync(path.resolve(`resources/lang/${locale}.json`), 'utf8')) as Record<string, string>;
      const slice = messagesForSurface(all, surface as LangSurface);

      return {
        code: `export default JSON.parse(${JSON.stringify(JSON.stringify(slice))});`,
        map: null,
        moduleSideEffects: false,
      };
    },
  };
}

/**
 * Keeps one Laravel-readable public/build/manifest.json across the two passes: the site pass files its entries
 * aside, the panel pass merges them back. Runs after Vite has written the manifest (closeBundle, post).
 */
function mergeSurfaceManifests(): Plugin {
  return {
    name: 'bp:merge-surface-manifests',
    apply: 'build',
    closeBundle: {
      sequential: true,
      order: 'post',
      handler() {
        const manifest = path.resolve('public/build/manifest.json');
        const stashed = path.resolve(SITE_MANIFEST);
        if (!fs.existsSync(manifest)) return;

        if (IS_SITE) {
          fs.copyFileSync(manifest, stashed); // the panel pass does not empty outDir, so this survives
          return;
        }

        if (!fs.existsSync(stashed)) return;
        const site = JSON.parse(fs.readFileSync(stashed, 'utf8')) as Record<string, unknown>;
        const panel = JSON.parse(fs.readFileSync(manifest, 'utf8')) as Record<string, unknown>;
        fs.writeFileSync(manifest, JSON.stringify({ ...site, ...panel }, null, 2));
      },
    },
  };
}

/**
 * Site bundle only: `@shared/format/date` (in any of its import spellings) resolves to `date.site.ts`, the
 * Intl-based formatter, so no site page pulls dayjs + utc + timezone + the bn locale (REALTIME.md §8).
 */
function siteDateFormatter(): Plugin {
  const dayjsImpl = path.resolve('resources/js/shared/format/date.ts');
  const intlImpl = path.resolve('resources/js/shared/format/date.site.ts');

  return {
    name: 'bp:site-date-formatter',
    enforce: 'pre',
    async resolveId(source, importer, options) {
      if (importer === intlImpl || !source.includes('format/date')) return null;

      const resolved = await this.resolve(source, importer, { ...options, skipSelf: true });

      return resolved && resolved.id === dayjsImpl ? intlImpl : null;
    },
  };
}

/**
 * `react` for the SITE production bundle only.
 *
 * Why: React 19 + react-dom is 60 KB gzip, 63% of the ≤95 KB first-load budget in REALTIME.md §8, before
 * Inertia's 40 KB is counted — the budget is unreachable with it. preact/compat is ~10 KB and runs the site's
 * pages unchanged: they use plain hooks, Inertia's Link/Head/useForm/router and react-i18next, nothing
 * React-19-specific. The panel keeps real React — MUI, emotion and the date pickers are not on this path.
 *
 * preact/compat has no `use()`, and @inertiajs/react 3.x calls `use(PageContext)` inside usePage(). For a
 * context that is exactly useContext, so the shim covers the one call site; React 19's other `use()` form (a
 * promise) has no preact equivalent, so it throws loudly rather than failing silently. It lives here as a
 * virtual module because preact/compat's types are `export =`, which a real `.ts` file cannot `export *` from.
 */
const PREACT_REACT = '\0bp-preact/react';

function preactReactShim(): Plugin {
  return {
    name: 'bp:preact-react-shim',
    enforce: 'pre',
    resolveId: (source) => (source === PREACT_REACT ? PREACT_REACT : null),
    load(id) {
      if (id !== PREACT_REACT) return null;

      return [
        "export * from 'preact/compat';",
        "export { default } from 'preact/compat';",
        "import { useContext } from 'preact/compat';",
        'export function use(resource) {',
        "  if (resource && typeof resource.then === 'function') throw new Error('use(promise) is not supported by the site bundle (preact/compat) — only use(Context)');",
        '  return useContext(resource);',
        '}',
      ].join('\n');
    },
  };
}

// Order matters: rollup's alias matches in sequence and treats `react-dom` as a prefix of `react-dom/client`.
const PREACT_ALIASES = [
  { find: /^react\/jsx-runtime$/, replacement: 'preact/jsx-runtime' },
  { find: /^react\/jsx-dev-runtime$/, replacement: 'preact/jsx-dev-runtime' },
  { find: /^react-dom\/test-utils$/, replacement: 'preact/test-utils' },
  { find: /^react-dom\/client$/, replacement: 'preact/compat/client' },
  { find: /^react-dom$/, replacement: 'preact/compat' },
  { find: /^react$/, replacement: PREACT_REACT },
];

export default defineConfig({
  plugins: [
    langBundles(),
    ...(IS_SITE ? [preactReactShim(), siteDateFormatter()] : []),
    laravel({
      input: IS_SITE ? SITE_INPUT : SURFACE === 'panel' ? PANEL_INPUT : [...PANEL_INPUT, ...SITE_INPUT],
      refresh: ['resources/views/**', 'routes/**'],
    }),
    react(),
    tailwindcss(),
    ...(HAS_PANEL ? [VitePWA({
      strategies: 'injectManifest',          // we own the service worker (resources/js/panel/sw.ts, OFFLINE.md §11)
      srcDir: 'resources/js/panel',
      filename: 'sw.ts',
      outDir: 'public',                      // emits public/sw.js served from '/', so the SW may control '/panel/' (the web manifest is emitted into Vite's outDir: public/build/panel.webmanifest)
      buildBase: '/',                        // registerSW() registers `${buildBase}${filename}` → must be /sw.js (verified in vite-plugin-pwa 1.3 dist/index.js); precache entries are globbed from `public` so they already carry the build/ prefix
      base: '/',
      scope: '/panel/',
      registerType: 'prompt',
      injectRegister: null,                  // registration is explicit in resources/js/panel/pwa.ts
      manifestFilename: 'panel.webmanifest',
      includeAssets: ['icons/*.png'],        // public/fonts is populated by scripts/sync-fonts.sh for print templates only; the desk's woff2 are hashed under build/assets
      manifest: {
        name: 'Clinic Desk', short_name: 'Desk', start_url: '/panel/reception', scope: '/panel/',
        display: 'standalone', background_color: '#ffffff', theme_color: '#0f766e', lang: 'bn',
        icons: [{ src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' }, { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' }],
      },
      // Precache every hashed asset: laravel-vite-plugin names both entries app-*.js and Rollup auto-names common chunks
      // (boot-*.js) and page chunks, so a {panel,shared}-* glob would leave the desk unable to cold-boot offline.
      // The panel pass runs second, so public/build/assets already holds the site pass's output too.
      injectManifest: { globDirectory: 'public', globPatterns: ['build/assets/*.{js,css,woff2}', 'icons/*.{png,svg}'], maximumFileSizeToCacheInBytes: 4_000_000 },
      devOptions: { enabled: true, type: 'module' },
    }), copyPanelManifest()] : []),
    mergeSurfaceManifests(),
  ],
  resolve: {
    alias: [
      ...(IS_SITE ? PREACT_ALIASES : []),
      { find: '@panel', replacement: path.resolve('resources/js/panel') },
      { find: '@site', replacement: path.resolve('resources/js/site') },
      { find: '@shared', replacement: path.resolve('resources/js/shared') },
      { find: '@lang', replacement: path.resolve('resources/lang') },
    ],
  },
  build: {
    // The site pass runs first and cleans public/build; the panel pass appends to it (and its PWA precache
    // manifest then covers both surfaces, as it did when the two apps shared one build).
    emptyOutDir: SURFACE !== 'panel',
    // REALTIME.md §8: Chrome/WebView ≥ 80, Android 8+ for the public site. The panel is a desk browser.
    target: IS_SITE ? 'es2019' : undefined,
    rollupOptions: {
      output: {
        manualChunks: IS_SITE
          ? {
              // preact + Inertia are the site's whole framework floor; i18next rides along because every page
              // translates. laravel-echo/pusher-js stay a separate chunk: import()ed after first paint.
              vendor: ['preact', 'preact/compat', 'preact/hooks', '@inertiajs/react', 'i18next', 'react-i18next'],
              realtime: ['laravel-echo', 'pusher-js'],
            }
          : {
              shared: ['react', 'react-dom', '@inertiajs/react', 'zustand', 'i18next', 'react-i18next', 'dayjs'],
              realtime: ['laravel-echo', 'pusher-js'],   // loaded with import() after first paint (REALTIME.md §8): keeps ~30 KB gzip off the site's first load
            },
      },
    },
  },
  server: { host: '127.0.0.1', port: 5173, watch: { ignored: ['**/storage/framework/views/**'] } },
});
