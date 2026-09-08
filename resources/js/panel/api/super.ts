// Network calls of the super console (CONVENTIONS §7.2: every fetch/axios call lives in <surface>/api/<module>.ts
// and goes through the shared axios instance).
//
// Only ONE console screen talks JSON: the custom-brand promotion queue. Those endpoints belong to the Catalog
// module (`super.catalog.promotions.*`) and are handed to the page as absolute URLs in the `endpoints` prop, with
// the literal placeholder `__ID__` where the promotion's public_id goes — so this file never builds a URL of its
// own. Everything else in the console is an Inertia form post returning a redirect.
import { http } from '@shared/http';
import type {
  ApprovePromotionPayload, JsonPage, PromotionDetail, PromotionRow, RejectPromotionPayload,
} from '@panel/Components/Super/types';

/** Substitute a promotion's public_id into one of the `endpoints` templates. */
export function promotionUrl(template: string, publicId: string): string {
  return template.replace('__ID__', encodeURIComponent(publicId));
}

export interface PromotionListParams {
  status: string;
  q?: string;
  page?: number;
}

export async function listPromotions(url: string, params: PromotionListParams, signal?: AbortSignal): Promise<JsonPage<PromotionRow>> {
  const { data } = await http.get<JsonPage<PromotionRow>>(url, { params, signal });
  return data;
}

export async function showPromotion(template: string, publicId: string, signal?: AbortSignal): Promise<PromotionDetail> {
  const { data } = await http.get<PromotionDetail>(promotionUrl(template, publicId), { signal });
  return data;
}

export interface ApproveResult {
  promotion: PromotionRow;
  master: unknown;
}

export async function approvePromotion(template: string, publicId: string, payload: ApprovePromotionPayload): Promise<ApproveResult> {
  const { data } = await http.post<ApproveResult>(promotionUrl(template, publicId), payload);
  return data;
}

export async function rejectPromotion(template: string, publicId: string, payload: RejectPromotionPayload): Promise<{ promotion: PromotionRow }> {
  const { data } = await http.post<{ promotion: PromotionRow }>(promotionUrl(template, publicId), payload);
  return data;
}
