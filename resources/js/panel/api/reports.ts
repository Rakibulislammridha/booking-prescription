// The only network call this module makes outside Inertia (CONVENTIONS §7.2): the dashboard's refresh poll.
// Everything else is an Inertia visit or a plain download link.
import { http } from '@shared/http';
import { route } from '@shared/routes';
import type { ReportDashboard } from '@shared/types/models';

export interface DashboardSnapshot {
  data: ReportDashboard;
  generated_at: string;
  cached: boolean;
}

export interface DashboardQuery {
  from?: string;
  to?: string;
  branch?: string | null;
  doctor?: string | null;
}

/** GET /api/reports/dashboard — same payload as the page, so a refresh cannot disagree with the first render. */
export async function fetchDashboard(params: DashboardQuery = {}, signal?: AbortSignal): Promise<DashboardSnapshot> {
  const query: Record<string, string> = {};
  for (const [key, value] of Object.entries(params)) if (value) query[key] = value;

  const { data } = await http.get<DashboardSnapshot>(route('api.reports.dashboard'), { params: query, signal });

  return data;
}
