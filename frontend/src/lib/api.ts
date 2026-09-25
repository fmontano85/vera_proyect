/**
 * Cliente HTTP para la API de VERA (backend/routes/api.php). Sanctum SPA
 * por cookies (seccion 2 del CLAUDE.md raiz): toda request va con
 * `credentials: 'include'`, y las mutaciones (POST) necesitan primero el
 * handshake de CSRF via GET /sanctum/csrf-cookie.
 */

export const API_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000';

export class ApiError extends Error {
  status: number;
  errors?: Record<string, string[]>;

  constructor(message: string, status: number, errors?: Record<string, string[]>) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.errors = errors;
  }
}

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));
  const value = match?.[1];
  return value ? decodeURIComponent(value) : null;
}

/** Handshake de CSRF - llamar antes de login o de cualquier mutacion si
 * todavia no hay cookie XSRF-TOKEN (el navegador la guarda sola despues). */
async function ensureCsrfCookie(): Promise<void> {
  if (readCookie('XSRF-TOKEN')) return;

  await fetch(`${API_URL}/sanctum/csrf-cookie`, { credentials: 'include' });
}

type RequestOptions = Omit<RequestInit, 'body'> & { body?: unknown };

async function request<T>(path: string, init: RequestOptions = {}): Promise<T> {
  const method = (init.method ?? 'GET').toUpperCase();

  if (method !== 'GET') {
    await ensureCsrfCookie();
  }

  const headers = new Headers(init.headers);
  headers.set('Accept', 'application/json');
  const xsrfToken = readCookie('XSRF-TOKEN');
  if (xsrfToken) headers.set('X-XSRF-TOKEN', xsrfToken);

  let body: BodyInit | undefined;
  if (init.body instanceof FormData) {
    body = init.body;
  } else if (init.body !== undefined) {
    headers.set('Content-Type', 'application/json');
    body = JSON.stringify(init.body);
  }

  const response = await fetch(`${API_URL}${path}`, {
    ...init,
    method,
    headers,
    body,
    credentials: 'include',
  });

  if (response.status === 204) {
    return undefined as T;
  }

  const isJson = response.headers.get('content-type')?.includes('application/json');
  const data = isJson ? await response.json() : undefined;

  if (!response.ok) {
    throw new ApiError(
      (data && (data.message ?? data.mensaje)) ?? 'Error de red',
      response.status,
      data?.errors,
    );
  }

  return data as T;
}

export const api = {
  get: <T>(path: string) => request<T>(path),
  post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body }),
  patch: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body }),
  put: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PUT', body }),
  delete: <T>(path: string) => request<T>(path, { method: 'DELETE' }),
};
