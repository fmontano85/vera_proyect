import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { SearchTag } from '@/types/api';

/** Catalogo de tags de busqueda por tenant (sesion posterior a la 3.7). */
export function useSearchTags() {
  return useQuery({
    queryKey: ['tags-busqueda'],
    queryFn: () => api.get<SearchTag[]>('/api/tags-busqueda'),
  });
}

export function useCrearSearchTag() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (nombre: string) => api.post<SearchTag>('/api/tags-busqueda', { nombre }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['tags-busqueda'] });
    },
  });
}

export function useBuscarPorTags() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ tagIds, diasAtras }: { tagIds: number[]; diasAtras?: number }) =>
      api.post<{ mensaje: string; fuentes: number[] }>('/api/busquedas-tags', {
        tag_ids: tagIds,
        dias_atras: diasAtras,
      }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['busquedas-tags', 'resultados'] });
    },
  });
}

/** Pantalla de gestion: incluye los tags inactivos (solo admin y
 * oficial_cumplimiento; el backend responde 403 al resto). */
export function useTagsGestion() {
  return useQuery({
    queryKey: ['tags-busqueda', 'gestion'],
    queryFn: () => api.get<SearchTag[]>('/api/tags-busqueda?todos=1'),
  });
}

export function useActualizarTag() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, ...cambios }: { id: number; nombre?: string; activo?: boolean }) =>
      api.patch<SearchTag>(`/api/tags-busqueda/${id}`, cambios),
    onSuccess: () => {
      // Prefijo: refresca tambien el catalogo que usa la busqueda por tags.
      queryClient.invalidateQueries({ queryKey: ['tags-busqueda'] });
    },
  });
}
