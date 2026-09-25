import { createFileRoute } from '@tanstack/react-router';
import { Pencil, Power, Search } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { NivelRiesgoBadge } from '@/components/NivelRiesgoBadge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { puedeProponer, puedeResolver, useCurrentUser } from '@/features/auth/useAuth';
import { AliasesSubject } from '@/features/consulta/AliasesSubject';
import { DatosSubjectDialog } from '@/features/consulta/DatosSubjectDialog';
import { EstadoSubjectDialog } from '@/features/consulta/EstadoSubjectDialog';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { FiltroEstado, type FiltroResultados } from '@/features/resultados/FiltroEstado';
import { filtrarResultados } from '@/features/resultados/filtrarResultados';
import { ResultadoCard } from '@/features/resultados/ResultadoCard';
import { useSearchResults } from '@/features/resultados/useSearchResults';
import { useBuscarSubject, useSubject } from '@/features/consulta/useSubjects';
import { SeguimientoCard } from '@/features/seguimiento/SeguimientoCard';
import { ApiError } from '@/lib/api';

export const Route = createFileRoute('/_authenticated/subjects/$subjectId')({
  component: SubjectDetailPage,
});

/** Cuanto durar la marca visual de "buscando" tras encolar una consulta
 * puntual. RunSubjectSearchJob sigue siendo async (cola 'search') - los
 * primeros search_results tardan unos segundos en aparecer aunque ya no
 * haya scrape/IA automatico detras (seccion 3.7 del CLAUDE.md raiz). */
const VENTANA_BUSCANDO_MS = 15_000;

function SubjectDetailPage() {
  const { subjectId } = Route.useParams();
  const id = Number(subjectId);
  const { data: subject, isLoading } = useSubject(id);
  const [buscando, setBuscando] = useState(false);
  const [yaBuscoAlgunaVez, setYaBuscoAlgunaVez] = useState(false);
  const { data: resultados, isLoading: resultadosLoading } = useSearchResults(id, buscando);
  const buscar = useBuscarSubject(id);
  const [filtro, setFiltro] = useState<FiltroResultados>('todos');
  const { data: user } = useCurrentUser();
  const [datosAbierto, setDatosAbierto] = useState(false);
  const [estadoAbierto, setEstadoAbierto] = useState(false);

  function handleBuscar() {
    buscar.mutate(undefined, {
      onSuccess: () => {
        toast.success('Consulta puntual encolada. Los resultados aparecen abajo en unos segundos.');
        setBuscando(true);
        setYaBuscoAlgunaVez(true);
        setTimeout(() => setBuscando(false), VENTANA_BUSCANDO_MS);
      },
      onError: (error) => {
        toast.error(error instanceof ApiError ? error.message : 'No se pudo iniciar la búsqueda.');
      },
    });
  }

  // Si ya aparecio algo, no tiene sentido seguir mostrando "buscando".
  if (buscando && (resultados?.data.length ?? 0) > 0) setBuscando(false);

  const listaCompleta = resultados?.data ?? [];
  const listaFiltrada = filtrarResultados(listaCompleta, filtro);

  return (
    <AppShell title={subject?.nombre_canonico ?? 'Sujeto'}>
      {isLoading && <Skeleton className="h-24 w-full" />}

      {subject && (
        <Card className="mb-6">
          <CardHeader className="flex flex-row items-start justify-between gap-4">
            <div>
              <CardTitle className="text-xl">{subject.nombre_canonico}</CardTitle>
              <p className="text-muted-foreground mt-1 text-sm">
                {subject.tipo === 'juridica' ? 'Persona jurídica' : 'Persona natural'}
                {subject.documento && ` · ${subject.documento}`}
              </p>
            </div>
            <div className="flex shrink-0 flex-col items-end gap-1.5">
              <NivelRiesgoBadge nivel={subject.nivel_riesgo} />
              {!subject.activo && <Badge variant="outline">Inactivo</Badge>}
            </div>
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            {!subject.activo && (
              <p className="text-muted-foreground text-sm">
                Sujeto inactivo: no participa en el cruce de coincidencias ni en la agenda de seguimiento.
              </p>
            )}

            <AliasesSubject subject={subject} editable={puedeProponer(user)} />

            <div className="flex flex-wrap gap-2">
              <Button onClick={handleBuscar} disabled={buscar.isPending}>
                <Search />
                {buscar.isPending ? 'Encolando…' : 'Consulta puntual'}
              </Button>
              {puedeProponer(user) && (
                <Button variant="outline" onClick={() => setDatosAbierto(true)}>
                  <Pencil />
                  Editar datos
                </Button>
              )}
              {puedeResolver(user) && (
                <Button variant={subject.activo ? 'destructive' : 'outline'} onClick={() => setEstadoAbierto(true)}>
                  <Power />
                  {subject.activo ? 'Desactivar' : 'Reactivar'}
                </Button>
              )}
            </div>
          </CardContent>
          {/* Montados solo al abrir: el formulario toma los valores actuales. */}
          {datosAbierto && <DatosSubjectDialog subject={subject} onOpenChange={setDatosAbierto} />}
          {estadoAbierto && <EstadoSubjectDialog subject={subject} onOpenChange={setEstadoAbierto} />}
        </Card>
      )}

      {subject && <SeguimientoCard subject={subject} />}

      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold">Resultados de búsqueda</h2>
        <FiltroEstado value={filtro} onChange={setFiltro} mostrarDesdeSeguimiento />
      </div>

      {resultadosLoading && <Skeleton className="h-32 w-full" />}

      {!resultadosLoading && listaCompleta.length === 0 && buscando && (
        <p className="text-muted-foreground py-8 text-center text-sm">
          Buscando… esto puede tardar unos segundos.
        </p>
      )}

      {!resultadosLoading && listaCompleta.length === 0 && !buscando && yaBuscoAlgunaVez && (
        <p className="text-muted-foreground py-8 text-center text-sm">
          Ya se ejecutó la búsqueda — no se encontraron resultados para este sujeto en las fuentes
          configuradas.
        </p>
      )}

      {!resultadosLoading && listaCompleta.length === 0 && !buscando && !yaBuscoAlgunaVez && (
        <p className="text-muted-foreground py-8 text-center text-sm">
          Sin resultados todavía. Ejecuta una consulta puntual arriba.
        </p>
      )}

      {!resultadosLoading && listaCompleta.length > 0 && listaFiltrada.length === 0 && (
        <p className="text-muted-foreground py-8 text-center text-sm">
          Ningún resultado coincide con este filtro.
        </p>
      )}

      <div className="flex flex-col gap-4">
        {listaFiltrada.map((resultado) => (
          <ResultadoCard key={resultado.id} resultado={resultado} queryKey={['subjects', id, 'resultados']} />
        ))}
      </div>
    </AppShell>
  );
}
