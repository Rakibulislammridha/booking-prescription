// Anchored scanner over the normalised body (PRESCRIPTION.md §2.2) — mirrors App\Domain\Prescription\Shorthand\
// Tokenizer. At every position the first matching rule wins, in this order: slots · interval · qty · max ·
// duration · amount · word · other. Amount tokens stand alone; the Assembler binds a pending amount to the
// schedule keyword that follows it (`1 drop be tds`).
import { classifyWord } from './classify';
import { KEYWORDS, durationWords, packWords, unitAliases } from './keywords';
import { parseAmount } from './numberFormat';

export type TokenType = 'slots' | 'amount' | 'freq' | 'interval' | 'stat' | 'sos' | 'hs' | 'max' | 'duration' | 'timing' | 'route' | 'qty' | 'unknown';

export interface AmountData {
  value: number;
  unit: string | null;
  text: string;
}

export interface Token {
  type: TokenType;
  text: string;
  data: Record<string, unknown>;
}

const NUM = '(?:\\d+ \\d+\\/\\d+|\\d+\\/\\d+|\\d+\\.\\d+|\\d+)';

function quote(word: string): string {
  return word.replace(/[.\\+*?[\]^$(){}|\-#/]/g, '\\$&');
}

/** PHP: length desc, then strcmp asc — longest alternative must win. */
function alternation(words: string[]): string {
  const sorted = [...words].sort((a, b) => b.length - a.length || (a < b ? -1 : a > b ? 1 : 0));
  return `(?:${sorted.map(quote).join('|')})`;
}

interface Rules {
  type: TokenType | 'word' | 'other';
  re: RegExp;
}

let rules: Rules[] | null = null;

function buildRules(): Rules[] {
  const units = alternation(Object.keys(unitAliases()));
  const amt = `${NUM}(?: ?${units}\\b)?`;
  const words = durationWords();
  const closed = alternation(Object.keys(words).filter((w) => words[w]?.[1] !== null && words[w]?.[1] !== undefined));
  const open = alternation(Object.keys(words).filter((w) => words[w]?.[1] === null));
  const packs = alternation(Object.keys(packWords()));
  const hours = alternation(KEYWORDS.interval.hour_words);
  const hrly = alternation(KEYWORDS.interval.hrly);

  return [
    { type: 'slots', re: new RegExp(`^(${amt}(?:\\+${amt})+)`) },
    { type: 'interval', re: new RegExp(`^(?:q(\\d+)(?:${hours})|(\\d+)(?:${hrly}))\\b`) },
    { type: 'qty', re: new RegExp(`^x(\\d+)(?: (${packs}))?\\b`) },
    { type: 'max', re: new RegExp('^max (\\d+)\\b') },
    { type: 'duration', re: new RegExp(`^(?:(\\d+) ?(${closed})|(${open}))\\b`) },
    { type: 'amount', re: new RegExp(`^(?:(${NUM})(?: ?(${units})\\b)?|(${units})\\b)`) },
    { type: 'word', re: /^([a-z]+)/ },
    { type: 'other', re: /^(\S+)/ },
  ];
}

/** "2 tsp" | "500mg" | "1 1/2" | "apply" → value + canonical unit. */
export function amountOf(text: string): AmountData {
  const aliases = unitAliases();
  const m = /^(\d+ \d+\/\d+|\d+\/\d+|\d+\.\d+|\d+) ?([a-z]+)?$/.exec(text);
  if (m) {
    const unit = m[2] !== undefined ? aliases[m[2]] ?? null : null;
    return { value: parseAmount(m[1] ?? ''), unit, text };
  }
  return { value: 1, unit: aliases[text] ?? null, text };
}

function build(type: Rules['type'], text: string, m: RegExpExecArray): Token {
  switch (type) {
    case 'slots':
      return { type: 'slots', text, data: { amounts: text.split('+').map(amountOf) } };
    case 'interval':
      return { type: 'interval', text, data: { hours: Number((m[1] ?? '') !== '' ? m[1] : m[2]) } };
    case 'qty':
      return { type: 'qty', text, data: { value: Number(m[1]), pack: m[2] !== undefined && m[2] !== '' ? packWords()[m[2]] ?? null : null } };
    case 'max':
      return { type: 'max', text, data: { value: Number(m[1]) } };
    case 'duration':
      return duration(text, m);
    case 'amount':
      return { type: 'amount', text, data: amountOf(text) as unknown as Record<string, unknown> };
    case 'word':
      return classifyWord(text);
    default:
      return { type: 'unknown', text, data: { suggestion: null } };
  }
}

function duration(text: string, m: RegExpExecArray): Token {
  const words = durationWords();
  if ((m[1] ?? '') !== '') {
    const def = words[m[2] ?? ''];
    const perUnit = def?.[1] ?? 0;
    return { type: 'duration', text, data: { kind: 'days', days: Number(m[1]) * perUnit, word: def?.[0] ?? 'days' } };
  }
  const kind = words[m[3] ?? '']?.[0] ?? 'days';
  return { type: 'duration', text, data: { kind, days: null, word: kind } };
}

export function tokenize(body: string): Token[] {
  rules ??= buildRules();
  const tokens: Token[] = [];
  let pos = 0;
  const len = body.length;

  while (pos < len) {
    const rest = body.slice(pos);
    if (rest === '' || rest.trim() === '') break;
    if (rest[0] === ' ') {
      pos++;
      continue;
    }

    let matched = false;
    for (const rule of rules) {
      const m = rule.re.exec(rest);
      if (m === null) continue;
      const text = m[0];
      tokens.push(build(rule.type, text, m));
      pos += text.length;
      matched = true;
      break;
    }
    if (!matched) pos++; // unreachable: `other` matches any non-space
  }

  return tokens;
}
