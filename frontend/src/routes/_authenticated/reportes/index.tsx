import { createFileRoute } from '@tanstack/react-router';
import { Download, FileBarChart, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { BuscadorSubjectAsync } from '@/features/consulta/BuscadorSubjectAsync';
import {
  NOMBRE_TIPO,
  type FormatoReporte,
  type Reporte,
  type TipoReporte,
  useReportes,
  useSolicitarReporte,
} from '@/features/reportes/useReportes';
import { api, mensajeApi } from '@/lib/api';
import { formatearFecha, formatearFechaHora, hoyEnElSalvador } from '@/lib/fechas';
import type { Subject } from '@/types/api';

export const Route = createFileRoute('/_authenticated/reportes/')({
  component: ReportesPage,
});

/**
 * Reportes de auditoria exportables (seccion 1, punto 5): todos los roles
 * del tenant los generan (seccion 3.2: lectura = "consulta y reportes").
 */
function ReportesPage() {
  return (
    <AppShell title="Reportes">
      <div className="flex max-w-4xl flex-col gap-6">
        <NuevoReporte />
        <ListaReportes />
      </div>
    </AppShell>
  );
}

const NIVELES = [
  { value: 'todos', label: 'Todos los niveles' },
  { value: 'alto', label: 'Riesgo alto' },
  { value: 'medio', label: 'Riesgo medio' },
  { value: 'bajo', label: 'Riesgo bajo' },
  { value: 'sin_nivel', label: 'Sin nivel asignado' },
];

function primerDiaDelMes(): string {
  return `${hoyEnElSalvador().slice(0, 8)}01`;
}

function NuevoReporte() {
  const solicitar = useSolicitarReporte();
  const [tipo, setTipo] = useState<TipoReporte>('ficha_persona');
  const [formato, setFormato] = useState<FormatoReporte>('pdf');
  const [persona, setPersona] = useState<Subject | null>(null);
  const [desde, setDesde] = useState(primerDiaDelMes);
  const [hasta, setHasta] = useState(hoyEnElSalvador);
  const [nivel, setNivel] = useState('todos');
  const [inactivas, setInactivas] = useState(false);

  const incompleto =
    (tipo === 'ficha_persona' && !persona) || (tipo === 'actividad_periodo' && (!desde || !hasta || hasta < desde));

  function generar(e: React.FormEvent) {
    e.preventDefault();
    solicitar.mutate(
      {
        tipo,
        formato,
        ...(tipo === 'ficha_persona' && { subject_id: persona!.id }),
        ...(tipo === 'actividad_periodo' && { desde, hasta }),
        ...(tipo === 'lista_por_riesgo' && { nivel_riesgo: nivel, incluir_inactivos: inactivas }),
      },
      {
        onSuccess: () => toast.success('Reporte en preparación. Aparecerá abajo cuando esté listo.'),
        onError: (error) => toast.error(mensajeApi(error, 'No se pudo solicitar el reporte.')),
      },
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Nuevo reporte</CardTitle>
        <CardDescription>
          Las coincidencias sin resolver aparecen como "Pendiente de resolución": un reporte nunca afirma que una persona
          está involucrada sin una resolución humana registrada.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={generar} className="flex flex-col gap-4">
          <div className="grid gap-4 sm:grid-cols-[1fr_12rem]">
            <div className="flex flex-col gap-2">
              <Label>Tipo de reporte</Label>
              <Select value={tipo} onValueChange={(v: TipoReporte) => setTipo(v)}>
                <SelectTrigger aria-label="Tipo de reporte">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {(Object.keys(NOMBRE_TIPO) as TipoReporte[]).map((t) => (
                    <SelectItem key={t} value={t}>
                      {NOMBRE_TIPO[t]}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="flex flex-col gap-2">
              <Label>Formato</Label>
              <Select value={formato} onValueChange={(v: FormatoReporte) => setFormato(v)}>
                <SelectTrigger aria-label="Formato">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="pdf">PDF (para presentar)</SelectItem>
                  <SelectItem value="csv">CSV (para Excel)</SelectItem>
                </SelectContent>
              </Select>
            </div>
          </div>

          {tipo === 'ficha_persona' && (
            <div className="flex flex-col gap-2">
              <Label>Persona</Label>
              <BuscadorSubjectAsync seleccionado={persona} onSeleccionar={setPersona} />
            </div>
          )}

          {tipo === 'actividad_periodo' && (
            <div className="flex flex-wrap gap-4">
              <div className="flex flex-col gap-2">
                <Label htmlFor="reporte-desde">Desde</Label>
                <Input id="reporte-desde" type="date" className="w-44" value={desde} max={hasta} onChange={(e) => setDesde(e.target.value)} />
              </div>
              <div className="flex flex-col gap-2">
                <Label htmlFor="reporte-hasta">Hasta</Label>
                <Input id="reporte-hasta" type="date" className="w-44" value={hasta} min={desde} onChange={(e) => setHasta(e.target.value)} />
              </div>
              <p className="text-muted-foreground self-end pb-2 text-xs">Máximo un año. Incluye coincidencias y sanciones resueltas, consultas y seguimientos.</p>
            </div>
          )}

          {tipo === 'lista_por_riesgo' && (
            <div className="flex flex-wrap items-end gap-4">
              <div className="flex flex-col gap-2">
                <Label>Nivel de riesgo</Label>
                <Select value={nivel} onValueChange={setNivel}>
                  <SelectTrigger className="w-52" aria-label="Nivel de riesgo">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {NIVELES.map((n) => (
                      <SelectItem key={n.value} value={n.value}>
                        {n.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <label className="flex items-center gap-2 pb-2 text-sm">
                <input type="checkbox" checked={inactivas} onChange={(e) => setInactivas(e.target.checked)} />
                Incluir personas inactivas
              </label>
            </div>
          )}

          <Button type="submit" className="self-start" disabled={incompleto || solicitar.isPending}>
            <FileBarChart />
            {solicitar.isPending ? 'Solicitando…' : 'Generar reporte'}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}

function describirParametros(r: Reporte): string {
  const p = r.parametros;
  if (r.tipo === 'actividad_periodo') return `${formatearFecha(String(p.desde))} al ${formatearFecha(String(p.hasta))}`;
  if (r.tipo === 'lista_por_riesgo') {
    const nivel = NIVELES.find((n) => n.value === p.nivel_riesgo)?.label ?? String(p.nivel_riesgo);
    return p.incluir_inactivos ? `${nivel}, con inactivas` : nivel;
  }
  return typeof p.nombre === 'string' ? p.nombre : `Persona #${String(p.subject_id)}`;
}

function EstadoBadge({ estado }: { estado: Reporte['estado'] }) {
  if (estado === 'listo') return <Badge variant="outline">Listo</Badge>;
  if (estado === 'fallido') return <Badge variant="destructive">Falló</Badge>;
  return (
    <Badge variant="outline" className="gap-1">
      <Loader2 className="size-3 animate-spin" />
      {estado === 'pendiente' ? 'En cola' : 'Generando'}
    </Badge>
  );
}

function ListaReportes() {
  const [page, setPage] = useState(1);
  const { data, isLoading } = useReportes(page);

  async function descargar(r: Reporte) {
    try {
      await api.download(`/api/reportes/${r.id}/descargar`);
    } catch (e) {
      toast.error(mensajeApi(e, 'No se pudo descargar el reporte.'));
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-base">Reportes generados</CardTitle>
        <CardDescription>De toda tu organización, los más recientes primero. Cada descarga queda en la bitácora.</CardDescription>
      </CardHeader>
      <CardContent>
        {isLoading && <Skeleton className="h-32 w-full" />}
        {data?.data.length === 0 && <p className="text-muted-foreground text-sm">Todavía no se ha generado ningún reporte.</p>}
        <ul className="divide-y">
          {data?.data.map((r) => (
            <li key={r.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
              <div className="min-w-0">
                <p className="flex items-center gap-2 font-medium">
                  {NOMBRE_TIPO[r.tipo]}
                  <Badge variant="outline" className="uppercase">{r.formato}</Badge>
                  <EstadoBadge estado={r.estado} />
                </p>
                <p className="text-muted-foreground text-sm">
                  {describirParametros(r)} · {r.generado_por ?? 'Usuario eliminado'} · {formatearFechaHora(r.solicitado_en)}
                </p>
                {r.estado === 'fallido' && r.error && <p className="text-destructive text-sm">{r.error}</p>}
              </div>
              {r.estado === 'listo' && (
                <Button size="sm" variant="outline" onClick={() => descargar(r)}>
                  <Download />
                  Descargar
                </Button>
              )}
            </li>
          ))}
        </ul>
        {data && data.last_page > 1 && (
          <div className="mt-3 flex items-center justify-between">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>
              Anterior
            </Button>
            <span className="text-muted-foreground text-xs">
              Página {data.current_page} de {data.last_page}
            </span>
            <Button variant="outline" size="sm" disabled={page >= data.last_page} onClick={() => setPage((p) => p + 1)}>
              Siguiente
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
