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

/** Shape comun del cuerpo de error que devuelve la API (validation.php /
 * respuestas 422/403/503 con `mensaje`). Compartido por request() y
 * download() para que ambos muestren el mismo mensaje real del backend
 * en vez de que uno lo ignore. */
interface CuerpoError {
  message?: string;
  mensaje?: string;
  errors?: Record<string, string[]>;
}

function construirApiError(status: number, data?: CuerpoError): ApiError {
  return new ApiError((data?.message ?? data?.mensaje) ?? 'Error de red', status, data?.errors);
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
    throw construirApiError(response.status, data);
  }

  return data as T;
}

/** Descarga un archivo autenticado (cookies de Sanctum) y dispara el guardado
 * del navegador. Se usa fetch + blob y no un <a href> porque la API vive en
 * otro origen y la descarga necesita las credenciales de la sesion. */
async function download(path: string): Promise<void> {
  const response = await fetch(`${API_URL}${path}`, {
    credentials: 'include',
    headers: { Accept: '*/*' },
  });

  if (!response.ok) {
    const isJson = response.headers.get('content-type')?.includes('application/json');
    const data: CuerpoError | undefined = isJson ? await response.json().catch(() => undefined) : undefined;
    // Fallback solo si el backend no mando su propio mensaje (ej. 404 sin cuerpo).
    throw construirApiError(response.status, data ?? { mensaje: 'No se pudo descargar el archivo.' });
  }

  const nombre =
    response.headers.get('content-disposition')?.match(/filename="?([^";]+)"?/)?.[1] ?? 'evidencia';
  const url = URL.createObjectURL(await response.blob());
  // El enlace debe estar en el DOM para que el click() dispare la descarga
  // en todos los navegadores; revocar la URL en el mismo tick que el click,
  // antes de que el navegador haya empezado a leerla, puede perder la
  // descarga en silencio (sin toast.error: el fetch ya resolvio bien).
  const enlace = document.createElement('a');
  enlace.href = url;
  enlace.download = nombre;
  document.body.appendChild(enlace);
  enlace.click();
  enlace.remove();
  setTimeout(() => URL.revokeObjectURL(url), 0);
}

export const api = {
  download,
  get: <T>(path: string) => request<T>(path),
  post: <T>(path: string, body?: unknown) => request<T>(path, { method: 'POST', body }),
  patch: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PATCH', body }),
  put: <T>(path: string, body?: unknown) => request<T>(path, { method: 'PUT', body }),
  delete: <T>(path: string, body?: unknown) => request<T>(path, { method: 'DELETE', body }),
};

/** Mensaje legible de un error de la API: el primer error de validacion
 * de campo si lo hay (el backend los devuelve en espanol), si no el
 * mensaje general. */
export function mensajeApi(error: unknown, porDefecto: string): string {
  if (!(error instanceof ApiError)) return porDefecto;
  const primero = error.errors ? Object.values(error.errors)[0]?.[0] : undefined;
  return primero ?? error.message ?? porDefecto;
}
