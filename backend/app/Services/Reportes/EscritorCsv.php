<?php

declare(strict_types=1);

namespace App\Services\Reportes;

/**
 * CSV de los reportes (decision del usuario 2026-09-28: CSV, sin libreria).
 * UTF-8 con BOM para que Excel respete tildes y eñes.
 *
 * Inyeccion de formulas (OWASP): el contenido viene en parte de noticias
 * de terceros; una celda que empieza con = + - @ (o tab/retorno) se
 * prefija con ' para que Excel la trate como texto y no la ejecute.
 */
final class EscritorCsv
{
    /**
     * @param  list<string>  $encabezados
     * @param  iterable<list<scalar|null>>  $filas
     */
    public static function escribir(array $encabezados, iterable $filas): string
    {
        $salida = fopen('php://temp', 'r+');
        fwrite($salida, "\u{FEFF}");
        fputcsv($salida, $encabezados, escape: '');

        foreach ($filas as $fila) {
            fputcsv($salida, array_map(self::neutralizar(...), $fila), escape: '');
        }

        rewind($salida);
        $csv = (string) stream_get_contents($salida);
        fclose($salida);

        return $csv;
    }

    private static function neutralizar(mixed $valor): string
    {
        $texto = (string) ($valor ?? '');

        return preg_match('/^[=+\-@\t\r]/', $texto) === 1 ? "'".$texto : $texto;
    }
}
