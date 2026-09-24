import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
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
    refetchInterval: (query) => {
      const enProceso = query.state.data?.data.some((r) => r.estado === 'procesando') ?? false;
      return activo || enProceso ? 3000 : false;
    },
  });
}

export function useExtraer(subjectId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (resultadoId: number) =>
      api.post<SearchResult>(`/api/resultados/${resultadoId}/extraer`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['subjects', subjectId, 'resultados'] });
    },
  });
}

export function useDescartar(subjectId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (resultadoId: number) =>
      api.post<SearchResult>(`/api/resultados/${resultadoId}/descartar`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['subjects', subjectId, 'resultados'] });
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
  pdf: File;
}

export function useCapturaManual(subjectId: number) {
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
      form.set('pdf', datos.pdf);

      return api.post(`/api/resultados/${resultadoId}/captura-manual`, form);
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['subjects', subjectId, 'resultados'] });
    },
  });
}
