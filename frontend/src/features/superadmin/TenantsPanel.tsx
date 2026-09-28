import { Check, Pencil, Plus, X } from 'lucide-react';
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

/** Tenants de la plataforma: alta con su primer admin, renombrado y Sanciones por tenant. */
export function TenantsPanel() {
  const { data: tenants, isLoading } = useTenantsSuperadmin();
  const actualizar = useActualizarTenantSuperadmin();
  const [dialogoAbierto, setDialogoAbierto] = useState(false);

  function alternarSanciones(t: TenantSuperadmin) {
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
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="text-base">Tenants</CardTitle>
          <CardDescription>
            Sanciones (cruce contra listas internacionales) está deshabilitada por defecto para cada tenant nuevo.
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
            <TenantFila key={t.id} tenant={t} onAlternarSanciones={() => alternarSanciones(t)} />
          ))}
        </ul>
      </CardContent>

      {dialogoAbierto && <NuevoTenantDialog onOpenChange={setDialogoAbierto} />}
    </Card>
  );
}

function TenantFila({ tenant, onAlternarSanciones }: { tenant: TenantSuperadmin; onAlternarSanciones: () => void }) {
  const actualizar = useActualizarTenantSuperadmin();
  const [editando, setEditando] = useState(false);
  const [nombre, setNombre] = useState(tenant.name ?? '');

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
    <li className="flex items-center justify-between gap-3 py-3">
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
      </div>
      <div className="flex shrink-0 items-center gap-3">
        <Badge variant={tenant.documentos_al_dia ? 'outline' : 'destructive'}>
          {tenant.documentos_al_dia ? 'Términos aceptados' : 'Términos sin aceptar'}
        </Badge>
        <Badge variant={tenant.sanciones_habilitado ? 'default' : 'outline'}>
          Sanciones {tenant.sanciones_habilitado ? 'habilitada' : 'deshabilitada'}
        </Badge>
        <Button size="sm" variant="outline" disabled={actualizar.isPending} onClick={onAlternarSanciones}>
          {tenant.sanciones_habilitado ? 'Deshabilitar' : 'Habilitar'}
        </Button>
      </div>
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
