import { Check, Eraser, MoreHorizontal, Pencil, Plus, ShieldAlert, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import {
  useActualizarTenantSuperadmin,
  useCrearTenantSuperadmin,
  useTenantsSuperadmin,
} from '@/features/superadmin/useSuperadmin';
import { mensajeApi } from '@/lib/api';
import type { TenantSuperadmin } from '@/types/superadmin';

/** Tenants de la plataforma: alta con su primer admin, renombrado, Sanciones y depuracion por tenant. */
export function TenantsPanel() {
  const { data: tenants, isLoading } = useTenantsSuperadmin();
  const [dialogoAbierto, setDialogoAbierto] = useState(false);

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="text-base">Tenants</CardTitle>
          <CardDescription>
            Cada tenant nuevo empieza con Sanciones y la depuración automática deshabilitadas.
          </CardDescription>
        </div>
        <Button size="sm" onClick={() => setDialogoAbierto(true)}>
          <Plus />
          Nuevo tenant
        </Button>
      </CardHeader>
      <CardContent>
        {isLoading && <Skeleton className="h-40 w-full" />}
        <ul className="divide-y">
          {tenants?.map((t) => (
            <TenantFila key={t.id} tenant={t} />
          ))}
        </ul>
      </CardContent>

      {dialogoAbierto && <NuevoTenantDialog onOpenChange={setDialogoAbierto} />}
    </Card>
  );
}

function TenantFila({ tenant }: { tenant: TenantSuperadmin }) {
  const actualizar = useActualizarTenantSuperadmin();
  const [editando, setEditando] = useState(false);
  const [nombre, setNombre] = useState(tenant.name ?? '');
  const [confirmarDepuracion, setConfirmarDepuracion] = useState(false);

  function cambiar(cambios: { sanciones_habilitado?: boolean; depuracion_habilitada?: boolean }, mensaje: string) {
    actualizar.mutate(
      { id: tenant.id, ...cambios },
      {
        onSuccess: () => {
          setConfirmarDepuracion(false);
          toast.success(mensaje);
        },
        onError: (e) => toast.error(mensajeApi(e, 'No se pudo cambiar el estado.')),
      },
    );
  }

  function guardarNombre() {
    const valor = nombre.trim();
    if (!valor || valor === tenant.name) return setEditando(false);
    actualizar.mutate(
      { id: tenant.id, name: valor },
      {
        onSuccess: () => {
          setEditando(false);
          toast.success('Tenant renombrado.');
        },
        onError: (e) => toast.error(mensajeApi(e, 'No se pudo renombrar.')),
      },
    );
  }

  return (
    <li className="flex flex-wrap items-center justify-between gap-3 py-3">
      <div className="min-w-0">
        {editando ? (
          <div className="flex items-center gap-2">
            <Input value={nombre} onChange={(e) => setNombre(e.target.value)} maxLength={255} autoFocus className="h-8" />
            <Button size="icon" variant="outline" className="size-8" aria-label="Guardar" disabled={actualizar.isPending} onClick={guardarNombre}>
              <Check />
            </Button>
            <Button
              size="icon"
              variant="ghost"
              className="size-8"
              aria-label="Cancelar"
              onClick={() => {
                setNombre(tenant.name ?? '');
                setEditando(false);
              }}
            >
              <X />
            </Button>
          </div>
        ) : (
          <div className="flex items-center gap-1.5">
            <p className="font-medium">{tenant.name ?? 'Sin nombre'}</p>
            <Button size="icon" variant="ghost" className="size-6" aria-label="Renombrar" onClick={() => setEditando(true)}>
              <Pencil className="size-3.5" />
            </Button>
          </div>
        )}
        <p className="text-muted-foreground truncate font-mono text-xs">{tenant.id}</p>
        <p className="text-muted-foreground mt-0.5 text-xs">
          Conservación de datos: {tenant.retencion_anios} años · Depuración{' '}
          {tenant.depuracion_habilitada ? 'habilitada' : 'deshabilitada'}
        </p>
      </div>
      <div className="flex shrink-0 flex-wrap items-center gap-2">
        <Badge variant={tenant.documentos_al_dia ? 'outline' : 'destructive'}>
          {tenant.documentos_al_dia ? 'Términos aceptados' : 'Términos sin aceptar'}
        </Badge>
        <Badge variant={tenant.sanciones_habilitado ? 'default' : 'outline'}>
          Sanciones {tenant.sanciones_habilitado ? 'habilitada' : 'deshabilitada'}
        </Badge>
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button size="icon" variant="ghost" className="size-8" aria-label="Opciones del tenant" disabled={actualizar.isPending}>
              <MoreHorizontal />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem
              onSelect={() =>
                cambiar(
                  { sanciones_habilitado: !tenant.sanciones_habilitado },
                  tenant.sanciones_habilitado ? 'Sanciones deshabilitada.' : 'Sanciones habilitada.',
                )
              }
            >
              <ShieldAlert />
              {tenant.sanciones_habilitado ? 'Deshabilitar Sanciones' : 'Habilitar Sanciones'}
            </DropdownMenuItem>
            <DropdownMenuItem
              onSelect={() =>
                tenant.depuracion_habilitada
                  ? cambiar({ depuracion_habilitada: false }, 'Depuración deshabilitada.')
                  : setConfirmarDepuracion(true)
              }
            >
              <Eraser />
              {tenant.depuracion_habilitada ? 'Deshabilitar depuración' : 'Habilitar depuración'}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>

      <Dialog open={confirmarDepuracion} onOpenChange={setConfirmarDepuracion}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>¿Habilitar la depuración automática?</DialogTitle>
            <DialogDescription>
              Cada día a las 04:00 se borrarán de forma permanente las personas inactivas de{' '}
              <span className="text-foreground font-medium">{tenant.name ?? 'este tenant'}</span> desactivadas hace más
              de {tenant.retencion_anios} años, con todos sus datos y evidencia. Las personas activas nunca se tocan.
              Queda registrado en la bitácora.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" disabled={actualizar.isPending} onClick={() => setConfirmarDepuracion(false)}>
              Cancelar
            </Button>
            <Button
              variant="destructive"
              disabled={actualizar.isPending}
              onClick={() => cambiar({ depuracion_habilitada: true }, 'Depuración habilitada.')}
            >
              Habilitar depuración
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </li>
  );
}

