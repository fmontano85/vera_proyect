import { useMutation, useQuery, useQueryClient, type QueryKey } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { BandejaCoincidencias, Coincidencia, EstadoMatch, MentionMatch, Paginated, ResumenInicio } from '@/types/api';

/** Proponer/resolver se usan desde la ficha del subject, la busqueda por
 * tags y el dashboard de coincidencias: reciben la queryKey de la
 * pantalla actual, y ademas siempre refrescan las bandejas y el inicio. */
type ResolucionFinal = Exclude<EstadoMatch, 'pendiente'>;

function useAccionMatch(accion: 'proponer' | 'resolver', queryKey: QueryKey) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ matchId, estado }: { matchId: number; estado: ResolucionFinal }) =>
      api.post<MentionMatch>(`/api/matches/${matchId}/${accion}`, { estado }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey });
      queryClient.invalidateQueries({ queryKey: ['coincidencias'] });
      queryClient.invalidateQueries({ queryKey: ['inicio'] });
    },
  });
}

export function useProponer(queryKey: QueryKey) {
  return useAccionMatch('proponer', queryKey);
}

export function useResolver(queryKey: QueryKey) {
  return useAccionMatch('resolver', queryKey);
}

export function useCoincidencias(bandeja: BandejaCoincidencias, pagina = 1) {
  return useQuery({
    queryKey: ['coincidencias', bandeja, pagina],
    queryFn: () => api.get<Paginated<Coincidencia>>(`/api/coincidencias?bandeja=${bandeja}&page=${pagina}`),
    placeholderData: (previo) => previo,
  });
}

export function useResumenInicio() {
  return useQuery({
    queryKey: ['inicio', 'resumen'],
    queryFn: () => api.get<ResumenInicio>('/api/inicio/resumen'),
  });
}
