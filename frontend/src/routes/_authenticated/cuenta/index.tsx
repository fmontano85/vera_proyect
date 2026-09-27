import { createFileRoute } from '@tanstack/react-router';
import { useState } from 'react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useCurrentUser } from '@/features/auth/useAuth';
import { useActualizarCuenta, useCambiarContrasena } from '@/features/usuarios/useUsuarios';
import { mensajeApi } from '@/lib/api';

export const Route = createFileRoute('/_authenticated/cuenta/')({
  component: CuentaPage,
});

/** Cuenta propia: cualquier rol, incluido lectura. */
function CuentaPage() {
  const { data: user } = useCurrentUser();

  return (
    <AppShell title="Mi cuenta">
      <div className="flex max-w-xl flex-col gap-6">
        {user && <FormularioNombre key={user.name} inicial={user.name} correo={user.email} />}
        <FormularioContrasena />
      </div>
    </AppShell>
  );
}

function FormularioNombre({ inicial, correo }: { inicial: string; correo: string }) {
  const actualizar = useActualizarCuenta();
  const [nombre, setNombre] = useState(inicial);

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Datos de la cuenta</CardTitle>
        <CardDescription>El correo y el rol los gestiona un administrador.</CardDescription>
      </CardHeader>
      <CardContent>
        <form
          className="flex flex-col gap-4"
          onSubmit={(e) => {
            e.preventDefault();
            actualizar.mutate(nombre.trim(), {
              onSuccess: () => toast.success('Nombre actualizado.'),
              onError: (err) => toast.error(mensajeApi(err, 'No se pudo actualizar el nombre.')),
            });
          }}
        >
          <div className="flex flex-col gap-2">
            <Label htmlFor="cuenta-correo">Correo electrónico</Label>
            <Input id="cuenta-correo" value={correo} disabled />
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="cuenta-nombre">Nombre</Label>
            <Input id="cuenta-nombre" required maxLength={255} value={nombre} onChange={(e) => setNombre(e.target.value)} />
          </div>
          <Button type="submit" className="self-start" disabled={actualizar.isPending || nombre.trim() === '' || nombre.trim() === inicial}>
            Guardar
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

function FormularioContrasena() {
  const cambiar = useCambiarContrasena();
  const [actual, setActual] = useState('');
  const [nueva, setNueva] = useState('');
  const [confirmacion, setConfirmacion] = useState('');

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Cambiar contraseña</CardTitle>
        <CardDescription>
          Al cambiarla se cierran tus otras sesiones abiertas. La recuperación por correo no está disponible todavía:
          si la olvidas, pide a un administrador que la restablezca.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form
          className="flex flex-col gap-4"
          onSubmit={(e) => {
            e.preventDefault();
            cambiar.mutate(
              { contrasena_actual: actual, contrasena: nueva, contrasena_confirmation: confirmacion },
              {
                onSuccess: () => {
                  toast.success('Contraseña cambiada.');
                  setActual('');
                  setNueva('');
                  setConfirmacion('');
                },
                onError: (err) => toast.error(mensajeApi(err, 'No se pudo cambiar la contraseña.')),
              },
            );
          }}
        >
          <div className="flex flex-col gap-2">
            <Label htmlFor="cuenta-actual">Contraseña actual</Label>
            <Input id="cuenta-actual" type="password" autoComplete="current-password" required value={actual} onChange={(e) => setActual(e.target.value)} />
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="cuenta-nueva">Nueva contraseña</Label>
            <Input id="cuenta-nueva" type="password" autoComplete="new-password" required minLength={12} value={nueva} onChange={(e) => setNueva(e.target.value)} />
            <p className="text-muted-foreground text-xs">Mínimo 12 caracteres, con letras y números.</p>
          </div>
          <div className="flex flex-col gap-2">
            <Label htmlFor="cuenta-confirmacion">Confirmar nueva contraseña</Label>
            <Input id="cuenta-confirmacion" type="password" autoComplete="new-password" required value={confirmacion} onChange={(e) => setConfirmacion(e.target.value)} />
          </div>
          <Button type="submit" className="self-start" disabled={cambiar.isPending}>
            {cambiar.isPending ? 'Guardando…' : 'Cambiar contraseña'}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
