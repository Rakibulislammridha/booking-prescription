// PRESCRIPTION.md §2.1 — mirrors App\Domain\Prescription\Shorthand\Normalizer: Bangla digits → ASCII (that mapping
// alone defines `raw`), unicode fractions / × / dashes, instruction split at the first `//` or quote, lower-case,
// whitespace collapse, digit/letter spacing outside the attached-unit set, no spaces around `+` (and `/` between digits).
import { attachedUnits } from './keywords';
import { enDigits } from './numberFormat';

export interface Normalized {
  raw: string;
  body: string;
  instruction: string | null;
}

const SUBSTITUTIONS: Array<[string, string]> = [
  ['×', 'x'],
  ['½', '1/2'],
  ['¼', '1/4'],
  ['¾', '3/4'],
  ['—', '-'],
  ['–', '-'],
];

const MARKERS: Array<[string, number]> = [
  ['//', 2],
  ['"', 1],
  ['“', 1],
  ['”', 1],
];

/** PHP trim(): " \t\n\r\0\x0B" only — JS String.trim() also eats unicode spaces, so be explicit. */
function phpTrim(text: string): string {
  return text.replace(/^[ \t\n\r\0\x0B]+/, '').replace(/[ \t\n\r\0\x0B]+$/, '');
}

function instructionOffset(text: string): [number, number] | null {
  let best: [number, number] | null = null;
  for (const [marker, length] of MARKERS) {
    const pos = text.indexOf(marker);
    if (pos !== -1 && (best === null || pos < best[0])) best = [pos, length];
  }
  return best;
}

export function normalize(text: string): Normalized {
  const raw = enDigits(text);
  let work = raw;
  for (const [from, to] of SUBSTITUTIONS) work = work.split(from).join(to);

  let instruction: string | null = null;
  const split = instructionOffset(work);

  if (split !== null) {
    const [offset, length] = split;
    let tail = phpTrim(work.slice(offset + length));
    tail = tail.replace(/["”“]+$/, '');
    tail = phpTrim(tail);
    instruction = tail === '' ? null : tail;
    work = work.slice(0, offset);
  }

  let body = work.toLowerCase();
  body = phpTrim(body.replace(/[ \t\n\r\f\v]+/g, ' '));
  body = body.replace(/[ \t\n\r\f\v]*\+[ \t\n\r\f\v]*/g, '+');
  body = body.replace(/(\d)[ \t\n\r\f\v]*\/[ \t\n\r\f\v]*(\d)/g, '$1/$2');
  const attached = attachedUnits();
  body = body.replace(/(\d)([a-z]+)/g, (whole, digit: string, word: string) => (attached.includes(word) ? whole : `${digit} ${word}`));

  return { raw, body: phpTrim(body), instruction };
}
