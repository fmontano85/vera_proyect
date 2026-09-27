import { Link } from '@tanstack/react-router';
import { toast } from 'sonner';
import { EstadoMatchBadge } from '@/components/EstadoMatchBadge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { puedeResolver, useCurrentUser } from '@/features/auth/useAuth';
import { useResolverSancion } from '@/features/sanciones/useSanciones';
import { mensajeApi } from '@/lib/api';
import type { EstadoMatch, HallazgoSancion } from '@/types/api';

const NOMBRE_LISTA: Record<string, string> = {
  ofac_sdn: 'OFAC SDN',
  un_consolidated: 'ONU consolidada',
  eu: 'Unión Europea',
};

const DECISIONES: { estado: Exclude<EstadoMatch, 'pendiente'>; label: string; variant: 'destructive' | 'outline' }[] = [
  { estado: 'confirmado', label: 'Confirmar', variant: 'destructive' },
  { estado: 'falso_positivo', label: 'Falso positivo', variant: 'outline' },
  { estado: 'homonimo', label: 'Homónimo', variant: 'outline' },
];

/**
 * Hallazgo de sanciones. Es una PROPUESTA del sistema (coincidencia
 * aproximada de nombres): nada aqui afirma que la persona este sancionada
 * hasta que un oficial de cumplimiento lo confirma (seccion 1 del CLAUDE.md).
 */
export function SancionCard({ hallazgo, mostrarSubject = true }: { hallazgo: HallazgoSancion; mostrarSubject?: boolean }) {
  const { data: user } = useCurrentUser();
  const resolver = useResolverSancion();
  const { entrada } = hallazgo;

  function handleResolver(estado: Exclude<EstadoMatch, 'pendiente'>) {
    resolver.mutate(
      { id: hallazgo.id, estado },
      {
        onSuccess: () => toast.success('Hallazgo resuelto.'),
        onError: (e) => toast.error(mensajeApi(e, 'No se pudo resolver el hallazgo.')),
      },
    );
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div className="min-w-0">
          <CardTitle className="text-base">{entrada.nombre}</CardTitle>
          <div className="mt-2 flex flex-wrap gap-1.5">
            {entrada.lista && <Badge variant="outline">{NOMBRE_LISTA[entrada.lista] ?? entrada.lista}</Badge>}
            {entrada.programa && <Badge variant="outline">Programa {entrada.programa}</Badge>}
            {entrada.tipo && <Badge variant="outline" className="capitalize">{entrada.tipo}</Badge>}
            {entrada.pais && <Badge variant="outline">{entrada.pais}</Badge>}
          </div>
          {entrada.aliases.length > 0 && (
            <p className="text-muted-foreground mt-2 text-sm">También conocido como: {entrada.aliases.join('; ')}</p>
          )}
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1.5">
          <EstadoMatchBadge estado={hallazgo.estado} />
          <span className="text-muted-foreground text-xs">Similitud {hallazgo.score.toFixed(0)}%</span>
        </div>
      </CardHeader>
      <CardContent className="flex flex-col gap-3">
        {mostrarSubject && hallazgo.subject && (
          <p className="text-sm">
            Coincide con{' '}
            <Link to="/subjects/$subjectId" params={{ subjectId: String(hallazgo.subject.id) }} className="text-primary font-medium hover:underline">
              {hallazgo.subject.nombre_canonico}
            </Link>
          </p>
        )}

        {hallazgo.estado === 'pendiente' ? (
          puedeResolver(user) ? (
            <div className="flex flex-wrap gap-2">
              {DECISIONES.map((d) => (
                <Button key={d.estado} size="sm" variant={d.variant} disabled={resolver.isPending} onClick={() => handleResolver(d.estado)}>
                  {d.label}
                </Button>
              ))}
            </div>
          ) : (
            <p className="text-muted-foreground text-sm">Pendiente: lo resuelve el oficial de cumplimiento.</p>
          )
        ) : (
          <p className="text-muted-foreground text-sm">
            Resuelto por {hallazgo.resuelto_por ?? '—'}
            {hallazgo.resuelto_en && ` el ${new Date(hallazgo.resuelto_en).toLocaleDateString('es-SV', { timeZone: 'America/El_Salvador' })}`}.
          </p>
        )}
      </CardContent>
    </Card>
  );
}
