import { createFileRoute } from '@tanstack/react-router';
import { Check, Pencil, X } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { puedeResolver, useCurrentUser } from '@/features/auth/useAuth';
import { useActualizarTag, useCrearSearchTag, useTagsGestion } from '@/features/resultados/useSearchTags';
import { ApiError } from '@/lib/api';
import type { SearchTag } from '@/types/api';

export const Route = createFileRoute('/_authenticated/tags/')({
  component: TagsPage,
});

/**
 * Catalogo de tags del tenant. Renombrar/desactivar cambia la busqueda por
 * tags de todo el equipo, por eso solo admin y oficial_cumplimiento (el
 * backend responde 403 al resto; ocultar la pantalla no es el control).
 */
function TagsPage() {
  const { data: user } = useCurrentUser();
  const { data: tags, isLoading } = useTagsGestion();
  const crear = useCrearSearchTag();
  const [nuevo, setNuevo] = useState('');

  if (user && !puedeResolver(user)) {
    return (
      <AppShell title="Catálogo de tags">
        <p className="text-muted-foreground text-sm">No tienes permiso para gestionar el catálogo de tags.</p>
      </AppShell>
    );
  }

  function handleCrear() {
    const nombre = nuevo.trim();
    if (!nombre) return;
    crear.mutate(nombre, {
      onSuccess: () => {
        setNuevo('');
        toast.success('Tag agregado.');
      },
      onError: (e) => toast.error(e instanceof ApiError ? e.message : 'No se pudo agregar el tag.'),
    });
  }

  return (
    <AppShell title="Catálogo de tags">
      <Card className="max-w-2xl">
        <CardHeader>
          <CardTitle className="text-base">Tags de búsqueda del tenant</CardTitle>
          <CardDescription>
            Los tags inactivos dejan de ofrecerse en la búsqueda por tags; los resultados ya obtenidos no se pierden.
            Los cambios quedan en la auditoría.
          </CardDescription>
        </CardHeader>
        <CardContent className="flex flex-col gap-4">
          <form
            className="flex gap-2"
            onSubmit={(e) => {
              e.preventDefault();
              handleCrear();
            }}
          >
            <Input value={nuevo} onChange={(e) => setNuevo(e.target.value)} placeholder="Nuevo tag" maxLength={255} />
            <Button type="submit" disabled={crear.isPending || !nuevo.trim()}>
              Agregar
            </Button>
          </form>

          {isLoading && <Skeleton className="h-40 w-full" />}
          <ul className="divide-y">
            {tags?.map((tag) => <TagFila key={tag.id} tag={tag} />)}
          </ul>
        </CardContent>
      </Card>
    </AppShell>
  );
}

function TagFila({ tag }: { tag: SearchTag }) {
  const actualizar = useActualizarTag();
  const [editando, setEditando] = useState(false);
  const [nombre, setNombre] = useState(tag.nombre);

  function guardar() {
    const valor = nombre.trim();
    if (!valor || valor === tag.nombre) return setEditando(false);
    actualizar.mutate(
      { id: tag.id, nombre: valor },
      {
        onSuccess: () => {
          setEditando(false);
          toast.success('Tag renombrado.');
        },
        onError: (e) => toast.error(e instanceof ApiError ? e.message : 'No se pudo renombrar.'),
      },
    );
  }

  function alternarActivo() {
    actualizar.mutate(
      { id: tag.id, activo: !tag.activo },
      { onError: (e) => toast.error(e instanceof ApiError ? e.message : 'No se pudo cambiar el estado.') },
    );
  }

  return (
    <li className="flex items-center justify-between gap-3 py-2">
      {editando ? (
        <div className="flex flex-1 items-center gap-2">
          <Input value={nombre} onChange={(e) => setNombre(e.target.value)} maxLength={255} autoFocus />
          <Button size="icon" variant="outline" aria-label="Guardar" disabled={actualizar.isPending} onClick={guardar}>
            <Check />
          </Button>
          <Button
            size="icon"
            variant="ghost"
            aria-label="Cancelar"
            onClick={() => {
              setNombre(tag.nombre);
              setEditando(false);
            }}
          >
            <X />
          </Button>
        </div>
      ) : (
        <div className="flex items-center gap-2">
          <span className={tag.activo ? undefined : 'text-muted-foreground line-through'}>{tag.nombre}</span>
          {!tag.activo && <Badge variant="outline">Inactivo</Badge>}
        </div>
      )}
      {!editando && (
        <div className="flex gap-2">
          <Button size="sm" variant="ghost" onClick={() => setEditando(true)}>
            <Pencil />
            Renombrar
          </Button>
          <Button size="sm" variant="outline" disabled={actualizar.isPending} onClick={alternarActivo}>
            {tag.activo ? 'Desactivar' : 'Activar'}
          </Button>
        </div>
      )}
    </li>
  );
}
