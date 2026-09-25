import { createFileRoute, Link } from '@tanstack/react-router';
import { AlertTriangle, CalendarClock, CalendarRange, Inbox, Scale } from 'lucide-react';
import type { ReactNode } from 'react';
import { AppShell } from '@/components/layout/AppShell';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { useCurrentUser } from '@/features/auth/useAuth';
import { useResumenInicio } from '@/features/coincidencias/useMatches';

export const Route = createFileRoute('/_authenticated/')({
  component: InicioPage,
});

/**
 * Inicio (dashboard): lo que requiere accion en el tenant, cada contador
 * con acceso directo a su bandeja o panel. Colores segun MASTER.md:
 * coincidencia pendiente = --warning, seguimiento vencido = --vencido,
 * GAP = --gap. En cero se muestran neutros (nada que hacer).
 */
function InicioPage() {
  const { data: user } = useCurrentUser();
  const { data: resumen, isLoading } = useResumenInicio();

  return (
    <AppShell title="Inicio">
      <p className="text-muted-foreground mb-4 text-sm">
        {user ? `Hola, ${user.name}. ` : ''}Esto es lo que requiere atención en tu lista de vigilancia.
      </p>

      {isLoading && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 5 }).map((_, i) => (
            <Skeleton key={i} className="h-32" />
          ))}
        </div>
      )}

      {resumen && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Contador
            titulo="Coincidencias sin revisar"
            descripcion="Esperan una propuesta del analista."
            valor={resumen.coincidencias_sin_propuesta}
            color="text-warning"
            icono={<Inbox />}
            enlace={
              <Link to="/coincidencias" search={{ bandeja: 'sin_propuesta', pagina: 1 }} className="text-primary text-sm hover:underline">
                Ver bandeja
              </Link>
            }
          />
          <Contador
            titulo="Esperan resolución"
            descripcion="Tienen propuesta; las resuelve el oficial de cumplimiento."
            valor={resumen.coincidencias_esperan_resolucion}
            color="text-warning"
            icono={<Scale />}
            enlace={
              <Link
                to="/coincidencias"
                search={{ bandeja: 'esperan_resolucion', pagina: 1 }}
                className="text-primary text-sm hover:underline"
              >
                Ver bandeja
              </Link>
            }
          />
          <Contador
            titulo="Seguimientos vencidos"
            descripcion="Personas a las que ya les tocaba revisión."
            valor={resumen.seguimientos_vencidos}
            color="text-vencido"
            icono={<CalendarClock />}
            enlace={
              <Link to="/seguimientos" className="text-primary text-sm hover:underline">
                Ver seguimientos
              </Link>
            }
          />
          <Contador
            titulo="Vencen en 7 días"
            descripcion="Seguimientos que se acercan."
            valor={resumen.seguimientos_proximos_7_dias}
            color="text-foreground"
            icono={<CalendarRange />}
            enlace={
              <Link to="/seguimientos" className="text-primary text-sm hover:underline">
                Ver seguimientos
              </Link>
            }
          />
          <Contador
            titulo="Resultados en GAP"
            descripcion="Noticias que no se pudieron leer automáticamente; admiten reintento o captura manual desde la ficha de cada persona."
            valor={resumen.resultados_gap}
            color="text-gap"
            icono={<AlertTriangle />}
          />
        </div>
      )}
    </AppShell>
  );
}

function Contador({
  titulo,
  descripcion,
  valor,
  color,
  icono,
  enlace,
}: {
  titulo: string;
  descripcion: string;
  valor: number;
  color: string;
  icono: ReactNode;
  enlace?: ReactNode;
}) {
  return (
    <Card>
      <CardHeader className="flex flex-row items-center justify-between gap-2 pb-2">
        <CardTitle className="text-sm font-medium">{titulo}</CardTitle>
        <span className="text-muted-foreground [&_svg]:size-4">{icono}</span>
      </CardHeader>
      <CardContent className="flex flex-col gap-1">
        <span className={`text-3xl font-semibold ${valor > 0 ? color : 'text-muted-foreground'}`}>{valor}</span>
        <p className="text-muted-foreground text-xs">{descripcion}</p>
        {enlace && valor > 0 && <div className="mt-1">{enlace}</div>}
      </CardContent>
    </Card>
  );
}
