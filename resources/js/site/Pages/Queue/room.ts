// `doctors.room_label` is free text — "Room 3", "রুম ৩" or just "3". The page adds its own localised "Room" word,
// so strip a leading one from the label to avoid "রুম Room ৩" on the queue page and the waiting-room display.
import { formatBn } from '@shared/format/number';
import type { Locale } from '@shared/types/shared-props';

const LEADING_ROOM_WORD = /^\s*(room|রুম|কক্ষ)\s*/i;

export function roomLabel(room: string | null | undefined, locale: Locale): string | null {
  if (!room) return null;
  const bare = room.replace(LEADING_ROOM_WORD, '').trim();
  return formatBn(bare === '' ? room.trim() : bare, locale);
}
