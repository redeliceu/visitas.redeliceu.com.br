<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MatriculaAudit extends Model
{
    protected $fillable = [
        'matricula_id',
        'user_id',
        'turma_anterior_id',
        'turma_id',
        'acao',
        'status_anterior',
        'status',
        'detalhes',
    ];

    protected function casts(): array
    {
        return [
            'detalhes' => 'array',
        ];
    }

    /** @return BelongsTo<Matricula, $this> */
    public function matricula(): BelongsTo
    {
        return $this->belongsTo(Matricula::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Turma, $this> */
    public function turmaAnterior(): BelongsTo
    {
        return $this->belongsTo(Turma::class, 'turma_anterior_id');
    }

    /** @return BelongsTo<Turma, $this> */
    public function turma(): BelongsTo
    {
        return $this->belongsTo(Turma::class);
    }
}
