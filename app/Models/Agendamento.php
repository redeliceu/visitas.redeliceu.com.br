<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Agendamento extends Model
{
    protected $fillable = [
        'unidade_id',
        'lead_id',
        'lead_status_anterior_id',
        'vendedor_id',
        'visita_id',
        'data',
        'horario',
        'responsavel',
        'telefone',
        'aluno',
        'observacoes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'date',
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

    /** @return BelongsTo<Visita, $this> */
    public function visita(): BelongsTo
    {
        return $this->belongsTo(Visita::class);
    }
}
