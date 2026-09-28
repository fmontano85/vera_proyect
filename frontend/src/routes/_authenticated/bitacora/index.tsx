import { createFileRoute } from '@tanstack/react-router';
import { useState } from 'react';
import { AppShell } from '@/components/layout/AppShell';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { esAdmin, useCurrentUser } from '@/features/auth/useAuth';
import { FiltrosBitacoraForm, PaginacionBitacora } from '@/features/bitacora/ControlesBitacora';
import { etiquetaEvento, etiquetaObjeto } from '@/features/bitacora/etiquetas';
import { useBitacoraTenant, useEventosBitacora } from '@/features/bitacora/useBitacora';
import { useUsuarios } from '@/features/usuarios/useUsuarios';
import { formatearFechaHora } from '@/lib/fechas';
import type { FiltrosBitacora, RegistroBitacora } from '@/types/bitacora';

export const Route = createFileRoute('/_authenticated/bitacora/')({
  component: BitacoraPage,
});

/** Bitacora del tenant (seccion 3.9, punto 7): solo el admin, completa. */
function BitacoraPage() {
  const { data: yo } = useCurrentUser();

  if (yo && !esAdmin(yo)) {
    return (
      <AppShell title="Bitácora">
        <p className="text-muted-foreground text-sm">Solo un administrador puede consultar la bitácora.</p>
      </AppShell>
    );
  }

  return (
    <AppShell title="Bitácora">
      <BitacoraTenant />
    </AppShell>
  );
}

function BitacoraTenant() {
  const [filtros, setFiltros] = useState<FiltrosBitacora>({ page: 1 });
  const { data, isLoading, isError } = useBitacoraTenant(filtros);
  const { data: eventos } = useEventosBitacora('tenant');
  const { data: usuarios } = useUsuarios();

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Registro de actividad del tenant</CardTitle>
        <CardDescription>
          Quién hizo qué y cuándo: cambios, consultas a Brave, extracciones, descargas de evidencia e inicios de sesión.
          No se registra la simple apertura de una ficha.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <FiltrosBitacoraForm
          filtros={filtros}
          eventos={eventos ?? []}
          onChange={setFiltros}
          extra={
            <div className="flex flex-col gap-1">
              <Label className="text-xs">Usuario</Label>
              <Select
                value={filtros.usuario_id ? String(filtros.usuario_id) : 'todos'}
                onValueChange={(v) => setFiltros({ ...filtros, usuario_id: v === 'todos' ? undefined : Number(v), page: 1 })}
              >
                <SelectTrigger className="w-52" aria-label="Usuario">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="todos">Todos los usuarios</SelectItem>
                  {usuarios?.map((u) => (
                    <SelectItem key={u.id} value={String(u.id)}>
                      {u.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          }
        />

        {isLoading && <Skeleton className="h-40 w-full" />}
        {isError && <p className="text-destructive text-sm">No se pudo cargar la bitácora.</p>}
        {data && data.data.length === 0 && <p className="text-muted-foreground text-sm">Sin registros para estos filtros.</p>}
        {data && data.data.length > 0 && (
          <ul className="divide-y">
            {data.data.map((r) => (
              <FilaBitacora key={r.id} registro={r} />
            ))}
          </ul>
        )}
        {data && (
          <PaginacionBitacora
            pagina={data.current_page}
            ultima={data.last_page}
            onPagina={(page) => setFiltros({ ...filtros, page })}
          />
        )}
      </CardContent>
    </Card>
  );
}

function valor(v: unknown): string {
  if (v === null || v === undefined || v === '') return '—';
  if (typeof v === 'object') return JSON.stringify(v);
  return String(v);
}

/** LogsActivity guarda 'created'/'updated'/'deleted' como descripcion y los
 * registros de acceso repiten el nombre del evento: no aportan nada. */
function mostrarDescripcion(r: RegistroBitacora): boolean {
  return r.descripcion !== r.evento && r.descripcion !== etiquetaEvento(r.evento);
}

function FilaBitacora({ registro: r }: { registro: RegistroBitacora }) {
  const nuevos = r.cambios?.attributes ?? {};
  const viejos = r.cambios?.old ?? {};
  const claves = [...new Set([...Object.keys(nuevos), ...Object.keys(viejos)])];
  const propiedades = Object.entries(r.propiedades ?? {});

  return (
    <li className="py-2 text-sm">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <p>
          <span className="font-medium">{etiquetaEvento(r.evento)}</span>
          <span className="text-muted-foreground"> · {etiquetaObjeto(r.objeto)}</span>
        </p>
        <p className="text-muted-foreground text-xs">
          {formatearFechaHora(r.fecha)} · {r.usuario?.name ?? 'Sistema'}
        </p>
      </div>
      {mostrarDescripcion(r) && <p className="text-muted-foreground">{r.descripcion}</p>}
      {(claves.length > 0 || propiedades.length > 0) && (
        <details className="mt-1">
          <summary className="text-primary cursor-pointer text-xs">Ver detalle</summary>
          <dl className="bg-muted mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 rounded-md p-2 text-xs break-all">
            {claves.map((k) => (
              <div key={`c-${k}`} className="contents">
                <dt className="font-medium">{k}</dt>
                <dd>{k in viejos ? `${valor(viejos[k])} → ${valor(nuevos[k])}` : valor(nuevos[k])}</dd>
              </div>
            ))}
            {propiedades.map(([k, v]) => (
              <div key={`p-${k}`} className="contents">
                <dt className="font-medium">{k}</dt>
                <dd>{valor(v)}</dd>
              </div>
            ))}
          </dl>
        </details>
      )}
    </li>
  );
}
