<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\DerivesTenantFromSubject;
use Database\Factories\SubjectAliasFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Stancl\Tenancy\Database\Concerns\BelongsToTenant;

#[Fillable(['subject_id', 'nombre'])]
class SubjectAlias extends Model
{
    /** @use HasFactory<SubjectAliasFactory> */
    use BelongsToTenant, DerivesTenantFromSubject, HasFactory, LogsActivity;

    /**
     * Seccion 7: todo cambio en la lista de vigilancia queda auditado - los
     * aliases cambian a quien encuentra el matching.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['subject_id', 'nombre']);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
