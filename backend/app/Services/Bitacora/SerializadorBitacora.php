<?php

declare(strict_types=1);

namespace App\Services\Bitacora;

use App\Models\Activity;
use App\Models\ConfiguracionSanciones;
use App\Models\FrecuenciaSeguimiento;
use App\Models\MentionMatch;
use App\Models\SanctionMatch;
use App\Models\SearchResult;
use App\Models\SearchTag;
use App\Models\Subject;
use App\Models\SubjectAlias;
use App\Models\User;
use Stancl\Tenancy\Database\Models\Tenant;

/**
 * JSON explicito de la bitacora (seccion 3.9, punto 7). Dos formas:
 * completa para el admin de su tenant, y sin datos personales para el
 * superadmin (opcion A, 2026-09-28): quien, que evento, cuando y
 * "persona #id" - nunca descripcion, cambios ni propiedades, que pueden
 * llevar nombres, documentos o tags de la persona vigilada.
 */
final class SerializadorBitacora
{
    private const TIPOS = [
        Subject::class => 'persona',
        SubjectAlias::class => 'alias',
        MentionMatch::class => 'coincidencia',
        SanctionMatch::class => 'sancion',
        SearchResult::class => 'resultado',
        SearchTag::class => 'tag',
        User::class => 'usuario',
        FrecuenciaSeguimiento::class => 'frecuencia',
        Tenant::class => 'tenant',
        ConfiguracionSanciones::class => 'configuracion_sanciones',
    ];

    /** @return array<string, mixed> */
    public function completo(Activity $registro): array
    {
        return $this->base($registro) + [
            'descripcion' => $registro->description,
            'cambios' => $registro->attribute_changes,
            'propiedades' => $registro->properties,
        ];
    }

    /**
     * @param  array<string, string|null>  $nombresTenant  id => name
     * @return array<string, mixed>
     */
    public function sinDatosPersonales(Activity $registro, array $nombresTenant): array
    {
        return $this->base($registro) + [
            'tenant' => $registro->tenant_id === null ? null : [
                'id' => $registro->tenant_id,
                'name' => $nombresTenant[$registro->tenant_id] ?? null,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function base(Activity $registro): array
    {
        $causante = $registro->causer;

        return [
            'id' => $registro->id,
            'fecha' => $registro->created_at?->toIso8601String(),
            'evento' => $registro->event,
            'usuario' => $causante instanceof User ? ['id' => $causante->id, 'name' => $causante->name] : null,
            'objeto' => $registro->subject_type === null ? null : [
                'tipo' => self::TIPOS[$registro->subject_type] ?? class_basename($registro->subject_type),
                // Los ids de modelos de la app son enteros; el de Tenant es UUID.
                'id' => ctype_digit((string) $registro->subject_id) ? (int) $registro->subject_id : $registro->subject_id,
            ],
        ];
    }
}
