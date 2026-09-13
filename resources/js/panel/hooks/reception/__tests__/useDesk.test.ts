// Whose board is on the desk? The Dexie cache is keyed by (tenant, DEVICE) and the pointer to it lives in
// browser-wide localStorage, so on a registered reception tablet every user of that browser profile opens the same
// database — the previous shift's whole branch, patient names, fees and all. What decides whether it may be read is
// the actor the SERVER last named for it, never the actor the cache names for itself.
//
// The cases below are the ones that went wrong: a compounder (DoctorScope-restricted, refused by
// AuthenticateReceptionDevice on every device call) rendering that cache for ever because each 403 fell back to it,
// and a second receptionist opening a colleague's board out of the same tablet. Each one also asserts what must NOT
// happen: the unsynced event log is never touched, because it is somebody's un-uploaded work.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { act, renderHook, waitFor } from '@testing-library/react';
import { uniqueDevice } from '@shared/offline/__tests__/setup';
import { applyBoard, EventLog, META_KEYS, ReceptionDB, type BootstrapPayload, type ServerBoard } from '@shared/offline';
import { resetConnectionForTests } from '@shared/connection/store';
import { DEVICE_POINTER_KEY, useDesk } from '../useDesk';
import type { Board } from '@shared/types/models';

const api = vi.hoisted(() => ({
  fetchBoard: vi.fn(),
  fetchDeviceBoard: vi.fn(),
  fetchBootstrap: vi.fn(),
  fetchRecentPatients: vi.fn(async () => []),
  leaseBlock: vi.fn(),
  syncEvents: vi.fn(async () => { throw new Error('offline'); }),
  resolveConflict: vi.fn(),
}));
vi.mock('@panel/api/reception', () => api);
vi.mock('@shared/realtime/useChannel', () => ({ useChannel: () => undefined }));

const DATE = '2026-09-12';

function board(sessionId: string, doctor: string): ServerBoard {
  return {
    date: DATE,
    branch: { public_id: 'brn_1', name: 'Dhanmondi', code: 'DHK', slug: 'dhanmondi' },
    generated_at: `${DATE}T04:00:00Z`,
    sessions: [{
      public_id: sessionId, code: 'B', date: DATE, status: 'running', mode: 'serial',
      planned_start_at: `${DATE}T03:00:00Z`, planned_end_at: `${DATE}T07:00:00Z`, delay_minutes: 0,
      doctor: { public_id: doctor, slug: 'dr-b', name: 'Dr. Md. Abdur Rahman', name_bn: null, room: null },
      now_serving: null, counts: { booked: 1 },
      remaining: { online: 3, counter: 5, buffer: 2, counter_in_blocks: 0, released: 0 },
      fee_new_paisa: 80_000, fee_followup_paisa: 50_000, max_serials: 30, version: 1,
      serials: [{
        public_id: `ser_${sessionId}`, display_code: 'B-001', number: 1, position: 1_000_000, status: 'booked', priority: 'normal', source: 'counter',
        patient: { public_id: 'pat_1', name: 'রহিমা বেগম', mobile_masked: '017*****21' },
        appointment: { public_id: 'apt_1', fee_paisa: 80_000, payment_status: 'unpaid', status: 'confirmed', hold_expires_at: null },
      }],
    }],
  };
}

const asBoard = (b: ServerBoard): Board => b as unknown as Board;
const sessionIds = (b: Board): string[] => b.sessions.map((s) => s.public_id);
const doctorIds = (b: Board): string[] => b.sessions.map((s) => s.doctor.public_id);

/** What a registered tablet really holds: the whole branch — here two doctors, neither of them the viewer's. */
function twoDoctorBoard(): ServerBoard {
  const first = board('ses_cached', 'doc_theirs');
  const second = board('ses_cached_2', 'doc_theirs_2');

  return { ...first, sessions: [...first.sessions, ...second.sessions] };
}

/** A registered tablet whose cache holds `cachedActor`'s two-doctor board and one un-uploaded check-in. */
async function registeredTablet(cachedActor: string): Promise<{ device: string; db: ReceptionDB }> {
  const device = uniqueDevice();
  localStorage.setItem(DEVICE_POINTER_KEY, JSON.stringify({ tenantId: 'ten_1', devicePublicId: device }));
  const db = new ReceptionDB('ten_1', device);
  await db.setMeta(META_KEYS.deviceToken, 'device-token');
  await db.setMeta(META_KEYS.actorUser, { public_id: cachedActor });
  await applyBoard(db, twoDoctorBoard());
  await new EventLog(db, () => cachedActor).append({ type: 'check_in', payload: { serialRef: 'ser_ses_cached' } });
  return { device, db };
}

