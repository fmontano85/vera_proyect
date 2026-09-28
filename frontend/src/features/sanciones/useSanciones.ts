import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { EstadoMatch, HallazgoSancion, Paginated } from '@/types/api';

export const sancionesKey = ['sanciones'] as const;

export function useSanciones(filtros: {
  estado: 'pendiente' | 'todos';
  subjectId?: number;
  page?: number;
  /** false evita el request si el tenant no tiene Sanciones habilitada
   * (default true - sin esto se dispara igual y solo recibe un 404). */
  enabled?: boolean;
}) {
  const params = new URLSearchParams({ estado: filtros.estado });
  if (filtros.subjectId) params.set('subject_id', String(filtros.subjectId));
  if (filtros.page && filtros.page > 1) params.set('page', String(filtros.page));

  return useQuery({
    queryKey: [...sancionesKey, filtros],
    queryFn: () => api.get<Paginated<HallazgoSancion>>(`/api/sanciones?${params.toString()}`),
    placeholderData: (previo) => previo,
    enabled: filtros.enabled ?? true,
  });
}

export function useResolverSancion() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, estado }: { id: number; estado: Exclude<EstadoMatch, 'pendiente'> }) =>
      api.post<HallazgoSancion>(`/api/sanciones/${id}/resolver`, { estado }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: sancionesKey });
      queryClient.invalidateQueries({ queryKey: ['inicio'] });
    },
  });
}

/** Cruza un subject contra las listas de sanciones ya importadas (busqueda local en Meilisearch, sin costo). */
export function useCruzarSanciones(subjectId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => api.post<{ hallazgos_nuevos: number }>(`/api/subjects/${subjectId}/sanciones/cruzar`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: sancionesKey });
      queryClient.invalidateQueries({ queryKey: ['inicio'] });
    },
  });
}
