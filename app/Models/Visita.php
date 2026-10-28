<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(Vendedor::class);
    }

    public function agendamentos(): HasMany
    {
        return $this->hasMany(Agendamento::class);
    }

    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }
}
