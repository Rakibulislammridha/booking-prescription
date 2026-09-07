// Guards the per-surface translation split (vite.config.ts `bp:lang-bundles` + surfaces.ts). The site ships one
// locale sliced to SITE_KEY_PREFIXES; if a site page starts using a key outside that list it must be added there,
// or the page would render the raw key in production while looking fine in tests (which load both files whole).
import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import en from '@lang/en.json';
import { SITE_KEY_PREFIXES, langPrefixesFor, messagesForSurface } from '../surfaces';

const ROOT = path.resolve(__dirname, '../../../../..');
const SITE_DIRS = ['resources/js/site', 'resources/js/shared'];
// Same literal-key pattern (and comment skipping) as App\Console\Commands\LangCheckCommand::usedKeys().
const KEY = /(?<![\w.$])t\(\s*['"]([a-z0-9_]+(?:\.[a-z0-9_-]+)+)['"]/g;

function stripComments(line: string): string {
  const trimmed = line.trimStart();
  if (trimmed.startsWith('*') || trimmed.startsWith('/*') || trimmed.startsWith('//')) return '';
  return line.replace(/\s\/\/(?![^'"]*['"][^'"]*$).*$/, '');
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
      const source = fs.readFileSync(path.join(ROOT, file), 'utf8').split('\n').map(stripComments).join('\n');
      for (const match of source.matchAll(KEY)) found.push([match[1] as string, file]);
    }
  }
  return found;
}

describe('per-surface translation bundles', () => {
  it('every literal t() key on the site falls inside SITE_KEY_PREFIXES', () => {
    const site = messagesForSurface(en as Record<string, string>, 'site');
    const orphans = siteKeys().filter(([key]) => !(key in site));
    expect(orphans, 'add the prefix to SITE_KEY_PREFIXES in resources/js/shared/lang/surfaces.ts').toEqual([]);
  });

  it('the site slice is a strict, much smaller subset of the flat file and keeps its order', () => {
    const all = en as Record<string, string>;
    const site = messagesForSurface(all, 'site');
    const keys = Object.keys(site);

    expect(keys.length).toBeGreaterThan(100);
    expect(keys.length).toBeLessThan(Object.keys(all).length / 2);
    expect(keys).toEqual(Object.keys(all).filter((key) => key in site));
    for (const [key, value] of Object.entries(site)) expect(value).toBe(all[key]);
    for (const key of keys) expect(SITE_KEY_PREFIXES.some((prefix) => key.startsWith(prefix))).toBe(true);
  });

  it('the panel keeps every key', () => {
    expect(langPrefixesFor('panel')).toBeNull();
    expect(messagesForSurface(en as Record<string, string>, 'panel')).toBe(en);
  });
});
