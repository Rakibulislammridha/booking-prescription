// Panel queue XHR (CONVENTIONS §7.2). The queue's own mutations are the serial engine's endpoints
// (@panel/api/serials); this file only carries the board poll the overview page uses in degraded mode.
import { http } from '@shared/http';
import { route } from '@shared/routes';
import type { QueueBoard, QueueDoctor } from '@shared/types/models';

export interface QueueTodayData {
  board: QueueBoard;
  doctors: Record<string, Omit<QueueDoctor, 'public_id'>>;
}

export async function fetchQueueToday(): Promise<QueueTodayData> {
  const { data } = await http.get<QueueTodayData>(route('panel.queue.today.data'));
  return data;
}
