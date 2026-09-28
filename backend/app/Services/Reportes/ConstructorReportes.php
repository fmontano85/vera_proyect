<?php

declare(strict_types=1);

namespace App\Services\Reportes;

use App\Actions\ProteccionDatos\ExportarSubject;
use App\Models\Activity;
use App\Models\MentionMatch;
use App\Models\Report;
use App\Models\SanctionMatch;
use App\Models\Subject;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Contenido de los reportes de auditoria (seccion 1, punto 5). Se ejecuta
 * dentro de tenancy (GenerarReporteJob): los modelos con scope solo ven el
 * tenant del reporte; Activity (sin scope) filtra tenant_id explicito.
 *
 * Regla no negociable (seccion 1): ningun reporte afirma involucramiento
 * sin resolucion humana - lo no resuelto se rotula "Pendiente de
 * resolucion" y el pie de cada PDF lo recuerda.
 */
class ConstructorReportes
{
    public const ESTADOS = [
        'confirmado' => 'Confirmada',
        'falso_positivo' => 'Falso positivo',
        'homonimo' => 'Homónimo',
        'pendiente' => 'Pendiente de resolución',
    ];

    private const EVENTOS = [
        'consulta_puntual' => 'Consulta puntual',
        'busqueda_tags' => 'Búsqueda por tags',
        'extraccion_solicitada' => 'Extracción de noticia',
        'seguimiento_realizado' => 'Seguimiento realizado',
    ];

    private const NIVELES = ['alto' => 'Alto', 'medio' => 'Medio', 'bajo' => 'Bajo', 'sin_nivel' => 'Sin nivel', 'todos' => 'Todos los niveles'];

    public function __construct(private readonly ExportarSubject $exportarSubject) {}

    /**
     * @return array{titulo: string, vista: string, orientacion: string, datos: array<string, mixed>, csv: array{encabezados: list<string>, filas: list<list<scalar|null>>}, personas: list<int>}
     */
    public function construir(Report $reporte): array
    {
        $contenido = match ($reporte->tipo) {
            'ficha_persona' => $this->fichaPersona($reporte),
            'actividad_periodo' => $this->actividadPeriodo($reporte),
            'lista_por_riesgo' => $this->listaPorRiesgo($reporte),
        };

        $contenido['datos'] += [
            'titulo' => $contenido['titulo'],
            'generado_por' => $reporte->generadoPor?->name,
            'generado_en' => now()->timezone(config('vera.zona_horaria'))->format('d/m/Y H:i'),
        ];

        return $contenido;
    }

    private function fichaPersona(Report $reporte): array
    {
        $subject = Subject::query()->findOrFail($reporte->parametros['subject_id']);
        $datos = $this->exportarSubject->datos($subject, $reporte->generadoPor ?? new User(['name' => 'Sistema']));
        $persona = $datos['persona'];

        $coincidencias = collect($datos['coincidencias'])->map(fn (array $c) => [
            'nombre' => $c['nombre_como_aparece'],
            'rol' => self::texto($c['rol']),
            'delitos' => implode(', ', $c['delitos'] ?? []),
            'estado' => self::ESTADOS[self::texto($c['estado'])] ?? self::texto($c['estado']),
            'resuelto_en' => self::fecha($c['resuelto_en']),
            'origen' => self::texto($c['origen']) === 'manual' ? 'Captura manual' : 'Automático',
            'url' => $c['articulo_url'],
        ])->all();

        return [
            'titulo' => 'Ficha de persona: '.$subject->nombre_canonico,
            'vista' => 'pdf.reportes.ficha_persona',
            'orientacion' => 'portrait',
            'datos' => [
                'persona' => [
                    'nombre' => $subject->nombre_canonico,
                    'tipo' => $persona['tipo'] === 'juridica' ? 'Persona jurídica' : 'Persona natural',
                    'documento' => $persona['documento'],
                    'nivel' => self::NIVELES[$persona['nivel_riesgo'] ?? 'sin_nivel'] ?? '—',
                    'estado' => $persona['activo'] ? 'Activa' : 'Inactiva desde '.self::fecha($persona['desactivado_en']),
                    'alta' => self::fecha($persona['created_at']),
                    'ultimo_seguimiento' => self::fecha($persona['ultimo_seguimiento_en']),
                    'proximo_seguimiento' => self::diaCalendario($persona['proximo_seguimiento_en']),
                ],
                'aliases' => $datos['aliases'],
                'coincidencias' => $coincidencias,
                'sanciones' => collect($datos['sanciones'])->map(fn (array $s) => [
                    'lista' => strtoupper(str_replace('_', ' ', (string) $s['lista'])),
                    'nombre' => $s['nombre_en_lista'],
                    'programa' => $s['programa'],
                    'puntaje' => $s['puntaje'],
                    'estado' => self::ESTADOS[self::texto($s['estado'])] ?? self::texto($s['estado']),
                ])->all(),
                // Revisados = procesados o descartados; 'nuevo'/'procesando' nadie los ha visto aun.
                'resultados' => collect($datos['resultados'])
                    ->reject(fn (array $r) => in_array(self::texto($r['estado']), ['nuevo', 'procesando'], true))->count(),
                'historial' => collect($datos['historial'])->map(fn (array $h) => [
                    'fecha' => self::fecha($h['creado_en']),
                    'evento' => self::describirHistorial($h),
                    'usuario' => $h['usuario'] ?? 'Sistema',
                ])->all(),
            ],
            'csv' => [
                'encabezados' => ['Persona', 'Documento', 'Nombre como aparece', 'Rol', 'Delitos', 'Estado', 'Resuelto en', 'Origen', 'Artículo'],
                'filas' => array_map(fn (array $c) => [
                    $subject->nombre_canonico, $persona['documento'], $c['nombre'], $c['rol'], $c['delitos'],
                    $c['estado'], $c['resuelto_en'], $c['origen'], $c['url'],
                ], $coincidencias),
            ],
            'personas' => [$subject->id],
        ];
    }

