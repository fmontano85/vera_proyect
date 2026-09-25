import { createFileRoute } from '@tanstack/react-router';
import { useState } from 'react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { esAdmin, useCurrentUser } from '@/features/auth/useAuth';
import { useActualizarFrecuencias, useFrecuencias } from '@/features/seguimiento/useSeguimientos';
import { ApiError } from '@/lib/api';
import type { FrecuenciasSeguimiento, NivelFrecuencia } from '@/types/api';

export const Route = createFileRoute('/_authenticated/configuracion/')({
  component: ConfiguracionPage,
});

const NIVELES: { nivel: NivelFrecuencia; label: string }[] = [
  { nivel: 'alto', label: 'Riesgo alto' },
  { nivel: 'medio', label: 'Riesgo medio' },
  { nivel: 'bajo', label: 'Riesgo bajo' },
  { nivel: 'sin_nivel', label: 'Sin nivel asignado' },
];

/**
 * Configuracion del tenant (seccion 3.2/3.8): dias de seguimiento por
 * nivel de riesgo. Todos los roles la ven; solo admin la cambia (el
 * backend responde 403 a los demas - ocultar el boton no es el control
 * de acceso, solo evita ofrecer una accion que seria rechazada).
 */
function ConfiguracionPage() {
  const { data: user } = useCurrentUser();
  const { data: frecuencias, isLoading } = useFrecuencias();

  return (
    <AppShell title="Configuración">
      <Card className="max-w-xl">
        <CardHeader>
          <CardTitle className="text-base">Frecuencia de seguimiento por nivel de riesgo</CardTitle>
          <CardDescription>
            Días entre revisiones de cada persona de la lista de vigilancia. Aplica a quienes no tienen una frecuencia
            personalizada; al guardar se recalcula su próxima fecha de revisión. El cambio queda en la auditoría.
          </CardDescription>
        </CardHeader>
        <CardContent>
          {isLoading && <Skeleton className="h-48 w-full" />}
          {/* key: remonta el formulario si los valores cambian en el servidor. */}
          {frecuencias && (
            <FormularioFrecuencias key={JSON.stringify(frecuencias)} inicial={frecuencias} editable={esAdmin(user)} />
          )}
        </CardContent>
      </Card>
    </AppShell>
  );
}

function FormularioFrecuencias({ inicial, editable }: { inicial: FrecuenciasSeguimiento; editable: boolean }) {
  const actualizar = useActualizarFrecuencias();
  const [valores, setValores] = useState<Record<NivelFrecuencia, string>>({
    alto: String(inicial.alto),
    medio: String(inicial.medio),
    bajo: String(inicial.bajo),
    sin_nivel: String(inicial.sin_nivel),
  });

  const invalido = (valor: string) => {
    const n = Number(valor);
    return valor.trim() === '' || !Number.isInteger(n) || n < 1 || n > 365;
  };
  const hayInvalidos = NIVELES.some(({ nivel }) => invalido(valores[nivel]));

  function handleGuardar(e: React.FormEvent) {
    e.preventDefault();
    actualizar.mutate(
      {
        alto: Number(valores.alto),
        medio: Number(valores.medio),
        bajo: Number(valores.bajo),
        sin_nivel: Number(valores.sin_nivel),
      },
      {
        onSuccess: () => toast.success('Frecuencias actualizadas. Se recalcularon las próximas fechas de revisión.'),
        onError: (error) =>
          toast.error(error instanceof ApiError ? error.message : 'No se pudieron guardar las frecuencias.'),
      },
    );
  }

  return (
    <form onSubmit={handleGuardar} className="flex flex-col gap-4">
      {NIVELES.map(({ nivel, label }) => (
        <div key={nivel} className="grid grid-cols-[1fr_8rem] items-center gap-3">
          <Label htmlFor={`frecuencia-${nivel}`}>{label}</Label>
          <div className="flex items-center gap-2">
            <Input
              id={`frecuencia-${nivel}`}
              type="number"
              inputMode="numeric"
              min={1}
              max={365}
              value={valores[nivel]}
              disabled={!editable}
              aria-invalid={invalido(valores[nivel])}
              onChange={(e) => setValores((v) => ({ ...v, [nivel]: e.target.value }))}
            />
            <span className="text-muted-foreground text-sm">días</span>
          </div>
        </div>
      ))}

      {hayInvalidos && <p className="text-destructive text-sm">Cada valor debe ser un número entero entre 1 y 365.</p>}

      {editable ? (
        <Button type="submit" className="self-start" disabled={actualizar.isPending || hayInvalidos}>
          {actualizar.isPending ? 'Guardando…' : 'Guardar'}
        </Button>
      ) : (
        <p className="text-muted-foreground text-sm">Solo un administrador del tenant puede cambiar estos valores.</p>
      )}
    </form>
  );
}
