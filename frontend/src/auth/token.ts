/**
 * The access token lives in this module's memory only — never in
 * localStorage, sessionStorage or a cookie (docs/04 section 2). A page reload
 * loses it; the session is restored from the HttpOnly refresh cookie.
 */
let accessToken: string | null = null;

export function getAccessToken(): string | null {
  return accessToken;
}

export function setAccessToken(token: string | null): void {
  accessToken = token;
}
