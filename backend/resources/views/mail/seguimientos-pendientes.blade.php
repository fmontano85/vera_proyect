<x-mail::message>
# Seguimientos pendientes

A las siguientes personas de la lista de vigilancia les corresponde revisión:

<x-mail::table>
| Persona | Nivel de riesgo | Vence |
|:--------|:----------------|:------|
@foreach ($alertas as $alerta)
| {{ $alerta->alertable->nombre_canonico }} | {{ $alerta->alertable->nivel_riesgo ?? 'sin nivel' }} | {{ $alerta->vencimiento->format('d/m/Y') }} |
@endforeach
</x-mail::table>

@if ($siguenVencidos > 0)
Además, **{{ $siguenVencidos }}** {{ $siguenVencidos === 1 ? 'seguimiento sigue vencido' : 'seguimientos siguen vencidos' }} de días anteriores.
@endif

La revisión es manual: abre cada persona, ejecuta la consulta puntual si lo consideras necesario y marca el seguimiento como realizado.

<x-mail::button :url="$urlPanel">
Ver seguimientos pendientes
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
