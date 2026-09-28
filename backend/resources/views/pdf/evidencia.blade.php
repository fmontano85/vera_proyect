@extends('pdf.layout')

@section('titulo', 'Evidencia #'.$resultado_id)

@section('contenido')
    <h1>{{ $titulo ?? 'Artículo sin título' }}</h1>
    <p class="sub">{{ $medio }}</p>

    <h2>Datos de la captura</h2>
    <table class="datos">
        <tr><td class="k">URL original</td><td class="mono">{{ $url }}</td></tr>
        <tr><td class="k">Fecha de publicación</td><td>{{ $fecha_publicacion ?? 'No detectada en la página' }}</td></tr>
        <tr><td class="k">Capturado</td><td>{{ $capturado_en }}</td></tr>
        <tr><td class="k">Hash SHA-256 registrado</td><td class="mono">{{ $hash ?? '—' }}</td></tr>
        <tr>
            <td class="k">Integridad</td>
            <td>
                @if ($integridad_verificada)
                    <span class="ok">Verificada:</span> el contenido guardado coincide con el hash registrado al capturarlo.
                @else
                    <span class="alerta">No coincide:</span> el contenido guardado no corresponde al hash registrado.
                    Hash actual: <span class="mono">{{ $hash_actual }}</span>
                @endif
            </td>
        </tr>
    </table>

    <div class="aviso">
        Este documento reproduce el texto principal de la página guardada en el momento de la captura, no la
        página actual. Se omiten menús, imágenes, scripts y recursos externos; el snapshot completo está disponible
        como descarga aparte y su integridad se verifica con el hash de arriba. La mención de una persona en un medio no implica su
        participación en los hechos: toda coincidencia requiere resolución humana registrada.
    </div>

    <h2>Contenido capturado</h2>
    <div class="texto">
        @forelse ($parrafos as $parrafo)
            <p>{{ $parrafo }}</p>
        @empty
            <p class="vacio">La página guardada no contiene texto legible.</p>
        @endforelse
    </div>
@endsection
