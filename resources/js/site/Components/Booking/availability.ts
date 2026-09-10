// The day/session picker's view of the availability payload (api.scheduling.availability → AvailabilityCalendar)
// and of the kiosk page's prefilled sessions, normalised to one shape so DayStrip and SessionCard never branch on
// the channel. Pure functions, unit-tested in Pages/Booking/__tests__.
import { formatDhaka } from '@shared/format/date';
import type { AvailabilityDay, KioskSession, ScheduleMode, SessionStatus } from '@shared/types/models';

/** Why a day cannot be booked; `open` when at least one session still has online serials. */
export type DayState = 'open' | 'full' | 'closed' | 'off' | 'holiday' | 'leave';

export interface PickableSession {
  public_id: string;
  code: string;
  date: string;
  status: SessionStatus;
  mode: ScheduleMode | null;
  planned_start_at: string;
  planned_end_at: string | null;
  delay_minutes: number;
  online_remaining: number;
  online_quota: number | null;
  slot_minutes: number | null;
  now_serving: string | null;
  /** The kiosk page lists several doctors' sessions, each with its own fee; the doctor page never needs these. */
  doctorName: string | null;
  fee_paisa: number | null;
}

export interface DayCell {
  date: string;
  state: DayState;
  sessions: PickableSession[];
}

export function isOpenStatus(status: SessionStatus): boolean {
  return status === 'scheduled' || status === 'running' || status === 'paused';
}

export function isBookable(s: PickableSession): boolean {
  return isOpenStatus(s.status) && s.online_remaining > 0;
}

function stateOf(day: AvailabilityDay, sessions: PickableSession[]): DayState {
  if (sessions.some(isBookable)) return 'open';
  if (sessions.length === 0) return day.closed ?? 'off';
  return sessions.some((s) => isOpenStatus(s.status)) ? 'full' : 'closed';
}

export function toDayCells(days: AvailabilityDay[]): DayCell[] {
  return days.map((day) => {
    const sessions = day.sessions.map<PickableSession>((s) => ({
      public_id: s.public_id, code: s.code, date: day.date, status: s.status, mode: s.mode,
      planned_start_at: s.planned_start_at, planned_end_at: s.planned_end_at, delay_minutes: s.delay_minutes,
      online_remaining: s.online_remaining, online_quota: s.online_quota, slot_minutes: s.slot_minutes, now_serving: s.now_serving,
      doctorName: null, fee_paisa: null,
    }));
    return { date: day.date, state: stateOf(day, sessions), sessions };
  });
}

/** The kiosk QR page: today's open sessions at the branch, each with its doctor, no calendar. */
export function kioskSessions(sessions: KioskSession[], doctorName: (en: string, bn: string | null) => string): PickableSession[] {
  return sessions.map((s) => ({
    public_id: s.public_id, code: s.code, date: s.date, status: s.status, mode: null,
    planned_start_at: s.planned_start_at, planned_end_at: null, delay_minutes: 0,
    online_remaining: s.online_remaining, online_quota: null, slot_minutes: null, now_serving: null,
    doctorName: doctorName(s.doctor.name, s.doctor.name_bn), fee_paisa: s.doctor.fee_paisa,
  }));
}

/** 0 = Sunday … 6 = Saturday for a calendar date "YYYY-MM-DD" (no zone arithmetic: it is a date, not an instant). */
export function weekdayOf(date: string): number {
  const [y, m, d] = date.split('-').map(Number);
  return new Date(y ?? 1970, (m ?? 1) - 1, d ?? 1).getDay();
}

/** Morning / afternoon / evening by the session's planned start in Dhaka — the label patients use for "session A". */
export function timeOfDay(plannedStartAt: string): 'morning' | 'afternoon' | 'evening' {
  const hour = Number(formatDhaka(plannedStartAt, 'HH', 'en'));
  return hour < 12 ? 'morning' : hour < 16 ? 'afternoon' : 'evening';
}

/** The share of the online quota still free — what the capacity bar draws. 0 when the session takes no serials. */
export function capacityFraction(s: PickableSession): number {
  if (!isBookable(s)) return 0;
  if (s.online_quota === null || s.online_quota <= 0) return 1;
  return Math.min(1, Math.max(0, s.online_remaining / s.online_quota));
}
