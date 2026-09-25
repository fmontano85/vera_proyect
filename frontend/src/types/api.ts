/** Formas JSON del backend (backend/app/Models, backend/app/Http/Controllers). */

export type Rol = 'superadmin' | 'admin' | 'oficial_cumplimiento' | 'analista' | 'lectura';

export interface User {
  id: number;
  name: string;
  email: string;
  tenant_id: string | null;
  roles: Rol[];
}

export type NivelRiesgo = 'bajo' | 'medio' | 'alto';

export interface Subject {
  id: number;
  tenant_id: string;
  tipo: 'natural' | 'juridica';
  nombre_canonico: string;
  documento: string | null;
  nivel_riesgo: NivelRiesgo | null;
  activo: boolean;
  created_at: string;
  aliases?: SubjectAlias[];
  /** Solo en el listado (GET /api/subjects). */
  aliases_count?: number;
  /** Seccion 3.8: null = usa el default del tenant para su nivel. */
  frecuencia_seguimiento_dias?: number | null;
  /** Fecha de calendario 'YYYY-MM-DD' (formatear con lib/fechas, nunca con new Date()). */
  proximo_seguimiento_en?: string | null;
  ultimo_seguimiento_en?: string | null;
  /** Presente en detalle, PATCH, panel y "seguimiento realizado". */
  seguimiento?: Seguimiento;
}

export interface SubjectAlias {
  id: number;
  nombre: string;
}

/** Filtros del listado de la lista de vigilancia (GET /api/subjects). */
export interface FiltrosSubjects {
  buscar?: string;
  nivel?: NivelRiesgo | 'sin_nivel';
  estado?: 'activos' | 'inactivos' | 'todos';
  page?: number;
}

/** Fila de GET /api/coincidencias (CoincidenciaController::serializar). */
export interface Coincidencia {
  id: number;
  estado: EstadoMatch;
  score_meilisearch: string | null;
  propuesta_estado: EstadoMatch | null;
  propuesta_en: string | null;
  propuesta_por_usuario: { id: number; name: string } | null;
  created_at: string | null;
  subject: Pick<Subject, 'id' | 'nombre_canonico' | 'nivel_riesgo' | 'activo'> | null;
  mention: {
    id: number;
    nombre_extraido: string;
    rol: RolMencion;
    delitos: string[];
    resumen: string | null;
    fecha_hecho: string | null;
    origen: OrigenMention;
    article: Pick<Article, 'id' | 'url' | 'titulo' | 'medio' | 'fecha_publicacion'> | null;
    search_result: { id: number; url: string; titulo: string | null; medio: string | null; fecha_brave: string | null } | null;
  } | null;
}

export type BandejaCoincidencias = 'sin_propuesta' | 'esperan_resolucion';

/** GET /api/inicio/resumen. */
export interface ResumenInicio {
  coincidencias_sin_propuesta: number;
  coincidencias_esperan_resolucion: number;
  seguimientos_vencidos: number;
  seguimientos_proximos_7_dias: number;
  resultados_gap: number;
}

/** Bloque de agenda de seguimiento (seccion 3.8, CalculadoraSeguimiento::resumen). */
export interface Seguimiento {
  frecuencia_dias: number;
  origen_frecuencia: 'nivel' | 'personalizada';
  proximo_seguimiento_en: string | null;
  vencido: boolean;
  ultimo_seguimiento_en: string | null;
  ultimo_seguimiento_por: { id: number; name: string } | null;
}

export type NivelFrecuencia = NivelRiesgo | 'sin_nivel';

/** Dias de seguimiento por nivel de riesgo del tenant (seccion 3.8). */
export type FrecuenciasSeguimiento = Record<NivelFrecuencia, number>;

export interface Article {
  id: number;
  url: string;
  titulo: string | null;
  medio: string | null;
  fecha_publicacion: string | null;
  hash_contenido: string;
  evidence_path: string | null;
  estado_extraccion: 'pendiente' | 'completado' | 'fallido';
}

export type RolMencion = 'imputado' | 'condenado' | 'victima' | 'testigo' | 'otro';
export type OrigenMention = 'automatico' | 'manual';

export interface Mention {
  id: number;
  article_id: number | null;
  search_result_id: number | null;
  nombre_extraido: string;
  rol: RolMencion;
  delitos: string[];
  fecha_hecho: string | null;
  confianza: string | null;
  resumen: string | null;
  origen: OrigenMention;
  creado_por: number | null;
  article?: Article | null;
  match?: MentionMatch | null;
}

export type EstadoMatch = 'pendiente' | 'confirmado' | 'falso_positivo' | 'homonimo';

export interface MentionMatch {
  id: number;
  mention_id: number;
  subject_id: number;
  score_meilisearch: string | null;
  estado: EstadoMatch;
  propuesta_estado: EstadoMatch | null;
  propuesta_por: number | null;
  propuesta_en: string | null;
  resuelto_por: number | null;
  resuelto_en: string | null;
  mention?: Mention;
}

/** Flujo bajo demanda - seccion 3.7 del CLAUDE.md raiz. */
export type EstadoSearchResult =
  | 'nuevo'
  | 'procesando'
  | 'extraido'
  | 'sin_menciones'
  | 'gap'
  | 'descartado';

export type GapMotivo =
  | 'http_403'
  | 'http_error'
  | 'timeout'
  | 'sin_contenido'
  | 'fuera_de_ventana'
  | 'no_html';

export interface SearchResult {
  id: number;
  search_run_id: number;
  subject_id: number | null;
  url: string;
  url_hash: string;
  titulo: string | null;
  snippet: string | null;
  medio: string | null;
  fecha_brave: string | null;
  estado: EstadoSearchResult;
  http_status: number | null;
  gap_motivo: GapMotivo | null;
  article_id: number | null;
  evidencia_manual_path: string | null;
  descartado_por: number | null;
  descartado_en: string | null;
  created_at: string;
  article?: Article | null;
  mentions?: Mention[];
  /** Seccion 3.8: aparecio despues del ultimo seguimiento del subject
   * (solo en GET /subjects/{id}/resultados, no en busqueda por tags). */
  nuevo_desde_ultimo_seguimiento?: boolean;
}

/** Busqueda por tags (sesion posterior a la 3.7) - catalogo por tenant. */
export interface SearchTag {
  id: number;
  nombre: string;
  activo: boolean;
}

/** Forma exacta del paginate() default de Laravel (sin API Resource). */
export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}
