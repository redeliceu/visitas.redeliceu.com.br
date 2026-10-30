<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Matricula extends Model
{
    protected $fillable = [
        'unidade_id',
        'lead_id',
        'visita_id',
        'turma_id',
        'data',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'datetime',
        ];
    }

    /** @return HasMany<MatriculaAudit, $this> */
    public function audits(): HasMany
    {
        return $this->hasMany(MatriculaAudit::class);
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

    /** @return BelongsTo<Visita, $this> */
    public function visita(): BelongsTo
    {
        return $this->belongsTo(Visita::class);
    }

    /** @return BelongsTo<Turma, $this> */
    public function turma(): BelongsTo
    {
        return $this->belongsTo(Turma::class);
    }
}
