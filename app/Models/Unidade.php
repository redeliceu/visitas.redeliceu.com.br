<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Unidade extends Model
{
    protected $fillable = [
        'nome',
        'ativo',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
        ];
    }

    /** @return HasMany<Vendedor, $this> */
    public function vendedores(): HasMany
    {
        return $this->hasMany(Vendedor::class);
    }

    /** @return BelongsToMany<User, $this> */
    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'unidade_user');
    }

    /** @return HasMany<Turma, $this> */
    public function turmas(): HasMany
    {
        return $this->hasMany(Turma::class);
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

    /** @return HasMany<Campanha, $this> */
    public function campanhas(): HasMany
    {
        return $this->hasMany(Campanha::class);
    }

    /** @return HasMany<Matricula, $this> */
    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }
}
