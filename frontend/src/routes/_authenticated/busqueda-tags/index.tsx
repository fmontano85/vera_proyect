import { createFileRoute } from '@tanstack/react-router';
import { Plus, Search } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { FiltroEstado, type FiltroResultados } from '@/features/resultados/FiltroEstado';
import { filtrarResultados } from '@/features/resultados/filtrarResultados';
import { ResultadoCard } from '@/features/resultados/ResultadoCard';
import { useTagSearchResults } from '@/features/resultados/useSearchResults';
import { useBuscarPorTags, useCrearSearchTag, useSearchTags } from '@/features/resultados/useSearchTags';
import { ApiError } from '@/lib/api';

export const Route = createFileRoute('/_authenticated/busqueda-tags/')({
  component: BusquedaPorTagsPage,
});

const QUERY_KEY = ['busquedas-tags', 'resultados'];

/** Ver comentario equivalente en $subjectId.tsx - RunTagSearchJob tambien
 * es async (cola 'search'). */
const VENTANA_BUSCANDO_MS = 15_000;

function BusquedaPorTagsPage() {
  const { data: tags, isLoading: tagsLoading } = useSearchTags();
  const crearTag = useCrearSearchTag();
  const buscar = useBuscarPorTags();

  const [seleccionados, setSeleccionados] = useState<Set<number>>(new Set());
  const [nuevoTag, setNuevoTag] = useState('');
  const [diasAtras, setDiasAtras] = useState('');
  const [buscando, setBuscando] = useState(false);
  const [yaBuscoAlgunaVez, setYaBuscoAlgunaVez] = useState(false);
  const [filtro, setFiltro] = useState<FiltroResultados>('todos');

  const { data: resultados, isLoading: resultadosLoading } = useTagSearchResults(buscando);

  function alternarTag(id: number) {
    setSeleccionados((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function agregarTag() {
    const nombre = nuevoTag.trim();
    if (!nombre) return;

    crearTag.mutate(nombre, {
      onSuccess: (tag) => {
        setSeleccionados((prev) => new Set(prev).add(tag.id));
        setNuevoTag('');
      },
      onError: (error) => {
        toast.error(error instanceof ApiError ? error.message : 'No se pudo agregar el tag.');
      },
    });
  }

  function handleBuscar() {
    buscar.mutate(
      {
        tagIds: Array.from(seleccionados),
        diasAtras: diasAtras ? Number(diasAtras) : undefined,
      },
      {
        onSuccess: () => {
          toast.success('Búsqueda por tags encolada. Los resultados aparecen abajo en unos segundos.');
          setBuscando(true);
          setYaBuscoAlgunaVez(true);
          setTimeout(() => setBuscando(false), VENTANA_BUSCANDO_MS);
        },
        onError: (error) => {
          toast.error(error instanceof ApiError ? error.message : 'No se pudo iniciar la búsqueda.');
        },
      },
    );
  }

  if (buscando && (resultados?.data.length ?? 0) > 0) setBuscando(false);

  const listaCompleta = resultados?.data ?? [];
  const listaFiltrada = filtrarResultados(listaCompleta, filtro);

  return (
    <AppShell title="Búsqueda por tags">
      <Card className="mb-6">
        <CardHeader>
          <CardTitle className="text-xl">Buscar por palabras clave</CardTitle>
          <p className="text-muted-foreground text-sm">
            Encuentra noticias judiciales sin apuntar a una persona en concreto — los resultados se
            cruzan después contra toda la lista de vigilancia.
          </p>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <div>
            <Label className="mb-2 block">Tags</Label>
            {tagsLoading && <Skeleton className="h-8 w-full" />}
            <div className="flex flex-wrap gap-2">
              {tags?.map((tag) => (
                <Badge
                  key={tag.id}
                  variant={seleccionados.has(tag.id) ? 'default' : 'outline'}
                  className="cursor-pointer select-none"
                  onClick={() => alternarTag(tag.id)}
                >
                  {tag.nombre}
                </Badge>
              ))}
            </div>
            <div className="mt-2 flex gap-2">
              <Input
                value={nuevoTag}
                onChange={(e) => setNuevoTag(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    agregarTag();
                  }
                }}
                placeholder="Agregar un tag nuevo al catálogo"
                className="max-w-xs"
              />
              <Button type="button" variant="outline" size="sm" disabled={crearTag.isPending} onClick={agregarTag}>
                <Plus />
                Agregar
              </Button>
            </div>
          </div>

          <div className="max-w-xs">
            <Label htmlFor="dias_atras" className="mb-2 block">
              Días atrás (opcional)
            </Label>
            <Input
              id="dias_atras"
              type="number"
              min={1}
              max={365}
              value={diasAtras}
              onChange={(e) => setDiasAtras(e.target.value)}
              placeholder="Por defecto: el de la configuración"
            />
          </div>

          <div>
            <Button
              onClick={handleBuscar}
              disabled={seleccionados.size === 0 || buscar.isPending}
            >
              <Search />
              {buscar.isPending ? 'Encolando…' : 'Buscar'}
            </Button>
          </div>
        </CardContent>
      </Card>

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
          Ya se ejecutó la búsqueda — no se encontraron resultados para estos tags.
        </p>
      )}

      {!resultadosLoading && listaCompleta.length === 0 && !buscando && !yaBuscoAlgunaVez && (
        <p className="text-muted-foreground py-8 text-center text-sm">
          Sin resultados todavía. Elige uno o más tags arriba y ejecuta una búsqueda.
        </p>
      )}

      {!resultadosLoading && listaCompleta.length > 0 && listaFiltrada.length === 0 && (
        <p className="text-muted-foreground py-8 text-center text-sm">
          Ningún resultado coincide con este filtro.
        </p>
      )}

      <div className="flex flex-col gap-4">
        {listaFiltrada.map((resultado) => (
          <ResultadoCard key={resultado.id} resultado={resultado} queryKey={QUERY_KEY} />
        ))}
      </div>
    </AppShell>
  );
}
