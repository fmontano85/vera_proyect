import { createFileRoute } from '@tanstack/react-router';
import { Search } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { NivelRiesgoBadge } from '@/components/NivelRiesgoBadge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { FiltroEstado, type FiltroResultados } from '@/features/resultados/FiltroEstado';
import { ResultadoCard } from '@/features/resultados/ResultadoCard';
import { useSearchResults } from '@/features/resultados/useSearchResults';
import { useBuscarSubject, useSubject } from '@/features/consulta/useSubjects';
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
  const listaFiltrada =
    filtro === 'todos' ? listaCompleta : listaCompleta.filter((r) => r.estado === filtro);

  return (
    <AppShell title={subject?.nombre_canonico ?? 'Sujeto'}>
      {isLoading && <Skeleton className="h-24 w-full" />}

      {subject && (
        <Card className="mb-6">
          <CardHeader className="flex flex-row items-start justify-between">
            <div>
              <CardTitle className="text-xl">{subject.nombre_canonico}</CardTitle>
              <p className="text-muted-foreground mt-1 text-sm capitalize">
                {subject.tipo} {subject.documento && `· ${subject.documento}`}
              </p>
            </div>
            <NivelRiesgoBadge nivel={subject.nivel_riesgo} />
          </CardHeader>
          <CardContent>
            <Button onClick={handleBuscar} disabled={buscar.isPending}>
              <Search />
              {buscar.isPending ? 'Encolando…' : 'Consulta puntual'}
            </Button>
          </CardContent>
        </Card>
      )}

      <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-lg font-semibold">Resultados de búsqueda</h2>
        <FiltroEstado value={filtro} onChange={setFiltro} />
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
