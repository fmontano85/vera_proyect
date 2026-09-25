import { CalendarCheck, Pencil } from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { puedeProponer, useCurrentUser } from '@/features/auth/useAuth';
import { EditarSeguimientoDialog } from '@/features/seguimiento/EditarSeguimientoDialog';
import { MarcarSeguimientoDialog } from '@/features/seguimiento/MarcarSeguimientoDialog';
import { diasEntre, formatearFecha, formatearFechaHora, hoyEnElSalvador } from '@/lib/fechas';
import type { Subject } from '@/types/api';

/**
 * Bloque de agenda de seguimiento en la ficha del subject (seccion 3.8).
 * Solo informa y registra el cierre manual - no dispara ninguna consulta
 * (la consulta puntual es el boton aparte, a criterio del usuario).
 */
export function SeguimientoCard({ subject }: { subject: Subject }) {
  const { data: user } = useCurrentUser();
  const [marcarAbierto, setMarcarAbierto] = useState(false);
  const [editarAbierto, setEditarAbierto] = useState(false);
  const seguimiento = subject.seguimiento;

  if (!seguimiento) return null;

  const puedeGestionar = puedeProponer(user);
  const proximo = seguimiento.proximo_seguimiento_en;
  const diasRestantes = proximo ? diasEntre(hoyEnElSalvador(), proximo) : null;

  return (
    <Card className="mb-6">
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="text-base">Seguimiento</CardTitle>
          <p className="text-muted-foreground mt-1 text-sm">
            Cada {seguimiento.frecuencia_dias} días{' '}
            {seguimiento.origen_frecuencia === 'personalizada' ? (
              <Badge variant="outline" className="ml-1">
                Personalizada
              </Badge>
            ) : (
              <span>(según nivel de riesgo)</span>
            )}
          </p>
        </div>
        {seguimiento.vencido && <Badge className="bg-vencido text-vencido-foreground">Vencido</Badge>}
      </CardHeader>

      <CardContent className="flex flex-col gap-4">
        <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-muted-foreground">Próxima revisión</dt>
            <dd className="font-medium">
              {formatearFecha(proximo)}
              {diasRestantes !== null && (
                <span className={seguimiento.vencido ? 'text-vencido ml-2' : 'text-muted-foreground ml-2'}>
                  {diasRestantes < 0
                    ? `(hace ${-diasRestantes} ${-diasRestantes === 1 ? 'día' : 'días'})`
                    : diasRestantes === 0
                      ? '(hoy)'
                      : `(en ${diasRestantes} ${diasRestantes === 1 ? 'día' : 'días'})`}
                </span>
              )}
            </dd>
          </div>
          <div>
            <dt className="text-muted-foreground">Último seguimiento</dt>
            <dd className="font-medium">
              {seguimiento.ultimo_seguimiento_en ? (
                <>
                  {formatearFechaHora(seguimiento.ultimo_seguimiento_en)}
                  {seguimiento.ultimo_seguimiento_por && (
                    <span className="text-muted-foreground font-normal"> · {seguimiento.ultimo_seguimiento_por.name}</span>
                  )}
                </>
              ) : (
                <span className="text-muted-foreground font-normal">Nunca</span>
              )}
            </dd>
          </div>
        </dl>

        {puedeGestionar && (
          <div className="flex flex-wrap gap-2">
            <Button onClick={() => setMarcarAbierto(true)}>
              <CalendarCheck />
              Seguimiento realizado
            </Button>
            <Button variant="outline" onClick={() => setEditarAbierto(true)}>
              <Pencil />
              Editar nivel y frecuencia
            </Button>
          </div>
        )}
      </CardContent>

      <MarcarSeguimientoDialog subjectId={subject.id} open={marcarAbierto} onOpenChange={setMarcarAbierto} />
      {/* Montado solo al abrir: el formulario se inicializa con los valores actuales del subject. */}
      {editarAbierto && <EditarSeguimientoDialog subject={subject} open onOpenChange={setEditarAbierto} />}
    </Card>
  );
}