    private function actividadPeriodo(Report $reporte): array
    {
        $zona = config('vera.zona_horaria');
        $desde = CarbonImmutable::parse($reporte->parametros['desde'], $zona)->startOfDay();
        $hasta = CarbonImmutable::parse($reporte->parametros['hasta'], $zona)->endOfDay();
        $rango = [$desde->utc(), $hasta->utc()];

        $filas = collect();

        MentionMatch::query()->whereBetween('resuelto_en', $rango)->where('estado', '!=', 'pendiente')
            ->with(['subject', 'mention.article', 'resueltoPor'])->orderBy('resuelto_en')->get()
            ->each(fn (MentionMatch $m) => $filas->push([
                'orden' => $m->resuelto_en, 'subject_id' => $m->subject_id,
                'fila' => [self::fecha($m->resuelto_en), 'Coincidencia resuelta', $m->subject?->nombre_canonico,
                    trim(($m->mention?->nombre_extraido ?? '').' — '.($m->mention?->article?->url ?? 'captura manual'), ' —'),
                    self::ESTADOS[self::texto($m->estado)] ?? self::texto($m->estado), $m->resueltoPor?->name],
            ]));

        SanctionMatch::query()->whereBetween('resuelto_en', $rango)->where('estado', '!=', 'pendiente')
            ->with(['subject', 'sanctionEntry', 'resueltoPor'])->orderBy('resuelto_en')->get()
            ->each(fn (SanctionMatch $s) => $filas->push([
                'orden' => $s->resuelto_en, 'subject_id' => $s->subject_id,
                'fila' => [self::fecha($s->resuelto_en), 'Hallazgo de sanciones resuelto', $s->subject?->nombre_canonico,
                    $s->sanctionEntry?->nombre, self::ESTADOS[self::texto($s->estado)] ?? self::texto($s->estado), $s->resueltoPor?->name],
            ]));

        $eventos = Activity::query()->where('tenant_id', tenant()->getTenantKey())
            ->whereIn('event', array_keys(self::EVENTOS))->whereBetween('created_at', $rango)
            ->with('causer')->orderBy('id')->get();
        $nombres = Subject::query()
            ->whereIn('id', $eventos->where('subject_type', Subject::class)->pluck('subject_id')->filter()->unique())
            ->pluck('nombre_canonico', 'id');
        $eventos->each(fn (Activity $a) => $filas->push([
            'orden' => $a->created_at,
            'subject_id' => $a->subject_type === Subject::class ? (int) $a->subject_id : null,
            'fila' => [self::fecha($a->created_at), self::EVENTOS[$a->event],
                $a->subject_type === Subject::class ? ($nombres[(int) $a->subject_id] ?? 'Persona eliminada') : null,
                $a->event === 'busqueda_tags' ? implode(', ', (array) $a->getProperty('tags', [])) : null,
                null, $a->causer?->name ?? 'Sistema'],
        ]));

        $ordenadas = $filas->sortBy(fn (array $f) => $f['orden'])->values();
        $periodo = $desde->format('d/m/Y').' al '.$hasta->format('d/m/Y');

        return [
            'titulo' => 'Actividad del '.$periodo,
            'vista' => 'pdf.reportes.actividad_periodo',
            'orientacion' => 'landscape',
            'datos' => ['periodo' => $periodo, 'filas' => $ordenadas->pluck('fila')->all()],
            'csv' => [
                'encabezados' => ['Fecha', 'Tipo', 'Persona', 'Detalle', 'Resolución', 'Usuario'],
                'filas' => $ordenadas->pluck('fila')->all(),
            ],
            'personas' => $ordenadas->pluck('subject_id')->filter()->unique()->values()->all(),
        ];
    }

