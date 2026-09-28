<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use RuntimeException;
use ZipArchive;

/**
 * ZIP temporal para las exportaciones de la seccion 3.9 (hallazgos del
 * code-review 2026-09-28):
 *
 * - Los archivos del Storage se copian por stream a temporales locales y
 *   se agregan con addFile(): ZipArchive::addFromString() retiene cada PDF
 *   en memoria hasta close() y un tenant con cientos de capturas manuales
 *   superaba el limite de memoria.
 * - cerrar() y descartar() borran siempre las copias temporales; descartar()
 *   borra tambien el ZIP (se usa cuando la exportacion falla a mitad), para
 *   no dejar datos personales en el directorio temporal.
 */
final class ArchivoZip
{
    public readonly string $ruta;

    private ZipArchive $zip;

    /** @var list<string> */
    private array $temporales = [];

    public function __construct(string $prefijo)
    {
        $this->ruta = tempnam(sys_get_temp_dir(), $prefijo);
        $this->zip = new ZipArchive;

        if ($this->zip->open($this->ruta, ZipArchive::OVERWRITE) !== true) {
            @unlink($this->ruta);
            throw new RuntimeException('No se pudo crear el archivo de exportación.');
        }
    }

    public function agregarTexto(string $nombre, string $contenido): void
    {
        $this->zip->addFromString($nombre, $contenido);
    }

    public function agregarDesdeDisco(Filesystem $disco, string $origen, string $nombre): void
    {
        $copia = tempnam(sys_get_temp_dir(), 'vera-copia-');
        $this->temporales[] = $copia;

        $entrada = $disco->readStream($origen);
        $salida = fopen($copia, 'wb');
        stream_copy_to_stream($entrada, $salida);
        fclose($salida);
        if (is_resource($entrada)) {
            fclose($entrada);
        }

        $this->zip->addFile($copia, $nombre);
    }

    /** Cierra el ZIP y devuelve su ruta (quien lo sirve lo borra al enviarlo). */
    public function cerrar(): string
    {
        try {
            $this->zip->close();
        } finally {
            $this->borrarTemporales();
        }

        return $this->ruta;
    }

    public function descartar(): void
    {
        @$this->zip->close();
        $this->borrarTemporales();
        @unlink($this->ruta);
    }

    private function borrarTemporales(): void
    {
        foreach ($this->temporales as $copia) {
            @unlink($copia);
        }
        $this->temporales = [];
    }
}
