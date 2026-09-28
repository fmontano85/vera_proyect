import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { authQueryKey } from '@/features/auth/useAuth';
import { api } from '@/lib/api';
import type { DocumentoLegalSuperadmin, DocumentoLegalTenant, TipoDocumentoLegal } from '@/types/documentosLegales';

const CLAVE_SUPERADMIN = ['superadmin', 'documentos-legales'] as const;

export function useDocumentosSuperadmin() {
  return useQuery({
    queryKey: CLAVE_SUPERADMIN,
    queryFn: () => api.get<DocumentoLegalSuperadmin[]>('/api/superadmin/documentos-legales'),
  });
}

function useMutacionSuperadmin<T, R>(fn: (datos: T) => Promise<R>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: fn,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: CLAVE_SUPERADMIN });
      // Publicar cambia documentos_al_dia de todos los tenants.
      queryClient.invalidateQueries({ queryKey: ['superadmin', 'tenants'] });
    },
  });
}

export function useCrearBorrador() {
  return useMutacionSuperadmin((d: { tipo: TipoDocumentoLegal; titulo: string; contenido: string }) =>
    api.post<DocumentoLegalSuperadmin>('/api/superadmin/documentos-legales', d),
  );
}

export function useGuardarBorrador() {
  return useMutacionSuperadmin(({ id, ...d }: { id: number; titulo: string; contenido: string }) =>
    api.put<DocumentoLegalSuperadmin>(`/api/superadmin/documentos-legales/${id}`, d),
  );
}

export function useDescartarBorrador() {
  return useMutacionSuperadmin((id: number) => api.delete<void>(`/api/superadmin/documentos-legales/${id}`));
}

export function usePublicarDocumento() {
  return useMutacionSuperadmin((id: number) =>
    api.post<DocumentoLegalSuperadmin>(`/api/superadmin/documentos-legales/${id}/publicar`),
  );
}

/** Documentos vigentes vistos desde el tenant. */
export function useDocumentosVigentes() {
  return useQuery({
    queryKey: ['documentos-legales'],
    queryFn: () => api.get<DocumentoLegalTenant[]>('/api/documentos-legales'),
  });
}

export function useAceptarDocumento() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => api.post<DocumentoLegalTenant>(`/api/documentos-legales/${id}/aceptar`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['documentos-legales'] });
      // terminos_pendientes vive en /api/user: el aviso desaparece al aceptar todo.
      queryClient.invalidateQueries({ queryKey: authQueryKey });
    },
  });
}
