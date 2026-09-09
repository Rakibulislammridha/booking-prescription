#!/usr/bin/env bash
#
# check-site-deps.sh — the public site's two hard budgets (CONVENTIONS §7.4, §9 row 9; ARCHITECTURE §7.1, §7.2).
#
#   1. DEPENDENCIES. `resources/js/site/**` — and every `resources/js/shared/**` file it reaches — may import only
#      the packages CONVENTIONS §7.4 allows. MUI, Emotion, recharts, dnd-kit and the date pickers are the panel's;
#      a single one of them in a shared file blows the budget below for every site page at once. The check walks
#      the real import graph rather than grepping `resources/js/site`, because that is how such a dependency
#      actually arrives: through `@shared/...`.
#
#   2. FIRST-LOAD JAVASCRIPT. Every site route must stay under 95 KB gzip (REALTIME.md §8, ARCHITECTURE §7.2).
#      "First load" is measured the way ARCHITECTURE §7.6 defines it, from `public/build/manifest.json`: the site
#      entry's static-import closure + the route's own page chunk (and ITS static imports) + the locale chunk.
#      Dynamic imports (the realtime chunk, page chunks of other routes) are not first load.
#
# Usage:
#   scripts/check-site-deps.sh                 # deps always; budget when a build is present
#   scripts/check-site-deps.sh --build         # build first (npm run build), then check both
#   scripts/check-site-deps.sh --require-build # fail if there is no build to measure (CI)
#
# CI: `npm run build && scripts/check-site-deps.sh --require-build`, or just `npm run check:site` after a build.
set -euo pipefail

cd "$(dirname "$0")/.."

BUDGET_KB=95
BUILD=0
REQUIRE_BUILD=0

for arg in "$@"; do
  case "$arg" in
    --build) BUILD=1 ;;
    --require-build) REQUIRE_BUILD=1 ;;
    -h|--help) sed -n '2,22p' "$0"; exit 0 ;;
    *) echo "check-site-deps: unknown option $arg" >&2; exit 2 ;;
  esac
done

if [ "$BUILD" = 1 ]; then
  npm run build
fi

command -v node >/dev/null 2>&1 || { echo "check-site-deps: node is required" >&2; exit 2; }

BUDGET_KB="$BUDGET_KB" REQUIRE_BUILD="$REQUIRE_BUILD" node - <<'JS'
'use strict';
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const ROOT = process.cwd();
const BUDGET = Number(process.env.BUDGET_KB) * 1024;
const REQUIRE_BUILD = process.env.REQUIRE_BUILD === '1';
const failures = [];
const notes = [];

// ---------------------------------------------------------------- 1. dependencies

// CONVENTIONS §7.4's allowed list, plus what the entry and the shared modules the site reaches actually need.
// A prefix matches the package and its subpaths (`dayjs/plugin/utc`). Add to this list ONLY with a matching
// CONVENTIONS §7.4 change: it is the whole point of the check.
const ALLOWED = [
  'react', 'react-dom', 'react/jsx-runtime', 'react/jsx-dev-runtime',   // aliased to preact/compat in the site build
  'preact',
  '@inertiajs/react', '@inertiajs/core',
  'laravel-vite-plugin/inertia-helpers',
  'zustand',
  'laravel-echo', 'pusher-js',
  'i18next', 'react-i18next',
  'dayjs',                          // bp:site-date-formatter keeps it out of the bundle; the import may exist
  'qrcode.react',
  'ziggy-js',
  'dexie',                          // portal cache only
  'axios',
  '@fontsource/noto-sans-bengali',
];

// Packages that may be reached ONLY through `import()`. They are far too big to sit in a first load — bigger,
// in livekit-client's case, than the entire 95 KB budget — so a static import of one is a failure even though
// the package is a legitimate dependency of the site. The budget below is the second half of the guarantee: a
// lazily imported package lands in its own chunk and is not counted, and if that ever stops being true the
// route's first-load number says so.
const LAZY_ONLY = {
  'livekit-client': 'the LiveKit browser SDK is ~90 KB gzip; it must be reached through `import()` from the video client, never statically (CONVENTIONS §7.4)',
};

