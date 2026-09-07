// The shared axios instance (CONVENTIONS §7.2): every fetch/axios call lives in <surface>/api/<module>.ts and
// imports `http` from here. Nothing else imports axios. Errors are normalised to ApiError so callers branch on
// the dotted `code` (CONVENTIONS §13), never on message text or HTTP status. Network evidence is fed to the
// connection store (OFFLINE.md §3.1): a success is reachability, ERR_NETWORK/timeout triggers a heartbeat.
import axios, { AxiosError, type AxiosInstance } from 'axios';
import { useConnection } from './connection/store';
import { triggerHeartbeat } from './connection/heartbeat';
import { getCsrfToken } from './csrf';
import { ApiError, isApiError, type ValidationErrors } from './apiError';

export { setCsrfToken, getCsrfToken } from './csrf';
// ApiError lives in ./apiError (no axios) so pages can narrow an error without loading the client; re-exported
// here because CONVENTIONS §7.2 makes @shared/http the public name.
export { ApiError, isApiError } from './apiError';
export type { ValidationErrors } from './apiError';

const NETWORK_CODES = new Set(['ERR_NETWORK', 'ECONNABORTED', 'ETIMEDOUT', 'ERR_CANCELED']);

export function toApiError(error: unknown): ApiError {
  if (isApiError(error)) return error;
  if (axios.isAxiosError(error)) {
    const ax = error as AxiosError<{ message?: string; code?: string; errors?: ValidationErrors }>;
    if (!ax.response) {
      const network = ax.code === undefined || NETWORK_CODES.has(ax.code);
      return new ApiError(ax.message || 'Network error', { status: null, code: network ? 'network' : ax.code ?? null, network, cause: error });
    }
    const body = ax.response.data ?? {};
    const status = ax.response.status;
    const code = typeof body.code === 'string' ? body.code : status === 422 && body.errors ? 'validation' : status === 401 ? 'auth.unauthenticated' : status === 403 ? 'auth.forbidden' : status === 404 ? 'http.not_found' : status === 419 ? 'auth.csrf' : status === 429 ? 'http.throttled' : `http.${status}`;
    return new ApiError(typeof body.message === 'string' && body.message !== '' ? body.message : ax.message, { status, code, errors: body.errors ?? {}, cause: error });
  }
  return new ApiError(error instanceof Error ? error.message : String(error), { cause: error });
}

export const http: AxiosInstance = axios.create({
  baseURL: '/',
  timeout: 15_000,
  withCredentials: true,
  withXSRFToken: true,
  xsrfCookieName: 'XSRF-TOKEN',
  xsrfHeaderName: 'X-XSRF-TOKEN',
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

// Belt and braces next to the XSRF cookie: also send the session token from the shared props.
http.interceptors.request.use((config) => {
  const token = getCsrfToken();
  if (token && !config.headers.has('X-CSRF-TOKEN')) config.headers.set('X-CSRF-TOKEN', token);
  return config;
});

http.interceptors.response.use(
  (response) => {
    useConnection.getState().heartbeatResult(true); // any successful response is reachability evidence
    return response;
  },
  (error: unknown) => {
    const apiError = toApiError(error);
    if (apiError.network) void triggerHeartbeat(); // let the heartbeat decide offline; never flip mode from here
    return Promise.reject(apiError);
  },
);
