import { ShieldAlert } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { puedeProponer, useCurrentUser } from '@/features/auth/useAuth';
import { SancionCard } from '@/features/sanciones/SancionCard';
import { useCruzarSanciones, useSanciones } from '@/features/sanciones/useSanciones';
import { mensajeApi } from '@/lib/api';

/** Hallazgos de sanciones de una persona + cruce bajo demanda (busqueda local, sin costo). */
export function SancionesSubject({ subjectId }: { subjectId: number }) {
  const { data: user } = useCurrentUser();
  const { data, isLoading } = useSanciones({ estado: 'todos', subjectId });
  const cruzar = useCruzarSanciones(subjectId);
  const hallazgos = data?.data ?? [];

  function handleCruzar() {
    cruzar.mutate(undefined, {
      onSuccess: ({ hallazgos_nuevos }) =>
        toast.success(
          hallazgos_nuevos > 0
            ? `Se encontraron ${hallazgos_nuevos} posibles coincidencias con listas de sanciones.`
            : 'Sin coincidencias nuevas con las listas de sanciones importadas.',
        ),
      onError: (e) => toast.error(mensajeApi(e, 'No se pudo cruzar con las listas de sanciones.')),
    });
  }

  return (
    <section className="mb-6">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold">Listas de sanciones</h2>
        {puedeProponer(user) && (
          <Button variant="outline" size="sm" disabled={cruzar.isPending} onClick={handleCruzar}>
            <ShieldAlert />
            {cruzar.isPending ? 'Cruzando…' : 'Cruzar con listas de sanciones'}
          </Button>
        )}
      </div>
      {isLoading && <Skeleton className="h-20 w-full" />}
      {!isLoading && hallazgos.length === 0 && (
        <p className="text-muted-foreground text-sm">
          Sin coincidencias con las listas de sanciones importadas (hoy OFAC SDN). El cruce completo corre cada semana.
        </p>
      )}
      <div className="flex flex-col gap-3">
        {hallazgos.map((h) => (
          <SancionCard key={h.id} hallazgo={h} mostrarSubject={false} />
        ))}
      </div>
    </section>
  );
}
