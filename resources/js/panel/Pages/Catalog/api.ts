// Network calls of the Catalog pages (CONVENTIONS §7.2: every call goes through the shared axios instance).
import { http } from '@shared/http';
import { route } from '@shared/routes';
import type { DrugSearchResponse, GenericOption, Icd10SearchResponse } from '@shared/types/models';

export async function searchGenerics(q: string, signal?: AbortSignal): Promise<GenericOption[]> {
  const { data } = await http.get<{ q: string; hits: GenericOption[] }>(route('api.catalog.generics'), { params: { q, limit: 15 }, signal });
  return data.hits;
}

export async function searchDrugs(q: string, options: { dx?: string[]; limit?: number } = {}, signal?: AbortSignal): Promise<DrugSearchResponse> {
  const { data } = await http.get<DrugSearchResponse>(route('api.catalog.drugs'), { params: { q, dx: options.dx, limit: options.limit }, signal });
  return data;
}

export async function searchIcd10(q: string, limit = 10, signal?: AbortSignal): Promise<Icd10SearchResponse> {
  const { data } = await http.get<Icd10SearchResponse>(route('api.catalog.icd10'), { params: { q, limit }, signal });
  return data;
}
