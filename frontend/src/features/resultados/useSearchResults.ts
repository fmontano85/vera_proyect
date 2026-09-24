import { useMutation, useQuery, useQueryClient, type QueryKey } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Paginated, RolMencion, SearchResult } from '@/types/api';

/** Flujo bajo demanda (seccion 3.7 del CLAUDE.md raiz): un search_result
 * por cada URL que devolvio Brave, sin scrape ni IA hasta que el analista
 * pida "Sacar informacion de noticia". */
export function useSearchResults(subjectId: number, activo: boolean) {
  return useQuery({
    queryKey: ['subjects', subjectId, 'resultados'],
    queryFn: () => api.get<Paginated<SearchResult>>(`/api/subjects/${subjectId}/resultados`),
    // Poll corto mientras haya algo en curso (extraccion en progreso, o la
    // busqueda recien se encolo y los search_results todavia no aparecen).
    refetchInterval: (query) => refetchMientrasEnCurso(query, activo),
  });
}

/** Busqueda por tags (sesion posterior a la 3.7): resultados sin subject
 * de todas las busquedas por tags del tenant, mismo shape/poll que
 * useSearchResults. */
export function useTagSearchResults(activo: boolean) {
  return useQuery({
    queryKey: ['busquedas-tags', 'resultados'],
    queryFn: () => api.get<Paginated<SearchResult>>('/api/busquedas-tags/resultados'),
    refetchInterval: (query) => refetchMientrasEnCurso(query, activo),
  });
}

function refetchMientrasEnCurso(
  query: { state: { data?: Paginated<SearchResult> } },
  activo: boolean,
): number | false {
  const enProceso = query.state.data?.data.some((r) => r.estado === 'procesando') ?? false;

  return activo || enProceso ? 3000 : false;
}

/** extraer/descartar/captura-manual actuan sobre un search_result
 * individual sin importar si vino de un subject o de una busqueda por
 * tags - solo cambia que query hay que invalidar despues, por eso reciben
 * la queryKey en vez de un subjectId fijo. */
export function useExtraer(queryKey: QueryKey) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (resultadoId: number) =>
      api.post<SearchResult>(`/api/resultados/${resultadoId}/extraer`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey });
    },
  });
}

export function useDescartar(queryKey: QueryKey) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (resultadoId: number) =>
      api.post<SearchResult>(`/api/resultados/${resultadoId}/descartar`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey });
    },
  });
}

export interface DatosCapturaManual {
  nombre_como_aparece: string;
  rol: RolMencion;
  delitos: string[];
  fecha_hecho?: string;
  resumen?: string;
  estado_resolucion: 'confirmado' | 'falso_positivo' | 'homonimo';
  // Solo obligatorio cuando el search_result no tiene subject propio
  // (vino de una busqueda por tags) - CapturaManualRequest lo exige en
  // ese caso.
  subject_id?: number;
  pdf: File;
}

export function useCapturaManual(queryKey: QueryKey) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ resultadoId, datos }: { resultadoId: number; datos: DatosCapturaManual }) => {
      const form = new FormData();
      form.set('nombre_como_aparece', datos.nombre_como_aparece);
      form.set('rol', datos.rol);
      datos.delitos.forEach((delito) => form.append('delitos[]', delito));
      if (datos.fecha_hecho) form.set('fecha_hecho', datos.fecha_hecho);
      if (datos.resumen) form.set('resumen', datos.resumen);
      form.set('estado_resolucion', datos.estado_resolucion);
      if (datos.subject_id) form.set('subject_id', String(datos.subject_id));
      form.set('pdf', datos.pdf);

      return api.post(`/api/resultados/${resultadoId}/captura-manual`, form);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey });
    },
  });
}
