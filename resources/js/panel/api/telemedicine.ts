// Telemedicine XHR for the panel (CONVENTIONS §7.2). `start` and `end` are Inertia posts from the page — they
// change the screen — so they are deliberately absent here.
import { http } from '@shared/http';
import { route } from '@shared/routes';
import type { VideoCredentials } from '@site/Pages/Telemedicine/core/videoClient';
import type { TelemedicineRoomState } from '@shared/types/models';

export async function requestDoctorToken(room: string): Promise<VideoCredentials> {
  const { data } = await http.post<VideoCredentials>(route('panel.telemedicine.token', { room }));
  return data;
}

export async function fetchRoomState(room: string): Promise<TelemedicineRoomState> {
  const { data } = await http.get<TelemedicineRoomState>(route('panel.telemedicine.state', { room }));
  return data;
}

export async function reportDoctorLeft(room: string): Promise<TelemedicineRoomState> {
  const { data } = await http.post<TelemedicineRoomState>(route('panel.telemedicine.leave', { room }));
  return data;
}

export async function setRecording(room: string, on: boolean): Promise<TelemedicineRoomState> {
  const { data } = await http.post<TelemedicineRoomState>(route('panel.telemedicine.recording', { room }), { on });
  return data;
}

export async function reportDoctorQuality(room: string, stats: { avg_bitrate_kbps?: number; packet_loss_pct?: number; rtt_ms?: number }): Promise<void> {
  await http.post(route('panel.telemedicine.quality', { room }), stats);
}
