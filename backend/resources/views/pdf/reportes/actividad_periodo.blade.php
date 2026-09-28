@extends('pdf.layout')

@section('titulo', $titulo)

@section('contenido')
    <h1>Actividad de cumplimiento</h1>
    <p class="sub">Periodo: {{ $periodo }} · {{ count($filas) }} registros</p>
    @include('pdf.reportes._aviso')

    @if ($filas)
        <table class="lista">
            <tr><th style="width: 13%">Fecha</th><th style="width: 17%">Tipo</th><th style="width: 18%">Persona</th><th>Detalle</th><th style="width: 12%">Resolución</th><th style="width: 13%">Usuario</th></tr>
            @foreach ($filas as [$fecha, $tipo, $persona, $detalle, $resolucion, $usuario])
                <tr>
                    <td>{{ $fecha }}</td><td>{{ $tipo }}</td><td>{{ $persona ?? '—' }}</td>
                    <td class="mono">{{ $detalle ?? '—' }}</td><td>{{ $resolucion ?? '—' }}</td><td>{{ $usuario ?? '—' }}</td>
                </tr>
            @endforeach
        </table>
    @else
        <p class="vacio">Sin actividad en el periodo.</p>
    @endif
@endsection
