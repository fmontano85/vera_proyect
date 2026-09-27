import { createFileRoute } from '@tanstack/react-router';
import { Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { esAdmin, useCurrentUser } from '@/features/auth/useAuth';
import { UsuarioDialog } from '@/features/usuarios/UsuarioDialog';
import { etiquetaRol, useActualizarUsuario, useUsuarios } from '@/features/usuarios/useUsuarios';
import { mensajeApi } from '@/lib/api';
import type { UsuarioTenant } from '@/types/api';

export const Route = createFileRoute('/_authenticated/usuarios/')({
  component: UsuariosPage,
});

/** Usuarios del tenant, solo admin (el backend responde 403 al resto). */
function UsuariosPage() {
  const { data: yo } = useCurrentUser();
  const { data: usuarios, isLoading } = useUsuarios();
  const actualizar = useActualizarUsuario();
  const [dialogo, setDialogo] = useState<{ usuario?: UsuarioTenant } | null>(null);

  if (yo && !esAdmin(yo)) {
    return (
      <AppShell title="Usuarios">
        <p className="text-muted-foreground text-sm">Solo un administrador puede gestionar los usuarios.</p>
      </AppShell>
    );
  }

  function alternarActivo(u: UsuarioTenant) {
    actualizar.mutate(
      { id: u.id, activo: !u.activo },
      {
        onSuccess: () => toast.success(u.activo ? 'Usuario desactivado; sus sesiones se cerraron.' : 'Usuario reactivado.'),
        onError: (e) => toast.error(mensajeApi(e, 'No se pudo cambiar el estado.')),
      },
    );
  }

  return (
    <AppShell title="Usuarios">
      <Card>
        <CardHeader className="flex flex-row items-start justify-between gap-4">
          <div>
            <CardTitle className="text-base">Usuarios del tenant</CardTitle>
            <CardDescription>
              Un usuario desactivado no puede iniciar sesión y se cierran sus sesiones abiertas. Nada se borra.
            </CardDescription>
          </div>
          <Button onClick={() => setDialogo({})}>
            <Plus />
            Nuevo usuario
          </Button>
        </CardHeader>
        <CardContent>
          {isLoading && <Skeleton className="h-40 w-full" />}
          <ul className="divide-y">
            {usuarios?.map((u) => (
              <li key={u.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                <div className="min-w-0">
                  <p className="flex items-center gap-2 font-medium">
                    {u.name}
                    {u.id === yo?.id && <Badge variant="outline">Tú</Badge>}
                    {!u.activo && <Badge variant="outline">Inactivo</Badge>}
                  </p>
                  <p className="text-muted-foreground text-sm">
                    {u.email} · {etiquetaRol(u.rol)}
                  </p>
                </div>
                <div className="flex gap-2">
                  <Button size="sm" variant="ghost" onClick={() => setDialogo({ usuario: u })}>
                    <Pencil />
                    Editar
                  </Button>
                  {u.id !== yo?.id && (
                    <Button size="sm" variant="outline" disabled={actualizar.isPending} onClick={() => alternarActivo(u)}>
                      {u.activo ? 'Desactivar' : 'Reactivar'}
                    </Button>
                  )}
                </div>
              </li>
            ))}
          </ul>
        </CardContent>
      </Card>

      {/* Montado solo al abrir: el formulario toma los valores actuales. */}
      {dialogo && (
        <UsuarioDialog
          usuario={dialogo.usuario}
          esUnoMismo={dialogo.usuario?.id === yo?.id}
          onOpenChange={(abierto) => !abierto && setDialogo(null)}
        />
      )}
    </AppShell>
  );
}
