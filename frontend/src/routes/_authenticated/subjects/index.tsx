import { createFileRoute, Link, useNavigate } from '@tanstack/react-router';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { NivelRiesgoBadge } from '@/components/NivelRiesgoBadge';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
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

export const Route = createFileRoute('/_authenticated/subjects/')({
  component: SubjectsPage,
});

function SubjectsPage() {
  const { data: subjects, isLoading } = useSubjects();
  const { data: user } = useCurrentUser();
  const [open, setOpen] = useState(false);

  return (
    <AppShell title="Sujetos">
      <div className="mb-4 flex items-center justify-between">
        <p className="text-muted-foreground text-sm">
          Personas naturales o jurídicas bajo consulta o vigilancia.
        </p>

        {puedeProponer(user) && (
          <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
              <Button>
                <Plus />
                Nuevo sujeto
              </Button>
            </DialogTrigger>
            <NuevoSubjectDialog onCreated={() => setOpen(false)} />
          </Dialog>
        )}
      </div>

      <div className="bg-card overflow-x-auto rounded-lg border">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Nombre</TableHead>
              <TableHead>Tipo</TableHead>
              <TableHead>Documento</TableHead>
              <TableHead>Nivel de riesgo</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading &&
              Array.from({ length: 4 }).map((_, i) => (
                <TableRow key={i}>
                  <TableCell colSpan={4}>
                    <Skeleton className="h-6 w-full" />
                  </TableCell>
                </TableRow>
              ))}

            {!isLoading && subjects?.data.length === 0 && (
              <TableRow>
                <TableCell colSpan={4} className="text-muted-foreground py-8 text-center">
                  Todavía no hay sujetos. Crea el primero con "Nuevo sujeto".
                </TableCell>
              </TableRow>
            )}

            {subjects?.data.map((subject) => (
              <TableRow key={subject.id} className="cursor-pointer">
                <TableCell className="font-medium">
                  <Link
                    to="/subjects/$subjectId"
                    params={{ subjectId: String(subject.id) }}
                    className="hover:underline"
                  >
                    {subject.nombre_canonico}
                  </Link>
                </TableCell>
                <TableCell className="capitalize">{subject.tipo}</TableCell>
                <TableCell>{subject.documento ?? '—'}</TableCell>
                <TableCell>
                  <NivelRiesgoBadge nivel={subject.nivel_riesgo} />
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
    </AppShell>
  );
}

function NuevoSubjectDialog({ onCreated }: { onCreated: () => void }) {
  const navigate = useNavigate();
  const createSubject = useCreateSubject();
  const [form, setForm] = useState<NuevoSubject>({ tipo: 'natural', nombre_canonico: '' });

  function handleSubmit(event: React.FormEvent) {
    event.preventDefault();

    createSubject.mutate(form, {
      onSuccess: (subject) => {
        onCreated();
        navigate({ to: '/subjects/$subjectId', params: { subjectId: String(subject.id) } });
      },
      onError: (error) => {
        toast.error(
          error instanceof ApiError ? error.message : 'No se pudo crear el sujeto.',
        );
      },
    });
  }

  return (
    <DialogContent>
      <form onSubmit={handleSubmit}>
        <DialogHeader>
          <DialogTitle>Nuevo sujeto</DialogTitle>
        </DialogHeader>

        <div className="flex flex-col gap-4 py-4">
          <div className="flex flex-col gap-2">
            <Label htmlFor="nombre_canonico">Nombre completo</Label>
            <Input
              id="nombre_canonico"
              required
              value={form.nombre_canonico}
              onChange={(e) => setForm({ ...form, nombre_canonico: e.target.value })}
            />
          </div>

          <div className="flex flex-col gap-2">
            <Label>Tipo</Label>
            <Select
              value={form.tipo}
              onValueChange={(tipo: NuevoSubject['tipo']) => setForm({ ...form, tipo })}
            >
              <SelectTrigger className="w-full">
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
              value={form.documento ?? ''}
              onChange={(e) => setForm({ ...form, documento: e.target.value })}
            />
          </div>

          <div className="flex flex-col gap-2">
            <Label>Nivel de riesgo (opcional)</Label>
            <Select
              value={form.nivel_riesgo}
              onValueChange={(nivel_riesgo: NonNullable<NuevoSubject['nivel_riesgo']>) =>
                setForm({ ...form, nivel_riesgo })
              }
            >
              <SelectTrigger className="w-full">
                <SelectValue placeholder="Sin evaluar" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="bajo">Bajo</SelectItem>
                <SelectItem value="medio">Medio</SelectItem>
                <SelectItem value="alto">Alto</SelectItem>
              </SelectContent>
            </Select>
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
