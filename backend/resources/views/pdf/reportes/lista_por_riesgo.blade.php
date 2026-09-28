@extends('pdf.layout')

@section('titulo', $titulo)

@section('contenido')
    <h1>{{ $titulo }}</h1>
    <p class="sub">{{ $total }} personas</p>
    @include('pdf.reportes._aviso')

    @if ($filas)
        <table class="lista">
            <tr>
                <th>Persona</th><th>Tipo</th><th>Documento</th><th>Nivel</th><th>Estado</th><th>Último seguimiento</th><th>Próximo</th>
                <th>Coinc. confirmadas</th><th>Coinc. pendientes</th><th>Sanc. confirmadas</th><th>Sanc. pendientes</th>
            </tr>
            @foreach ($filas as $f)
                <tr>
                    @foreach ($f as $i => $valor)
                        <td>{{ $valor ?? '—' }}</td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    @else
        <p class="vacio">No hay personas con ese criterio.</p>
    @endif
@endsection
