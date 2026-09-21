<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\DerivesTenantFromSubject;
use Database\Factories\SubjectAliasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[Fillable(['subject_id', 'nombre'])]
class SubjectAlias extends Model
{
    /** @use HasFactory<SubjectAliasFactory> */
    use BelongsToTenant, HasFactory, DerivesTenantFromSubject;

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
