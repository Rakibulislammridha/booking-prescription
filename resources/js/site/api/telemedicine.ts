// Telemedicine XHR for the public site (CONVENTIONS §7.2). Loaded LAZILY by the room page: it pulls axios in
// through @shared/http, and a patient who only ever watches the waiting-room queue must not pay for it. The
// state poll itself uses `fetch` in the page for the same reason.
import { http } from '@shared/http';
import { route } from '@shared/routes';
import type { VideoCredentials } from '@site/Pages/Telemedicine/core/videoClient';
import type { TelemedicineRoomState } from '@shared/types/models';

export async function requestPatientToken(room: string): Promise<VideoCredentials> {
  const { data } = await http.post<VideoCredentials>(route('site.telemedicine.room.token', { room }));
  return data;
}

export async function reportPatientLeft(room: string): Promise<TelemedicineRoomState> {
  const { data } = await http.post<TelemedicineRoomState>(route('site.telemedicine.room.leave', { room }));
  return data;
}

export async function reportPatientQuality(room: string, stats: { avg_bitrate_kbps?: number; packet_loss_pct?: number; rtt_ms?: number }): Promise<void> {
  await http.post(route('site.telemedicine.room.quality', { room }), stats);
}
