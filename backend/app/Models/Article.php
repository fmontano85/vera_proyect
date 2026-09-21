<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Global, deduplicado por url (ver seccion 3.1/3.3 del CLAUDE.md raiz) -
 * sin tenant_id. url_hash (sha256 de url) es la clave unica real: 'url'
 * puede superar el limite de bytes indexables de InnoDB con utf8mb4.
 */
#[Fillable(['url', 'titulo', 'medio', 'fecha_publicacion', 'hash_contenido', 'evidence_path', 'estado_extraccion'])]
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'fecha_publicacion' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $article) {
            $article->url_hash = hash('sha256', $article->url);
        });
    }
}
