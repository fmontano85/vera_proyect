import { createFileRoute } from '@tanstack/react-router';
import { CheckCircle2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { AppShell } from '@/components/layout/AppShell';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { esAdmin, useCurrentUser } from '@/features/auth/useAuth';
import { useAceptarDocumento, useDocumentosVigentes } from '@/features/documentos/useDocumentosLegales';
import { mensajeApi } from '@/lib/api';
import { formatearFechaHora } from '@/lib/fechas';
import { NOMBRE_DOCUMENTO, type DocumentoLegalTenant } from '@/types/documentosLegales';

export const Route = createFileRoute('/_authenticated/documentos/')({
  component: DocumentosPage,
});

/**
 * Terminos y contrato de encargo vigentes (seccion 3.9, punto 1): todos
 * los usuarios del tenant los leen; solo el admin los acepta en nombre de
 * la organizacion.
 */
function DocumentosPage() {
  const { data: documentos, isLoading } = useDocumentosVigentes();

  return (
    <AppShell title="Términos y contrato">
      <div className="flex max-w-4xl flex-col gap-6">
        {isLoading && <Skeleton className="h-64 w-full" />}
        {documentos?.length === 0 && (
          <p className="text-muted-foreground text-sm">No hay documentos publicados por el momento.</p>
        )}
        {documentos?.map((d) => <DocumentoCard key={d.id} documento={d} />)}
      </div>
    </AppShell>
  );
}

function DocumentoCard({ documento: d }: { documento: DocumentoLegalTenant }) {
  const { data: yo } = useCurrentUser();
  const aceptar = useAceptarDocumento();
  const [leido, setLeido] = useState(false);

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="text-base">{d.titulo}</CardTitle>
          <CardDescription>
            {NOMBRE_DOCUMENTO[d.tipo]} · versión {d.version} · publicada {formatearFechaHora(d.publicado_en)}
          </CardDescription>
        </div>
        {d.aceptacion ? (
          <Badge variant="outline" className="shrink-0">
            <CheckCircle2 className="text-primary" />
            Aceptado
          </Badge>
        ) : (
          <Badge variant="destructive" className="shrink-0">
            Pendiente
          </Badge>
        )}
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <div className="bg-muted/50 max-h-96 overflow-y-auto rounded-md p-4 text-sm whitespace-pre-wrap">{d.contenido}</div>

        {d.aceptacion && (
          <p className="text-muted-foreground text-sm">
            Aceptado por {d.aceptacion.aceptado_por ?? 'un usuario ya eliminado'} · {formatearFechaHora(d.aceptacion.aceptado_en)}
          </p>
        )}

        {!d.aceptacion && !esAdmin(yo) && (
          <p className="text-muted-foreground text-sm">
            Solo el administrador de tu organización puede aceptarlo. Mientras tanto no se pueden cargar personas ni hacer búsquedas.
          </p>
        )}

        {!d.aceptacion && esAdmin(yo) && (
          <div className="flex flex-wrap items-center justify-between gap-3 border-t pt-4">
            <label className="flex items-start gap-2 text-sm">
              <input type="checkbox" className="mt-1" checked={leido} onChange={(e) => setLeido(e.target.checked)} />
              <span>He leído este documento y lo acepto en nombre de mi organización.</span>
            </label>
            <Button
              disabled={!leido || aceptar.isPending}
              onClick={() =>
                aceptar.mutate(d.id, {
                  onSuccess: () => toast.success('Documento aceptado.'),
                  onError: (e) => toast.error(mensajeApi(e, 'No se pudo registrar la aceptación.')),
                })
              }
            >
              {aceptar.isPending ? 'Registrando…' : 'Aceptar'}
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
