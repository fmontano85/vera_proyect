import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { useSubjects } from '@/features/consulta/useSubjects';
import { useDebouncedValue } from '@/lib/useDebouncedValue';
import type { Subject } from '@/types/api';

/**
 * Buscador de personas de la lista de vigilancia contra el servidor
 * (?buscar=), con debounce - antes vivia como una implementacion unica
 * dentro de CapturaManualDialog; cualquier futuro picker (atribuir un
 * hallazgo de sanciones, reasignar un match) puede reusar este mismo
 * componente en vez de re-escribirlo.
 */
export function BuscadorSubjectAsync({
  seleccionado,
  onSeleccionar,
  placeholder = 'Buscar por nombre o alias…',
}: {
  seleccionado: Subject | null;
  onSeleccionar: (subject: Subject) => void;
  placeholder?: string;
}) {
  const [busqueda, setBusqueda] = useState('');
  const busquedaDebounced = useDebouncedValue(busqueda);
  const { data: subjects } = useSubjects({ buscar: busquedaDebounced });

  return (
    <div className="flex flex-col gap-1.5">
      {seleccionado && (
        <p className="text-sm">
          Seleccionada: <span className="font-medium">{seleccionado.nombre_canonico}</span>
        </p>
      )}
      <Input value={busqueda} onChange={(e) => setBusqueda(e.target.value)} placeholder={placeholder} />
      <ul className="max-h-40 divide-y overflow-y-auto rounded-md border">
        {subjects?.data.length === 0 && (
          <li className="text-muted-foreground p-2 text-sm">Sin coincidencias.</li>
        )}
        {subjects?.data.map((subject) => (
          <li key={subject.id}>
            <button
              type="button"
              className="hover:bg-accent w-full px-2 py-1.5 text-left text-sm"
              onClick={() => onSeleccionar(subject)}
            >
              {subject.nombre_canonico}
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}
