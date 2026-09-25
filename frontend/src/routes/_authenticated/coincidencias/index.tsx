import { createFileRoute } from '@tanstack/react-router';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { CoincidenciaCard } from '@/features/coincidencias/CoincidenciaCard';
import { useCoincidencias } from '@/features/coincidencias/useMatches';
import type { BandejaCoincidencias } from '@/types/api';

/** Opcionales: el menu enlaza a /coincidencias sin parametros. */
interface BusquedaCoincidencias {
  bandeja?: BandejaCoincidencias;
  pagina?: number;
}

/** Valores invalidos en la URL se descartan (sin libreria de esquemas). */
function validarBusqueda(search: Record<string, unknown>): BusquedaCoincidencias {
  const pagina = Number(search.pagina);

  return {
    bandeja: search.bandeja === 'esperan_resolucion' || search.bandeja === 'sin_propuesta' ? search.bandeja : undefined,
    pagina: Number.isInteger(pagina) && pagina >= 1 ? pagina : undefined,
  };
}

export const Route = createFileRoute('/_authenticated/coincidencias/')({
  validateSearch: validarBusqueda,
  component: CoincidenciasPage,
});

const DESCRIPCION: Record<BandejaCoincidencias, string> = {
  sin_propuesta: 'Coincidencias que el sistema detectó y nadie ha revisado. El analista propone una resolución.',
  esperan_resolucion:
    'Coincidencias con propuesta del analista. El oficial de cumplimiento resuelve en firme (puede coincidir o no con la propuesta).',
};

/**
 * Dashboard de coincidencias pendientes (Fase 2). La bandeja y la pagina
 * viven en la URL (?bandeja=...&pagina=...) para que el inicio enlace
 * directo a cada una y el boton atras funcione.
 */
function CoincidenciasPage() {
  const { bandeja = 'sin_propuesta', pagina = 1 } = Route.useSearch();
  const navigate = Route.useNavigate();
  const { data, isLoading } = useCoincidencias(bandeja, pagina);
  const coincidencias = data?.data ?? [];

  return (
    <AppShell title="Coincidencias pendientes">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p className="text-muted-foreground max-w-2xl text-sm">{DESCRIPCION[bandeja]}</p>
        <Tabs
          value={bandeja}
          onValueChange={(v) => navigate({ search: { bandeja: v as BandejaCoincidencias, pagina: 1 } })}
        >
          <TabsList>
            <TabsTrigger value="sin_propuesta">Sin propuesta</TabsTrigger>
            <TabsTrigger value="esperan_resolucion">Esperan resolución</TabsTrigger>
          </TabsList>
        </Tabs>
      </div>

      {isLoading && <Skeleton className="h-48 w-full" />}

      {!isLoading && coincidencias.length === 0 && (
        <Card>
          <CardContent className="text-muted-foreground py-10 text-center text-sm">
            {bandeja === 'sin_propuesta'
              ? 'No hay coincidencias sin revisar.'
              : 'No hay coincidencias esperando resolución.'}
          </CardContent>
        </Card>
      )}

      <div className="flex flex-col gap-4">
        {coincidencias.map((coincidencia) => (
          <CoincidenciaCard key={coincidencia.id} coincidencia={coincidencia} queryKey={['coincidencias']} />
        ))}
      </div>

      {data && data.last_page > 1 && (
        <div className="mt-3 flex items-center justify-between gap-3">
          <p className="text-muted-foreground text-sm">
            Página {data.current_page} de {data.last_page} · {data.total} en total. Las más antiguas primero.
          </p>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={pagina <= 1}
              onClick={() => navigate({ search: { bandeja, pagina: pagina - 1 } })}
            >
              Anterior
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={pagina >= data.last_page}
              onClick={() => navigate({ search: { bandeja, pagina: pagina + 1 } })}
            >
              Siguiente
            </Button>
          </div>
        </div>
      )}
    </AppShell>
  );
}