// Named so the failure says WHY, not just "not allowed".
const PANEL_ONLY = {
  '@mui/': 'MUI is the panel toolkit',
  '@emotion/': 'Emotion is MUI\'s runtime',
  'recharts': 'recharts is panel/Pages/Reports only',
  '@dnd-kit/': 'dnd-kit is the panel queue list and pad designer only',
  '@fontsource/inter': 'Inter is the panel font; the site uses system-ui for Latin',
  'puppeteer': 'server-side only',
};

const ALIASES = { '@panel': 'resources/js/panel', '@site': 'resources/js/site', '@shared': 'resources/js/shared', '@lang': 'resources/lang' };
const EXTS = ['.ts', '.tsx', '.d.ts', '.js', '.jsx', '.json'];

function resolveLocal(spec, importer) {
  let base = null;
  for (const [alias, dir] of Object.entries(ALIASES)) {
    if (spec === alias || spec.startsWith(alias + '/')) base = path.join(ROOT, dir + spec.slice(alias.length));
  }
  if (spec.startsWith('.')) base = path.resolve(path.dirname(importer), spec);
  if (base === null) return null;
  base = base.split('?')[0];
  if (fs.existsSync(base) && fs.statSync(base).isFile()) return base;
  for (const ext of EXTS) if (fs.existsSync(base + ext)) return base + ext;
  for (const ext of EXTS) if (fs.existsSync(path.join(base, 'index' + ext))) return path.join(base, 'index' + ext);
  return null;
}

// `import x from 'y'` / `export … from 'y'` / `import('y')` / `import 'y'` — enough for a codebase with no
// `require()` and no computed specifiers (ARCHITECTURE §7.1: TypeScript everywhere, no .js under resources/js).
const IMPORT_RE = /(?:^|[\s;}])(?:import|export)\s[^'"]*?from\s*['"]([^'"]+)['"]|(?:^|[\s;}(=])import\s*\(\s*['"]([^'"]+)['"]|(?:^|[\s;}])import\s*['"]([^'"]+)['"]/gm;

const visited = new Set();

function isAllowed(spec) {
  return ALLOWED.some((pkg) => spec === pkg || spec.startsWith(pkg + '/'));
}

function walk(file) {
  if (visited.has(file)) return;
  visited.add(file);
  const src = fs.readFileSync(file, 'utf8');
  const rel = path.relative(ROOT, file);
  IMPORT_RE.lastIndex = 0;
  let m;
  while ((m = IMPORT_RE.exec(src)) !== null) {
    const spec = m[1] || m[2] || m[3];
    if (!spec) continue;
    const dynamic = m[2] !== undefined;                     // `import('…')`, the only form LAZY_ONLY accepts
    const local = resolveLocal(spec, file);
    if (local !== null) { walk(local); continue; }
    if (spec.startsWith('.') || spec.startsWith('@panel') || spec.startsWith('@site') || spec.startsWith('@shared') || spec.startsWith('@lang')) {
      failures.push(`${rel}: cannot resolve local import '${spec}' (the check would miss whatever it imports)`);
      continue;
    }
    if (isAllowed(spec)) continue;
    const lazy = Object.entries(LAZY_ONLY).find(([p]) => spec === p || spec.startsWith(p + '/'));
    if (lazy) {
      if (dynamic) continue;
      failures.push(`${rel}: imports '${spec}' statically — ${lazy[1]}`);
      continue;
    }
    const reason = Object.entries(PANEL_ONLY).find(([p]) => spec === p || spec.startsWith(p));
    failures.push(reason
      ? `${rel}: imports '${spec}' — ${reason[1]}; the site bundle must not carry it (CONVENTIONS §7.4)`
      : `${rel}: imports '${spec}', which is not on the site's allowed dependency list (CONVENTIONS §7.4)`);
  }
}

// Roots: the entry, plus every page — pages are reached through `import.meta.glob('./Pages/**/*.tsx')`, which no
// import scanner can follow. Tests are not bundled.
function seed(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const file = path.join(dir, entry.name);
    if (entry.isDirectory()) { if (entry.name !== '__tests__') seed(file); continue; }
    if (/\.tsx?$/.test(entry.name) && !/\.test\.tsx?$/.test(entry.name)) walk(file);
  }
}

