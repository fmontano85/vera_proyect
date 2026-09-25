import type { QueryKey } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { ExternalLink } from 'lucide-react';
import { EstadoMatchBadge } from '@/components/EstadoMatchBadge';
import { NivelRiesgoBadge } from '@/components/NivelRiesgoBadge';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { MatchAcciones } from '@/features/coincidencias/MatchAcciones';
import { formatearFechaHora } from '@/lib/fechas';
import type { Coincidencia } from '@/types/api';

/**
 * Una coincidencia pendiente con el contexto necesario para decidir sin
 * salir de la bandeja: a quien de la lista de vigilancia apunta, como
 * aparece en la noticia (rol, delitos, resumen) y la fuente. Seccion 1:
 * el sistema solo PROPONE - nada aqui afirma que la persona este
 * involucrada hasta que alguien lo resuelva.
 */
export function CoincidenciaCard({ coincidencia, queryKey }: { coincidencia: Coincidencia; queryKey: QueryKey }) {
  const { subject, mention } = coincidencia;
  const fuente = mention?.article ?? mention?.search_result ?? null;

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div className="min-w-0">
          <p className="text-muted-foreground text-xs">Persona de la lista de vigilancia</p>
          {subject ? (
            <Link
              to="/subjects/$subjectId"
              params={{ subjectId: String(subject.id) }}
              className="text-primary font-medium hover:underline"
            >
              {subject.nombre_canonico}
            </Link>
          ) : (
            <span className="text-muted-foreground">—</span>
          )}
          {subject && !subject.activo && (
            <Badge variant="outline" className="ml-2">
              Inactivo
            </Badge>
          )}
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1.5">
          {subject && <NivelRiesgoBadge nivel={subject.nivel_riesgo} />}
          <EstadoMatchBadge estado={coincidencia.estado} />
        </div>
      </CardHeader>

      <CardContent className="flex flex-col gap-3">
        {mention && (
          <div className="rounded-lg border p-3">
            <p className="text-muted-foreground text-xs">Cómo aparece en la noticia</p>
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
        )}

        {fuente && (
          <a
            href={fuente.url}
            target="_blank"
            rel="noreferrer"
            className="text-primary flex items-center gap-1.5 text-sm hover:underline"
          >
            <ExternalLink className="size-3.5 shrink-0" />
            <span className="truncate">{fuente.titulo ?? fuente.url}</span>
            {fuente.medio && <span className="text-muted-foreground shrink-0">· {fuente.medio}</span>}
          </a>
        )}

        <p className="text-muted-foreground text-xs">
          Detectada el {formatearFechaHora(coincidencia.created_at)}
          {coincidencia.score_meilisearch !== null && ` · similitud del nombre ${coincidencia.score_meilisearch}`}
        </p>

        {coincidencia.propuesta_estado && (
          <p className="text-sm">
            Propuesta: <EstadoMatchBadge estado={coincidencia.propuesta_estado} />
            {coincidencia.propuesta_por_usuario && (
              <span className="text-muted-foreground">
                {' '}
                por {coincidencia.propuesta_por_usuario.name}, {formatearFechaHora(coincidencia.propuesta_en)}
              </span>
            )}
          </p>
        )}

        <Separator />
        <MatchAcciones matchId={coincidencia.id} estado={coincidencia.estado} queryKey={queryKey} />
      </CardContent>
    </Card>
  );
}
