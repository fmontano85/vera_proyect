import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { Download, Trash2 } from 'lucide-react';
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
import { api, mensajeApi } from '@/lib/api';
import type { Subject } from '@/types/api';

/**
 * Derechos de acceso y cancelacion (seccion 3.9, puntos 4 y 5): el admin
 * del tenant exporta todo lo que VERA tiene de la persona o la borra por
 * orden de su organizacion. El backend tambien exige el rol admin.
 */
export function ProteccionDatosSubject({ subject }: { subject: Subject }) {
  const [exportando, setExportando] = useState(false);
  const [borrarAbierto, setBorrarAbierto] = useState(false);

  async function exportar() {
    setExportando(true);
    try {
      await api.download(`/api/subjects/${subject.id}/exportar`);
      toast.success('Exportación descargada. Quedó registrada en la bitácora.');
    } catch (e) {
      toast.error(mensajeApi(e, 'No se pudo exportar.'));
    } finally {
      setExportando(false);
    }
  }

  return (
    <>
      <Button variant="outline" disabled={exportando} onClick={exportar}>
        <Download />
        {exportando ? 'Exportando…' : 'Exportar datos'}
      </Button>
      <Button variant="ghost" className="text-destructive hover:text-destructive" onClick={() => setBorrarAbierto(true)}>
        <Trash2 />
        Borrar persona
      </Button>
      {borrarAbierto && <BorrarSubjectDialog subject={subject} onOpenChange={setBorrarAbierto} />}
    </>
  );
}

function BorrarSubjectDialog({ subject, onOpenChange }: { subject: Subject; onOpenChange: (abierto: boolean) => void }) {
  const [confirmacion, setConfirmacion] = useState('');
  const queryClient = useQueryClient();
  const navigate = useNavigate();
  const borrar = useMutation({
    mutationFn: () => api.delete<void>(`/api/subjects/${subject.id}`, { confirmacion }),
    onSuccess: () => {
      queryClient.removeQueries({ queryKey: ['subjects', subject.id] });
      queryClient.invalidateQueries({ queryKey: ['subjects'] });
      toast.success('Persona y sus datos eliminados.');
      navigate({ to: '/subjects' });
    },
    onError: (e) => toast.error(mensajeApi(e, 'No se pudo borrar.')),
  });
  const coincide = confirmacion.trim().toLocaleLowerCase('es') === subject.nombre_canonico.trim().toLocaleLowerCase('es');

  return (
    <Dialog open onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Borrar a esta persona y todos sus datos</DialogTitle>
          <DialogDescription>
            Se borran de forma permanente sus datos, aliases, resultados de búsqueda, coincidencias, hallazgos de
            sanciones y la evidencia capturada a mano. La bitácora conserva quién hizo qué, pero sin sus datos
            personales. <span className="text-foreground font-medium">No se puede deshacer.</span> Si necesitas
            conservar una copia, exporta sus datos antes.
          </DialogDescription>
        </DialogHeader>
        <div className="flex flex-col gap-2 py-2">
          <Label htmlFor="confirmar-borrado">
            Escribe <span className="font-semibold">{subject.nombre_canonico}</span> para confirmar
          </Label>
          <Input
            id="confirmar-borrado"
            autoComplete="off"
            value={confirmacion}
            onChange={(e) => setConfirmacion(e.target.value)}
          />
        </div>
        <DialogFooter>
          <Button variant="outline" disabled={borrar.isPending} onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
          <Button variant="destructive" disabled={!coincide || borrar.isPending} onClick={() => borrar.mutate()}>
            {borrar.isPending ? 'Borrando…' : 'Borrar definitivamente'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
