import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { FrecuenciasSeguimiento, NivelRiesgo, Paginated, Subject } from '@/types/api';

/**
 * Agenda de seguimiento de la lista de vigilancia (seccion 3.8 del
 * CLAUDE.md raiz). Ninguna de estas llamadas consulta Brave ni Anthropic:
 * solo la BD propia. La consulta puntual sigue siendo el boton aparte.
 */

export type FiltroSeguimientos = 'vencidos' | 'proximos';

export function useSeguimientos(filtro: FiltroSeguimientos, pagina = 1) {
  return useQuery({
    queryKey: ['seguimientos', filtro, pagina],
    queryFn: () => api.get<Paginated<Subject>>(`/api/seguimientos?filtro=${filtro}&page=${pagina}`),
    placeholderData: (previo) => previo,
  });
}

/** Tras cualquier cambio de agenda: el panel, la lista y (por el
 * indicador nuevo_desde_ultimo_seguimiento) los resultados del subject. */
function invalidarAgenda(queryClient: ReturnType<typeof useQueryClient>, subjectId?: number) {
  queryClient.invalidateQueries({ queryKey: ['seguimientos'] });
  if (subjectId !== undefined) {
    queryClient.invalidateQueries({ queryKey: ['subjects', subjectId, 'resultados'] });
  }
}

export function useMarcarSeguimiento(subjectId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (observacion: string | null) =>
      api.post<Subject>(`/api/subjects/${subjectId}/seguimiento-realizado`, { observacion }),
    onSuccess: (subject) => {
      queryClient.setQueryData(['subjects', subjectId], (previo: Subject | undefined) => ({ ...previo, ...subject }));
      invalidarAgenda(queryClient, subjectId);
    },
  });
}

export interface CambiosSubject {
  nivel_riesgo?: NivelRiesgo | null;
  frecuencia_seguimiento_dias?: number | null;
}

export function useActualizarSubject(subjectId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (cambios: CambiosSubject) => api.patch<Subject>(`/api/subjects/${subjectId}`, cambios),
    onSuccess: (subject) => {
      queryClient.setQueryData(['subjects', subjectId], (previo: Subject | undefined) => ({ ...previo, ...subject }));
      queryClient.invalidateQueries({ queryKey: ['subjects'], exact: true });
      invalidarAgenda(queryClient);
    },
  });
}

export function useFrecuencias() {
  return useQuery({
    queryKey: ['configuracion', 'frecuencias-seguimiento'],
    queryFn: () => api.get<FrecuenciasSeguimiento>('/api/configuracion/frecuencias-seguimiento'),
  });
}

/** Solo admin (el backend responde 403 a los demas roles). Recalcula la
 * proxima fecha de todos los subjects que usan el default de su nivel. */
export function useActualizarFrecuencias() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (frecuencias: FrecuenciasSeguimiento) =>
      api.put<FrecuenciasSeguimiento>('/api/configuracion/frecuencias-seguimiento', frecuencias),
    onSuccess: (frecuencias) => {
      queryClient.setQueryData(['configuracion', 'frecuencias-seguimiento'], frecuencias);
      queryClient.invalidateQueries({ queryKey: ['subjects'] });
      invalidarAgenda(queryClient);
    },
  });
}
