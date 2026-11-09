<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property ?string $sellflux_token
 * @property ?string $sellflux_project_id
 * @property ?Carbon $sellflux_token_updated_at
 */
class Unidade extends Model
{
    protected $fillable = [
        'nome',
        'ativo',
    ];

    /**
     * The SellFlux token must never reach a page, even when a whole model is
     * serialized by accident. It is also left out of $fillable on purpose.
     */
    protected $hidden = [
        'sellflux_token',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'sellflux_token' => 'encrypted',
            'sellflux_token_updated_at' => 'datetime',
        ];
    }

    /**
     * @throws DecryptException when the application key changed since it was saved
     */
    public function sellfluxToken(): ?string
    {
        return $this->sellflux_token;
    }

    /**
     * Checks the stored value without decrypting it.
     */
    public function hasSellfluxToken(): bool
    {
        return $this->getRawOriginal('sellflux_token') !== null;
    }

    /** @return HasMany<SellfluxAtendente, $this> */
    public function sellfluxAtendentes(): HasMany
    {
        return $this->hasMany(SellfluxAtendente::class);
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
