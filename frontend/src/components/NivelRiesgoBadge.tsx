import { Badge } from '@/components/ui/badge';
import type { NivelRiesgo } from '@/types/api';

const ESTILOS: Record<NivelRiesgo, string> = {
  bajo: 'bg-success text-success-foreground',
  medio: 'bg-warning text-warning-foreground',
  alto: 'bg-destructive text-destructive-foreground',
};

const ETIQUETAS: Record<NivelRiesgo, string> = {
  bajo: 'Riesgo bajo',
  medio: 'Riesgo medio',
  alto: 'Riesgo alto',
};

export function NivelRiesgoBadge({ nivel }: { nivel: NivelRiesgo | null }) {
  if (!nivel) return <Badge variant="outline">Sin evaluar</Badge>;

  return <Badge className={ESTILOS[nivel]}>{ETIQUETAS[nivel]}</Badge>;
}
