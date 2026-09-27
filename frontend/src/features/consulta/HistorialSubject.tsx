import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { api } from '@/lib/api';
import type { EventoHistorial, Paginated } from '@/types/api';

const ETIQUETAS: Record<string, string> = {
  tipo: 'Tipo',
  nombre_canonico: 'Nombre',
  documento: 'Documento',
  nivel_riesgo: 'Nivel de riesgo',
  activo: 'Estado',
  frecuencia_seguimiento_dias: 'Frecuencia de seguimiento (días)',
  estado: 'Estado de la coincidencia',
  propuesta_estado: 'Propuesta',
};

function formatearValor(clave: string, valor: unknown): string {
  if (valor === null || valor === undefined || valor === '') return '—';
  if (clave === 'activo') return valor ? 'Activo' : 'Inactivo';
  return String(valor);
}

/** Traduce un registro de activity_log a una frase legible. */
function describirEvento(e: EventoHistorial): string {
  const nuevo = e.cambios?.attributes ?? {};
  const viejo = e.cambios?.old ?? {};

  if (e.evento === 'seguimiento_realizado') {
    const obs = e.propiedades?.observacion;
    return typeof obs === 'string' && obs ? `Seguimiento realizado: «${obs}»` : 'Seguimiento realizado';
  }

  if (e.entidad === 'alias') {
    const nombre = String(nuevo.nombre ?? viejo.nombre ?? '');
    if (e.evento === 'created') return `Alias agregado: ${nombre}`;
    if (e.evento === 'deleted') return `Alias eliminado: ${nombre}`;
  }

  if (e.entidad === 'subject' && e.evento === 'created') return 'Persona dada de alta en la lista de vigilancia';

  if (e.evento === 'updated') {
    const partes = Object.keys(nuevo)
      .filter((k) => k in ETIQUETAS)
      .map((k) => `${ETIQUETAS[k]}: ${formatearValor(k, viejo[k])} → ${formatearValor(k, nuevo[k])}`);
    if (partes.length > 0) {
      return `${e.entidad === 'coincidencia' ? 'Coincidencia' : 'Datos'} modificados — ${partes.join('; ')}`;
    }
  }

  return e.descripcion ?? e.evento;
}

/** Historial de auditoria de la persona vigilada. Se carga solo al abrirlo:
 * no es lo primero que busca quien abre la ficha. */
export function HistorialSubject({ subjectId }: { subjectId: number }) {
  const [abierto, setAbierto] = useState(false);
  const [page, setPage] = useState(1);
  const { data, isLoading, isError } = useQuery({
    queryKey: ['subjects', subjectId, 'historial', page],
    queryFn: () => api.get<Paginated<EventoHistorial>>(`/api/subjects/${subjectId}/historial?page=${page}`),
    enabled: abierto,
    placeholderData: (previo) => previo,
  });

  return (
    <Card className="mt-6">
      <CardHeader className="flex flex-row items-center justify-between">
        <CardTitle className="text-lg">Historial de auditoría</CardTitle>
        <Button variant="outline" size="sm" onClick={() => setAbierto((v) => !v)}>
          {abierto ? 'Ocultar' : 'Ver historial'}
        </Button>
      </CardHeader>
      {abierto && (
        <CardContent>
          {isLoading && <Skeleton className="h-24 w-full" />}
          {isError && <p className="text-destructive text-sm">No se pudo cargar el historial.</p>}
          {data && data.data.length === 0 && (
            <p className="text-muted-foreground text-sm">Sin actividad registrada.</p>
          )}
          {data && data.data.length > 0 && (
            <ul className="divide-y">
              {data.data.map((e) => (
                <li key={e.id} className="py-2 text-sm">
                  <p>{describirEvento(e)}</p>
                  <p className="text-muted-foreground text-xs">
                    {new Date(e.creado_en).toLocaleString('es-SV', { timeZone: 'America/El_Salvador' })}
                    {' · '}
                    {e.usuario ?? 'Sistema'}
                  </p>
                </li>
              ))}
            </ul>
          )}
          {data && data.last_page > 1 && (
            <div className="mt-3 flex items-center justify-between">
              <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
                Anterior
              </Button>
              <span className="text-muted-foreground text-xs">
                Página {data.current_page} de {data.last_page}
              </span>
              <Button
                variant="outline"
                size="sm"
                disabled={page >= data.last_page}
                onClick={() => setPage((p) => p + 1)}
              >
                Siguiente
              </Button>
            </div>
          )}
        </CardContent>
      )}
    </Card>
  );
}
