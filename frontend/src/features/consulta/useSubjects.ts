import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { FiltrosSubjects, Paginated, Subject } from '@/types/api';

/** Prefijo de todas las paginas/filtros del listado: invalidarlo refresca
 * cualquier combinacion de filtros abierta. */
export const listaSubjectsKey = ['subjects', 'lista'] as const;

function querySubjects(filtros: FiltrosSubjects): string {
  const params = new URLSearchParams();
  if (filtros.buscar?.trim()) params.set('buscar', filtros.buscar.trim());
  if (filtros.nivel) params.set('nivel', filtros.nivel);
  if (filtros.estado) params.set('estado', filtros.estado);
  if (filtros.page && filtros.page > 1) params.set('page', String(filtros.page));
  const qs = params.toString();

  return qs ? `?${qs}` : '';
}

export function useSubjects(filtros: FiltrosSubjects = {}) {
  return useQuery({
    queryKey: [...listaSubjectsKey, filtros],
    queryFn: () => api.get<Paginated<Subject>>(`/api/subjects${querySubjects(filtros)}`),
    placeholderData: (previo) => previo,
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
  aliases?: string[];
}

export function useCreateSubject() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data: NuevoSubject) => api.post<Subject>('/api/subjects', data),
    onSuccess: (subject) => {
      queryClient.setQueryData(['subjects', subject.id], subject);
      queryClient.invalidateQueries({ queryKey: listaSubjectsKey });
      queryClient.invalidateQueries({ queryKey: ['inicio'] });
    },
  });
}

/** Aliases (lista de vigilancia): el backend reindexa en Meilisearch y
 * devuelve el subject completo; si el indice no responde, 503 y no se
 * guarda nada. */
function useMutacionAlias<TVars>(subjectId: number, mutationFn: (vars: TVars) => Promise<Subject>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: (subject) => {
      queryClient.setQueryData(['subjects', subjectId], (previo: Subject | undefined) => ({ ...previo, ...subject }));
      queryClient.invalidateQueries({ queryKey: listaSubjectsKey });
    },
  });
}

export function useAgregarAlias(subjectId: number) {
  return useMutacionAlias(subjectId, (nombre: string) =>
    api.post<Subject>(`/api/subjects/${subjectId}/aliases`, { nombre }),
  );
}

export function useQuitarAlias(subjectId: number) {
  return useMutacionAlias(subjectId, (aliasId: number) =>
    api.delete<Subject>(`/api/subjects/${subjectId}/aliases/${aliasId}`),
  );
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
