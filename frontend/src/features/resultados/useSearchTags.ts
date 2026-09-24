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
