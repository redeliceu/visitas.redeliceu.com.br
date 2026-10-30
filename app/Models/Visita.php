<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Visita extends Model
{
    protected $fillable = [
        'unidade_id',
        'lead_id',
        'vendedor_id',
        'data',
        'horario',
        'responsavel',
        'telefone',
        'telefone2',
        'aluno',
        'idade',
        'serie',
        'situacao',
        'instituicao',
        'fonte',
        'status',
        'observacoes',
        'retorno_data',
        'motivo_perda',
        'agendamento_id',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'idade' => 'integer',
            'retorno_data' => 'datetime',
        ];
    }

    /** @return BelongsTo<Unidade, $this> */
    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<Vendedor, $this> */
    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(Vendedor::class);
    }

    /** @return HasMany<Agendamento, $this> */
    /** @return HasMany<Agendamento, $this> */
    public function agendamentos(): HasMany
    {
        return $this->hasMany(Agendamento::class);
    }

    /** @return HasMany<Matricula, $this> */
    /** @return HasMany<Matricula, $this> */
    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }

    /** @return HasOne<Matricula, $this> */
    public function matriculaAtiva(): HasOne
    {
        return $this->hasOne(Matricula::class)->where('status', 'Ativa');
    }
}
