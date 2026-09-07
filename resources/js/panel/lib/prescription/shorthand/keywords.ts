// The closed shorthand vocabulary — resources/shorthand/keywords.json, THE SAME FILE the PHP side reads through
// App\Domain\Prescription\Shorthand\Keywords (PRESCRIPTION.md §2.2). Never copy it: the import below is relative to
// resources/ on purpose so both parsers can only ever disagree if the file itself changes.
import raw from '../../../../../shorthand/keywords.json';

export interface FrequencyDef {
  code: 'od' | 'bd' | 'tds' | 'qds';
  per_day: number;
}
export interface FormDef {
  default_unit: string;
  pack_unit: string;
  routes: string[];
}
export interface TimingDef {
  tokens: string[];
  timing: 'before' | 'after' | 'with' | 'any';
}
export interface KeywordExample {
  group: string;
  input: string;
  en: string;
  bn: string;
}
export interface KeywordTable {
  version: number;
  frequency: Record<string, FrequencyDef>;
  stat: string[];
  sos: string[];
  hs: string[];
  max: string[];
  interval: { hour_words: string[]; hrly: string[] };
  units: Record<string, string[]>;
  attached_units: string[];
  mass_units: Record<string, number>;
  duration: Record<string, string[]>;
  duration_days: Record<string, number>;
  timing: Record<string, TimingDef>;
  routes: string[];
  quantity: { prefix: string; pack_words: Record<string, string[]> };
  families: Record<string, string[]>;
  liquid_ml: Record<string, number>;
  pack_days: number;
  default_pack_size: Record<string, number>;
  forms: Record<string, FormDef>;
  labels: {
    timing: Record<string, { bn: string; en: string }>;
    duration: Record<string, { bn: string; en: string }>;
    schedule: Record<string, { bn: string; en: string }>;
    units: Record<string, { bn: string; en: string }>;
    routes: Record<string, { bn: string; en: string }>;
  };
  messages: Record<string, { en: string; bn: string }>;
  examples: KeywordExample[];
}

export const KEYWORDS = raw as unknown as KeywordTable;

function memo<T>(build: () => T): () => T {
  let value: T | undefined;
  let done = false;
  return () => {
    if (!done) {
      value = build();
      done = true;
    }
    return value as T;
  };
}

export const frequencies = (): Record<string, FrequencyDef> => KEYWORDS.frequency;

/** alias → canonical unit ("tabs" → "tab", "iu" → "unit"). */
export const unitAliases = memo<Record<string, string>>(() => {
  const map: Record<string, string> = {};
  for (const [canonical, aliases] of Object.entries(KEYWORDS.units)) for (const alias of aliases) map[alias] = canonical;
  return map;
});

export const attachedUnits = (): string[] => KEYWORDS.attached_units;
export const massUnits = (): Record<string, number> => KEYWORDS.mass_units;

/** duration word → [kind, days-per-unit | null]. */
export const durationWords = memo<Record<string, [string, number | null]>>(() => {
  const map: Record<string, [string, number | null]> = {};
  for (const [kind, words] of Object.entries(KEYWORDS.duration)) {
    for (const word of words) map[word] = [kind, KEYWORDS.duration_days[kind] ?? null];
  }
  return map;
});

/** timing token → [code, timing column]. */
export const timingTokens = memo<Record<string, [string, string]>>(() => {
  const map: Record<string, [string, string]> = {};
  for (const [code, def] of Object.entries(KEYWORDS.timing)) for (const token of def.tokens) map[token] = [code, def.timing];
  return map;
});

export const routes = (): string[] => KEYWORDS.routes;

/** pack word alias → canonical ("bot" → "bottle"). */
export const packWords = memo<Record<string, string>>(() => {
  const map: Record<string, string> = {};
  for (const [canonical, aliases] of Object.entries(KEYWORDS.quantity.pack_words)) for (const alias of aliases) map[alias] = canonical;
  return map;
});

export const forms = (): Record<string, FormDef> => KEYWORDS.forms;

/** unit → family (counted | liquid | insulin | inhaler | packs). */
export function familyOf(unit: string): string {
  for (const [family, units] of Object.entries(KEYWORDS.families)) if (units.includes(unit)) return family;
  return 'counted';
}

export const liquidMl = (): Record<string, number> => KEYWORDS.liquid_ml;
export const packDays = (): number => KEYWORDS.pack_days;
export const defaultPackSize = (family: string): number | null => KEYWORDS.default_pack_size[family] ?? null;
export const labels = (): KeywordTable['labels'] => KEYWORDS.labels;
export const examples = (): KeywordExample[] => KEYWORDS.examples;

export function message(key: string): { en: string; bn: string } {
  return KEYWORDS.messages[key] ?? { en: key, bn: key };
}

/** Every keyword token → its category (mirrors Keywords::vocabulary()). */
export const vocabulary = memo<Record<string, string>>(() => {
  const v: Record<string, string> = {};
  const add = (tokens: Iterable<string>, category: string): void => {
    for (const token of tokens) v[token] = category;
  };
  add(Object.keys(frequencies()), 'frequency');
  add(KEYWORDS.stat, 'stat');
  add(KEYWORDS.sos, 'sos');
  add(KEYWORDS.hs, 'hs');
  add(KEYWORDS.max, 'max');
  add(Object.keys(unitAliases()), 'unit');
  add(
    Object.keys(durationWords()).filter((w) => !w.includes(' ')),
    'duration',
  );
  add(Object.keys(timingTokens()), 'timing');
  add(routes(), 'route');
  add(Object.keys(packWords()), 'pack');
  return v;
});

/** Word tokens (letters only, ≥ 2 chars) offered as "did you mean" candidates. */
export const suggestibleWords = memo<string[]>(() => Object.keys(vocabulary()).filter((w) => /^[a-z]+$/.test(w) && w.length >= 2));
