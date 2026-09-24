<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SearchResult;
use App\Models\User;

/**
 * Flujo bajo demanda (seccion 3.7 del CLAUDE.md raiz). capturaManual
 * excluye a analista a proposito: capturar a mano deja el match YA
 * resuelto (no pasa por proponer -> resolver), asi que solo lo pueden
 * hacer los roles que ya resuelven en firme (seccion 3.2), igual que
 * MentionMatchPolicy::resolver().
 */
class SearchResultPolicy
{
    public function view(User $user, SearchResult $searchResult): bool
    {
        return true;
    }

    public function extraer(User $user, SearchResult $searchResult): bool
    {
        return $user->hasAnyRole(['admin', 'oficial_cumplimiento', 'analista']);
    }

    public function descartar(User $user, SearchResult $searchResult): bool
    {
        return $user->hasAnyRole(['admin', 'oficial_cumplimiento', 'analista']);
    }

    public function capturaManual(User $user, SearchResult $searchResult): bool
    {
        return $user->hasAnyRole(['admin', 'oficial_cumplimiento']);
    }
}
