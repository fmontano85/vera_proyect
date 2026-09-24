import { Badge } from '@/components/ui/badge';
import type { EstadoMatch } from '@/types/api';

/**
 * Colores semanticos de dominio - frontend/design-system/MASTER.md.
 * OJO: 'confirmado' es la MALA noticia (se confirmo el hallazgo de riesgo)
 * y va en rojo, no en verde - es el anti-patron mas facil de repetir por
 * accidente en este proyecto.
 */
const ESTILOS: Record<EstadoMatch, string> = {
  pendiente: 'bg-warning text-warning-foreground',
  confirmado: 'bg-destructive text-destructive-foreground',
  falso_positivo: 'bg-success text-success-foreground',
  homonimo: 'bg-muted text-muted-foreground',
};

const ETIQUETAS: Record<EstadoMatch, string> = {
  pendiente: 'Pendiente',
  confirmado: 'Confirmado',
  falso_positivo: 'Falso positivo',
  homonimo: 'Homónimo',
};

export function EstadoMatchBadge({ estado }: { estado: EstadoMatch }) {
  return <Badge className={ESTILOS[estado]}>{ETIQUETAS[estado]}</Badge>;
}
