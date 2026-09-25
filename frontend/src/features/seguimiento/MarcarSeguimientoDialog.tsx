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
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useMarcarSeguimiento } from '@/features/seguimiento/useSeguimientos';
import { ApiError } from '@/lib/api';
import { formatearFecha } from '@/lib/fechas';

const MAX_OBSERVACION = 2000;

/**
 * Seccion 3.8: el cierre es manual y explicito - ejecutar la consulta
 * puntual NO cierra el seguimiento. La observacion queda en activity_log
 * (constancia ante revision de la UIF).
 */
export function MarcarSeguimientoDialog({
  subjectId,
  open,
  onOpenChange,
}: {
  subjectId: number;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}) {
  const marcar = useMarcarSeguimiento(subjectId);
  const [observacion, setObservacion] = useState('');

  function handleConfirmar() {
    marcar.mutate(observacion.trim() === '' ? null : observacion.trim(), {
      onSuccess: (subject) => {
        toast.success(
          `Seguimiento registrado. Próxima revisión: ${formatearFecha(subject.seguimiento?.proximo_seguimiento_en)}.`,
        );
        setObservacion('');
        onOpenChange(false);
      },
      onError: (error) =>
        toast.error(error instanceof ApiError ? error.message : 'No se pudo registrar el seguimiento.'),
    });
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Marcar seguimiento realizado</DialogTitle>
          <DialogDescription>
            Confirma que revisaste a esta persona. La próxima fecha se calcula desde hoy con su frecuencia de
            seguimiento. Queda registrado en la auditoría con tu usuario.
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-2">
          <Label htmlFor="observacion-seguimiento">Observación (opcional)</Label>
          <Textarea
            id="observacion-seguimiento"
            value={observacion}
            maxLength={MAX_OBSERVACION}
            onChange={(e) => setObservacion(e.target.value)}
            placeholder="Ej. Consulta puntual sin hallazgos nuevos."
            rows={4}
          />
          <p className="text-muted-foreground text-right text-xs">
            {observacion.length}/{MAX_OBSERVACION}
          </p>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={marcar.isPending}>
            Cancelar
          </Button>
          <Button onClick={handleConfirmar} disabled={marcar.isPending}>
            {marcar.isPending ? 'Registrando…' : 'Seguimiento realizado'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
