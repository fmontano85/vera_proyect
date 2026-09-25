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
import { useActualizarSubject } from '@/features/seguimiento/useSeguimientos';
import { ApiError } from '@/lib/api';
import type { Subject } from '@/types/api';

/**
 * Desactivar/reactivar (solo oficial_cumplimiento y admin - decision del
 * usuario 2026-09-25). Desactivar saca a la persona del cruce de
 * coincidencias y de la agenda de seguimiento; se confirma explicitamente.
 */
export function EstadoSubjectDialog({
  subject,
  onOpenChange,
}: {
  subject: Subject;
  onOpenChange: (open: boolean) => void;
}) {
  const actualizar = useActualizarSubject(subject.id);
  const desactivar = subject.activo;

  function handleConfirmar() {
    actualizar.mutate(
      { activo: !subject.activo },
      {
        onSuccess: () => {
          toast.success(desactivar ? 'Sujeto desactivado.' : 'Sujeto reactivado.');
          onOpenChange(false);
        },
        onError: (error) =>
          toast.error(error instanceof ApiError ? error.message : 'No se pudo cambiar el estado.'),
      },
    );
  }

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{desactivar ? 'Desactivar sujeto' : 'Reactivar sujeto'}</DialogTitle>
          <DialogDescription>
            {desactivar
              ? `${subject.nombre_canonico} dejará de aparecer en el cruce de coincidencias y en la agenda de seguimiento. Su historial se conserva y puedes reactivarlo cuando quieras.`
              : `${subject.nombre_canonico} vuelve al cruce de coincidencias y a la agenda de seguimiento.`}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)} disabled={actualizar.isPending}>
            Cancelar
          </Button>
          <Button variant={desactivar ? 'destructive' : 'default'} onClick={handleConfirmar} disabled={actualizar.isPending}>
            {actualizar.isPending ? 'Guardando…' : desactivar ? 'Desactivar' : 'Reactivar'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
