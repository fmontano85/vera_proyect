import type { QueryKey } from '@tanstack/react-query';
import { useState } from 'react';
import { X } from 'lucide-react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
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
import { Textarea } from '@/components/ui/textarea';
import { useSubjects } from '@/features/consulta/useSubjects';
import { useCapturaManual } from '@/features/resultados/useSearchResults';
import { ApiError } from '@/lib/api';
import type { RolMencion, SearchResult } from '@/types/api';

const ESTADO_RESOLUCION_OPCIONES = [
  { value: 'confirmado', label: 'Confirmado' },
  { value: 'falso_positivo', label: 'Falso positivo' },
  { value: 'homonimo', label: 'Homónimo' },
] as const;

const ROL_OPCIONES: { value: RolMencion; label: string }[] = [
  { value: 'imputado', label: 'Imputado' },
  { value: 'condenado', label: 'Condenado' },
  { value: 'victima', label: 'Víctima' },
  { value: 'testigo', label: 'Testigo' },
  { value: 'otro', label: 'Otro' },
];

interface CapturaManualDialogProps {
  resultado: SearchResult;
  queryKey: QueryKey;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

/** Si el resultado no tiene subject propio (vino de una busqueda por
 * tags, seccion posterior a la 3.7), el formulario tiene que preguntar a
 * que persona vigilada se le atribuye - sin eso no hay a quien resolverle
 * el match (CapturaManualRequest lo exige del lado del backend). */
export function CapturaManualDialog({
  resultado,
  queryKey,
  open,
  onOpenChange,
}: CapturaManualDialogProps) {
  const capturar = useCapturaManual(queryKey);
  const necesitaElegirSubject = resultado.subject_id === null;
  const { data: subjects } = useSubjects();

  const [nombre, setNombre] = useState('');
  const [rol, setRol] = useState<RolMencion | ''>('');
  const [delitos, setDelitos] = useState<string[]>([]);
  const [delitoActual, setDelitoActual] = useState('');
  const [fechaHecho, setFechaHecho] = useState('');
  const [resumen, setResumen] = useState('');
  const [estadoResolucion, setEstadoResolucion] = useState<
    (typeof ESTADO_RESOLUCION_OPCIONES)[number]['value'] | ''
  >('');
  const [subjectId, setSubjectId] = useState<string>('');
  const [pdf, setPdf] = useState<File | null>(null);

  function limpiar() {
    setNombre('');
    setRol('');
    setDelitos([]);
    setDelitoActual('');
    setFechaHecho('');
    setResumen('');
    setEstadoResolucion('');
    setSubjectId('');
    setPdf(null);
  }

  function agregarDelito() {
    const valor = delitoActual.trim();
    if (!valor || delitos.includes(valor)) return;
    setDelitos([...delitos, valor]);
    setDelitoActual('');
  }

  function quitarDelito(delito: string) {
    setDelitos(delitos.filter((d) => d !== delito));
  }

  const formularioValido =
    nombre.trim() !== '' &&
    rol !== '' &&
    delitos.length > 0 &&
    estadoResolucion !== '' &&
    pdf !== null &&
    (!necesitaElegirSubject || subjectId !== '');

  function enviar() {
    if (!formularioValido || !rol || !estadoResolucion || !pdf) return;

    capturar.mutate(
      {
        resultadoId: resultado.id,
        datos: {
          nombre_como_aparece: nombre.trim(),
          rol,
          delitos,
          fecha_hecho: fechaHecho || undefined,
          resumen: resumen.trim() || undefined,
          estado_resolucion: estadoResolucion,
          subject_id: subjectId ? Number(subjectId) : undefined,
          pdf,
        },
      },
      {
        onSuccess: () => {
          toast.success('Captura manual guardada. El resultado queda resuelto.');
          limpiar();
          onOpenChange(false);
        },
        onError: (error) => {
          toast.error(error instanceof ApiError ? error.message : 'No se pudo guardar la captura.');
        },
      },
    );
  }

  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) limpiar();
        onOpenChange(next);
      }}
    >
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>Captura manual</DialogTitle>
          <DialogDescription>
            El fetch automático falló para este resultado (GAP). Ingresa los datos a mano a partir del
            PDF de la página — esto deja el resultado ya resuelto, sin pasar por proponer/resolver.
          </DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4">
          <div className="grid gap-1.5">
            <Label htmlFor="nombre_como_aparece">Nombre como aparece en la noticia</Label>
            <Input
              id="nombre_como_aparece"
              value={nombre}
              onChange={(e) => setNombre(e.target.value)}
            />
          </div>

          <div className="grid gap-1.5">
            <Label>Rol</Label>
            <Select value={rol} onValueChange={(v: RolMencion) => setRol(v)}>
              <SelectTrigger>
                <SelectValue placeholder="Elegir rol" />
              </SelectTrigger>
              <SelectContent>
                {ROL_OPCIONES.map((opcion) => (
                  <SelectItem key={opcion.value} value={opcion.value}>
                    {opcion.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="delito">Delitos (mínimo 1)</Label>
            <div className="flex gap-2">
              <Input
                id="delito"
                value={delitoActual}
                onChange={(e) => setDelitoActual(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    e.preventDefault();
                    agregarDelito();
                  }
                }}
                placeholder="ej. homicidio simple"
              />
              <Button type="button" variant="outline" onClick={agregarDelito}>
                Agregar
              </Button>
            </div>
            {delitos.length > 0 && (
              <div className="flex flex-wrap gap-1.5">
                {delitos.map((delito) => (
                  <Badge key={delito} variant="outline" className="gap-1 pr-1">
                    {delito}
                    <button
                      type="button"
                      onClick={() => quitarDelito(delito)}
                      className="hover:text-destructive"
                      aria-label={`Quitar ${delito}`}
                    >
                      <X className="size-3" />
                    </button>
                  </Badge>
                ))}
              </div>
            )}
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="fecha_hecho">Fecha del hecho (opcional)</Label>
            <Input
              id="fecha_hecho"
              type="date"
              value={fechaHecho}
              onChange={(e) => setFechaHecho(e.target.value)}
            />
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="resumen">Resumen (opcional)</Label>
            <Textarea id="resumen" value={resumen} onChange={(e) => setResumen(e.target.value)} />
          </div>

          {necesitaElegirSubject && (
            <div className="grid gap-1.5">
              <Label>Persona vigilada a la que se atribuye el hallazgo</Label>
              <Select value={subjectId} onValueChange={setSubjectId}>
                <SelectTrigger>
                  <SelectValue placeholder="Elegir de la lista de vigilancia" />
                </SelectTrigger>
                <SelectContent>
                  {subjects?.data.map((subject) => (
                    <SelectItem key={subject.id} value={String(subject.id)}>
                      {subject.nombre_canonico}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          )}

          <div className="grid gap-1.5">
            <Label>Resolución</Label>
            <Select
              value={estadoResolucion}
              onValueChange={(v: (typeof ESTADO_RESOLUCION_OPCIONES)[number]['value']) =>
                setEstadoResolucion(v)
              }
            >
              <SelectTrigger>
                <SelectValue placeholder="Elegir resolución" />
              </SelectTrigger>
              <SelectContent>
                {ESTADO_RESOLUCION_OPCIONES.map((opcion) => (
                  <SelectItem key={opcion.value} value={opcion.value}>
                    {opcion.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

          <div className="grid gap-1.5">
            <Label htmlFor="pdf">Evidencia en PDF (obligatorio, máx. 10MB)</Label>
            <Input
              id="pdf"
              type="file"
              accept="application/pdf"
              onChange={(e) => setPdf(e.target.files?.[0] ?? null)}
            />
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
          <Button disabled={!formularioValido || capturar.isPending} onClick={enviar}>
            {capturar.isPending ? 'Guardando…' : 'Guardar captura'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
