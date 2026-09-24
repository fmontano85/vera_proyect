import { ExternalLink, Loader2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { EstadoMatchBadge } from '@/components/EstadoMatchBadge';
import { EstadoSearchResultBadge } from '@/components/EstadoSearchResultBadge';
import { GapMotivoBadge } from '@/components/GapMotivoBadge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { puedeProponer, puedeResolver, useCurrentUser } from '@/features/auth/useAuth';
import { useProponer, useResolver } from '@/features/coincidencias/useMatches';
import { useDescartar, useExtraer } from '@/features/resultados/useSearchResults';
import { CapturaManualDialog } from '@/features/resultados/CapturaManualDialog';
import { ApiError } from '@/lib/api';
import type { EstadoMatch, Mention, SearchResult } from '@/types/api';

export function ResultadoCard({
  resultado,
  subjectId,
}: {
  resultado: SearchResult;
  subjectId: number;
}) {
  const { data: user } = useCurrentUser();
  const extraer = useExtraer(subjectId);
  const descartar = useDescartar(subjectId);
  const [capturaAbierta, setCapturaAbierta] = useState(false);

  function handleExtraer() {
    extraer.mutate(resultado.id, {
      onError: (error) =>
        toast.error(error instanceof ApiError ? error.message : 'No se pudo iniciar la extracción.'),
    });
  }

  function handleDescartar() {
    descartar.mutate(resultado.id, {
      onSuccess: () => toast.success('Resultado descartado.'),
      onError: (error) =>
        toast.error(error instanceof ApiError ? error.message : 'No se pudo descartar.'),
    });
  }

  const esDescartado = resultado.estado === 'descartado';

  return (
    <Card className={esDescartado ? 'opacity-60' : undefined}>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div className="min-w-0">
          <CardTitle className="text-base">{resultado.titulo ?? resultado.url}</CardTitle>
          <a
            href={resultado.url}
            target="_blank"
            rel="noreferrer"
            className="text-primary mt-1 flex items-center gap-1.5 text-sm hover:underline"
          >
            <ExternalLink className="size-3.5 shrink-0" />
            <span className="truncate">
              {resultado.medio ?? resultado.url}
              {resultado.fecha_brave && ` · ${new Date(resultado.fecha_brave).toLocaleDateString('es-SV')}`}
            </span>
          </a>
          {resultado.snippet && (
            <p className="text-muted-foreground mt-2 text-sm">{resultado.snippet}</p>
          )}
        </div>
        <EstadoSearchResultBadge estado={resultado.estado} />
      </CardHeader>

      <CardContent className="flex flex-col gap-3">
        {resultado.estado === 'gap' && resultado.gap_motivo && (
          <GapMotivoBadge motivo={resultado.gap_motivo} />
        )}

        {resultado.estado === 'procesando' && (
          <div className="text-muted-foreground flex items-center gap-2 text-sm">
            <Loader2 className="size-4 animate-spin" />
            Procesando…
          </div>
        )}

        {(resultado.estado === 'nuevo' || resultado.estado === 'gap') &&
          puedeProponer(user) && (
            <div className="flex flex-wrap items-center gap-2">
              <Button size="sm" disabled={extraer.isPending} onClick={handleExtraer}>
                {resultado.estado === 'gap'
                  ? 'Reintentar'
                  : extraer.isPending
                    ? 'Encolando…'
                    : 'Sacar información de noticia'}
              </Button>
              {resultado.estado === 'gap' && puedeResolver(user) && (
                <Button size="sm" variant="outline" onClick={() => setCapturaAbierta(true)}>
                  Ingresar datos manualmente
                </Button>
              )}
              <Button size="sm" variant="ghost" disabled={descartar.isPending} onClick={handleDescartar}>
                Descartar
              </Button>
            </div>
          )}

        {resultado.estado === 'sin_menciones' && (
          <div className="flex flex-wrap items-center gap-2">
            <p className="text-muted-foreground text-sm">
              Se leyó el artículo, la IA no encontró personas.
            </p>
            {puedeProponer(user) && (
              <Button size="sm" variant="ghost" disabled={descartar.isPending} onClick={handleDescartar}>
                Descartar
              </Button>
            )}
          </div>
        )}

        {resultado.estado === 'extraido' &&
          resultado.mentions?.map((mention) => (
            <MentionCard key={mention.id} mention={mention} subjectId={subjectId} />
          ))}
      </CardContent>

      <CapturaManualDialog
        subjectId={subjectId}
        resultadoId={resultado.id}
        open={capturaAbierta}
        onOpenChange={setCapturaAbierta}
      />
    </Card>
  );
}

function MentionCard({ mention, subjectId }: { mention: Mention; subjectId: number }) {
  const { data: user } = useCurrentUser();
  const proponer = useProponer(subjectId);
  const resolver = useResolver(subjectId);
  const [seleccion, setSeleccion] = useState<Exclude<EstadoMatch, 'pendiente'> | ''>('');

  const match = mention.match;
  const puedeActuar = match?.estado === 'pendiente';

  function accion(tipo: 'proponer' | 'resolver') {
    if (!seleccion || !match) return;

    const mutation = tipo === 'proponer' ? proponer : resolver;
    mutation.mutate(
      { matchId: match.id, estado: seleccion },
      {
        onSuccess: () =>
          toast.success(tipo === 'proponer' ? 'Resolución propuesta.' : 'Coincidencia resuelta.'),
        onError: (error) =>
          toast.error(error instanceof ApiError ? error.message : 'No se pudo completar la acción.'),
      },
    );
  }

  return (
    <div className="rounded-lg border p-3">
      <div className="flex flex-row items-start justify-between gap-4">
        <div>
          <p className="font-medium">{mention.nombre_extraido}</p>
          <div className="mt-2 flex flex-wrap gap-1.5">
            <Badge variant="outline" className="capitalize">
              {mention.rol}
            </Badge>
            {mention.delitos.map((delito) => (
              <Badge key={delito} variant="outline">
                {delito}
              </Badge>
            ))}
            {mention.origen === 'manual' && <Badge variant="outline">Ingresado manualmente</Badge>}
          </div>
          {mention.resumen && <p className="text-muted-foreground mt-2 text-sm">{mention.resumen}</p>}
        </div>
        {match && <EstadoMatchBadge estado={match.estado} />}
      </div>

      {match?.propuesta_estado && (
        <p className="text-muted-foreground mt-2 text-sm">
          Propuesta del analista: <EstadoMatchBadge estado={match.propuesta_estado} />
        </p>
      )}

      {puedeActuar && (puedeProponer(user) || puedeResolver(user)) && (
        <>
          <Separator className="my-3" />
          <div className="flex flex-wrap items-center gap-2">
            <Select value={seleccion} onValueChange={(v: typeof seleccion) => setSeleccion(v)}>
              <SelectTrigger className="w-48">
                <SelectValue placeholder="Elegir resolución" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="confirmado">Confirmado</SelectItem>
                <SelectItem value="falso_positivo">Falso positivo</SelectItem>
                <SelectItem value="homonimo">Homónimo</SelectItem>
              </SelectContent>
            </Select>

            {puedeProponer(user) && (
              <Button
                size="sm"
                variant="outline"
                disabled={!seleccion || proponer.isPending}
                onClick={() => accion('proponer')}
              >
                Proponer
              </Button>
            )}

            {puedeResolver(user) && (
              <Button size="sm" disabled={!seleccion || resolver.isPending} onClick={() => accion('resolver')}>
                Resolver
              </Button>
            )}
          </div>
        </>
      )}
    </div>
  );
}
