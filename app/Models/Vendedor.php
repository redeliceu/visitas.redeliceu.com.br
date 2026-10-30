<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vendedor extends Model
{
    protected $table = 'vendedores';

    protected $fillable = [
        'unidade_id',
        'user_id',
        'nome',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
        ];
    }

    /** @return BelongsTo<Unidade, $this> */
    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Lead, $this> */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /** @return HasMany<Visita, $this> */
    public function visitas(): HasMany
    {
        return $this->hasMany(Visita::class);
    }

    /** @return HasMany<Agendamento, $this> */
    public function agendamentos(): HasMany
    {
        return $this->hasMany(Agendamento::class);
    }

    /** @return HasMany<MetaVendedor, $this> */
    public function metas(): HasMany
    {
        return $this->hasMany(MetaVendedor::class);
    }
}
