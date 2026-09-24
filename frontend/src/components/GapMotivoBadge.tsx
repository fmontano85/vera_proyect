import { Badge } from '@/components/ui/badge';
import type { GapMotivo } from '@/types/api';

const ETIQUETAS: Record<GapMotivo, string> = {
  http_403: 'Bloqueado (403)',
  http_error: 'Error HTTP',
  timeout: 'Tiempo de espera agotado',
  sin_contenido: 'Sin contenido',
  fuera_de_ventana: 'Fuera de la ventana temporal',
  no_html: 'No es una página HTML',
};

export function GapMotivoBadge({ motivo }: { motivo: GapMotivo }) {
  return <Badge variant="outline">{ETIQUETAS[motivo]}</Badge>;
}
