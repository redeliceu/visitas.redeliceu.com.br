<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaVendedor extends Model
{
    protected $table = 'metas_vendedores';

    protected $fillable = [
        'campanha_id',
        'vendedor_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'integer',
        ];
    }

    public function campanha(): BelongsTo
    {
        return $this->belongsTo(Campanha::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(Vendedor::class);
    }
}
