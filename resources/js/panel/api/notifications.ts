// Notifications module XHR (CONVENTIONS §7.2): the only place the panel talks JSON to the notification endpoints.
// Everything else on these screens is an Inertia form post returning a redirect + flash.
import { http } from '@shared/http';
import { route } from '@shared/routes';
import type {
  NotificationDetail,
  NotificationTemplatePreview,
  PushSubscriptionRow,
} from '@shared/types/models';

/** The detail drawer: the row plus every delivery attempt with its provider exchange. */
export async function fetchNotification(id: number, signal?: AbortSignal): Promise<NotificationDetail> {
  const { data } = await http.get<NotificationDetail>(route('panel.notifications.show', { notification: id }), { signal });
  return data;
}

export interface PreviewPayload {
  event_key: string;
  channel: string;
  locale: string;
  body: string;
  subject?: string | null;
}

/** Server-side render of an UNSAVED body with the event's sample values, and the authoritative segment maths. */
export async function previewTemplate(payload: PreviewPayload, signal?: AbortSignal): Promise<NotificationTemplatePreview> {
  const { data } = await http.post<NotificationTemplatePreview>(route('panel.notifications.templates.preview'), payload, { signal });
  return data;
}

/** VAPID application server key for `pushManager.subscribe`; null when the server has no key pair. */
export async function fetchPushKey(): Promise<string | null> {
  const { data } = await http.get<{ public_key: string | null }>(route('api.notifications.push.key'));
  return data.public_key;
}

export interface PushSubscriptionPayload {
  endpoint: string;
  keys: { p256dh: string; auth: string };
  content_encoding?: string;
}

export async function subscribeToPush(payload: PushSubscriptionPayload): Promise<PushSubscriptionRow> {
  const { data } = await http.post<{ data: PushSubscriptionRow }>(route('api.notifications.push.subscribe'), payload);
  return data.data;
}

export async function unsubscribeFromPush(endpoint: string): Promise<void> {
  await http.delete(route('api.notifications.push.unsubscribe'), { data: { endpoint } });
}
