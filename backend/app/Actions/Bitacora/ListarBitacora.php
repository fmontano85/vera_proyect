<?php

declare(strict_types=1);

namespace App\Actions\Bitacora;

use App\Models\Activity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consulta de la bitacora con filtros (seccion 3.9, punto 7). Activity no
 * tiene scope de tenant a proposito (ver App\Models\Activity): el admin
 * entra SIEMPRE por delTenant(), con el tenant obligatorio - no como un
 * filtro opcional que un valor vacio pudiera saltarse.
 *
 * Las fechas desde/hasta son dias de calendario de El Salvador
 * (vera.zona_horaria); created_at se guarda en UTC.
 */
class ListarBitacora
{
    /** @param  array{evento?: string|null, usuario_id?: int|null, desde?: string|null, hasta?: string|null}  $filtros */
    public function delTenant(string $tenantId, array $filtros): LengthAwarePaginator
    {
        return $this->consulta($filtros)->where('tenant_id', $tenantId)->paginate(50);
    }

    /** @param  array{tenant_id?: string|null, evento?: string|null, desde?: string|null, hasta?: string|null}  $filtros */
    public function global(array $filtros): LengthAwarePaginator
    {
        return $this->consulta($filtros)
            ->when($filtros['tenant_id'] ?? null, fn (Builder $q, string $id) => $q->where('tenant_id', $id))
            ->paginate(50);
    }

    /** @param  array<string, mixed>  $filtros */
    private function consulta(array $filtros): Builder
    {
        $zona = config('vera.zona_horaria');

        return Activity::query()
            ->with('causer')
            ->when($filtros['evento'] ?? null, fn (Builder $q, string $evento) => $q->where('event', $evento))
            ->when($filtros['usuario_id'] ?? null, fn (Builder $q, int $id) => $q
                ->where('causer_type', User::class)->where('causer_id', $id))
            ->when($filtros['desde'] ?? null, fn (Builder $q, string $desde) => $q
                ->where('created_at', '>=', CarbonImmutable::parse($desde, $zona)->startOfDay()->utc()))
            ->when($filtros['hasta'] ?? null, fn (Builder $q, string $hasta) => $q
                ->where('created_at', '<=', CarbonImmutable::parse($hasta, $zona)->endOfDay()->utc()))
            ->orderByDesc('id');
    }
}
