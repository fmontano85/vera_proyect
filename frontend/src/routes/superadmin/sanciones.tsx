import { createFileRoute } from '@tanstack/react-router';
import { CalendarClock, CheckCircle2, Hand, RefreshCw, TriangleAlert } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import {
  useActualizarConfiguracionSancionesSuperadmin,
  useActualizarListaOfacSuperadmin,
  useConfiguracionSancionesSuperadmin,
} from '@/features/superadmin/useSuperadmin';
import { mensajeApi } from '@/lib/api';
import { formatearFechaHora } from '@/lib/fechas';
import { cn } from '@/lib/utils';
import type { ModoDescargaOfac } from '@/types/superadmin';

export const Route = createFileRoute('/superadmin/sanciones')({
  component: ListasSancionesPage,
});

const MODOS: { valor: ModoDescargaOfac; titulo: string; descripcion: string; icon: typeof CalendarClock }[] = [
  {
    valor: 'automatico',
    titulo: 'Automática',
    descripcion: 'Se descarga cada domingo a las 02:00 (hora de El Salvador) y se cruza contra todas las listas de vigilancia con la función habilitada.',
    icon: CalendarClock,
  },
  {
    valor: 'manual',
    titulo: 'Manual',
    descripcion: 'Solo se actualiza cuando lo pides con "Actualizar ahora". Útil si quieres controlar cuándo cambia la lista.',
    icon: Hand,
  },
];

/**
 * Lista OFAC SDN (catalogo global, compartido por todos los tenants):
 * estado de la ultima importacion, modo de descarga y actualizacion a
 * demanda. Habilitar Sanciones por tenant vive en la pantalla de Tenants.
 */
function ListasSancionesPage() {
  const { data: config, isLoading } = useConfiguracionSancionesSuperadmin();
  const actualizarModo = useActualizarConfiguracionSancionesSuperadmin();
  const actualizarLista = useActualizarListaOfacSuperadmin();

  function cambiarModo(modo: ModoDescargaOfac) {
    if (modo === config?.modo_descarga_ofac) return;
    actualizarModo.mutate(modo, {
      onSuccess: () => toast.success('Modo de descarga actualizado.'),
      onError: (e) => toast.error(mensajeApi(e, 'No se pudo cambiar el modo.')),
    });
  }

  function actualizarAhora() {
    actualizarLista.mutate(undefined, {
      onSuccess: () => toast.success('Actualización encolada. La lista y el cruce se procesan en segundo plano.'),
      onError: (e) => toast.error(mensajeApi(e, 'No se pudo encolar la actualización.')),
    });
  }

  const lista = config?.lista;

  return (
    <div className="flex flex-col gap-6">
      <Card>
        <CardHeader className="flex flex-row items-start justify-between gap-4">
          <div>
            <CardTitle className="text-base">OFAC SDN — Departamento del Tesoro de EE. UU.</CardTitle>
            <CardDescription>
              Catálogo único para toda la plataforma. Cada tenant solo ve sus coincidencias si tiene Sanciones habilitada.
            </CardDescription>
          </div>
          <Button variant="outline" disabled={actualizarLista.isPending} onClick={actualizarAhora}>
            <RefreshCw className={cn(actualizarLista.isPending && 'animate-spin')} />
            Actualizar ahora
          </Button>
        </CardHeader>
        <CardContent>
          {isLoading && <Skeleton className="h-20 w-full" />}
          {config && !lista && (
            <div className="border-warning/40 bg-warning/10 flex items-start gap-3 rounded-lg border p-4 text-sm">
              <TriangleAlert className="text-warning mt-0.5 size-4 shrink-0" />
              <p>
                La lista todavía no se ha importado. Usa <span className="font-medium">Actualizar ahora</span> para la
                primera carga.
              </p>
            </div>
          )}
          {lista && (
            <dl className="grid gap-4 sm:grid-cols-3">
              <Dato etiqueta="Última importación" valor={formatearFechaHora(lista.fecha_importacion)} />
              <Dato etiqueta="Versión de la lista" valor={lista.version ?? '—'} />
              <Dato etiqueta="Entradas" valor={lista.entradas.toLocaleString('es-SV')} />
            </dl>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle className="text-base">Modo de descarga</CardTitle>
          <CardDescription>
            {config?.actualizado_por
              ? `Último cambio: ${config.actualizado_por}, ${formatearFechaHora(config.updated_at)}.`
              : 'Aplica a la lista compartida, no a un tenant en particular.'}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {isLoading && <Skeleton className="h-28 w-full" />}
          {config && (
            <div role="radiogroup" aria-label="Modo de descarga" className="grid gap-3 sm:grid-cols-2">
              {MODOS.map(({ valor, titulo, descripcion, icon: Icon }) => {
                const activo = config.modo_descarga_ofac === valor;
                return (
                  <button
                    key={valor}
                    type="button"
                    role="radio"
                    aria-checked={activo}
                    disabled={actualizarModo.isPending}
                    onClick={() => cambiarModo(valor)}
                    className={cn(
                      'flex items-start gap-3 rounded-lg border p-4 text-left transition-colors disabled:opacity-60',
                      activo ? 'border-primary bg-primary/5 ring-primary ring-1' : 'hover:bg-muted',
                    )}
                  >
                    <Icon className={cn('mt-0.5 size-5 shrink-0', activo ? 'text-primary' : 'text-muted-foreground')} />
                    <span className="flex flex-col gap-1">
                      <span className="flex items-center gap-2 font-medium">
                        {titulo}
                        {activo && <CheckCircle2 className="text-primary size-4" />}
                      </span>
                      <span className="text-muted-foreground text-sm">{descripcion}</span>
                    </span>
                  </button>
                );
              })}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

function Dato({ etiqueta, valor }: { etiqueta: string; valor: string }) {
  return (
    <div className="bg-muted/50 rounded-lg p-3">
      <dt className="text-muted-foreground text-xs">{etiqueta}</dt>
      <dd className="mt-1 font-medium">{valor}</dd>
    </div>
  );
}
