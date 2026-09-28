import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type {
  ConfiguracionSanciones,
  ModoDescargaOfac,
  NuevoTenant,
  TenantCreado,
  TenantSuperadmin,
} from '@/types/superadmin';

export function useTenantsSuperadmin() {
  return useQuery({
    queryKey: ['superadmin', 'tenants'],
    queryFn: () => api.get<TenantSuperadmin[]>('/api/superadmin/tenants'),
  });
}

export function useCrearTenantSuperadmin() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (datos: NuevoTenant) => api.post<TenantCreado>('/api/superadmin/tenants', datos),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['superadmin', 'tenants'] }),
  });
}

export function useActualizarTenantSuperadmin() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, ...cambios }: { id: string; name?: string; sanciones_habilitado?: boolean }) =>
      api.patch<TenantSuperadmin>(`/api/superadmin/tenants/${id}`, cambios),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['superadmin', 'tenants'] }),
  });
}

export function useConfiguracionSancionesSuperadmin() {
  return useQuery({
    queryKey: ['superadmin', 'configuracion-sanciones'],
    queryFn: () => api.get<ConfiguracionSanciones>('/api/superadmin/configuracion-sanciones'),
  });
}

export function useActualizarConfiguracionSancionesSuperadmin() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (modo_descarga_ofac: ModoDescargaOfac) =>
      api.put<ConfiguracionSanciones>('/api/superadmin/configuracion-sanciones', { modo_descarga_ofac }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['superadmin', 'configuracion-sanciones'] }),
  });
}

export function useActualizarListaOfacSuperadmin() {
  return useMutation({
    mutationFn: () => api.post<{ mensaje: string }>('/api/superadmin/sanciones/actualizar-lista'),
  });
}
