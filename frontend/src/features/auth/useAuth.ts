import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiError } from '@/lib/api';
import type { User } from '@/types/api';

export const authQueryKey = ['auth', 'user'] as const;

/** null = invitado (401), no un error - se usa tanto en useCurrentUser
 * como en el guard de rutas (_authenticated.tsx) para compartir la misma
 * entrada de cache. */
export async function fetchCurrentUser(): Promise<User | null> {
  try {
    return await api.get<User>('/api/user');
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) return null;
    throw error;
  }
}

export function useCurrentUser() {
  return useQuery({
    queryKey: authQueryKey,
    queryFn: fetchCurrentUser,
    staleTime: 5 * 60 * 1000,
    retry: false,
  });
}

export function useLogin() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (credentials: { email: string; password: string }) =>
      api.post<User>('/api/login', credentials),
    onSuccess: (user) => {
      queryClient.setQueryData(authQueryKey, user);
    },
  });
}

export function useLogout() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () => api.post('/api/logout'),
    onSuccess: () => {
      queryClient.setQueryData(authQueryKey, null);
      queryClient.clear();
    },
  });
}

/** Roles con permiso de resolver en firme una coincidencia (seccion 3.2:
 * "oficial_cumplimiento resuelve" - admin tiene el mismo alcance amplio
 * que ya usan las demas Policies del backend). */
export function puedeResolver(user: User | null | undefined): boolean {
  return !!user?.roles.some((r) => r === 'oficial_cumplimiento' || r === 'admin');
}

/** Roles que pueden proponer una resolucion o ejecutar una consulta
 * puntual (seccion 3.2: analista/oficial_cumplimiento/admin). */
export function puedeProponer(user: User | null | undefined): boolean {
  return !!user?.roles.some((r) => ['analista', 'oficial_cumplimiento', 'admin'].includes(r));
}
