<?php

declare(strict_types=1);

namespace App\Actions\ProteccionDatos;

use App\Actions\Subjects\ListarHistorialSubject;
use App\Models\MentionMatch;
use App\Models\SanctionMatch;
use App\Models\SearchResult;
use App\Models\Subject;
use App\Models\User;
use App\Services\ProteccionDatos\ResultadosDePersona;
use App\Support\ArchivoZip;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Todo lo que VERA tiene sobre una persona, para que el cliente atienda un
 * derecho de acceso (seccion 3.9, punto 4): persona.json + los PDF de
 * evidencia manual. El snapshot HTML de los articulos no se incluye: es
 * contenido publico global, se referencia por su URL.
 *
 * Devuelve la ruta de un ZIP temporal; quien lo sirve lo borra al enviarlo.
 */
class ExportarSubject
{
    public function __construct(private readonly ListarHistorialSubject $historial) {}

    public function handle(Subject $subject, User $por): string
    {
        $zip = new ArchivoZip('vera-export-');

        try {
            $this->agregarAlZip($zip, $subject, $por, '');

            return $zip->cerrar();
        } catch (Throwable $e) {
            $zip->descartar();
            throw $e;
        }
    }

    /**
     * Agrega persona.json y la evidencia manual de una persona bajo un
     * prefijo (vacio para la exportacion individual; 'personas/{id}/' en
     * la exportacion completa del tenant - ExportarTenant).
     */
    public function agregarAlZip(ArchivoZip $zip, Subject $subject, User $por, string $prefijo): void
    {
        // Una sola lectura de los resultados para los datos y la evidencia.
        $resultados = $this->resultados($subject);
        $zip->agregarTexto("{$prefijo}persona.json", self::json($this->datos($subject, $por, $resultados)));
        self::agregarEvidencias($zip, $resultados, $prefijo);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, SearchResult> */
    private function resultados(Subject $subject): \Illuminate\Database\Eloquent\Collection
    {
        return SearchResult::query()->whereIn('id', ResultadosDePersona::ids($subject->id))
            ->with('article')->orderBy('id')->get();
    }

    /**
     * Todo lo que VERA tiene de la persona. Lo usan la exportacion
     * (persona.json) y el reporte "ficha de persona" (App\Services\Reportes).
     *
     * @return array<string, mixed>
     */
    public function datos(Subject $subject, User $por, ?\Illuminate\Database\Eloquent\Collection $resultados = null): array
    {
        $subject->load('aliases');

        $resultados ??= $this->resultados($subject);
        $coincidencias = MentionMatch::query()->where('subject_id', $subject->id)->with('mention.article')->orderBy('id')->get();
        $sanciones = SanctionMatch::query()->where('subject_id', $subject->id)->with('sanctionEntry.sanctionList')->orderBy('id')->get();

        return [
            'exportado_en' => now()->toIso8601String(),
            'exportado_por' => $por->name,
            'persona' => $subject->only([
                'id', 'tipo', 'nombre_canonico', 'documento', 'nivel_riesgo', 'activo', 'desactivado_en',
                'frecuencia_seguimiento_dias', 'ultimo_seguimiento_en', 'proximo_seguimiento_en', 'created_at', 'updated_at',
            ]),
            'aliases' => $subject->aliases->pluck('nombre')->all(),
            'resultados' => $resultados->map(fn (SearchResult $r) => [
                'id' => $r->id,
                'url' => $r->url,
                'titulo' => $r->titulo,
                'snippet' => $r->snippet,
                'medio' => $r->medio,
                'fecha' => $r->fecha_brave,
                'estado' => $r->estado,
                'gap_motivo' => $r->gap_motivo,
                'encontrado_en' => $r->created_at,
                'articulo' => $r->article?->only(['url', 'titulo', 'medio', 'fecha_publicacion', 'hash_contenido']),
                'evidencia_manual' => $r->evidencia_manual_path ? "evidencia-manual/resultado-{$r->id}.pdf" : null,
            ])->all(),
            'coincidencias' => $coincidencias->map(fn (MentionMatch $m) => [
                'nombre_como_aparece' => $m->mention?->nombre_extraido,
                'rol' => $m->mention?->rol,
                'delitos' => $m->mention?->delitos,
                'fecha_hecho' => $m->mention?->fecha_hecho,
                'resumen' => $m->mention?->resumen,
                'origen' => $m->mention?->origen,
                'articulo_url' => $m->mention?->article?->url,
                'estado' => $m->estado,
                'resuelto_en' => $m->resuelto_en,
            ])->all(),
            'sanciones' => $sanciones->map(fn (SanctionMatch $s) => [
                'lista' => $s->sanctionEntry?->sanctionList?->codigo,
                'nombre_en_lista' => $s->sanctionEntry?->nombre,
                'programa' => $s->sanctionEntry?->programa,
                'puntaje' => $s->score,
                'estado' => $s->estado,
            ])->all(),
            'historial' => collect($this->historial->handle($subject, 10_000)->items())->all(),
        ];
    }

    public static function json(mixed $datos): string
    {
        return json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param  iterable<SearchResult>  $resultados */
    public static function agregarEvidencias(ArchivoZip $zip, iterable $resultados, string $prefijo): void
    {
        $disco = Storage::disk(config('vera.evidencia_manual_disk'));
        foreach ($resultados as $r) {
            if ($r->evidencia_manual_path && $disco->exists($r->evidencia_manual_path)) {
                $zip->agregarDesdeDisco($disco, $r->evidencia_manual_path, "{$prefijo}evidencia-manual/resultado-{$r->id}.pdf");
            }
        }
    }
}