/** The Inertia props, built ONCE: `useDesk` re-seeds the board whenever the prop identity changes. */
function mount(actorPublicId: string, doctorScoped: boolean) {
  const initial = asBoard(board('ses_props', 'doc_mine'));
  const options = { tenantPublicId: 'ten_1', channel: null, actorPublicId, doctorScoped, settings: {} };

  return renderHook(() => useDesk(initial, options));
}

let db: ReceptionDB;

beforeEach(() => { resetConnectionForTests({ mode: 'online' }); localStorage.clear(); });
afterEach(() => { db?.close(); });

describe('useDesk — a device cache is read only by the viewer the server named for it', () => {
  it('keeps a DoctorScope-restricted viewer off the device path entirely, and off the cache with it', async () => {
    ({ db } = await registeredTablet('usr_receptionist'));
    api.fetchBoard.mockResolvedValue(board('ses_props', 'doc_mine'));

    const { result } = mount('usr_compounder', true);

    // Nothing of the device's is touched: no bootstrap, no device board, no "this device" chip — the three calls
    // that 403 for this viewer and used to leave the stale cache on screen as the only thing left.
    await waitFor(() => expect(result.current.board.date).toBe(DATE));
    expect(api.fetchBootstrap).not.toHaveBeenCalled();
    expect(api.fetchDeviceBoard).not.toHaveBeenCalled();
    expect(result.current.registered).toBe(false);
    expect(sessionIds(result.current.board)).toEqual(['ses_props']);
    expect(doctorIds(result.current.board)).toEqual(['doc_mine']);   // neither doctor the cache holds is on screen

    // …and the 5 s poll goes to the panel JSON, which is the DoctorScope-filtered document.
    await act(async () => { await result.current.refresh(); });
    expect(api.fetchDeviceBoard).not.toHaveBeenCalled();
    expect(api.fetchBoard).toHaveBeenCalledTimes(1);
    expect(sessionIds(result.current.board)).toEqual(['ses_props']);

    // Refused, never erased: the receptionist's un-uploaded check-in is exactly where they left it.
    expect(await db.events.count()).toBe(1);
    expect(await db.serials.count()).toBe(2);   // both cached rows still there — refused, not erased
  });

  it('refuses a cache that names somebody else, even to a receptionist the device would accept', async () => {
    ({ db } = await registeredTablet('usr_other'));
    api.fetchBootstrap.mockRejectedValue(new Error('device unreachable'));
    api.fetchDeviceBoard.mockRejectedValue(new Error('device unreachable'));
    api.fetchBoard.mockResolvedValue(board('ses_panel', 'doc_mine'));

    const { result } = mount('usr_me', false);

    await waitFor(() => expect(api.fetchBootstrap).toHaveBeenCalled());
    expect(sessionIds(result.current.board)).toEqual(['ses_props']);

    // The fallback order: a refused device board lands on the staff JSON — the cache is not the second answer.
    await act(async () => { await result.current.refresh(); });
    expect(api.fetchDeviceBoard).toHaveBeenCalled();
    expect(sessionIds(result.current.board)).toEqual(['ses_panel']);
    expect(doctorIds(result.current.board)).toEqual(['doc_mine']);
    expect(await db.events.count()).toBe(1);
  });

  it('renders the cache for the viewer it belongs to — the offline desk still works', async () => {
    ({ db } = await registeredTablet('usr_me'));
    api.fetchBootstrap.mockRejectedValue(new Error('device unreachable'));

    const { result } = mount('usr_me', false);

    await waitFor(() => expect(sessionIds(result.current.board)).toEqual(['ses_cached', 'ses_cached_2']));
    expect(result.current.registered).toBe(true);
  });

  it('hands the cache over only when the server says so: a bootstrap naming this viewer as the actor', async () => {
    ({ db } = await registeredTablet('usr_other'));
    const payload: Partial<BootstrapPayload> = {
      actor: { public_id: 'usr_me', name: 'Karim', roles: ['receptionist'] },
      device: { public_id: 'dev', number: 1, name: 'Desk 1' },
      days: [board('ses_cached', 'doc_theirs')],
      blocks: [], print_templates: [], doctors: [], settings: {},
      tenant: { public_id: 'ten_1', name: 'Demo', timezone: 'Asia/Dhaka' },
      branch: { public_id: 'brn_1', name: 'Dhanmondi', code: 'DHK', slug: 'dhanmondi', phone: null },
      channel: null, print_format: 'a5', server_time: `${DATE}T04:00:00Z`,
    };
    api.fetchBootstrap.mockResolvedValue(payload as BootstrapPayload);

    const { result } = mount('usr_me', false);

    await waitFor(() => expect(sessionIds(result.current.board)).toEqual(['ses_cached']));
  });
});
