/** Bitacora (seccion 3.9, punto 7 del CLAUDE.md raiz). */

interface RegistroBitacoraBase {
  id: number;
  /** ISO 8601 (UTC). */
  fecha: string | null;
  evento: string | null;
  usuario: { id: number; name: string } | null;
  /** 'persona', 'resultado', 'usuario'... o el nombre de la clase si no se reconoce. */
  objeto: { tipo: string; id: number | string } | null;
}

/** GET /api/bitacora (admin, su tenant completo). */
export interface RegistroBitacora extends RegistroBitacoraBase {
  descripcion: string;
  cambios: { attributes?: Record<string, unknown>; old?: Record<string, unknown> } | null;
  propiedades: Record<string, unknown> | null;
}

/** GET /api/superadmin/bitacora (todos los tenants, sin datos personales). */
export interface RegistroBitacoraGlobal extends RegistroBitacoraBase {
  tenant: { id: string; name: string | null } | null;
}

export interface FiltrosBitacora {
  evento?: string;
  usuario_id?: number;
  tenant_id?: string;
  desde?: string;
  hasta?: string;
  page: number;
}
