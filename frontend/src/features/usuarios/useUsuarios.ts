import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Rol, UsuarioTenant } from '@/types/api';

/** Roles que un admin puede asignar (superadmin nunca: el backend tambien lo rechaza). */
export const ROLES_ASIGNABLES: { value: Rol; label: string }[] = [
  { value: 'admin', label: 'Administrador' },
  { value: 'oficial_cumplimiento', label: 'Oficial de cumplimiento' },
  { value: 'analista', label: 'Analista' },
  { value: 'lectura', label: 'Solo lectura' },
];

export function etiquetaRol(rol: Rol | null): string {
  return ROLES_ASIGNABLES.find((r) => r.value === rol)?.label ?? rol ?? '—';
}

export function useUsuarios() {
  return useQuery({
    queryKey: ['usuarios'],
    queryFn: () => api.get<UsuarioTenant[]>('/api/usuarios'),
  });
}

export function useCrearUsuario() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (data: { name: string; email: string; password: string; rol: Rol }) =>
      api.post<UsuarioTenant>('/api/usuarios', data),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['usuarios'] }),
  });
}

export function useActualizarUsuario() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, ...cambios }: { id: number; name?: string; rol?: Rol; activo?: boolean; password?: string }) =>
      api.patch<UsuarioTenant>(`/api/usuarios/${id}`, cambios),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['usuarios'] }),
  });
}

export function useCambiarContrasena() {
  return useMutation({
    mutationFn: (data: { contrasena_actual: string; contrasena: string; contrasena_confirmation: string }) =>
      api.post<void>('/api/cuenta/contrasena', data),
  });
}

export function useActualizarCuenta() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (name: string) => api.patch<{ name: string }>('/api/cuenta', { name }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['auth', 'user'] }),
  });
}
