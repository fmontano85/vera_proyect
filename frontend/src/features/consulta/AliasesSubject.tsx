import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useAgregarAlias, useQuitarAlias } from '@/features/consulta/useSubjects';
import { ApiError } from '@/lib/api';
import type { Subject } from '@/types/api';

const MAX_ALIASES = 20;

/**
 * Aliases del subject: se usan en la busqueda de noticias y en el cruce
 * de coincidencias. Cada cambio se guarda al momento (el backend reindexa
 * y audita; si el indice no responde, no se guarda y avisa).
 */
export function AliasesSubject({ subject, editable }: { subject: Subject; editable: boolean }) {
  const agregar = useAgregarAlias(subject.id);
  const quitar = useQuitarAlias(subject.id);
  const [nuevo, setNuevo] = useState('');
  const aliases = subject.aliases ?? [];
  const alMaximo = aliases.length >= MAX_ALIASES;

  function handleAgregar() {
    const nombre = nuevo.trim();
    if (nombre === '') return;

    agregar.mutate(nombre, {
      onSuccess: () => {
        setNuevo('');
        toast.success('Alias agregado.');
      },
      onError: (error) => {
        const detalle = error instanceof ApiError ? (error.errors?.nombre?.[0] ?? error.message) : null;
        toast.error(detalle ?? 'No se pudo agregar el alias.');
      },
    });
  }

  function handleQuitar(aliasId: number, nombre: string) {
    quitar.mutate(aliasId, {
      onSuccess: () => toast.success(`Alias "${nombre}" quitado.`),
      onError: (error) => toast.error(error instanceof ApiError ? error.message : 'No se pudo quitar el alias.'),
    });
  }

  return (
    <div className="flex flex-col gap-2">
      <p className="text-muted-foreground text-sm">Aliases</p>

      {aliases.length === 0 ? (
        <p className="text-muted-foreground text-sm">Sin aliases.</p>
      ) : (
        <div className="flex flex-wrap gap-1.5">
          {aliases.map((alias) => (
            <Badge key={alias.id} variant="outline" className={editable ? 'gap-1 pr-1' : undefined}>
              {alias.nombre}
              {editable && (
                <button
                  type="button"
                  className="hover:bg-muted rounded-sm p-0.5 disabled:opacity-50"
                  aria-label={`Quitar alias ${alias.nombre}`}
                  disabled={quitar.isPending}
                  onClick={() => handleQuitar(alias.id, alias.nombre)}
                >
                  <X className="size-3" />
                </button>
              )}
            </Badge>
          ))}
        </div>
      )}

      {editable && !alMaximo && (
        <form
          className="flex max-w-md gap-2"
          onSubmit={(e) => {
            e.preventDefault();
            handleAgregar();
          }}
        >
          <Input
            aria-label="Nuevo alias"
            placeholder="Agregar alias"
            maxLength={255}
            value={nuevo}
            onChange={(e) => setNuevo(e.target.value)}
          />
          <Button type="submit" variant="outline" disabled={agregar.isPending || nuevo.trim() === ''}>
            <Plus />
            {agregar.isPending ? 'Agregando…' : 'Agregar'}
          </Button>
        </form>
      )}
      {editable && alMaximo && (
        <p className="text-muted-foreground text-xs">Máximo de {MAX_ALIASES} aliases alcanzado.</p>
      )}
    </div>
  );
}