/** Alta de un tenant nuevo con su primer usuario admin — sin un admin el
 * tenant queda inutilizable (nadie puede entrar a el). */
function NuevoTenantDialog({ onOpenChange }: { onOpenChange: (open: boolean) => void }) {
  const crear = useCrearTenantSuperadmin();
  const [name, setName] = useState('');
  const [adminName, setAdminName] = useState('');
  const [adminEmail, setAdminEmail] = useState('');
  const [adminPassword, setAdminPassword] = useState('');

  function handleGuardar(e: React.FormEvent) {
    e.preventDefault();
    crear.mutate(
      { name: name.trim(), admin_name: adminName.trim(), admin_email: adminEmail.trim(), admin_password: adminPassword },
      {
        onSuccess: (r) => {
          toast.success(`Tenant "${r.tenant.name}" creado. Entrega la contraseña inicial a ${r.admin.email}.`);
          onOpenChange(false);
        },
        onError: (e) => toast.error(mensajeApi(e, 'No se pudo crear el tenant.')),
      },
    );
  }

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent>
        <form onSubmit={handleGuardar}>
          <DialogHeader>
            <DialogTitle>Nuevo tenant</DialogTitle>
            <DialogDescription>
              Crea el tenant junto con su primer usuario (rol admin) — sin uno, nadie podría entrar a él. Entrega la
              contraseña inicial por un medio seguro.
            </DialogDescription>
          </DialogHeader>

          <div className="flex flex-col gap-4 py-4">
            <div className="flex flex-col gap-2">
              <Label htmlFor="tenant-name">Nombre del tenant</Label>
              <Input id="tenant-name" required maxLength={255} value={name} onChange={(e) => setName(e.target.value)} />
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="tenant-admin-name">Nombre del primer usuario</Label>
              <Input id="tenant-admin-name" required maxLength={255} value={adminName} onChange={(e) => setAdminName(e.target.value)} />
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="tenant-admin-email">Correo del primer usuario</Label>
              <Input
                id="tenant-admin-email"
                type="email"
                required
                maxLength={255}
                value={adminEmail}
                onChange={(e) => setAdminEmail(e.target.value)}
              />
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="tenant-admin-password">Contraseña inicial</Label>
              <Input
                id="tenant-admin-password"
                type="password"
                autoComplete="new-password"
                required
                minLength={12}
                value={adminPassword}
                onChange={(e) => setAdminPassword(e.target.value)}
              />
              <p className="text-muted-foreground text-xs">Mínimo 12 caracteres, con letras y números.</p>
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" disabled={crear.isPending} onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
            <Button type="submit" disabled={crear.isPending || !name.trim() || !adminName.trim() || !adminEmail.trim()}>
              {crear.isPending ? 'Creando…' : 'Crear tenant'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
