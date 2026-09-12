// Thin fetch wrapper: attaches the bearer token, normalizes errors into a
// single ApiError type, and centralizes session storage so every page reads
// and writes the session the same way.

const SESSION_KEY = 'yolotask_session';

export function getSession() {
  try { return JSON.parse(localStorage.getItem(SESSION_KEY) || 'null'); }
  catch { return null; }
}
export function setSession(session) {
  localStorage.setItem(SESSION_KEY, JSON.stringify(session));
}
export function clearSession() {
  localStorage.removeItem(SESSION_KEY);
}

export class ApiError extends Error {
  constructor(message, status) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
  }
}

/**
 * @param {string} path e.g. 'auth?action=login'
 * @param {{method?: string, body?: object}} options
 */
export async function apiRequest(path, options = {}) {
  const session = getSession();
  const headers = { 'Content-Type': 'application/json' };
  if (session?.access_token) headers.Authorization = `Bearer ${session.access_token}`;

  let response;
  try {
    response = await fetch(`/api/${path}`, {
      method: options.method || 'GET',
      headers,
      body: options.body !== undefined ? JSON.stringify(options.body) : undefined
    });
  } catch {
    throw new ApiError('Could not reach the server. Check your connection and try again.', 0);
  }

  let payload = {};
  try { payload = await response.json(); } catch { /* non-JSON error page */ }

  if (!response.ok || payload.ok === false) {
    throw new ApiError(payload.error || 'The request could not be completed.', response.status);
  }
  return payload;
}
