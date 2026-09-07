// The normalised API error (CONVENTIONS §13) — deliberately free of axios, so a page can branch on a failure
// without dragging the 20 KB gzip HTTP client into its first load. @shared/http re-exports all of this, so
// existing `import { isApiError } from '@shared/http'` call sites are unaffected; import from here instead
// when the page only touches the client behind an `await import()`.
export type ValidationErrors = Record<string, string[]>;

export class ApiError extends Error {
  readonly status: number | null;
  /** Dotted domain code (`serials.pool_exhausted`), `validation` for 422 field errors, `network` when unreachable, else null. */
  readonly code: string | null;
  readonly errors: ValidationErrors;
  readonly network: boolean;

  constructor(message: string, init: { status?: number | null; code?: string | null; errors?: ValidationErrors; network?: boolean; cause?: unknown }) {
    super(message, { cause: init.cause });
    this.name = 'ApiError';
    this.status = init.status ?? null;
    this.code = init.code ?? null;
    this.errors = init.errors ?? {};
    this.network = init.network ?? false;
  }

  is(code: string): boolean {
    return this.code === code;
  }

  /** First message for a field from a 422 validation response. */
  fieldError(field: string): string | undefined {
    return this.errors[field]?.[0];
  }
}

export function isApiError(value: unknown): value is ApiError {
  return value instanceof ApiError;
}
