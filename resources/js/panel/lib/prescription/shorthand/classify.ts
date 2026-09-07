// Word classification against the keyword table + the "did you mean" suggestion for unknown tokens (Levenshtein
// ≤ 1, or ≤ 2 for words of 5+ characters; ties → shortest, then alphabetical) — mirrors
// App\Domain\Prescription\Shorthand\Classifier.
import { KEYWORDS, frequencies, routes, suggestibleWords, timingTokens } from './keywords';
import type { Token } from './tokenize';

export function levenshtein(a: string, b: string): number {
  if (a === b) return 0;
  if (a.length === 0) return b.length;
  if (b.length === 0) return a.length;
  let previous = new Array<number>(b.length + 1);
  let current = new Array<number>(b.length + 1);
  for (let j = 0; j <= b.length; j++) previous[j] = j;
  for (let i = 1; i <= a.length; i++) {
    current[0] = i;
    for (let j = 1; j <= b.length; j++) {
      const cost = a.charCodeAt(i - 1) === b.charCodeAt(j - 1) ? 0 : 1;
      current[j] = Math.min((current[j - 1] ?? 0) + 1, (previous[j] ?? 0) + 1, (previous[j - 1] ?? 0) + cost);
    }
    const swap = previous;
    previous = current;
    current = swap;
  }
  return previous[b.length] ?? 0;
}

/** PHP array comparison of [distance, length, word]. */
function less(a: [number, number, string], b: [number, number, string]): boolean {
  if (a[0] !== b[0]) return a[0] < b[0];
  if (a[1] !== b[1]) return a[1] < b[1];
  return a[2] < b[2];
}

export function suggest(word: string): string | null {
  if (!/^[a-z]+$/.test(word)) return null;
  const limit = word.length >= 5 ? 2 : 1;
  let best: string | null = null;
  let bestKey: [number, number, string] | null = null;

  for (const candidate of suggestibleWords()) {
    const distance = levenshtein(word, candidate);
    if (distance === 0 || distance > limit) continue;
    const key: [number, number, string] = [distance, candidate.length, candidate];
    if (bestKey === null || less(key, bestKey)) {
      bestKey = key;
      best = candidate;
    }
  }

  return best;
}

export function classifyWord(word: string): Token {
  const freq = frequencies()[word];
  if (freq) return { type: 'freq', text: word, data: { code: freq.code, per_day: Number(freq.per_day) } };
  if (KEYWORDS.stat.includes(word)) return { type: 'stat', text: word, data: {} };
  if (KEYWORDS.sos.includes(word)) return { type: 'sos', text: word, data: {} };
  if (KEYWORDS.hs.includes(word)) return { type: 'hs', text: word, data: {} };

  const timing = timingTokens()[word];
  if (timing) return { type: 'timing', text: word, data: { code: timing[0], timing: timing[1] } };
  if (routes().includes(word)) return { type: 'route', text: word, data: { code: word } };

  return { type: 'unknown', text: word, data: { suggestion: suggest(word) } };
}
