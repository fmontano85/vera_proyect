import type { QueryKey } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { puedeProponer, puedeResolver, useCurrentUser } from '@/features/auth/useAuth';
import { useProponer, useResolver } from '@/features/coincidencias/useMatches';
import { ApiError } from '@/lib/api';
import type { EstadoMatch } from '@/types/api';

type ResolucionFinal = Exclude<EstadoMatch, 'pendiente'>;

/**
 * Control de dos pasos (seccion 1/3.2): el analista propone, el oficial
 * resuelve (puede coincidir con la propuesta o no). Compartido por la
 * ficha del subject, la busqueda por tags y el dashboard de
 * coincidencias. Solo se muestra con el match en 'pendiente' y a quien
 * tiene al menos uno de los dos permisos (el backend igual los exige).
 */
export function MatchAcciones({
  matchId,
  estado,
  queryKey,
}: {
  matchId: number;
  estado: EstadoMatch;
  queryKey: QueryKey;
}) {
  const { data: user } = useCurrentUser();
  const proponer = useProponer(queryKey);
  const resolver = useResolver(queryKey);
  const [seleccion, setSeleccion] = useState<ResolucionFinal | ''>('');

  if (estado !== 'pendiente' || !(puedeProponer(user) || puedeResolver(user))) return null;

  function accion(tipo: 'proponer' | 'resolver') {
    if (!seleccion) return;

    const mutation = tipo === 'proponer' ? proponer : resolver;
    mutation.mutate(
      { matchId, estado: seleccion },
      {
        onSuccess: () => toast.success(tipo === 'proponer' ? 'Resolución propuesta.' : 'Coincidencia resuelta.'),
        onError: (error) =>
          toast.error(error instanceof ApiError ? error.message : 'No se pudo completar la acción.'),
      },
    );
  }

  return (
    <div className="flex flex-wrap items-center gap-2">
      <Select value={seleccion} onValueChange={(v: ResolucionFinal) => setSeleccion(v)}>
        <SelectTrigger className="w-48" aria-label="Resolución">
          <SelectValue placeholder="Elegir resolución" />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="confirmado">Confirmado</SelectItem>
          <SelectItem value="falso_positivo">Falso positivo</SelectItem>
          <SelectItem value="homonimo">Homónimo</SelectItem>
        </SelectContent>
      </Select>

      {puedeProponer(user) && (
        <Button size="sm" variant="outline" disabled={!seleccion || proponer.isPending} onClick={() => accion('proponer')}>
          Proponer
        </Button>
      )}

      {puedeResolver(user) && (
        <Button size="sm" disabled={!seleccion || resolver.isPending} onClick={() => accion('resolver')}>
          Resolver
        </Button>
      )}
    </div>
  );
}