seed(path.join(ROOT, 'resources/js/site'));
notes.push(`dependencies: ${visited.size} files reachable from resources/js/site, ${ALLOWED.length} packages allowed, ${Object.keys(LAZY_ONLY).length} allowed only behind import()`);

// ---------------------------------------------------------------- 2. first-load budget

const manifestPath = path.join(ROOT, 'public/build/manifest.json');

if (!fs.existsSync(manifestPath)) {
  const message = 'public/build/manifest.json is missing — run `npm run build` (or pass --build) to check the first-load budget';
  if (REQUIRE_BUILD) failures.push(message);
  else notes.push(`SKIPPED first-load budget: ${message}`);
} else {
  const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  const ENTRY = 'resources/js/site/app.tsx';

  if (!manifest[ENTRY]) {
    failures.push(`${ENTRY} is not in public/build/manifest.json — the site pass of \`npm run build\` did not run`);
  } else {
    const closure = (key, seen) => {
      if (seen.has(key) || !manifest[key]) return seen;
      seen.add(key);
      for (const imported of manifest[key].imports ?? []) closure(imported, seen);
      return seen;
    };
    const gzipped = (file) => zlib.gzipSync(fs.readFileSync(path.join(ROOT, 'public/build', file)), { level: 9 }).length;

    // The heavier locale: a Bangla-first site must fit the budget in Bangla.
    const localeChunks = Object.keys(manifest).filter((k) => /^bp-lang\/lang-site-/.test(k));
    const localeKey = localeChunks.sort((a, b) => gzipped(manifest[b].file) - gzipped(manifest[a].file))[0] ?? null;
    const base = closure(ENTRY, new Set());
    if (localeKey !== null) closure(localeKey, base);

    // A ROUTE is a page component: `.tsx` under site/Pages. The `.ts` modules that live beside them
    // (Telemedicine/core/*) are not routes, and measuring them here would be actively misleading — the panel
    // build imports those same modules, both passes write a manifest entry under the identical source-path key,
    // and the panel's entry wins the merge (vite.config.ts `{ ...site, ...panel }`). Their closure is then the
    // PANEL's vendor chunk on top of the SITE's entry: two different applications added together, a number no
    // visitor ever downloads. Every one of them is reached through `import()` and is therefore not a first load
    // on either surface anyway.
    const pages = Object.keys(manifest).filter((k) => k.startsWith('resources/js/site/Pages/') && k.endsWith('.tsx')).sort();
    if (pages.length === 0) failures.push('no site page chunks in the manifest — the build looks wrong');

    const rows = [];
    for (const page of pages) {
      const chunks = closure(page, new Set(base));
      const js = [...chunks].map((k) => manifest[k].file).filter((f) => f.endsWith('.js'));
      const bytes = js.reduce((total, file) => total + gzipped(file), 0);
      rows.push([page.replace('resources/js/site/Pages/', ''), bytes, js.length]);
      if (bytes > BUDGET) {
        failures.push(`${page}: first load is ${(bytes / 1024).toFixed(1)} KB gzip of JS, over the ${(BUDGET / 1024).toFixed(0)} KB budget (REALTIME.md §8)`);
      }
    }
    rows.sort((a, b) => b[1] - a[1]);
    const worst = rows[0];
    for (const [name, bytes, count] of rows) {
      notes.push(`  ${(bytes / 1024).toFixed(1).padStart(7)} KB  ${name}  (${count} chunks)`);
    }
    notes.push(`first-load budget: ${(BUDGET / 1024).toFixed(0)} KB gzip; worst route ${worst[0]} at ${(worst[1] / 1024).toFixed(1)} KB` + (localeKey ? ` (locale chunk ${localeKey})` : ''));
  }
}

// ---------------------------------------------------------------- report

for (const note of notes) console.log(note);

if (failures.length > 0) {
  console.error('');
  for (const failure of failures) console.error(`FAIL  ${failure}`);
  console.error(`\ncheck-site-deps: ${failures.length} problem(s).`);
  process.exit(1);
}

console.log('\ncheck-site-deps: OK');
JS
