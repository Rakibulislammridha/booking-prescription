// The session CSRF token from the shared props, kept here (dependency-free) so bootShared() does not import axios.
let token: string | null = null;

export function setCsrfToken(next: string | null | undefined): void {
  token = next || null;
}

export function getCsrfToken(): string | null {
  return token;
}
