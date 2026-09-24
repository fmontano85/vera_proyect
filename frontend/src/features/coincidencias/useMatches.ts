import { useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { EstadoMatch, MentionMatch } from '@/types/api';

/** El listado ya no vive aqui - las matches se ven anidadas dentro de
 * cada search_result (seccion 3.7 del CLAUDE.md raiz, ver
 * features/resultados). Proponer/resolver siguen siendo los mismos
 * endpoints de siempre; reciben la queryKey a invalidar en vez de un
 * subjectId fijo porque tambien se usan desde la busqueda por tags
 * (donde no hay un solo subject "actual"). */
type ResolucionFinal = Exclude<EstadoMatch, 'pendiente'>;

export function useProponer(queryKey: QueryKey) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ matchId, estado }: { matchId: number; estado: ResolucionFinal }) =>
      api.post<MentionMatch>(`/api/matches/${matchId}/proponer`, { estado }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey });
    },
  });
}

export function useResolver(queryKey: QueryKey) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ matchId, estado }: { matchId: number; estado: ResolucionFinal }) =>
      api.post<MentionMatch>(`/api/matches/${matchId}/resolver`, { estado }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey });
    },
  });
}
