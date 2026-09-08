// The central marketing pages resolve their strings through `makeCopy()` rather than i18next, so the guard in
// `resources/js/shared/lang/__tests__/bundles.test.ts` — which only sees literal `t('…')` calls — cannot cover
// them. This is that guard: every literal `c('…')` on the central surface must exist in `resources/lang/en.json`
// under the `saas.` prefix, or the page renders a raw key in production while looking fine here.
import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import en from '@lang/en.json';
import { makeCopy } from '../copy';

const ROOT = path.resolve(__dirname, '../../../../..');
const DIRS = ['resources/js/site/Pages/Central', 'resources/js/site/Components/Central'];
const CALL = /(?<![\w.$])c\(\s*['"]([a-z0-9_]+(?:\.[a-z0-9_-]+)+)['"]/g;
// `title="marketing.title"` on <CentralLayout>, which the layout resolves through the same map.
const TITLE = /<CentralLayout[^>]*\stitle="([a-z0-9_]+(?:\.[a-z0-9_-]+)+)"/g;

function sources(dir: string): string[] {
  const full = path.join(ROOT, dir);
  if (!fs.existsSync(full)) return [];
  return fs.readdirSync(full, { withFileTypes: true }).flatMap((entry) => {
    const rel = `${dir}/${entry.name}`;
    if (entry.isDirectory()) return entry.name === '__tests__' ? [] : sources(rel);
    return /\.tsx?$/.test(entry.name) ? [rel] : [];
  });
}

function usedKeys(): Array<[string, string]> {
  const found: Array<[string, string]> = [];
  for (const dir of DIRS) {
    for (const file of sources(dir)) {
      const source = fs.readFileSync(path.join(ROOT, file), 'utf8');
      for (const match of source.matchAll(CALL)) found.push([match[1] as string, file]);
      for (const match of source.matchAll(TITLE)) found.push([match[1] as string, file]);
    }
  }
  return found;
}

describe('central marketing copy', () => {
  it('every literal c() key exists in the translation file under saas.', () => {
    const messages = en as Record<string, string>;
    const missing = usedKeys().filter(([key]) => !(`saas.${key}` in messages));
    expect(missing, 'add the key to resources/lang/{en,bn}.json and to CentralCopy::PAGES').toEqual([]);
  });

  it('the central pages carry no site-bundle translation keys of their own', () => {
    // If a `t('saas.…')` call ever reappears here it would have to be added to SITE_KEY_PREFIXES, which is the
    // 4.5 KB the whole `copy` prop exists to keep out of every tenant route's first load.
    const offenders: string[] = [];
    for (const dir of DIRS) {
      for (const file of sources(dir)) {
        if (/(?<![\w.$])t\(\s*['"`]saas\./.test(fs.readFileSync(path.join(ROOT, file), 'utf8'))) offenders.push(file);
      }
    }
    expect(offenders).toEqual([]);
  });

  it('makeCopy falls back to the key and interpolates Laravel placeholders', () => {
    const c = makeCopy({ 'onboarding.step_of': 'Step :current of :total', 'nav.docs': 'Docs' });
    expect(c('nav.docs')).toBe('Docs');
    expect(c('onboarding.step_of', { current: 2, total: 4 })).toBe('Step 2 of 4');
    expect(c('nope.missing')).toBe('nope.missing');
  });
});
