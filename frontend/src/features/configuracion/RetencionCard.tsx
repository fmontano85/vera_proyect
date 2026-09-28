import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { api, mensajeApi } from '@/lib/api';

interface Retencion {
  retencion_anios: number;
  retencion_minima_anios: number;
  depuracion_habilitada: boolean;
}

const CLAVE = ['configuracion', 'retencion'] as const;

/**
 * Conservacion de datos del tenant (seccion 3.9, punto 2): anios que se
 * guardan los datos de una persona desde que se desactiva. La cambia solo
 * el admin, nunca por debajo del minimo legal (15 anios, Art. 26 Decreto
 * 426). Si la depuracion automatica corre lo decide el superadmin.
 */
export function RetencionCard({ editable }: { editable: boolean }) {
  const { data, isLoading } = useQuery({ queryKey: CLAVE, queryFn: () => api.get<Retencion>('/api/configuracion/retencion') });

  return (
    <Card className="max-w-xl">
      <CardHeader>
        <CardTitle className="text-base">Conservación de datos</CardTitle>
        <CardDescription>
          Años que se conservan los datos de una persona desde que se desactiva en la lista de vigilancia. La ley exige
          un mínimo de 15 años (Art. 26, Ley Contra el Lavado de Dinero y de Activos).
        </CardDescription>
      </CardHeader>
      <CardContent>
        {isLoading && <Skeleton className="h-24 w-full" />}
        {data && <FormularioRetencion key={data.retencion_anios} inicial={data} editable={editable} />}
      </CardContent>
    </Card>
  );
}

function FormularioRetencion({ inicial, editable }: { inicial: Retencion; editable: boolean }) {
  const [anios, setAnios] = useState(String(inicial.retencion_anios));
  const queryClient = useQueryClient();
  const guardar = useMutation({
    mutationFn: (retencion_anios: number) => api.put<Retencion>('/api/configuracion/retencion', { retencion_anios }),
    onSuccess: (r) => {
      queryClient.setQueryData(CLAVE, r);
      toast.success('Plazo de conservación actualizado.');
    },
    onError: (e) => toast.error(mensajeApi(e, 'No se pudo guardar el plazo.')),
  });
  const n = Number(anios);
  const invalido = !Number.isInteger(n) || n < inicial.retencion_minima_anios || n > 100;

  return (
    <form
      className="flex flex-col gap-4"
      onSubmit={(e) => {
        e.preventDefault();
        guardar.mutate(n);
      }}
    >
      <div className="grid grid-cols-[1fr_8rem] items-center gap-3">
        <Label htmlFor="retencion-anios">Plazo de conservación</Label>
        <div className="flex items-center gap-2">
          <Input
            id="retencion-anios"
            type="number"
            inputMode="numeric"
            min={inicial.retencion_minima_anios}
            max={100}
            value={anios}
            disabled={!editable}
            aria-invalid={invalido}
            onChange={(e) => setAnios(e.target.value)}
          />
          <span className="text-muted-foreground text-sm">años</span>
        </div>
      </div>
      {invalido && (
        <p className="text-destructive text-sm">Debe ser un número entero entre {inicial.retencion_minima_anios} y 100.</p>
      )}

      <div className="flex items-start gap-2 text-sm">
        <span className="text-muted-foreground">Depuración automática al vencer el plazo:</span>
        <Badge variant="outline">{inicial.depuracion_habilitada ? 'Habilitada' : 'Deshabilitada'}</Badge>
      </div>
      <p className="text-muted-foreground -mt-2 text-xs">
        La habilita o deshabilita el administrador de la plataforma. Las personas activas nunca se depuran.
      </p>

      {editable ? (
        <Button type="submit" className="self-start" disabled={guardar.isPending || invalido || n === inicial.retencion_anios}>
          {guardar.isPending ? 'Guardando…' : 'Guardar'}
        </Button>
      ) : (
        <p className="text-muted-foreground text-sm">Solo un administrador del tenant puede cambiar el plazo.</p>
      )}
    </form>
  );
}
