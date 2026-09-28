import { createFileRoute } from '@tanstack/react-router';
import { FilePlus2, Send, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import {
  useCrearBorrador,
  useDescartarBorrador,
  useDocumentosSuperadmin,
  useGuardarBorrador,
  usePublicarDocumento,
} from '@/features/documentos/useDocumentosLegales';
import { mensajeApi } from '@/lib/api';
import { formatearFechaHora } from '@/lib/fechas';
import { NOMBRE_DOCUMENTO, type DocumentoLegalSuperadmin, type TipoDocumentoLegal } from '@/types/documentosLegales';

export const Route = createFileRoute('/superadmin/documentos')({
  component: DocumentosLegalesPage,
});

const TIPOS: TipoDocumentoLegal[] = ['terminos', 'contrato_encargo'];

/**
 * Terminos de servicio y contrato de encargo (seccion 3.9, punto 1): el
 * superadmin redacta un borrador y lo publica como version nueva; cada
 * version publicada es inmutable y todos los tenants deben aceptarla.
 */
function DocumentosLegalesPage() {
  const { data, isLoading } = useDocumentosSuperadmin();

  if (isLoading) return <Skeleton className="h-64 w-full" />;

  return (
    <div className="flex flex-col gap-6">
      {TIPOS.map((tipo) => (
        <SeccionDocumento key={tipo} tipo={tipo} documentos={(data ?? []).filter((d) => d.tipo === tipo)} />
      ))}
    </div>
  );
}

function SeccionDocumento({ tipo, documentos }: { tipo: TipoDocumentoLegal; documentos: DocumentoLegalSuperadmin[] }) {
  const borrador = documentos.find((d) => d.estado === 'borrador');
  const vigente = documentos.find((d) => d.estado === 'vigente');
  const anteriores = documentos.filter((d) => d.estado === 'anterior');
  const crear = useCrearBorrador();

  function nuevaVersion() {
    crear.mutate(
      {
        tipo,
        titulo: vigente?.titulo ?? NOMBRE_DOCUMENTO[tipo],
        // Parte del texto vigente: lo normal es corregir, no reescribir. El
        // backend exige texto no vacio incluso en un borrador.
        contenido: vigente?.contenido ?? `Redacta aquí el texto de: ${NOMBRE_DOCUMENTO[tipo]}.`,
      },
      { onError: (e) => toast.error(mensajeApi(e, 'No se pudo crear el borrador.')) },
    );
  }

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-4">
        <div>
          <CardTitle className="text-base">{NOMBRE_DOCUMENTO[tipo]}</CardTitle>
          <CardDescription>
            {vigente
              ? `Vigente: versión ${vigente.version}, publicada ${formatearFechaHora(vigente.publicado_en)}${vigente.publicado_por ? ` por ${vigente.publicado_por}` : ''}. Aceptada por ${vigente.aceptaciones} tenant${vigente.aceptaciones === 1 ? '' : 's'}.`
              : 'Todavía no hay una versión publicada: los tenants no están obligados a aceptar nada.'}
          </CardDescription>
        </div>
        {!borrador && (
          <Button variant="outline" disabled={crear.isPending} onClick={nuevaVersion}>
            <FilePlus2 />
            {vigente ? 'Nueva versión' : 'Redactar'}
          </Button>
        )}
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        {borrador && <EditorBorrador key={borrador.id} borrador={borrador} hayVigente={Boolean(vigente)} />}

        {vigente && !borrador && (
          <details>
            <summary className="text-primary cursor-pointer text-sm">Ver texto vigente</summary>
            <p className="bg-muted/50 mt-2 max-h-80 overflow-y-auto rounded-md p-3 text-sm whitespace-pre-wrap">{vigente.contenido}</p>
          </details>
        )}

        {anteriores.length > 0 && (
          <details>
            <summary className="text-muted-foreground cursor-pointer text-sm">Versiones anteriores ({anteriores.length})</summary>
            <ul className="mt-2 divide-y text-sm">
              {anteriores.map((d) => (
                <li key={d.id} className="flex justify-between gap-2 py-2">
                  <span>
                    Versión {d.version} · {d.titulo}
                  </span>
                  <span className="text-muted-foreground text-xs">
                    {formatearFechaHora(d.publicado_en)} · {d.aceptaciones} aceptaciones
                  </span>
                </li>
              ))}
            </ul>
          </details>
        )}
      </CardContent>
    </Card>
  );
}

