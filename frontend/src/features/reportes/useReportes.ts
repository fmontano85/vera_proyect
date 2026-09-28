import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from '@/lib/api';
import type { Paginated } from '@/types/api';

/** Reportes de auditoria (seccion 1, punto 5 del CLAUDE.md raiz). */
export type TipoReporte = 'ficha_persona' | 'actividad_periodo' | 'lista_por_riesgo';
export type FormatoReporte = 'pdf' | 'csv';
export type EstadoReporte = 'pendiente' | 'generando' | 'listo' | 'fallido';

export interface Reporte {
  id: number;
  tipo: TipoReporte;
  formato: FormatoReporte;
  parametros: Record<string, unknown>;
  estado: EstadoReporte;
  error: string | null;
  generado_por: string | null;
  solicitado_en: string | null;
  generado_en: string | null;
}

export interface SolicitudReporte {
  tipo: TipoReporte;
  formato: FormatoReporte;
  subject_id?: number;
  desde?: string;
  hasta?: string;
  nivel_riesgo?: string;
  incluir_inactivos?: boolean;
}

export const NOMBRE_TIPO: Record<TipoReporte, string> = {
  ficha_persona: 'Ficha de persona',
  actividad_periodo: 'Actividad de un periodo',
  lista_por_riesgo: 'Lista de vigilancia por nivel de riesgo',
};

export function useReportes(page: number) {
  return useQuery({
    queryKey: ['reportes', page],
    queryFn: () => api.get<Paginated<Reporte>>(`/api/reportes?page=${page}`),
    placeholderData: keepPreviousData,
    // Se generan en segundo plano: consultar cada 3 s mientras alguno no termine.
    refetchInterval: (query) =>
      query.state.data?.data.some((r) => r.estado === 'pendiente' || r.estado === 'generando') ? 3000 : false,
  });
}

export function useSolicitarReporte() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (solicitud: SolicitudReporte) => api.post<Reporte>('/api/reportes', solicitud),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['reportes'] }),
  });
}
