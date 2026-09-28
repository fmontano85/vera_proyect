<?php

declare(strict_types=1);

namespace App\Actions\ProteccionDatos;

use App\Actions\Subjects\ListarHistorialSubject;
use App\Models\MentionMatch;
use App\Models\SanctionMatch;
use App\Models\SearchResult;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

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
        $subject->load('aliases');

        $resultados = SearchResult::query()->where('subject_id', $subject->id)->with('article')->orderBy('id')->get();
        $coincidencias = MentionMatch::query()->where('subject_id', $subject->id)->with('mention.article')->orderBy('id')->get();
        $sanciones = SanctionMatch::query()->where('subject_id', $subject->id)->with('sanctionEntry.sanctionList')->orderBy('id')->get();

        $datos = [
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

        return $this->empaquetar($datos, $resultados);
    }

    /** @param  iterable<SearchResult>  $resultados */
    private function empaquetar(array $datos, iterable $resultados): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'vera-export-');
        $zip = new ZipArchive;

        if ($zip->open($ruta, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el archivo de exportación.');
        }

        $zip->addFromString('persona.json', json_encode($datos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $disco = Storage::disk(config('vera.evidencia_manual_disk'));
        foreach ($resultados as $r) {
            if ($r->evidencia_manual_path && $disco->exists($r->evidencia_manual_path)) {
                $zip->addFromString("evidencia-manual/resultado-{$r->id}.pdf", $disco->get($r->evidencia_manual_path));
            }
        }

        $zip->close();

        return $ruta;
    }
}
