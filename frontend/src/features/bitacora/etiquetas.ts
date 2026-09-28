/** Nombres legibles de los eventos de la bitacora. Un evento nuevo sin
 * etiqueta se muestra con su clave tal cual (no se pierde, solo se ve feo). */
const EVENTOS: Record<string, string> = {
  created: 'Creación',
  updated: 'Modificación',
  deleted: 'Eliminación',
  inicio_sesion: 'Inicio de sesión',
  cierre_sesion: 'Cierre de sesión',
  consulta_puntual: 'Consulta puntual',
  busqueda_tags: 'Búsqueda por tags',
  extraccion_solicitada: 'Extracción de noticia',
  resultado_descartado: 'Resultado descartado',
  evidencia_descargada: 'Descarga de evidencia',
  sanciones_cruzadas: 'Cruce de sanciones',
  seguimiento_realizado: 'Seguimiento realizado',
  alertas_enviadas: 'Alertas enviadas',
  rol_cambiado: 'Cambio de rol',
  contrasena_restablecida: 'Contraseña restablecida',
  contrasena_cambiada: 'Contraseña cambiada',
  tenant_creado: 'Tenant creado',
  tenant_renombrado: 'Tenant renombrado',
  sanciones_cambiado: 'Sanciones habilitada/deshabilitada',
};

const OBJETOS: Record<string, string> = {
  persona: 'Persona',
  alias: 'Alias',
  coincidencia: 'Coincidencia',
  sancion: 'Hallazgo de sanciones',
  resultado: 'Resultado',
  tag: 'Tag',
  usuario: 'Usuario',
  frecuencia: 'Frecuencia de seguimiento',
  tenant: 'Tenant',
  configuracion_sanciones: 'Configuración de sanciones',
};

export function etiquetaEvento(evento: string | null): string {
  if (!evento) return '—';
  return EVENTOS[evento] ?? evento;
}

export function etiquetaObjeto(objeto: { tipo: string; id: number | string } | null): string {
  if (!objeto) return '—';
  return `${OBJETOS[objeto.tipo] ?? objeto.tipo} #${objeto.id}`;
}
