import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { EstadoSearchResult } from '@/types/api';

export type FiltroResultados = 'todos' | EstadoSearchResult;

const OPCIONES: { value: FiltroResultados; label: string }[] = [
  { value: 'todos', label: 'Todos' },
  { value: 'nuevo', label: 'Nuevos' },
  { value: 'gap', label: 'GAP' },
  { value: 'extraido', label: 'Extraídos' },
  { value: 'descartado', label: 'Descartados' },
];

export function FiltroEstado({
  value,
  onChange,
}: {
  value: FiltroResultados;
  onChange: (value: FiltroResultados) => void;
}) {
  return (
    <Tabs value={value} onValueChange={(v) => onChange(v as FiltroResultados)}>
      <TabsList>
        {OPCIONES.map((opcion) => (
          <TabsTrigger key={opcion.value} value={opcion.value}>
            {opcion.label}
          </TabsTrigger>
        ))}
      </TabsList>
    </Tabs>
  );
}
