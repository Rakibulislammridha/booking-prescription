// The interpretation line under every Rx line (PRESCRIPTION.md §2.13) — the client mirror of
// App\Domain\Prescription\Services\DisplayFormatter, so what the doctor reads while typing is exactly what the
// snapshot will freeze. Bangla renderings use Bangla digits; drug names always stay English.
import type { ItemDisplay, ParsedLine } from '@shared/types/models';
import { labels } from './shorthand/keywords';
import { amount as spellAmount, bnDigits, decimal, enDigits } from './shorthand/numberFormat';

export type DisplayLang = 'bn' | 'en';

const BN_COUNTER_UNITS = new Set(['tab', 'cap', 'supp', 'pessary', 'neb', 'sachet']);
const BN_QUANTITY_UNITS = new Set(['tab', 'cap', 'supp', 'pessary', 'neb', 'sachet', 'respule']);

function bnCounter(unit: string): string {
  return BN_COUNTER_UNITS.has(unit) ? 'টা' : ` ${labels().units[unit]?.bn ?? unit}`;
}

export function durationText(lang: DisplayLang, line: ParsedLine): string {
  const l = labels().duration;
  const d = line.duration;
  if (d === null) return '';
  if (d.type === 'days') {
    return lang === 'bn' ? `${bnDigits(String(d.days))} ${l.days?.bn ?? ''}` : `${d.days} ${(d.days === 1 ? l.day?.en : l.days?.en) ?? ''}`;
  }
  if (d.type === 'continuous') return l.continuous?.[lang] ?? '';
  return l.till_finish?.[lang] ?? '';
}

export function quantityText(lang: DisplayLang, line: ParsedLine): string {
  const q = line.quantity;
  if (q.value === null) return '';
  const units = labels().units;
  const value = decimal(Number(q.value));
  if (lang === 'bn') return bnDigits(value) + (BN_QUANTITY_UNITS.has(q.unit) ? 'টি' : ` ${units[q.unit]?.bn ?? q.unit}`);
  return `${value} ${units[q.unit]?.en ?? q.unit}`;
}

export function itemDisplay(lang: DisplayLang, line: ParsedLine): ItemDisplay {
  const l = labels();
  const bn = lang === 'bn';
  const n = (s: string): string => (bn ? bnDigits(s) : s);
  const unitLabel = l.units[line.unit]?.[lang] ?? line.unit;
  const s = line.schedule;
  let dose = '';

  if (s !== null) {
    switch (s.type) {
      case 'slots':
        dose = s.slots.map((v) => n(spellAmount(v))).join(' + ') + (bn ? '' : ` ${unitLabel}`);
        break;
      case 'frequency':
        dose = `${n(spellAmount(s.amount))}${bn ? bnCounter(line.unit) : ` ${unitLabel}`} ${l.schedule[s.code]?.[lang] ?? s.code}`;
        break;
      case 'interval':
        dose = `${n(spellAmount(s.amount))}${bn ? bnCounter(line.unit) : ` ${unitLabel}`} ${(l.schedule.interval?.[lang] ?? '').replace('{hours}', n(String(s.every_hours)))}`;
        break;
      case 'stat':
        dose = `${n(spellAmount(s.amount))}${bn ? bnCounter(line.unit) : ` ${unitLabel}`} ${l.schedule.stat?.[lang] ?? ''}`;
        break;
      case 'sos':
        dose = `${n(spellAmount(s.amount))}${bn ? bnCounter(line.unit) : ` ${unitLabel}`} ${
          s.max_per_day !== null ? (l.schedule.sos_max?.[lang] ?? '').replace('{max}', n(String(s.max_per_day))) : l.schedule.sos?.[lang] ?? ''
        }`;
        break;
    }
  }

  const duration = durationText(lang, line);
  const timing = line.timing_code !== null ? l.timing[line.timing_code]?.[lang] ?? '' : '';
  const route = line.route_code !== null ? l.routes[line.route_code]?.[lang] ?? line.route_code : '';
  const quantity = quantityText(lang, line);
  const parts = [dose, duration, timing, route, quantity].filter((p) => p !== '');

  return { dose, duration, timing, quantity, route, interpretation: parts.join(' · ') };
}

/** "3d" → {bn: "৩ দিন", en: "3 days"} — chief-complaint durations (§4.1). */
export function complaintDuration(duration: string | null): { bn: string; en: string } {
  if (duration === null || duration.trim() === '') return { bn: '', en: '' };
  const m = /^(\d+)\s*(d|day|days|w|wk|wks|week|weeks|m|mo|month|months|h|hr|hrs|hour|hours|y|yr|yrs|year|years)$/i.exec(enDigits(duration).trim());
  if (m === null) return { bn: bnDigits(duration), en: duration };

  const value = Number(m[1]);
  const unit = (m[2] ?? '').toLowerCase();
  let en: string;
  let bn: string;
  if (unit.startsWith('d')) [en, bn] = [value === 1 ? 'day' : 'days', 'দিন'];
  else if (unit.startsWith('w')) [en, bn] = [value === 1 ? 'week' : 'weeks', 'সপ্তাহ'];
  else if (unit.startsWith('m')) [en, bn] = [value === 1 ? 'month' : 'months', 'মাস'];
  else if (unit.startsWith('h')) [en, bn] = [value === 1 ? 'hour' : 'hours', 'ঘণ্টা'];
  else [en, bn] = [value === 1 ? 'year' : 'years', 'বছর'];

  return { bn: `${bnDigits(String(value))} ${bn}`, en: `${value} ${en}` };
}

/** Splits "fever 3d" into text + duration using the §2.5 duration rule (§4.1 chip entry). The match runs on a
 *  digit-normalised copy but the text is sliced from the original, so a Bangla complaint keeps its Bangla digits. */
export function splitComplaint(input: string): { text: string; duration: string | null } {
  const original = input.trim().replace(/\s+/g, ' ');
  const ascii = enDigits(original); // 1:1 per character, so offsets stay aligned
  const m = /^(.*\S)\s+(\d+\s*(?:d|day|days|w|wk|wks|week|weeks|m|mo|month|months|h|hr|hrs|hour|hours|y|yr|yrs|year|years))$/i.exec(ascii);
  if (m === null) return { text: original, duration: null };
  return { text: original.slice(0, (m[1] ?? '').length), duration: (m[2] ?? '').replace(/\s+/g, '').toLowerCase() };
}
