import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Paginated, Subject } from '@/types/api';

export function useSubjects() {
  return useQuery({
    queryKey: ['subjects'],
    queryFn: () => api.get<Paginated<Subject>>('/api/subjects'),
  });
}

export function useSubject(id: number) {
  return useQuery({
    queryKey: ['subjects', id],
    queryFn: () => api.get<Subject>(`/api/subjects/${id}`),
  });
}

export interface NuevoSubject {
  tipo: 'natural' | 'juridica';
  nombre_canonico: string;
  documento?: string;
  nivel_riesgo?: 'bajo' | 'medio' | 'alto';
}

export function useCreateSubject() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data: NuevoSubject) => api.post<Subject>('/api/subjects', data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['subjects'] });
    },
  });
}

/** Consulta puntual (seccion 1/5 del CLAUDE.md raiz): dispara el pipeline
 * completo para este subject contra las fuentes activas. */
export function useBuscarSubject(subjectId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => api.post<{ mensaje: string; fuentes: number[] }>(`/api/subjects/${subjectId}/buscar`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['subjects', subjectId, 'resultados'] });
    },
  });
}
