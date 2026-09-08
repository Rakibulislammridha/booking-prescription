#!/usr/bin/env bash
#
# check-panel-budget.sh — the staff PWA's two hard budgets, the sibling of scripts/check-site-deps.sh.
#
# The public site has had a payload guard since day one (95 KB gzip per route). The panel had none, and it drifted
# to 442 KB gzip of first-load JS on the reception board — the one screen BRIEF §8 names, running all day on cheap
# clinic hardware over clinic wifi. This script is that guard.
#
#   1. FIRST-LOAD JAVASCRIPT, measured exactly the way the site script measures it, from public/build/manifest.json:
#      the panel entry's static-import closure + the heavier panel locale chunk + the route's own page chunk (and
#      ITS static imports). Dynamic imports — the realtime chunk, dialogs behind React.lazy, other routes' pages —
#      are not first load, which is the whole point of splitting them.
#
#      Two budgets, because the panel is two products wearing one shell:
#        PANEL_BUDGET_KB — every panel route. Set from the measured worst route plus headroom, not from a wish.
#        DESK_BUDGET_KB  — the clinical routes a clinic actually lives in (reception desk, prescription writer,
#                          queue, patient record, dashboard). These are the BRIEF §8 screens and they are held
#                          tighter than the back-office reports an admin opens on a desktop once a week.
#
#   2. DEPENDENCY RULES of CONVENTIONS §7.3, which is where the payload comes from in the first place:
#      no `@mui/material` / `@mui/icons-material` BARREL imports (one barrel import pulls the whole toolkit),
#      recharts and @dnd-kit only in the files allowed to have them, and @mui/x-date-pickers never from the entry
#      or a layout — a picker is a page's own cost, not everyone's.
#
# Usage:
#   scripts/check-panel-budget.sh                 # rules always; budget when a build is present
#   scripts/check-panel-budget.sh --build         # build first (npm run build), then check both
#   scripts/check-panel-budget.sh --require-build # fail if there is no build to measure (CI)
#
# CI: `npm run build && scripts/check-panel-budget.sh --require-build`, or `npm run check:panel` after a build.
set -euo pipefail

cd "$(dirname "$0")/.."

PANEL_BUDGET_KB=445
DESK_BUDGET_KB=380
BUILD=0
REQUIRE_BUILD=0

for arg in "$@"; do
  case "$arg" in
    --build) BUILD=1 ;;
    --require-build) REQUIRE_BUILD=1 ;;
    -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
    *) echo "check-panel-budget: unknown option $arg" >&2; exit 2 ;;
  esac
done

if [ "$BUILD" = 1 ]; then
  npm run build
fi

command -v node >/dev/null 2>&1 || { echo "check-panel-budget: node is required" >&2; exit 2; }

PANEL_BUDGET_KB="$PANEL_BUDGET_KB" DESK_BUDGET_KB="$DESK_BUDGET_KB" REQUIRE_BUILD="$REQUIRE_BUILD" node - <<'JS'
'use strict';
const fs = require('fs');
const path = require('path');
const zlib = require('zlib');

const ROOT = process.cwd();
const PANEL_BUDGET = Number(process.env.PANEL_BUDGET_KB) * 1024;
const DESK_BUDGET = Number(process.env.DESK_BUDGET_KB) * 1024;
const REQUIRE_BUILD = process.env.REQUIRE_BUILD === '1';
const failures = [];
const notes = [];

// The routes a clinic is inside all day (BRIEF §8). Prefixes of the page path under panel/Pages/.
const DESK_ROUTES = ['Reception/', 'Prescription/', 'Queue/', 'Patients/', 'Dashboard/'];

// ---------------------------------------------------------------- 1. dependency rules (CONVENTIONS §7.3)

const RULES = [
  {
    // A barrel import defeats per-component tree shaking and is how a panel bundle doubles overnight.
    test: (spec) => spec === '@mui/material' || spec === '@mui/icons-material',
    allow: () => false,
    why: "barrel import — use '@mui/material/<Component>' / '@mui/icons-material/<Icon>' (CONVENTIONS §7.3)",
  },
  {
    test: (spec) => spec === 'recharts' || spec.startsWith('recharts/'),
    allow: (rel) => rel.startsWith('resources/js/panel/Pages/Reports/')
      || rel.startsWith('resources/js/panel/Pages/Super/')
      || rel.startsWith('resources/js/panel/Components/Reports/')
      || rel === 'resources/js/panel/Components/Patients/VitalsTrendCharts.tsx',
    why: 'recharts is ~95 KB gzip — allowed only in Reports/Super pages, their components, and the patient vitals trend (CONVENTIONS §7.3)',
  },
  {
    test: (spec) => spec.startsWith('@dnd-kit/'),
    allow: (rel) => rel === 'resources/js/panel/Components/Serials/QueueList.tsx'
      || rel.startsWith('resources/js/panel/Components/Clinic/Pad'),
    why: 'dnd-kit is the serial queue list and the pad designer only (CONVENTIONS §7.3)',
  },
  {
    // Removing this from the entry was worth 5 KB gzip on every single panel route; keep it out.
    test: (spec) => spec.startsWith('@mui/x-date-pickers'),
    allow: (rel) => !(rel === 'resources/js/panel/app.tsx' || rel.startsWith('resources/js/panel/Layouts/')),
    why: 'the date pickers must not sit in the panel entry or a layout — a page that needs one mounts its own LocalizationProvider',
  },
];

