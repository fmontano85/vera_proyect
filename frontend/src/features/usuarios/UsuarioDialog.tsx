import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
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
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { CampoContrasena } from '@/features/usuarios/CampoContrasena';
import { ROLES_ASIGNABLES, useActualizarUsuario, useCrearUsuario } from '@/features/usuarios/useUsuarios';
import { mensajeApi } from '@/lib/api';
import type { Rol, UsuarioTenant } from '@/types/api';

/**
 * Alta (sin `usuario`) o edicion de un usuario del tenant. La contrasena
 * inicial la define el admin y se la entrega a la persona; al editar, dejar
 * la contrasena vacia la conserva (rellenarla la restablece y cierra las
 * sesiones abiertas de esa persona).
 */
export function UsuarioDialog({
  usuario,
  esUnoMismo,
  onOpenChange,
}: {
  usuario?: UsuarioTenant;
  esUnoMismo?: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const crear = useCrearUsuario();
  const actualizar = useActualizarUsuario();
  const [name, setName] = useState(usuario?.name ?? '');
  const [email, setEmail] = useState(usuario?.email ?? '');
  const [rol, setRol] = useState<Rol>(usuario?.rol ?? 'analista');
  const [password, setPassword] = useState('');
  const pendiente = crear.isPending || actualizar.isPending;

  function handleGuardar(e: React.FormEvent) {
    e.preventDefault();
    const opciones = {
      onSuccess: () => {
        toast.success(usuario ? 'Usuario actualizado.' : 'Usuario creado.');
        onOpenChange(false);
      },
      onError: (error: unknown) => toast.error(mensajeApi(error, 'No se pudo guardar el usuario.')),
    };

    if (usuario) {
      actualizar.mutate(
        {
          id: usuario.id,
          name: name.trim(),
          ...(esUnoMismo ? {} : { rol }),
          ...(password ? { password } : {}),
        },
        opciones,
      );
    } else {
      crear.mutate({ name: name.trim(), email: email.trim(), password, rol }, opciones);
    }
  }

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent>
        <form onSubmit={handleGuardar}>
          <DialogHeader>
            <DialogTitle>{usuario ? 'Editar usuario' : 'Nuevo usuario'}</DialogTitle>
            <DialogDescription>
              {usuario
                ? 'Los cambios de rol y de contraseña quedan en la auditoría.'
                : 'Define la contraseña inicial y entrégasela a la persona por un medio seguro.'}
            </DialogDescription>
          </DialogHeader>

          <div className="flex flex-col gap-4 py-4">
            <div className="flex flex-col gap-2">
              <Label htmlFor="usuario-nombre">Nombre</Label>
              <Input id="usuario-nombre" required maxLength={255} value={name} onChange={(e) => setName(e.target.value)} />
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="usuario-email">Correo electrónico</Label>
              <Input
                id="usuario-email"
                type="email"
                required
                maxLength={255}
                disabled={!!usuario}
                value={email}
                onChange={(e) => setEmail(e.target.value)}
              />
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="usuario-rol">Rol</Label>
              <Select value={rol} onValueChange={(v: Rol) => setRol(v)} disabled={esUnoMismo}>
                <SelectTrigger id="usuario-rol" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {ROLES_ASIGNABLES.map((r) => (
                    <SelectItem key={r.value} value={r.value}>
                      {r.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {esUnoMismo && <p className="text-muted-foreground text-xs">No puedes cambiar tu propio rol.</p>}
            </div>
            <div>
              <CampoContrasena
                id="usuario-password"
                label={usuario ? 'Nueva contraseña (opcional)' : 'Contraseña inicial'}
                autoComplete="new-password"
                required={!usuario}
                value={password}
                onChange={setPassword}
              />
              {esUnoMismo && (
                <p className="text-muted-foreground mt-1 text-xs">
                  Cambia tu contraseña sin cerrar tu sesión actual.
                </p>
              )}
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" disabled={pendiente} onClick={() => onOpenChange(false)}>
              Cancelar
            </Button>
            <Button type="submit" disabled={pendiente || name.trim() === ''}>
              {pendiente ? 'Guardando…' : 'Guardar'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
