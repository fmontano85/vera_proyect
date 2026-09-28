import { createFileRoute, redirect, useNavigate } from '@tanstack/react-router';
import { RefreshCw, ShieldCheck } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { authQueryKey, fetchCurrentUser, useCurrentUser, useLogout } from '@/features/auth/useAuth';
import {
  useActualizarConfiguracionSancionesSuperadmin,
  useActualizarListaOfacSuperadmin,
  useActualizarTenantSuperadmin,
  useConfiguracionSancionesSuperadmin,
  useTenantsSuperadmin,
} from '@/features/superadmin/useSuperadmin';
import { mensajeApi } from '@/lib/api';
import type { ModoDescargaOfac, TenantSuperadmin } from '@/types/superadmin';

/**
 * Primera pantalla del panel de superadmin (seccion 3.2 del CLAUDE.md
 * raiz, Fase 3 - hoy solo esto): activar/desactivar Sanciones por
 * tenant y el modo de descarga global de la lista OFAC (decision del
 * usuario 2026-09-28). Ruta de nivel superior, fuera de _authenticated:
 * superadmin no tiene tenant, ninguna pantalla de ahi le sirve.
 */
export const Route = createFileRoute('/superadmin/')({
  beforeLoad: async ({ context }) => {
    const user = await context.queryClient.ensureQueryData({
      queryKey: authQueryKey,
      queryFn: fetchCurrentUser,
    });

    if (!user) {
      throw redirect({ to: '/login' });
    }
    if (!user.roles.includes('superadmin')) {
      throw redirect({ to: '/' });
    }
  },
  component: SuperadminPage,
});

function SuperadminPage() {
  const { data: user } = useCurrentUser();
  const logout = useLogout();
  const navigate = useNavigate();

  return (
    <div className="min-h-dvh">
      <header className="border-border bg-card sticky top-0 z-10 flex h-14 items-center justify-between border-b px-4">
        <div className="flex items-center gap-2">
          <ShieldCheck className="text-primary size-5" />
          <h1 className="text-lg font-semibold">VERA — Panel de superadmin</h1>
        </div>
        {user && (
          <DropdownMenu>
            <DropdownMenuTrigger className="text-sm">{user.name}</DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem
                variant="destructive"
                onSelect={() => logout.mutate(undefined, { onSuccess: () => navigate({ to: '/login' }) })}
              >
                Cerrar sesión
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        )}
      </header>

      <main className="mx-auto flex max-w-3xl flex-col gap-6 p-4 md:p-6">
        <ConfiguracionSancionesGlobal />
        <TenantsSanciones />
      </main>
    </div>
  );
}

function ConfiguracionSancionesGlobal() {
  const { data: config, isLoading } = useConfiguracionSancionesSuperadmin();
  const actualizarModo = useActualizarConfiguracionSancionesSuperadmin();
  const actualizarLista = useActualizarListaOfacSuperadmin();

  function cambiarModo(modo: ModoDescargaOfac) {
    actualizarModo.mutate(modo, {
      onSuccess: () => toast.success('Modo de descarga actualizado.'),
      onError: (e) => toast.error(mensajeApi(e, 'No se pudo cambiar el modo.')),
    });
  }

  function actualizarAhora() {
    actualizarLista.mutate(undefined, {
      onSuccess: () => toast.success('Actualización de la lista OFAC encolada.'),
      onError: (e) => toast.error(mensajeApi(e, 'No se pudo encolar la actualización.')),
    });
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Lista OFAC (sanciones internacionales)</CardTitle>
        <CardDescription>
          Es una sola lista, compartida por todos los tenants. En modo manual, esta es la única forma de
          actualizarla.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-wrap items-center justify-between gap-4">
        {isLoading && <Skeleton className="h-9 w-56" />}
        {config && (
          <div className="flex items-center gap-2">
            <Button
              size="sm"
              variant={config.modo_descarga_ofac === 'automatico' ? 'default' : 'outline'}
              disabled={actualizarModo.isPending}
              onClick={() => cambiarModo('automatico')}
            >
              Automática (cada domingo)
            </Button>
            <Button
              size="sm"
              variant={config.modo_descarga_ofac === 'manual' ? 'default' : 'outline'}
              disabled={actualizarModo.isPending}
              onClick={() => cambiarModo('manual')}
            >
              Manual
            </Button>
          </div>
        )}
        <Button size="sm" variant="outline" disabled={actualizarLista.isPending} onClick={actualizarAhora}>
          <RefreshCw />
          {actualizarLista.isPending ? 'Encolando…' : 'Actualizar lista ahora'}
        </Button>
      </CardContent>
    </Card>
  );
}

function TenantsSanciones() {
  const { data: tenants, isLoading } = useTenantsSuperadmin();
  const actualizar = useActualizarTenantSuperadmin();

  function alternar(t: TenantSuperadmin) {
    actualizar.mutate(
      { id: t.id, sanciones_habilitado: !t.sanciones_habilitado },
      {
        onSuccess: () =>
          toast.success(t.sanciones_habilitado ? 'Sanciones deshabilitada.' : 'Sanciones habilitada.'),
        onError: (e) => toast.error(mensajeApi(e, 'No se pudo cambiar el estado.')),
      },
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Tenants</CardTitle>
        <CardDescription>
          Sanciones (cruce contra listas internacionales) está deshabilitada por defecto para cada tenant nuevo.
        </CardDescription>
      </CardHeader>
      <CardContent>
        {isLoading && <Skeleton className="h-40 w-full" />}
        <ul className="divide-y">
          {tenants?.map((t) => (
            <li key={t.id} className="flex items-center justify-between gap-3 py-3">
              <div className="min-w-0">
                <p className="font-medium">{t.name ?? 'Sin nombre'}</p>
                <p className="text-muted-foreground truncate font-mono text-xs">{t.id}</p>
              </div>
              <div className="flex shrink-0 items-center gap-3">
                <Badge variant={t.sanciones_habilitado ? 'default' : 'outline'}>
                  Sanciones {t.sanciones_habilitado ? 'habilitada' : 'deshabilitada'}
                </Badge>
                <Button size="sm" variant="outline" disabled={actualizar.isPending} onClick={() => alternar(t)}>
                  {t.sanciones_habilitado ? 'Deshabilitar' : 'Habilitar'}
                </Button>
              </div>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}