const IMPORT_RE = /(?:^|[\s;}])(?:import|export)\s[^'"]*?from\s*['"]([^'"]+)['"]|(?:^|[\s;}(=])import\s*\(\s*['"]([^'"]+)['"]|(?:^|[\s;}])import\s*['"]([^'"]+)['"]/gm;

function scan(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const file = path.join(dir, entry.name);
    if (entry.isDirectory()) { if (entry.name !== '__tests__') scan(file); continue; }
    if (!/\.tsx?$/.test(entry.name) || /\.test\.tsx?$/.test(entry.name)) continue;
    const rel = path.relative(ROOT, file);
    const src = fs.readFileSync(file, 'utf8');
    IMPORT_RE.lastIndex = 0;
    let m;
    while ((m = IMPORT_RE.exec(src)) !== null) {
      const spec = m[1] || m[2] || m[3];
      if (!spec) continue;
      for (const rule of RULES) {
        if (rule.test(spec) && !rule.allow(rel)) failures.push(`${rel}: imports '${spec}' — ${rule.why}`);
      }
    }
  }
}

scan(path.join(ROOT, 'resources/js/panel'));
notes.push(`dependency rules: ${RULES.length} rules over resources/js/panel`);

// ---------------------------------------------------------------- 2. first-load budget

const manifestPath = path.join(ROOT, 'public/build/manifest.json');

if (!fs.existsSync(manifestPath)) {
  const message = 'public/build/manifest.json is missing — run `npm run build` (or pass --build) to check the first-load budget';
  if (REQUIRE_BUILD) failures.push(message);
  else notes.push(`SKIPPED first-load budget: ${message}`);
} else {
  const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  const ENTRY = 'resources/js/panel/app.tsx';

  if (!manifest[ENTRY]) {
    failures.push(`${ENTRY} is not in public/build/manifest.json — the panel pass of \`npm run build\` did not run`);
  } else {
    const closure = (key, seen) => {
      if (seen.has(key) || !manifest[key]) return seen;
      seen.add(key);
      for (const imported of manifest[key].imports ?? []) closure(imported, seen);
      return seen;
    };
    const gzipped = (file) => zlib.gzipSync(fs.readFileSync(path.join(ROOT, 'public/build', file)), { level: 9 }).length;

    // The heavier locale: a Bangla clinic must fit the budget in Bangla.
    const localeChunks = Object.keys(manifest).filter((k) => /^bp-lang\/lang-panel-/.test(k));
    const localeKey = localeChunks.sort((a, b) => gzipped(manifest[b].file) - gzipped(manifest[a].file))[0] ?? null;
    const base = closure(ENTRY, new Set());
    if (localeKey !== null) closure(localeKey, base);

    // Every page file on disk is a route. Most are keyed in the manifest by their source path; a page that is ALSO
    // imported statically by another page (Prescription/Writer, which Telemedicine/Console embeds) is hoisted into
    // a named chunk instead, so fall back to matching that chunk by name. A page with neither is a page nobody can
    // open — that is a build failure, not a missing measurement.
    const pageFiles = [];
    (function walk(dir) {
      for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        const file = path.join(dir, entry.name);
        if (entry.isDirectory()) { if (entry.name !== '__tests__') walk(file); continue; }
        if (/\.tsx$/.test(entry.name) && !/\.test\.tsx$/.test(entry.name)) pageFiles.push(path.relative(ROOT, file));
      }
    })(path.join(ROOT, 'resources/js/panel/Pages'));

    const byName = new Map();
    for (const [key, chunk] of Object.entries(manifest)) {
      if (chunk.isDynamicEntry && !chunk.src && chunk.name) byName.set(chunk.name, key);
    }

    const rows = [];
    for (const page of pageFiles.sort()) {
      const key = manifest[page] ? page : byName.get(path.basename(page, '.tsx'));
      if (key === undefined) {
        failures.push(`${page}: no chunk in the manifest — the page is not reachable from the panel entry`);
        continue;
      }
      const chunks = closure(key, new Set(base));
      const js = [...chunks].map((k) => manifest[k].file).filter((f) => f.endsWith('.js'));
      const bytes = js.reduce((total, file) => total + gzipped(file), 0);
      const name = page.replace('resources/js/panel/Pages/', '');
      const desk = DESK_ROUTES.some((prefix) => name.startsWith(prefix));
      rows.push([name, bytes, js.length, desk]);

      const budget = desk ? DESK_BUDGET : PANEL_BUDGET;
      if (bytes > budget) {
        failures.push(`${page}: first load is ${(bytes / 1024).toFixed(1)} KB gzip of JS, over the ${(budget / 1024).toFixed(0)} KB ${desk ? 'clinical-route' : 'panel'} budget`);
      }
    }

    if (rows.length === 0) failures.push('no panel page chunks in the manifest — the build looks wrong');

    rows.sort((a, b) => b[1] - a[1]);
    for (const [name, bytes, count, desk] of rows) {
      notes.push(`  ${(bytes / 1024).toFixed(1).padStart(7)} KB ${desk ? '·desk' : '     '}  ${name}  (${count} chunks)`);
    }
    const worst = rows[0];
    const worstDesk = rows.find((r) => r[3]);
    notes.push(`first-load budgets: ${(PANEL_BUDGET / 1024).toFixed(0)} KB panel / ${(DESK_BUDGET / 1024).toFixed(0)} KB clinical routes` + (localeKey ? ` (locale chunk ${localeKey})` : ''));
    notes.push(`worst panel route ${worst[0]} at ${(worst[1] / 1024).toFixed(1)} KB` + (worstDesk ? `; worst clinical route ${worstDesk[0]} at ${(worstDesk[1] / 1024).toFixed(1)} KB` : ''));
  }
}

// ---------------------------------------------------------------- report

for (const note of notes) console.log(note);

if (failures.length > 0) {
  console.error('');
  for (const failure of failures) console.error(`FAIL  ${failure}`);
  console.error(`\ncheck-panel-budget: ${failures.length} problem(s).`);
  process.exit(1);
}

console.log('\ncheck-panel-budget: OK');
JS
