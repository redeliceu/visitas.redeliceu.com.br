<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    protected $fillable = [
        'unidade_id',
        'vendedor_id',
        'data',
        'responsavel',
        'telefone',
        'email',
        'aluno',
        'nascimento',
        'serie_id',
        'ciclo_id',
        'turno_id',
        'canal_contato',
        'status_id',
        'retorno_data',
        'observacoes',
        'origem',
        'data_matricula',
        'desconto',
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'datetime',
            'nascimento' => 'date',
            'retorno_data' => 'datetime',
            'data_matricula' => 'datetime',
            'desconto' => 'decimal:2',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relacionamentos
    |--------------------------------------------------------------------------
    */

    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(Vendedor::class);
    }

    public function serie(): BelongsTo
    {
        return $this->belongsTo(Serie::class);
    }

    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class);
    }

    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'status_id');
    }

    public function visitas(): HasMany
    {
        return $this->hasMany(Visita::class);
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
