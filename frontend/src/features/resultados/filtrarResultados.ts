import type { FiltroResultados } from '@/features/resultados/FiltroEstado';
import type { SearchResult } from '@/types/api';

/** Archivo aparte de FiltroEstado.tsx: un modulo de componentes que
 * tambien exporta funciones rompe el fast refresh (oxlint
 * only-export-components). */
export function filtrarResultados(resultados: SearchResult[], filtro: FiltroResultados): SearchResult[] {
  if (filtro === 'todos') return resultados;
  if (filtro === 'desde_seguimiento') return resultados.filter((r) => r.nuevo_desde_ultimo_seguimiento);

  return resultados.filter((r) => r.estado === filtro);
}
