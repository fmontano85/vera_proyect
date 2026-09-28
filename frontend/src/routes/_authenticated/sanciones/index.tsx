import { createFileRoute } from '@tanstack/react-router';
import { useState } from 'react';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useCurrentUser } from '@/features/auth/useAuth';
import { SancionCard } from '@/features/sanciones/SancionCard';
import { useSanciones } from '@/features/sanciones/useSanciones';

export const Route = createFileRoute('/_authenticated/sanciones/')({
  component: SancionesPage,
});

/**
 * Hallazgos del cruce de la lista de vigilancia contra listas de sanciones
 * internacionales. Son coincidencias aproximadas de nombre: requieren
 * revision humana antes de tratarlas como un hallazgo real.
 */
function SancionesPage() {
  const { data: user } = useCurrentUser();
  const [estado, setEstado] = useState<'pendiente' | 'todos'>('pendiente');
  const [pagina, setPagina] = useState(1);
  // enabled evita el 404 real del backend si se llega por URL directa a
  // un tenant sin Sanciones habilitada (el menu ya la oculta, esto cubre
  // un bookmark viejo o el caso en que el superadmin la desactive
  // mientras la pantalla esta abierta).
  const { data, isLoading } = useSanciones({ estado, page: pagina, enabled: user?.sanciones_habilitado });

  if (user && !user.sanciones_habilitado) {
    return (
      <AppShell title="Sanciones">
        <p className="text-muted-foreground text-sm">
          Esta función no está habilitada para tu tenant. Pide al superadmin que la active.
        </p>
      </AppShell>
    );
  }

  const hallazgos = data?.data ?? [];

  return (
    <AppShell title="Sanciones">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p className="text-muted-foreground max-w-2xl text-sm">
          Coincidencias aproximadas entre tu lista de vigilancia y la lista OFAC SDN. Compara el nombre, el país y el
          programa antes de resolver. El cruce corre cada semana; también puedes lanzarlo desde la ficha de cada persona.
        </p>
        <Tabs
          value={estado}
          onValueChange={(v) => {
            setEstado(v as 'pendiente' | 'todos');
            setPagina(1);
          }}
        >
          <TabsList>
            <TabsTrigger value="pendiente">Pendientes</TabsTrigger>
            <TabsTrigger value="todos">Todas</TabsTrigger>
          </TabsList>
        </Tabs>
      </div>

      {isLoading && <Skeleton className="h-48 w-full" />}

      {!isLoading && hallazgos.length === 0 && (
        <Card>
          <CardContent className="text-muted-foreground py-10 text-center text-sm">
            {estado === 'pendiente' ? 'No hay hallazgos de sanciones pendientes.' : 'No hay hallazgos de sanciones.'}
          </CardContent>
        </Card>
      )}

      <div className="flex flex-col gap-4">
        {hallazgos.map((h) => (
          <SancionCard key={h.id} hallazgo={h} />
        ))}
      </div>

      {data && data.last_page > 1 && (
        <div className="mt-3 flex items-center justify-between gap-3">
          <p className="text-muted-foreground text-sm">
            Página {data.current_page} de {data.last_page} · {data.total} en total.
          </p>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" disabled={pagina <= 1} onClick={() => setPagina(pagina - 1)}>
              Anterior
            </Button>
            <Button variant="outline" size="sm" disabled={pagina >= data.last_page} onClick={() => setPagina(pagina + 1)}>
              Siguiente
            </Button>
          </div>
        </div>
      )}
    </AppShell>
  );
}
