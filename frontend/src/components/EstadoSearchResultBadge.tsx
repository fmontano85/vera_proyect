import { Badge } from '@/components/ui/badge';
import type { EstadoSearchResult } from '@/types/api';

/** Colores semanticos de dominio - frontend/design-system/MASTER.md. */
const ESTILOS: Record<EstadoSearchResult, string> = {
  nuevo: 'bg-muted text-muted-foreground',
  procesando: 'bg-secondary text-secondary-foreground',
  extraido: 'bg-success text-success-foreground',
  sin_menciones: 'bg-muted text-muted-foreground',
  gap: 'bg-gap text-gap-foreground',
  descartado: 'bg-muted text-muted-foreground opacity-70',
};

const ETIQUETAS: Record<EstadoSearchResult, string> = {
  nuevo: 'Nuevo',
  procesando: 'Procesando…',
  extraido: 'Extraído',
  sin_menciones: 'Sin menciones',
  gap: 'GAP',
  descartado: 'Descartado',
};

export function EstadoSearchResultBadge({ estado }: { estado: EstadoSearchResult }) {
  return <Badge className={ESTILOS[estado]}>{ETIQUETAS[estado]}</Badge>;
}
