// Guards the per-bundle translation split (vite.config.ts `bp:lang-bundles` + surfaces.ts).
//
// Two surfaces, two ways to get this wrong. The site ships one locale sliced to SITE_KEY_PREFIXES; the panel
// ships a small base plus ONE module slice per route. Either way, a page that starts using a key its bundle does
// not carry renders the raw key in production while looking perfectly fine in tests — which load both files
// whole. These tests are the thing that notices.
import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import en from '@lang/en.json';
import {
  PANEL_BASE_PREFIXES, PANEL_MODULES, PANEL_MODULE_PREFIXES, SITE_KEY_PREFIXES,
  isLangSurface, isPanelModule, langPrefixesFor, messagesForSurface, panelModuleForPage, type LangSurface, type PanelModule,
} from '../surfaces';

const ROOT = path.resolve(__dirname, '../../../../..');
const SITE_DIRS = ['resources/js/site', 'resources/js/shared'];
const PANEL_PAGES = 'resources/js/panel/Pages';
const MESSAGES = en as Record<string, string>;
// Same literal-key pattern (and comment skipping) as App\Console\Commands\LangCheckCommand::usedKeys().
const KEY = /(?<![\w.$])t\(\s*['"]([a-z0-9_]+(?:\.[a-z0-9_-]+)+)['"]/g;

function stripComments(line: string): string {
  const trimmed = line.trimStart();
  if (trimmed.startsWith('*') || trimmed.startsWith('/*') || trimmed.startsWith('//')) return '';
  return line.replace(/\s\/\/(?![^'"]*['"][^'"]*$).*$/, '');
}

function readSource(file: string): string {
  return fs.readFileSync(path.join(ROOT, file), 'utf8').split('\n').map(stripComments).join('\n');
}

function sourceFiles(dir: string): string[] {
  return fs.readdirSync(path.join(ROOT, dir), { withFileTypes: true }).flatMap((entry) => {
    const rel = `${dir}/${entry.name}`;
    if (entry.isDirectory()) return entry.name === '__tests__' ? [] : sourceFiles(rel);
    return /\.tsx?$/.test(entry.name) ? [rel] : [];
  });
}

function siteKeys(): Array<[string, string]> {
  const found: Array<[string, string]> = [];
  for (const dir of SITE_DIRS) {
    for (const file of sourceFiles(dir)) {
      for (const match of readSource(file).matchAll(KEY)) found.push([match[1] as string, file]);
    }
  }
  return found;
}

describe('per-surface translation bundles', () => {
  it('every literal t() key on the site falls inside SITE_KEY_PREFIXES', () => {
    const site = messagesForSurface(MESSAGES, 'site');
    const orphans = siteKeys().filter(([key]) => !(key in site));
    expect(orphans, 'add the prefix to SITE_KEY_PREFIXES in resources/js/shared/lang/surfaces.ts').toEqual([]);
  });

  it('the site slice is a strict, much smaller subset of the flat file and keeps its order', () => {
    const site = messagesForSurface(MESSAGES, 'site');
    const keys = Object.keys(site);

    expect(keys.length).toBeGreaterThan(100);
    expect(keys.length).toBeLessThan(Object.keys(MESSAGES).length / 2);
    expect(keys).toEqual(Object.keys(MESSAGES).filter((key) => key in site));
    for (const [key, value] of Object.entries(site)) expect(value).toBe(MESSAGES[key]);
    for (const key of keys) expect(SITE_KEY_PREFIXES.some((prefix) => key.startsWith(prefix))).toBe(true);
  });

  it('the panel base stays small — it is on every route, including the reception desk', () => {
    const base = messagesForSurface(MESSAGES, 'panel');
    const keys = Object.keys(base);

    expect(keys.length).toBeGreaterThan(50);
    expect(keys.length).toBeLessThan(Object.keys(MESSAGES).length / 10);
    for (const key of keys) expect(PANEL_BASE_PREFIXES.some((prefix) => key.startsWith(prefix))).toBe(true);
    // The shell renders these on every screen; a route that had to wait for its module slice for them would
    // flash raw keys.
    for (const key of ['nav.dashboard', 'common.actions.save', 'connection.offline_short', 'auth.login_title']) {
      expect(base, `${key} must be in the panel base`).toHaveProperty(key);
    }
  });

  it('no panel module slice repeats a base key, and every slice keeps the flat file order', () => {
    const base = messagesForSurface(MESSAGES, 'panel');
    for (const module of PANEL_MODULES) {
      const slice = messagesForSurface(MESSAGES, `panel-${module}`);
      const keys = Object.keys(slice);

      expect(keys.length, `panel-${module} is empty`).toBeGreaterThan(0);
      expect(keys.filter((key) => key in base), `panel-${module} repeats base keys`).toEqual([]);
      expect(keys).toEqual(Object.keys(MESSAGES).filter((key) => key in slice));
      for (const [key, value] of Object.entries(slice)) expect(value).toBe(MESSAGES[key]);
      for (const key of keys) {
        expect(PANEL_MODULE_PREFIXES[module].some((prefix) => key.startsWith(prefix)), `${key} in panel-${module}`).toBe(true);
      }
    }
  });

  it('base + module together stay far under the whole file for every module', () => {
    const base = Object.keys(messagesForSurface(MESSAGES, 'panel')).length;
    const all = Object.keys(MESSAGES).length;
    for (const module of PANEL_MODULES) {
      const slice = Object.keys(messagesForSurface(MESSAGES, `panel-${module}`)).length;
      expect(base + slice, `panel-${module} is barely a split`).toBeLessThan(all * 0.75);
    }
  });

  it('recognises exactly the bundles the Vite plugin knows how to build', () => {
    expect(isLangSurface('panel')).toBe(true);
    expect(isLangSurface('site')).toBe(true);
    expect(isLangSurface('panel-reception')).toBe(true);
    expect(isLangSurface('panel-nope')).toBe(false);
    expect(isLangSurface('site-booking')).toBe(false);
    expect(langPrefixesFor('panel-reports' as LangSurface)).toBe(PANEL_MODULE_PREFIXES.reports);
  });

  it('maps every panel page directory to a module or deliberately to the base', () => {
    const dirs = new Set(
      fs.readdirSync(path.join(ROOT, PANEL_PAGES), { withFileTypes: true })
        .filter((e) => e.isDirectory() && e.name !== '__tests__')
        .map((e) => e.name),
    );
    // The three that ride on the base alone; everything else must name a module, or its copy is not shipped.
    const BASE_ONLY = new Set(['Auth', 'Dashboard']);
    for (const dir of dirs) {
      const module = panelModuleForPage(`${dir}/Anything`);
      if (BASE_ONLY.has(dir)) expect(module, `${dir} should ride on the base`).toBeNull();
      else expect(module, `${dir}/ has no module in PANEL_PAGE_MODULES (surfaces.ts)`).not.toBeNull();
    }
    expect(panelModuleForPage('Suspended')).toBeNull();
  });
});

// ---------------------------------------------------------------------------------------------------------
// The panel guard: walk each page's real import graph and check every key it can render is in its bundle.

const ALIASES: Record<string, string> = {
  '@panel': 'resources/js/panel', '@site': 'resources/js/site', '@shared': 'resources/js/shared', '@lang': 'resources/lang',
};
const EXTS = ['.ts', '.tsx', '.d.ts'];

function resolveLocal(spec: string, importer: string): string | null {
  let base: string | null = null;
  for (const [alias, dir] of Object.entries(ALIASES)) {
    if (spec === alias || spec.startsWith(`${alias}/`)) base = path.join(ROOT, dir + spec.slice(alias.length));
  }
  if (spec.startsWith('.')) base = path.resolve(path.dirname(path.join(ROOT, importer)), spec);
  if (base === null) return null;
  base = base.split('?')[0] as string;
  for (const ext of EXTS) if (fs.existsSync(base + ext)) return path.relative(ROOT, base + ext);
  for (const ext of EXTS) if (fs.existsSync(path.join(base, `index${ext}`))) return path.relative(ROOT, path.join(base, `index${ext}`));
  return null;
}

// Static AND dynamic imports: a dialog behind React.lazy still renders its copy from the same i18next store.
const IMPORT_RE = /(?:^|[\s;}])(?:import|export)\s[^'"]*?from\s*['"]([^'"]+)['"]|(?:^|[\s;}(=])import\s*\(\s*['"]([^'"]+)['"]|(?:^|[\s;}])import\s*['"]([^'"]+)['"]/gm;

function importClosure(entry: string, seen = new Set<string>()): Set<string> {
  if (seen.has(entry)) return seen;
  seen.add(entry);
  // Comment-stripped: LazyChart's own header shows an `import(...)` example, and a doc comment is not an edge.
  const src = readSource(entry);
  for (const match of src.matchAll(IMPORT_RE)) {
    const spec = match[1] ?? match[2] ?? match[3];
    if (spec === undefined) continue;
    const local = resolveLocal(spec, entry);
    if (local !== null && /\.tsx?$/.test(local) && !local.endsWith('.d.ts')) importClosure(local, seen);
  }
  return seen;
}

// A key is anything in the file that IS a key of en.json — `t('x.y')`, a `<PanelLayout title="x.y">` prop, a
// `<Footnotes keys={[…]}>` array. A PREFIX is the static head of a computed key (`t(`reports.status.${s}`)`).
const KEY_TOKEN = /[a-z0-9_]+(?:\.[a-z0-9_-]+)+/g;
const PREFIX_TOKEN = /[a-z0-9_]+(?:\.[a-z0-9_-]+)*\.(?=\$\{|['"`]\s*\+)/g;
const ALL_KEYS = Object.keys(MESSAGES);

// A file whose copy renders only on ONE module's pages says so on its first line: `// @lang-module super`. The
// walk is static and cannot see a runtime branch — PanelLayout is one shell for two surfaces and mounts
// Components/Super/{nav,SuperSidebar}.tsx only when `surface === 'super'`, i.e. only under Super/* pages — so
// without the pragma every clinic route would be asked to carry `super.nav.*`. The pragma moves that file's keys
// to the named module's check and out of every other page's; the runtime guard it describes is what
// panel/Layouts/__tests__/PanelLayout.test.tsx proves.
const MODULE_PRAGMA = /^\/\/\s*@lang-module\s+([a-z]+)\b/;

function langModuleOf(file: string): PanelModule | null {
  const first = fs.readFileSync(path.join(ROOT, file), 'utf8').split('\n', 1)[0] ?? '';
  const match = MODULE_PRAGMA.exec(first);
  if (match === null) return null;
  const module = match[1] as string;
  if (!isPanelModule(module)) throw new Error(`${file}: @lang-module names '${module}', which is not a panel module (surfaces.ts PANEL_MODULES)`);
  return module;
}

function keysUsedBy(file: string): { keys: string[]; prefixes: string[] } {
  const src = readSource(file);
  const keys = [...src.matchAll(KEY_TOKEN)].map((m) => m[0]).filter((k) => Object.hasOwn(MESSAGES, k));
  const prefixes = [...src.matchAll(PREFIX_TOKEN)].map((m) => m[0]).filter((p) => ALL_KEYS.some((k) => k.startsWith(p)));
  return { keys, prefixes };
}

function panelPages(): string[] {
  const walk = (dir: string): string[] => fs.readdirSync(path.join(ROOT, dir), { withFileTypes: true }).flatMap((entry) => {
    const rel = `${dir}/${entry.name}`;
    if (entry.isDirectory()) return entry.name === '__tests__' ? [] : walk(rel);
    return /\.tsx$/.test(entry.name) && !/\.test\.tsx$/.test(entry.name) ? [rel] : [];
  });
  return walk(PANEL_PAGES);
}

describe('panel module lang bundles cover every key their pages can render', () => {
  const pages = panelPages();

  it('finds the panel pages at all', () => {
    expect(pages.length).toBeGreaterThan(50);
  });

  it('the @lang-module files are the console drawer, and the clinic pages reach them only through the shell', () => {
    const pragma = sourceFiles('resources/js/panel').filter((file) => langModuleOf(file) !== null);
    expect(pragma.sort()).toEqual(['resources/js/panel/Components/Super/SuperSidebar.tsx', 'resources/js/panel/Components/Super/nav.tsx']);
    for (const file of pragma) expect(langModuleOf(file)).toBe('super');
    // No page outside Super/ imports them directly — the only door is PanelLayout's `surface === 'super'` branch.
    for (const page of pages.filter((p) => !p.startsWith(`${PANEL_PAGES}/Super/`))) {
      const direct = [...readSource(page).matchAll(IMPORT_RE)].map((m) => m[1] ?? m[2] ?? m[3]).filter((s) => s !== undefined && /Components\/Super\/(nav|SuperSidebar)/.test(s));
      expect(direct, `${page} imports the console drawer`).toEqual([]);
    }
  });

  it.each(pages)('%s', (page) => {
    const name = page.slice(`${PANEL_PAGES}/`.length).replace(/\.tsx$/, '');
    const module = panelModuleForPage(name);
    const bundle: Record<string, string> = {
      ...messagesForSurface(MESSAGES, 'panel'),
      ...(module === null ? {} : messagesForSurface(MESSAGES, `panel-${module}`)),
    };
    const where = module === null ? 'PANEL_BASE_PREFIXES' : `PANEL_MODULE_PREFIXES.${module}`;
    const hint = `add the prefix to ${where} in resources/js/shared/lang/surfaces.ts`;

    const missingKeys = new Set<string>();
    const missingPrefixes = new Set<string>();
    for (const file of importClosure(page)) {
      const only = langModuleOf(file);
      if (only !== null && only !== module) continue;
      const { keys, prefixes } = keysUsedBy(file);
      for (const key of keys) if (!(key in bundle)) missingKeys.add(`${key} (${file})`);
      for (const prefix of prefixes) {
        if (!Object.keys(bundle).some((k) => k.startsWith(prefix))) missingPrefixes.add(`${prefix}* (${file})`);
      }
    }

    expect([...missingKeys], hint).toEqual([]);
    expect([...missingPrefixes], hint).toEqual([]);
  });
});
