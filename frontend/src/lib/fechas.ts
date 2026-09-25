/**
 * Fechas de calendario 'YYYY-MM-DD' del backend (proximo_seguimiento_en,
 * vencimientos - seccion 3.8). NUNCA pasarlas por new Date(): la
 * interpreta como medianoche UTC y en El Salvador (UTC-6) muestra el dia
 * anterior. Se formatean y comparan como texto/calendario.
 */

/** '2026-10-25' -> '25/10/2026'. */
export function formatearFecha(fecha: string | null | undefined): string {
  if (!fecha) return '—';

  const [anio, mes, dia] = fecha.slice(0, 10).split('-');

  return `${dia}/${mes}/${anio}`;
}

/** Fecha de hoy en la zona horaria de El Salvador como 'YYYY-MM-DD'. */
export function hoyEnElSalvador(): string {
  // en-CA formatea como YYYY-MM-DD.
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'America/El_Salvador' }).format(new Date());
}

/** Dias entre dos fechas de calendario (b - a), sin horas ni zonas. */
export function diasEntre(a: string, b: string): number {
  const utc = (f: string) => {
    const [anio, mes, dia] = f.slice(0, 10).split('-').map(Number);
    return Date.UTC(anio!, mes! - 1, dia!);
  };

  return Math.round((utc(b) - utc(a)) / 86_400_000);
}

/** Timestamp ISO completo (ultimo_seguimiento_en) en hora de El Salvador. */
export function formatearFechaHora(iso: string | null | undefined): string {
  if (!iso) return '—';

  // Mismo dd/mm/aaaa que formatearFecha (dateStyle 'short' daba '25/9/26').
  return new Intl.DateTimeFormat('es-SV', {
    timeZone: 'America/El_Salvador',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(iso));
}
