<?php

declare(strict_types=1);

namespace App\Sources\Sanctions;

use Generator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Descarga y parsea el SDN.csv publico de OFAC (sin credenciales - es un
 * archivo publico). Formato de 12 columnas verificado contra el archivo
 * real el 2026-09-13. OFAC publica los aliases (alt.csv) y direcciones
 * (add.csv) en archivos separados, unidos por ent_num - esta primera
 * version NO los descarga todavia, asi que "aliases" y "pais" quedan
 * vacios; agregarlos es ampliar este adaptador, no un bug de esta version.
 */
class OfacSdnAdapter implements SanctionListAdapterInterface
{
    private const URL = 'https://www.treasury.gov/ofac/downloads/sdn.csv';

    private const COLUMNS = [
        'ent_num', 'sdn_name', 'sdn_type', 'program', 'title',
        'call_sign', 'vess_type', 'tonnage', 'grt', 'vess_flag',
        'vess_owner', 'remarks',
    ];

    public function fetch(): iterable
    {
        // timeout/retry moderados a proposito: 'imports' es la cola de
        // prioridad mas baja (seccion 3.4) y solo hay 3 workers de Horizon
        // en total (seccion 2) - un timeout largo aqui puede acaparar un
        // worker entero mientras colas mas prioritarias esperan.
        $csv = Http::timeout(15)->retry(2, 500)->get(self::URL)->throw()->body();

        yield from $this->parse($csv);
    }

    /**
     * @return Generator<int, array{external_id: string|null, nombre: string, aliases: array<int, string>, tipo: string|null, programa: string|null, pais: string|null, raw_json: array<string, mixed>}>
     */
    private function parse(string $csv): Generator
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        try {
            while (($fields = fgetcsv($stream)) !== false) {
                if ($fields === [null]) {
                    continue;
                }

                if (count($fields) !== count(self::COLUMNS)) {
                    // Numero de columnas distinto al esperado (ej. un
                    // delimitador sin escapar corrompiendo el parseo) - se
                    // descarta en vez de truncarla en silencio con datos
                    // desalineados. Se deja registro (Log::warning) porque
                    // ImportSanctionListsJob solo puede detectar el caso
                    // extremo de "0 filas en total", no filas sueltas
                    // perdidas dentro de un import que si trajo datos.
                    Log::warning('OfacSdnAdapter: fila con numero de columnas inesperado, descartada', [
                        'columnas_esperadas' => count(self::COLUMNS),
                        'columnas_recibidas' => count($fields),
                    ]);

                    continue;
                }

                $row = array_combine(self::COLUMNS, $fields);

                $row = array_map(
                    fn (?string $value) => in_array(trim((string) $value), ['-0-', ''], true) ? null : trim((string) $value),
                    $row,
                );

                yield [
                    'external_id' => $row['ent_num'],
                    'nombre' => (string) $row['sdn_name'],
                    'aliases' => [],
                    'tipo' => $row['sdn_type'],
                    'programa' => $row['program'],
                    'pais' => null,
                    'raw_json' => $row,
                ];
            }
        } finally {
            fclose($stream);
        }
    }
}
