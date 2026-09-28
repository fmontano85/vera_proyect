@extends('pdf.layout')

@section('titulo', $titulo)

@section('contenido')
    <h1>{{ $persona['nombre'] }}</h1>
    <p class="sub">Ficha de persona · {{ $persona['tipo'] }}</p>
    @include('pdf.reportes._aviso')

    <h2>Datos</h2>
    <table class="datos">
        <tr><td class="k">Documento</td><td>{{ $persona['documento'] ?? '—' }}</td></tr>
        <tr><td class="k">Nivel de riesgo</td><td>{{ $persona['nivel'] }}</td></tr>
        <tr><td class="k">Estado</td><td>{{ $persona['estado'] }}</td></tr>
        <tr><td class="k">En la lista de vigilancia desde</td><td>{{ $persona['alta'] ?? '—' }}</td></tr>
        <tr><td class="k">Último seguimiento</td><td>{{ $persona['ultimo_seguimiento'] ?? 'Sin seguimientos' }}</td></tr>
        <tr><td class="k">Próximo seguimiento</td><td>{{ $persona['proximo_seguimiento'] ?? '—' }}</td></tr>
        <tr><td class="k">Otros nombres (aliases)</td><td>{{ $aliases ? implode(', ', $aliases) : '—' }}</td></tr>
        <tr><td class="k">Resultados de búsqueda revisados</td><td>{{ $resultados }}</td></tr>
    </table>

    <h2>Coincidencias en medios</h2>
    @if ($coincidencias)
        <table class="lista">
            <tr><th>Nombre como aparece</th><th>Rol</th><th>Delitos</th><th>Estado</th><th>Resuelta</th><th>Fuente</th></tr>
            @foreach ($coincidencias as $c)
                <tr>
                    <td>{{ $c['nombre'] }}</td>
                    <td>{{ $c['rol'] }}</td>
                    <td>{{ $c['delitos'] ?: '—' }}</td>
                    <td><span class="etiqueta {{ $c['estado'] === 'Confirmada' ? 'confirmado' : ($c['estado'] === 'Pendiente de resolución' ? 'pendiente' : 'descartado') }}">{{ $c['estado'] }}</span></td>
                    <td>{{ $c['resuelto_en'] ?? '—' }}</td>
                    <td class="mono">{{ $c['url'] ?? $c['origen'] }}</td>
                </tr>
            @endforeach
        </table>
    @else
        <p class="vacio">Sin coincidencias registradas.</p>
    @endif

    <h2>Listas de sanciones</h2>
    @if ($sanciones)
        <table class="lista">
            <tr><th>Lista</th><th>Nombre en la lista</th><th>Programa</th><th>Puntaje</th><th>Estado</th></tr>
            @foreach ($sanciones as $s)
                <tr>
                    <td>{{ $s['lista'] }}</td><td>{{ $s['nombre'] }}</td><td>{{ $s['programa'] ?? '—' }}</td><td>{{ $s['puntaje'] }}</td>
                    <td><span class="etiqueta {{ $s['estado'] === 'Confirmada' ? 'confirmado' : ($s['estado'] === 'Pendiente de resolución' ? 'pendiente' : 'descartado') }}">{{ $s['estado'] }}</span></td>
                </tr>
            @endforeach
        </table>
    @else
        <p class="vacio">Sin hallazgos en listas de sanciones.</p>
    @endif

    <h2>Historial de auditoría</h2>
    @if ($historial)
        <table class="lista">
            <tr><th style="width: 22%">Fecha</th><th>Evento</th><th style="width: 22%">Usuario</th></tr>
            @foreach ($historial as $h)
                <tr><td>{{ $h['fecha'] }}</td><td>{{ $h['evento'] }}</td><td>{{ $h['usuario'] }}</td></tr>
            @endforeach
        </table>
    @else
        <p class="vacio">Sin actividad registrada.</p>
    @endif
@endsection
