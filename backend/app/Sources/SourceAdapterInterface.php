<?php

declare(strict_types=1);

namespace App\Sources;

interface SourceAdapterInterface
{
    /**
     * @return array{urls: array<int, string>, costo: float|null}
     */
    public function buscar(string $query): array;
}
