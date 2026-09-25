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
import type { Subject } from '@/types/api';

/**
 * Datos basicos del subject (nombre, tipo, documento). Cambiar el nombre
 * cambia a quien encuentra el matching: el backend reindexa y lo audita.
 * Nivel y frecuencia se editan en el bloque de seguimiento.
 */
export function DatosSubjectDialog({
  subject,
  onOpenChange,
}: {
  subject: Subject;
  onOpenChange: (open: boolean) => void;
}) {
  const actualizar = useActualizarSubject(subject.id);
  const [nombre, setNombre] = useState(subject.nombre_canonico);
  const [tipo, setTipo] = useState<Subject['tipo']>(subject.tipo);
  const [documento, setDocumento] = useState(subject.documento ?? '');

  function handleGuardar(e: React.FormEvent) {
    e.preventDefault();
    actualizar.mutate(
      { nombre_canonico: nombre.trim(), tipo, documento: documento.trim() === '' ? null : documento.trim() },
      {
        onSuccess: () => {
          toast.success('Datos actualizados.');
          onOpenChange(false);
        },
        onError: (error) =>
          toast.error(error instanceof ApiError ? error.message : 'No se pudieron guardar los datos.'),
      },
    );
  }

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent>
        <form onSubmit={handleGuardar}>
          <DialogHeader>
            <DialogTitle>Editar datos del sujeto</DialogTitle>
            <DialogDescription>
              Cambiar el nombre cambia a quién encuentra el cruce de coincidencias. Queda registrado en la auditoría.
            </DialogDescription>
          </DialogHeader>

          <div className="flex flex-col gap-4 py-4">
            <div className="flex flex-col gap-2">
              <Label htmlFor="editar-nombre">Nombre completo</Label>
              <Input id="editar-nombre" required maxLength={255} value={nombre} onChange={(e) => setNombre(e.target.value)} />
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="editar-tipo">Tipo</Label>
              <Select value={tipo} onValueChange={(v: Subject['tipo']) => setTipo(v)}>
                <SelectTrigger id="editar-tipo" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="natural">Persona natural</SelectItem>
                  <SelectItem value="juridica">Persona jurídica</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="flex flex-col gap-2">
              <Label htmlFor="editar-documento">Documento (opcional)</Label>
              <Input
                id="editar-documento"
                maxLength={255}
                value={documento}
                onChange={(e) => setDocumento(e.target.value)}
              />
            </div>
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={actualizar.isPending}>
              Cancelar
            </Button>
            <Button type="submit" disabled={actualizar.isPending || nombre.trim() === ''}>
              {actualizar.isPending ? 'Guardando…' : 'Guardar'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
