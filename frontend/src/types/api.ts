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
  aliases?: { id: number; nombre: string }[];
}

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
  subject_id: number;
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
}

/** Forma exacta del paginate() default de Laravel (sin API Resource). */
export interface Paginated<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}
