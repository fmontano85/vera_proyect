<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SanctionEntry;
use App\Models\SanctionList;
use App\Sources\Sanctions\OfacSdnAdapter;
use App\Sources\Sanctions\SanctionListAdapterInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * sanction_lists/sanction_entries son catalogo global (sin tenant_id):
 * este job corre en contexto central, no necesita tenancy()->initialize().
 *
 * Solo 'ofac_sdn' tiene adaptador por ahora. 'un_consolidated' y 'eu' se
 * dejan sin implementar a proposito: las URLs publicas que se intentaron
 * verificar (2026-09-13) no resolvieron (404/403) y no hay una fuente
 * confiable para adivinarlas - agregar sus adaptadores cuando se confirme
 * el endpoint correcto de cada una.
 */
class ImportSanctionListsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $codigo)
    {
        $this->onQueue('imports');
    }

    private const CHUNK_SIZE = 500;

    public function handle(): void
    {
        $adapter = $this->adapterFor($this->codigo);

        $list = SanctionList::firstOrCreate(['codigo' => $this->codigo]);

        /**
         * La descarga/parseo (adapter->fetch(), un generador) corre aqui
         * AFUERA de cualquier transaccion: fetch() no ejecuta su HTTP::get()
         * hasta que se itera, asi que si el transaction() envolviera este
         * foreach, la llamada de red a treasury.gov quedaria corriendo con
         * una conexion/transaccion de BD abierta - conexion + lock
         * innecesariamente retenidos durante segundos de red, en un VPS con
         * solo 3 workers de Horizon. Se junta todo en memoria primero (los
         * chunks), y la transaccion solo envuelve los upserts.
         */
        $chunks = [];
        $buffer = [];
        $total = 0;

        foreach ($adapter->fetch() as $entry) {
            /**
             * external_id nulo es inaceptable aqui: dos entradas sin id
             * colapsarian en el mismo upsert (no hay forma de
             * distinguirlas por la clave unica). Sin un id estable no
             * se puede hacer upsert idempotente (seccion 7), asi que se
             * rechaza en vez de arriesgar corromper datos en silencio.
             */
            if ($entry['external_id'] === null) {
                throw new RuntimeException(
                    "El adaptador de '{$this->codigo}' devolvio una entrada sin external_id: {$entry['nombre']}"
                );
            }

            $buffer[] = [
                'sanction_list_id' => $list->id,
                'external_id' => $entry['external_id'],
                'nombre' => $entry['nombre'],
                'aliases' => json_encode($entry['aliases']),
                'tipo' => $entry['tipo'],
                'programa' => $entry['programa'],
                'pais' => $entry['pais'],
                'raw_json' => json_encode($entry['raw_json']),
            ];
            $total++;

            if (count($buffer) >= self::CHUNK_SIZE) {
                $chunks[] = $buffer;
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $chunks[] = $buffer;
        }

        DB::transaction(function () use ($chunks) {
            foreach ($chunks as $chunk) {
                $this->upsertChunk($chunk);
            }
        });

        /**
         * Si el feed respondio 200 pero con contenido vacio/corrupto (ej.
         * pagina de mantenimiento de OFAC), procesar 0 filas NO cuenta como
         * importacion exitosa - en un pipeline de sanciones AML, marcar
         * fecha_importacion sin haber importado nada es peor que no
         * marcarla (esconde el problema en vez de fallar el job).
         */
        if ($total === 0) {
            throw new RuntimeException("La importacion de '{$this->codigo}' proceso 0 entradas - feed vacio o corrupto.");
        }

        $list->update([
            'version' => (string) now()->timestamp,
            'fecha_importacion' => now(),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $chunk
     */
    private function upsertChunk(array $chunk): void
    {
        SanctionEntry::upsert(
            $chunk,
            ['sanction_list_id', 'external_id'],
            ['nombre', 'aliases', 'tipo', 'programa', 'pais', 'raw_json'],
        );
    }

    private function adapterFor(string $codigo): SanctionListAdapterInterface
    {
        return match ($codigo) {
            'ofac_sdn' => app(OfacSdnAdapter::class),
            'un_consolidated', 'eu' => throw new InvalidArgumentException(
                "Adaptador de '{$codigo}' todavia no implementado - falta confirmar la URL oficial vigente."
            ),
            default => throw new InvalidArgumentException("Codigo de lista de sanciones desconocido: {$codigo}"),
        };
    }
}
