<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Campanha extends Model
{
    protected $fillable = [
        'unidade_id',
        'inicio',
        'fim',
        'meta',
        'atualizado_em',
    ];

    protected function casts(): array
    {
        return [
            'inicio' => 'date',
            'fim' => 'date',
            'meta' => 'integer',
            'atualizado_em' => 'datetime',
        ];
    }

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function metasVendedores(): HasMany
    {
        return $this->hasMany(MetaVendedor::class);
    }
}
