import { createFileRoute, Link, useNavigate } from '@tanstack/react-router';
import { Plus, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ChipsInput } from '@/components/ChipsInput';
import { AppShell } from '@/components/layout/AppShell';
import { NivelRiesgoBadge } from '@/components/NivelRiesgoBadge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
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
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useCurrentUser, puedeProponer } from '@/features/auth/useAuth';
import { useCreateSubject, useSubjects, type NuevoSubject } from '@/features/consulta/useSubjects';
import { ApiError } from '@/lib/api';
import { formatearFecha } from '@/lib/fechas';
import type { FiltrosSubjects } from '@/types/api';

export const Route = createFileRoute('/_authenticated/subjects/')({
  component: SubjectsPage,
});

const TODOS = 'todos';

/** Lista de vigilancia: busqueda por nombre o alias, filtros y paginacion. */
function SubjectsPage() {
  const { data: user } = useCurrentUser();
  const [open, setOpen] = useState(false);
  const [texto, setTexto] = useState('');
  const [filtros, setFiltros] = useState<FiltrosSubjects>({ estado: 'activos', page: 1 });
  const { data: subjects, isLoading, isFetching } = useSubjects(filtros);

  // La busqueda espera a que el usuario deje de escribir.
  useEffect(() => {
    const t = setTimeout(() => setFiltros((f) => (f.buscar === texto ? f : { ...f, buscar: texto, page: 1 })), 300);
    return () => clearTimeout(t);
  }, [texto]);

  const hayFiltros = (filtros.buscar ?? '') !== '' || filtros.nivel !== undefined || filtros.estado !== 'activos';

  return (
    <AppShell title="Lista de vigilancia">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p className="text-muted-foreground text-sm">Personas naturales o jurídicas bajo consulta o vigilancia.</p>

        {puedeProponer(user) && (
          <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
              <Button>
                <Plus />
                Nuevo sujeto
              </Button>
            </DialogTrigger>
            {open && <NuevoSubjectDialog onCreated={() => setOpen(false)} />}
          </Dialog>
        )}
      </div>

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <div className="relative w-full sm:w-72">
          <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2" />
          <Input
            aria-label="Buscar por nombre o alias"
            placeholder="Buscar por nombre o alias"
            className="pl-8"
            value={texto}
            onChange={(e) => setTexto(e.target.value)}
          />
        </div>

        <Select
          value={filtros.nivel ?? TODOS}
          onValueChange={(v) =>
            setFiltros((f) => ({ ...f, nivel: v === TODOS ? undefined : (v as FiltrosSubjects['nivel']), page: 1 }))
          }
        >
          <SelectTrigger className="w-44" aria-label="Nivel de riesgo">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value={TODOS}>Todos los niveles</SelectItem>
            <SelectItem value="alto">Riesgo alto</SelectItem>
            <SelectItem value="medio">Riesgo medio</SelectItem>
            <SelectItem value="bajo">Riesgo bajo</SelectItem>
            <SelectItem value="sin_nivel">Sin evaluar</SelectItem>
          </SelectContent>
        </Select>

        <Select
          value={filtros.estado ?? 'activos'}
          onValueChange={(v) => setFiltros((f) => ({ ...f, estado: v as FiltrosSubjects['estado'], page: 1 }))}
        >
          <SelectTrigger className="w-36" aria-label="Estado">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="activos">Activos</SelectItem>
            <SelectItem value="inactivos">Inactivos</SelectItem>
            <SelectItem value="todos">Todos</SelectItem>
          </SelectContent>
        </Select>

        {hayFiltros && (
          <Button
            variant="ghost"
            size="sm"
            onClick={() => {
              setTexto('');
              setFiltros({ estado: 'activos', page: 1 });
            }}
          >
            Limpiar filtros
          </Button>
        )}
      </div>

      <div className={`bg-card overflow-x-auto rounded-lg border ${isFetching && !isLoading ? 'opacity-70' : ''}`}>
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Nombre</TableHead>
              <TableHead>Tipo</TableHead>
              <TableHead>Documento</TableHead>
              <TableHead>Nivel de riesgo</TableHead>
              <TableHead>Aliases</TableHead>
              <TableHead>Próxima revisión</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading &&
              Array.from({ length: 4 }).map((_, i) => (
                <TableRow key={i}>
                  <TableCell colSpan={6}>
                    <Skeleton className="h-6 w-full" />
                  </TableCell>
                </TableRow>
              ))}

            {!isLoading && subjects?.data.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="text-muted-foreground py-8 text-center">
                  {hayFiltros
                    ? 'Ningún sujeto coincide con la búsqueda o los filtros.'
                    : 'Todavía no hay sujetos. Crea el primero con "Nuevo sujeto".'}
                </TableCell>
              </TableRow>
            )}

            {subjects?.data.map((subject) => (
              <TableRow key={subject.id} className={subject.activo ? undefined : 'opacity-60'}>
                <TableCell className="font-medium">
                  <Link to="/subjects/$subjectId" params={{ subjectId: String(subject.id) }} className="hover:underline">
                    {subject.nombre_canonico}
                  </Link>
                  {!subject.activo && (
                    <Badge variant="outline" className="ml-2">
                      Inactivo
                    </Badge>
                  )}
                </TableCell>
                <TableCell className="capitalize">{subject.tipo === 'juridica' ? 'jurídica' : subject.tipo}</TableCell>
                <TableCell>{subject.documento ?? '—'}</TableCell>
                <TableCell>
                  <NivelRiesgoBadge nivel={subject.nivel_riesgo} />
                </TableCell>
                <TableCell>{subject.aliases_count ?? 0}</TableCell>
                <TableCell>
                  {subject.activo ? (
                    <span className={subject.seguimiento?.vencido ? 'text-vencido font-medium' : undefined}>
                      {formatearFecha(subject.seguimiento?.proximo_seguimiento_en)}
                      {subject.seguimiento?.vencido && ' · vencido'}
                    </span>
                  ) : (
                    <span className="text-muted-foreground">—</span>
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>

      {subjects && subjects.last_page > 1 && (
        <div className="mt-3 flex items-center justify-between gap-3">
          <p className="text-muted-foreground text-sm">
            Página {subjects.current_page} de {subjects.last_page} · {subjects.total} sujetos
          </p>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={(filtros.page ?? 1) <= 1}
              onClick={() => setFiltros((f) => ({ ...f, page: (f.page ?? 1) - 1 }))}
            >
              Anterior
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={(filtros.page ?? 1) >= subjects.last_page}
              onClick={() => setFiltros((f) => ({ ...f, page: (f.page ?? 1) + 1 }))}
            >
              Siguiente
            </Button>
          </div>
        </div>
      )}
    </AppShell>
  );
}

function NuevoSubjectDialog({ onCreated }: { onCreated: () => void }) {
  const navigate = useNavigate();
  const createSubject = useCreateSubject();
  const [form, setForm] = useState<NuevoSubject>({ tipo: 'natural', nombre_canonico: '', aliases: [] });

  function handleSubmit(event: React.FormEvent) {
    event.preventDefault();

    createSubject.mutate(form, {
      onSuccess: (subject) => {
        onCreated();
        navigate({ to: '/subjects/$subjectId', params: { subjectId: String(subject.id) } });
      },
      onError: (error) => {
        toast.error(error instanceof ApiError ? error.message : 'No se pudo crear el sujeto.');
      },
    });
  }

  return (
    <DialogContent>
      <form onSubmit={handleSubmit}>
        <DialogHeader>
          <DialogTitle>Nuevo sujeto</DialogTitle>
          <DialogDescription>Queda en la lista de vigilancia con su agenda de seguimiento.</DialogDescription>
        </DialogHeader>

        <div className="flex flex-col gap-4 py-4">
          <div className="flex flex-col gap-2">
            <Label htmlFor="nombre_canonico">Nombre completo</Label>
            <Input
              id="nombre_canonico"
              required
              maxLength={255}
              value={form.nombre_canonico}
              onChange={(e) => setForm({ ...form, nombre_canonico: e.target.value })}
            />
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="tipo">Tipo</Label>
            <Select value={form.tipo} onValueChange={(tipo: NuevoSubject['tipo']) => setForm({ ...form, tipo })}>
              <SelectTrigger id="tipo" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="natural">Persona natural</SelectItem>
                <SelectItem value="juridica">Persona jurídica</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="documento">Documento (opcional)</Label>
            <Input
              id="documento"
              maxLength={255}
              value={form.documento ?? ''}
              onChange={(e) => setForm({ ...form, documento: e.target.value })}
            />
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="nivel">Nivel de riesgo (opcional)</Label>
            <Select
              value={form.nivel_riesgo}
              onValueChange={(nivel_riesgo: NonNullable<NuevoSubject['nivel_riesgo']>) => setForm({ ...form, nivel_riesgo })}
            >
              <SelectTrigger id="nivel" className="w-full">
                <SelectValue placeholder="Sin evaluar" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="bajo">Bajo</SelectItem>
                <SelectItem value="medio">Medio</SelectItem>
                <SelectItem value="alto">Alto</SelectItem>
              </SelectContent>
            </Select>
          </div>

          <div className="flex flex-col gap-2">
            <Label htmlFor="aliases">Aliases (opcional)</Label>
            <ChipsInput
              id="aliases"
              value={form.aliases ?? []}
              onChange={(aliases) => setForm({ ...form, aliases })}
              placeholder="Otro nombre con el que aparece. Enter para agregar"
            />
            <p className="text-muted-foreground text-xs">
              La búsqueda de noticias y el cruce de coincidencias también usan los aliases.
            </p>
          </div>
        </div>

        <DialogFooter>
          <Button type="submit" disabled={createSubject.isPending}>
            {createSubject.isPending ? 'Creando…' : 'Crear'}
          </Button>
        </DialogFooter>
      </form>
    </DialogContent>
  );
}
