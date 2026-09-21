<?php

declare(strict_types=1);

namespace App\Sources\Sanctions;

interface SanctionListAdapterInterface
{
    /**
     * @return iterable<array{
     *     external_id: string|null,
     *     nombre: string,
     *     aliases: array<int, string>,
     *     tipo: string|null,
     *     programa: string|null,
     *     pais: string|null,
     *     raw_json: array<string, mixed>,
     * }>
     */
    public function fetch(): iterable;
}
