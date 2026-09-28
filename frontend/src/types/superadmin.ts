/** GET /api/superadmin/tenants (panel de superadmin, seccion 3.2). */
export interface TenantSuperadmin {
  id: string;
  name: string | null;
  sanciones_habilitado: boolean;
}

/** POST /api/superadmin/tenants. */
export interface NuevoTenant {
  name: string;
  admin_name: string;
  admin_email: string;
  admin_password: string;
}

export interface TenantCreado {
  tenant: TenantSuperadmin;
  admin: { name: string; email: string };
}

export type ModoDescargaOfac = 'manual' | 'automatico';

/** GET/PUT /api/superadmin/configuracion-sanciones. */
export interface ConfiguracionSanciones {
  modo_descarga_ofac: ModoDescargaOfac;
  actualizado_por: string | null;
  updated_at: string | null;
  /** null si la lista OFAC nunca se ha importado. */
  lista: { version: string | null; fecha_importacion: string | null; entradas: number } | null;
}
