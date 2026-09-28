import { useState } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { FiltrosBitacoraForm, PaginacionBitacora } from '@/features/bitacora/ControlesBitacora';
import { etiquetaEvento, etiquetaObjeto } from '@/features/bitacora/etiquetas';
import { useBitacoraGlobal, useEventosBitacora } from '@/features/bitacora/useBitacora';
import { useTenantsSuperadmin } from '@/features/superadmin/useSuperadmin';
import { formatearFechaHora } from '@/lib/fechas';
import type { FiltrosBitacora } from '@/types/bitacora';

/** El UUID completo satura la fila: un tenant sin nombre se identifica por su prefijo. */
function nombreTenant(tenant: { id: string; name: string | null } | null): string {
  if (!tenant) return 'Sin tenant';
  return tenant.name ?? `Sin nombre (${tenant.id.slice(0, 8)})`;
}

/**
 * Bitacora de todos los tenants para el superadmin, sin datos personales
 * (opcion A, 2026-09-28): el backend nunca envia nombres de personas
 * vigiladas, descripciones ni cambios - solo quien, que, cuando y el id.
 */
export function BitacoraGlobal() {
  const [filtros, setFiltros] = useState<FiltrosBitacora>({ page: 1 });
  const { data, isLoading, isError } = useBitacoraGlobal(filtros);
  const { data: eventos } = useEventosBitacora('global');
  const { data: tenants } = useTenantsSuperadmin();

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Bitácora de todos los tenants</CardTitle>
        <CardDescription>
          Quién hizo qué y cuándo. Por protección de datos no se muestran nombres ni datos de las personas vigiladas:
          solo su número interno.
        </CardDescription>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <FiltrosBitacoraForm
          filtros={filtros}
          eventos={eventos ?? []}
          onChange={setFiltros}
          extra={
            <div className="flex flex-col gap-1">
              <Label className="text-xs">Tenant</Label>
              <Select
                value={filtros.tenant_id ?? 'todos'}
                onValueChange={(v) => setFiltros({ ...filtros, tenant_id: v === 'todos' ? undefined : v, page: 1 })}
              >
                <SelectTrigger className="w-52" aria-label="Tenant">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="todos">Todos los tenants</SelectItem>
                  {tenants?.map((t) => (
                    <SelectItem key={t.id} value={t.id}>
                      {nombreTenant(t)}
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
              <li key={r.id} className="flex flex-wrap items-baseline justify-between gap-2 py-2 text-sm">
                <p>
                  <span className="font-medium">{etiquetaEvento(r.evento)}</span>
                  <span className="text-muted-foreground"> · {etiquetaObjeto(r.objeto)}</span>
                </p>
                <p className="text-muted-foreground text-xs">
                  {nombreTenant(r.tenant)} · {formatearFechaHora(r.fecha)} ·{' '}
                  {r.usuario?.name ?? 'Sistema'}
                </p>
              </li>
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
