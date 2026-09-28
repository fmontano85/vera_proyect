/** GET /api/superadmin/tenants (panel de superadmin, seccion 3.2). */
export interface TenantSuperadmin {
  id: string;
  name: string | null;
  sanciones_habilitado: boolean;
}

export type ModoDescargaOfac = 'manual' | 'automatico';

/** GET/PUT /api/superadmin/configuracion-sanciones. */
export interface ConfiguracionSanciones {
  modo_descarga_ofac: ModoDescargaOfac;
  actualizado_por: string | null;
  updated_at: string | null;
}
