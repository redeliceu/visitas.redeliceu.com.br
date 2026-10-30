<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $unidade_id
 * @property Carbon|null $inicio
 * @property Carbon|null $fim
 * @property int|null $meta
 */
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
            'unidade_id' => 'integer',
            'inicio' => 'date',
            'fim' => 'date',
            'meta' => 'integer',
            'atualizado_em' => 'datetime',
        ];
    }

    /** @return BelongsTo<Unidade, $this> */
    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    /** @return HasMany<MetaVendedor, $this> */
    public function metasVendedores(): HasMany
    {
        return $this->hasMany(MetaVendedor::class);
    }
}