    private function listaPorRiesgo(Report $reporte): array
    {
        $nivel = $reporte->parametros['nivel_riesgo'];
        $conInactivas = (bool) ($reporte->parametros['incluir_inactivos'] ?? false);

        $personas = Subject::query()
            ->when($nivel === 'sin_nivel', fn (Builder $q) => $q->whereNull('nivel_riesgo'))
            ->when(! in_array($nivel, ['sin_nivel', 'todos'], true), fn (Builder $q) => $q->where('nivel_riesgo', $nivel))
            ->when(! $conInactivas, fn (Builder $q) => $q->where('activo', true))
            ->withCount([
                'coincidencias as coincidencias_confirmadas' => fn (Builder $q) => $q->where('estado', 'confirmado'),
                'coincidencias as coincidencias_pendientes' => fn (Builder $q) => $q->where('estado', 'pendiente'),
                'sanciones as sanciones_confirmadas' => fn (Builder $q) => $q->where('estado', 'confirmado'),
                'sanciones as sanciones_pendientes' => fn (Builder $q) => $q->where('estado', 'pendiente'),
            ])
            ->orderBy('nombre_canonico')->get();

        $filas = $personas->map(fn (Subject $s) => [
            $s->nombre_canonico,
            $s->tipo === 'juridica' ? 'Jurídica' : 'Natural',
            $s->documento,
            self::NIVELES[$s->nivel_riesgo ?? 'sin_nivel'],
            $s->activo ? 'Activa' : 'Inactiva',
            self::fecha($s->ultimo_seguimiento_en),
            self::diaCalendario($s->proximo_seguimiento_en),
            $s->coincidencias_confirmadas,
            $s->coincidencias_pendientes,
            $s->sanciones_confirmadas,
            $s->sanciones_pendientes,
        ])->all();

        $titulo = 'Lista de vigilancia: '.self::NIVELES[$nivel].($conInactivas ? ' (incluye inactivas)' : '');

        return [
            'titulo' => $titulo,
            'vista' => 'pdf.reportes.lista_por_riesgo',
            'orientacion' => 'landscape',
            'datos' => ['filas' => $filas, 'total' => count($filas)],
            'csv' => [
                'encabezados' => ['Persona', 'Tipo', 'Documento', 'Nivel de riesgo', 'Estado', 'Último seguimiento', 'Próximo seguimiento',
                    'Coincidencias confirmadas', 'Coincidencias pendientes', 'Sanciones confirmadas', 'Sanciones pendientes'],
                'filas' => $filas,
            ],
            'personas' => $personas->modelKeys(),
        ];
    }

    /** Registro del historial (ListarHistorialSubject) en una frase legible, no la clave del evento. */
    private static function describirHistorial(array $h): string
    {
        $accion = ['created' => 'Alta', 'updated' => 'Modificación', 'deleted' => 'Eliminación'][$h['evento']] ?? null;
        $entidad = ['subject' => 'la persona', 'alias' => 'un alias', 'coincidencia' => 'una coincidencia'][$h['entidad']] ?? 'un registro';

        if ($accion !== null) {
            return "{$accion} de {$entidad}";
        }

        return self::EVENTOS[$h['evento']] ?? ($h['descripcion'] ?: $h['evento']);
    }

    private static function texto(mixed $valor): string
    {
        return $valor instanceof BackedEnum ? (string) $valor->value : (string) ($valor ?? '');
    }

    /**
     * Fecha de calendario (proximo_seguimiento_en, cast FechaSinHora):
     * medianoche sin zona. Convertirla a El Salvador mostraria el dia
     * anterior (gotcha documentado en lib/fechas.ts del frontend).
     */
    private static function diaCalendario(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return $valor instanceof DateTimeInterface ? $valor->format('d/m/Y') : CarbonImmutable::parse(substr((string) $valor, 0, 10))->format('d/m/Y');
    }

    private static function fecha(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $fecha = $valor instanceof DateTimeInterface ? CarbonImmutable::instance($valor) : CarbonImmutable::parse((string) $valor);

        // Fechas de calendario (Y-m-d) sin hora: no se convierten de zona.
        return is_string($valor) && strlen($valor) === 10
            ? $fecha->format('d/m/Y')
            : $fecha->timezone(config('vera.zona_horaria'))->format('d/m/Y H:i');
    }
}
