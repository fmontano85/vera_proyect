import { createFileRoute, Link } from '@tanstack/react-router';
import { useState } from 'react';
import { AppShell } from '@/components/layout/AppShell';
import { NivelRiesgoBadge } from '@/components/NivelRiesgoBadge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { type FiltroSeguimientos, useSeguimientos } from '@/features/seguimiento/useSeguimientos';
import { diasEntre, formatearFecha, formatearFechaHora, hoyEnElSalvador } from '@/lib/fechas';

export const Route = createFileRoute('/_authenticated/seguimientos/')({
  component: SeguimientosPage,
});

/**
 * Panel de seguimientos pendientes (seccion 3.8, Fase 2) - destino del
 * enlace del correo diario. Ordenado por el backend: vencimiento y, a
 * igual fecha, nivel de riesgo (alto primero). La revision es manual:
 * desde aqui solo se navega a la ficha de cada persona.
 */
function SeguimientosPage() {
  const [filtro, setFiltro] = useState<FiltroSeguimientos>('vencidos');
  const [pagina, setPagina] = useState(1);
  const { data, isLoading } = useSeguimientos(filtro, pagina);
  const subjects = data?.data ?? [];
  const hoy = hoyEnElSalvador();

  return (
    <AppShell title="Seguimientos">
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p className="text-muted-foreground max-w-2xl text-sm">
          Personas de la lista de vigilancia a las que les corresponde revisión. Abre cada una, ejecuta la consulta
          puntual si lo consideras necesario y marca el seguimiento como realizado.
        </p>
        <Tabs
          value={filtro}
          onValueChange={(v) => {
            setFiltro(v as FiltroSeguimientos);
            setPagina(1);
          }}
        >
          <TabsList>
            <TabsTrigger value="vencidos">Vencidos</TabsTrigger>
            <TabsTrigger value="proximos">Próximos 30 días</TabsTrigger>
          </TabsList>
        </Tabs>
      </div>

      {isLoading && <Skeleton className="h-40 w-full" />}

      {!isLoading && subjects.length === 0 && (
        <Card>
          <CardContent className="text-muted-foreground py-10 text-center text-sm">
            {filtro === 'vencidos'
              ? 'No hay seguimientos vencidos. La lista de vigilancia está al día.'
              : 'No hay seguimientos que venzan en los próximos 30 días.'}
          </CardContent>
        </Card>
      )}

      {!isLoading && subjects.length > 0 && (
        <Card>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Persona</TableHead>
                  <TableHead>Nivel de riesgo</TableHead>
                  <TableHead>Vence</TableHead>
                  <TableHead>Frecuencia</TableHead>
                  <TableHead>Último seguimiento</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {subjects.map((subject) => {
                  const seguimiento = subject.seguimiento;
                  const proximo = seguimiento?.proximo_seguimiento_en ?? null;
                  const dias = proximo ? diasEntre(hoy, proximo) : null;

                  return (
                    <TableRow key={subject.id}>
                      <TableCell>
                        <Link
                          to="/subjects/$subjectId"
                          params={{ subjectId: String(subject.id) }}
                          className="text-primary font-medium hover:underline"
                        >
                          {subject.nombre_canonico}
                        </Link>
                      </TableCell>
                      <TableCell>
                        <NivelRiesgoBadge nivel={subject.nivel_riesgo} />
                      </TableCell>
                      <TableCell>
                        <div className="flex items-center gap-2">
                          <span>{formatearFecha(proximo)}</span>
                          {dias !== null && dias < 0 && (
                            <Badge className="bg-vencido text-vencido-foreground">
                              {-dias} {-dias === 1 ? 'día' : 'días'} de atraso
                            </Badge>
                          )}
                          {dias === 0 && <Badge className="bg-vencido text-vencido-foreground">Hoy</Badge>}
                          {dias !== null && dias > 0 && (
                            <span className="text-muted-foreground text-sm">en {dias} {dias === 1 ? 'día' : 'días'}</span>
                          )}
                        </div>
                      </TableCell>
                      <TableCell className="text-sm">
                        {seguimiento ? `${seguimiento.frecuencia_dias} días` : '—'}
                        {seguimiento?.origen_frecuencia === 'personalizada' && (
                          <Badge variant="outline" className="ml-2">
                            Personalizada
                          </Badge>
                        )}
                      </TableCell>
                      <TableCell className="text-sm">
                        {seguimiento?.ultimo_seguimiento_en ? (
                          <>
                            {formatearFechaHora(seguimiento.ultimo_seguimiento_en)}
                            {seguimiento.ultimo_seguimiento_por && (
                              <span className="text-muted-foreground"> · {seguimiento.ultimo_seguimiento_por.name}</span>
                            )}
                          </>
                        ) : (
                          <span className="text-muted-foreground">Nunca</span>
                        )}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {data && data.last_page > 1 && (
        <div className="mt-3 flex items-center justify-between gap-3">
          <p className="text-muted-foreground text-sm">
            Página {data.current_page} de {data.last_page} · {data.total} en total. Los más urgentes aparecen primero.
          </p>
          <div className="flex gap-2">
            <Button variant="outline" size="sm" disabled={pagina <= 1} onClick={() => setPagina((p) => p - 1)}>
              Anterior
            </Button>
            <Button
              variant="outline"
              size="sm"
              disabled={pagina >= data.last_page}
              onClick={() => setPagina((p) => p + 1)}
            >
              Siguiente
            </Button>
          </div>
        </div>
      )}
    </AppShell>
  );
}