function EditorBorrador({ borrador, hayVigente }: { borrador: DocumentoLegalSuperadmin; hayVigente: boolean }) {
  const [titulo, setTitulo] = useState(borrador.titulo);
  const [contenido, setContenido] = useState(borrador.contenido);
  const [confirmando, setConfirmando] = useState(false);
  const guardar = useGuardarBorrador();
  const descartar = useDescartarBorrador();
  const publicar = usePublicarDocumento();
  const sinCambios = titulo === borrador.titulo && contenido === borrador.contenido;
  const ocupado = guardar.isPending || descartar.isPending || publicar.isPending;

  function guardarBorrador(despues?: () => void) {
    guardar.mutate(
      { id: borrador.id, titulo: titulo.trim(), contenido },
      {
        onSuccess: () => (despues ? despues() : toast.success('Borrador guardado.')),
        onError: (e) => toast.error(mensajeApi(e, 'No se pudo guardar el borrador.')),
      },
    );
  }

  function publicarAhora() {
    const hacerPublicacion = () =>
      publicar.mutate(borrador.id, {
        onSuccess: (d) => {
          setConfirmando(false);
          toast.success(`Versión ${d.version} publicada. Cada tenant deberá aceptarla.`);
        },
        onError: (e) => toast.error(mensajeApi(e, 'No se pudo publicar.')),
      });
    // Lo que se publica es lo guardado: guardar primero si hay cambios en pantalla.
    if (sinCambios) hacerPublicacion();
    else guardarBorrador(hacerPublicacion);
  }

  return (
    <div className="border-warning/50 bg-warning/5 flex flex-col gap-3 rounded-lg border p-4">
      <div className="flex items-center gap-2">
        <Badge variant="outline">Borrador</Badge>
        <span className="text-muted-foreground text-xs">
          Solo lo ves tú. Al publicarlo pasa a ser la versión vigente y ya no se puede modificar.
        </span>
      </div>
      <div className="flex flex-col gap-2">
        <Label htmlFor={`titulo-${borrador.id}`}>Título</Label>
        <Input id={`titulo-${borrador.id}`} maxLength={255} value={titulo} onChange={(e) => setTitulo(e.target.value)} />
      </div>
      <div className="flex flex-col gap-2">
        <Label htmlFor={`contenido-${borrador.id}`}>Texto</Label>
        <Textarea
          id={`contenido-${borrador.id}`}
          className="min-h-72 font-mono text-sm"
          value={contenido}
          onChange={(e) => setContenido(e.target.value)}
        />
        <p className="text-muted-foreground text-xs">Texto plano: los saltos de línea se respetan tal cual.</p>
      </div>
      <div className="flex flex-wrap justify-between gap-2">
        <Button
          variant="ghost"
          disabled={ocupado}
          onClick={() =>
            descartar.mutate(borrador.id, {
              onSuccess: () => toast.success('Borrador descartado.'),
              onError: (e) => toast.error(mensajeApi(e, 'No se pudo descartar.')),
            })
          }
        >
          <Trash2 />
          Descartar borrador
        </Button>
        <div className="flex gap-2">
          <Button variant="outline" disabled={ocupado || sinCambios || !titulo.trim()} onClick={() => guardarBorrador()}>
            Guardar borrador
          </Button>
          <Button disabled={ocupado || !titulo.trim() || !contenido.trim()} onClick={() => setConfirmando(true)}>
            <Send />
            Publicar
          </Button>
        </div>
      </div>

      <Dialog open={confirmando} onOpenChange={setConfirmando}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>¿Publicar esta versión?</DialogTitle>
            <DialogDescription>
              {hayVigente
                ? 'Reemplaza a la versión vigente. Todos los tenants tendrán que aceptarla de nuevo y, mientras no lo hagan, no podrán cargar personas ni hacer búsquedas.'
                : 'Será la primera versión. Todos los tenants tendrán que aceptarla y, mientras no lo hagan, no podrán cargar personas ni hacer búsquedas.'}{' '}
              Una versión publicada no se puede modificar.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" disabled={ocupado} onClick={() => setConfirmando(false)}>
              Cancelar
            </Button>
            <Button disabled={ocupado} onClick={publicarAhora}>
              {publicar.isPending ? 'Publicando…' : 'Publicar'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
