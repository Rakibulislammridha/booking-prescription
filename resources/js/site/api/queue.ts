// Site queue XHR (CONVENTIONS §7.2). The 5 s ETag poll itself lives in @shared/realtime/liveQueue (it needs the raw
// Response headers, so it uses fetch directly); everything else the queue and display pages ask for is here.
import { http } from '@shared/http';
import { route } from '@shared/routes';
import type { DisplayTile, QueueSessionsResponse, ResolvedLocalSerial } from '@shared/types/models';

export async function fetchQueueSessions(doctorSlug: string, date?: string): Promise<QueueSessionsResponse> {
  const { data } = await http.get<QueueSessionsResponse>(route('site.queue.sessions', { doctorSlug }), { params: date ? { date } : {} });
  return data;
}

/** A slip printed offline carries the device's client_event_id; this maps it once the desk has synced (OFFLINE §10). */
export async function resolveLocalSerial(localId: string): Promise<ResolvedLocalSerial> {
  const { data } = await http.get<ResolvedLocalSerial>(route('site.queue.resolve', { localId }));
  return data;
}

export interface DisplayTilesResponse {
  branch: { public_id: string; slug: string; name: string };
  date: string;
  tiles: DisplayTile[];
}

/** The display's 10-minute self-heal (REALTIME §9.3): new sessions appear without a page reload. */
export async function fetchDisplayTiles(branchPublicId: string, bearerToken?: string | null): Promise<DisplayTilesResponse> {
  const { data } = await http.get<DisplayTilesResponse>(route('api.queue.display.tiles', { branch: branchPublicId }), {
    headers: bearerToken ? { Authorization: `Bearer ${bearerToken}` } : undefined,
  });
  return data;
}
