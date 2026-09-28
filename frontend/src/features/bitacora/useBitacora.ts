import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Paginated } from '@/types/api';
import type { FiltrosBitacora, RegistroBitacora, RegistroBitacoraGlobal } from '@/types/bitacora';

function query(filtros: FiltrosBitacora): string {
  const params = new URLSearchParams();
  for (const [clave, valor] of Object.entries(filtros)) {
    if (valor !== undefined && valor !== '') params.set(clave, String(valor));
  }
  return params.toString();
}

/** Bitacora del tenant (solo admin; el backend responde 403 al resto). */
export function useBitacoraTenant(filtros: FiltrosBitacora) {
  return useQuery({
    queryKey: ['bitacora', 'tenant', filtros],
    queryFn: () => api.get<Paginated<RegistroBitacora>>(`/api/bitacora?${query(filtros)}`),
    placeholderData: keepPreviousData,
  });
}

/** Bitacora de todos los tenants para el superadmin, sin datos personales. */
export function useBitacoraGlobal(filtros: FiltrosBitacora) {
  return useQuery({
    queryKey: ['bitacora', 'global', filtros],
    queryFn: () => api.get<Paginated<RegistroBitacoraGlobal>>(`/api/superadmin/bitacora?${query(filtros)}`),
    placeholderData: keepPreviousData,
  });
}

export function useEventosBitacora(alcance: 'tenant' | 'global') {
  return useQuery({
    queryKey: ['bitacora', alcance, 'eventos'],
    queryFn: () => api.get<string[]>(alcance === 'tenant' ? '/api/bitacora/eventos' : '/api/superadmin/bitacora/eventos'),
  });
}
