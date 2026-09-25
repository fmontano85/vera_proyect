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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useActualizarSubject } from '@/features/seguimiento/useSeguimientos';
import { ApiError } from '@/lib/api';
import type { NivelRiesgo, Subject } from '@/types/api';

/** Radix Select no admite value="" - centinela para "sin nivel". */
const SIN_NIVEL = 'sin_nivel';

const NIVELES: { value: NivelRiesgo | typeof SIN_NIVEL; label: string }[] = [
  { value: 'alto', label: 'Alto' },
  { value: 'medio', label: 'Medio' },
  { value: 'bajo', label: 'Bajo' },
  { value: SIN_NIVEL, label: 'Sin evaluar' },
];

/**
 * Seccion 3.8: nivel de riesgo y frecuencia personalizada. Frecuencia
 * vacia = usar el default del tenant para el nivel. Rango 1..365 (sin
 * piso regulatorio UIF todavia). Ambos cambios quedan auditados y
 * recalculan la proxima fecha en el backend.
 */
export function EditarSeguimientoDialog({
  subject,
  open,
  onOpenChange,
}: {
  subject: Subject;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const actualizar = useActualizarSubject(subject.id);
  const [nivel, setNivel] = useState<string>(subject.nivel_riesgo ?? SIN_NIVEL);
  const [frecuencia, setFrecuencia] = useState<string>(
    subject.frecuencia_seguimiento_dias != null ? String(subject.frecuencia_seguimiento_dias) : '',
  );

  const frecuenciaNumero = frecuencia.trim() === '' ? null : Number(frecuencia);
  const frecuenciaInvalida =
    frecuenciaNumero !== null && (!Number.isInteger(frecuenciaNumero) || frecuenciaNumero < 1 || frecuenciaNumero > 365);

  function handleGuardar() {
    actualizar.mutate(
      {
        nivel_riesgo: nivel === SIN_NIVEL ? null : (nivel as NivelRiesgo),
        frecuencia_seguimiento_dias: frecuenciaNumero,
      },
      {
        onSuccess: () => {
          toast.success('Seguimiento actualizado.');
          onOpenChange(false);
        },
        onError: (error) =>
          toast.error(error instanceof ApiError ? error.message : 'No se pudo actualizar el seguimiento.'),
      },
    );
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Editar seguimiento</DialogTitle>
          <DialogDescription>
            El cambio recalcula la próxima fecha de revisión y queda registrado en la auditoría.
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4">
          <div className="flex flex-col gap-2">
            <Label htmlFor="nivel-riesgo">Nivel de riesgo</Label>
            <Select value={nivel} onValueChange={setNivel}>
              <SelectTrigger id="nivel-riesgo" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {NIVELES.map((n) => (
                  <SelectItem key={n.value} value={n.value}>
                    {n.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="frecuencia">Frecuencia personalizada (días)</Label>
            <Input
              id="frecuencia"
              type="number"
              inputMode="numeric"
              min={1}
              max={365}
              value={frecuencia}
              onChange={(e) => setFrecuencia(e.target.value)}
              placeholder="Vacío = usar el default del nivel"
              aria-invalid={frecuenciaInvalida}
            />
            {frecuenciaInvalida ? (
              <p className="text-destructive text-sm">Debe ser un número entero entre 1 y 365.</p>
            ) : (
              <p className="text-muted-foreground text-sm">Déjalo vacío para usar los días configurados para el nivel.</p>
            )}
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={actualizar.isPending}>
            Cancelar
          </Button>
          <Button onClick={handleGuardar} disabled={actualizar.isPending || frecuenciaInvalida}>
            {actualizar.isPending ? 'Guardando…' : 'Guardar'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
