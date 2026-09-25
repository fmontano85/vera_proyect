import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { EstadoSearchResult } from '@/types/api';

/** 'desde_seguimiento' no es un estado: filtra por el indicador
 * nuevo_desde_ultimo_seguimiento (seccion 3.8). */
export type FiltroResultados = 'todos' | EstadoSearchResult | 'desde_seguimiento';

const OPCIONES: { value: FiltroResultados; label: string }[] = [
  { value: 'todos', label: 'Todos' },
  { value: 'nuevo', label: 'Nuevos' },
  { value: 'gap', label: 'GAP' },
  { value: 'extraido', label: 'Extraídos' },
  { value: 'descartado', label: 'Descartados' },
];

const OPCION_DESDE_SEGUIMIENTO = { value: 'desde_seguimiento', label: 'Desde el último seguimiento' } as const;

/** mostrarDesdeSeguimiento: solo en la ficha de un subject (la busqueda
 * por tags no tiene agenda de seguimiento). */
export function FiltroEstado({
  value,
  onChange,
  mostrarDesdeSeguimiento = false,
}: {
  value: FiltroResultados;
  onChange: (value: FiltroResultados) => void;
  mostrarDesdeSeguimiento?: boolean;
}) {
  const opciones = mostrarDesdeSeguimiento ? [...OPCIONES, OPCION_DESDE_SEGUIMIENTO] : OPCIONES;

  return (
    <Tabs value={value} onValueChange={(v) => onChange(v as FiltroResultados)}>
      <TabsList className="h-auto flex-wrap">
        {opciones.map((opcion) => (
          <TabsTrigger key={opcion.value} value={opcion.value}>
            {opcion.label}
          </TabsTrigger>
        ))}
      </TabsList>
    </Tabs>
  );
}
