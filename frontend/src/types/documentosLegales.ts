/** Terminos y contrato de encargo (seccion 3.9, punto 1 del CLAUDE.md raiz). */

export type TipoDocumentoLegal = 'terminos' | 'contrato_encargo';

interface DocumentoLegalBase {
  id: number;
  tipo: TipoDocumentoLegal;
  /** null mientras es borrador. */
  version: number | null;
  titulo: string;
  /** Texto plano: se muestra con saltos de linea, nunca como HTML. */
  contenido: string;
  publicado_en: string | null;
}

/** GET /api/superadmin/documentos-legales */
export interface DocumentoLegalSuperadmin extends DocumentoLegalBase {
  estado: 'borrador' | 'vigente' | 'anterior';
  publicado_por: string | null;
  aceptaciones: number;
  updated_at: string | null;
}

/** GET /api/documentos-legales (vigentes, con la aceptacion del tenant). */
export interface DocumentoLegalTenant extends DocumentoLegalBase {
  aceptacion: { aceptado_por: string | null; aceptado_en: string } | null;
}

export const NOMBRE_DOCUMENTO: Record<TipoDocumentoLegal, string> = {
  terminos: 'Términos de servicio',
  contrato_encargo: 'Contrato de encargo de tratamiento',
};
