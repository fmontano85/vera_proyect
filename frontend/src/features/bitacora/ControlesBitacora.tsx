import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { etiquetaEvento } from '@/features/bitacora/etiquetas';
import type { FiltrosBitacora } from '@/types/bitacora';

/** Filtros comunes (evento y rango de fechas en dias de El Salvador) de las
 * dos bitacoras; `extra` agrega el selector propio de cada una (usuario o tenant). */
export function FiltrosBitacoraForm({
  filtros,
  eventos,
  onChange,
  extra,
}: {
  filtros: FiltrosBitacora;
  eventos: string[];
  onChange: (filtros: FiltrosBitacora) => void;
  extra?: ReactNode;
}) {
  const cambiar = (cambios: Partial<FiltrosBitacora>) => onChange({ ...filtros, ...cambios, page: 1 });
  const hayFiltros = Boolean(filtros.evento || filtros.desde || filtros.hasta || filtros.usuario_id || filtros.tenant_id);

  return (
    <div className="flex flex-wrap items-end gap-3">
      {extra}
      <div className="flex flex-col gap-1">
        <Label className="text-xs">Evento</Label>
        <Select value={filtros.evento ?? 'todos'} onValueChange={(v) => cambiar({ evento: v === 'todos' ? undefined : v })}>
          <SelectTrigger className="w-52" aria-label="Evento">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="todos">Todos los eventos</SelectItem>
            {eventos.map((e) => (
              <SelectItem key={e} value={e}>
                {etiquetaEvento(e)}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>
      <div className="flex flex-col gap-1">
        <Label htmlFor="bitacora-desde" className="text-xs">
          Desde
        </Label>
        <Input
          id="bitacora-desde"
          type="date"
          className="w-40"
          value={filtros.desde ?? ''}
          onChange={(e) => cambiar({ desde: e.target.value || undefined })}
        />
      </div>
      <div className="flex flex-col gap-1">
        <Label htmlFor="bitacora-hasta" className="text-xs">
          Hasta
        </Label>
        <Input
          id="bitacora-hasta"
          type="date"
          className="w-40"
          value={filtros.hasta ?? ''}
          min={filtros.desde}
          onChange={(e) => cambiar({ hasta: e.target.value || undefined })}
        />
      </div>
      {hayFiltros && (
        <Button variant="ghost" size="sm" onClick={() => onChange({ page: 1 })}>
          Limpiar filtros
        </Button>
      )}
    </div>
  );
}

export function PaginacionBitacora({
  pagina,
  ultima,
  onPagina,
}: {
  pagina: number;
  ultima: number;
  onPagina: (pagina: number) => void;
}) {
  if (ultima <= 1) return null;

  return (
    <div className="mt-3 flex items-center justify-between">
      <Button variant="outline" size="sm" disabled={pagina <= 1} onClick={() => onPagina(pagina - 1)}>
        Anterior
      </Button>
      <span className="text-muted-foreground text-xs">
        Página {pagina} de {ultima}
      </span>
      <Button variant="outline" size="sm" disabled={pagina >= ultima} onClick={() => onPagina(pagina + 1)}>
        Siguiente
      </Button>
    </div>
  );
}
