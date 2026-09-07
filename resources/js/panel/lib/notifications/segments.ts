// The browser mirror of App\Domain\Notifications\Services\SegmentCounter. The template editor must show the SAME
// number the server stores in `notifications.segments` and the gateway bills for, so the two implementations follow
// one set of rules and are tested against the same table of cases:
//
//   GSM-7  : every character is in the basic set (or the escape-extension set, which costs 2) → 160 / 153 per part
//   UCS-2  : anything else — all Bengali — counted in UTF-16 code units                        → 70 / 67 per part
//
// A 2-unit character (a GSM escape pair, or an astral surrogate pair) is never split across parts.
import type { SmsSegmentCount } from '@shared/types/models';

export const GSM_SINGLE = 160;
export const GSM_MULTI = 153;
export const UCS2_SINGLE = 70;
export const UCS2_MULTI = 67;

const GSM_BASIC =
  '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

const GSM_EXTENDED = '^{}\\[~]|€\f';

/** Code points, so an astral character (emoji) is one entry rather than two halves of a surrogate pair. */
function codePoints(body: string): string[] {
  return Array.from(body);
}

export function isGsm7(body: string): boolean {
  return codePoints(body).every((char) => GSM_BASIC.includes(char) || GSM_EXTENDED.includes(char));
}

/** True when the body contains Bengali (U+0980–U+09FF) — the reason a clinic's SMS bill triples. */
export function containsBengali(body: string): boolean {
  return /[ঀ-৿]/.test(body);
}

function pack(costs: number[], perPart: number): { segments: number; remaining: number } {
  let segments = 1;
  let inPart = 0;

  for (const cost of costs) {
    if (inPart + cost > perPart) {
      segments += 1;
      inPart = 0;
    }
    inPart += cost;
  }

  return { segments, remaining: perPart - inPart };
}

export function countSegments(body: string): SmsSegmentCount {
  const gsm = isGsm7(body);
  const costs = codePoints(body).map((char) =>
    gsm ? (GSM_EXTENDED.includes(char) ? 2 : 1) : ((char.codePointAt(0) ?? 0) > 0xffff ? 2 : 1),
  );
  const units = costs.reduce((total, cost) => total + cost, 0);
  const single = gsm ? GSM_SINGLE : UCS2_SINGLE;
  const multi = gsm ? GSM_MULTI : UCS2_MULTI;
  const encoding = gsm ? 'GSM-7' : 'UCS-2';

  if (units <= single) {
    return { encoding, units, segments: 1, remaining: single - units, per_segment: single };
  }

  const { segments, remaining } = pack(costs, multi);

  return { encoding, units, segments, remaining, per_segment: multi };
}
