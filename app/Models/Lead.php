<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Auth;

class Lead extends Model
{
    protected $fillable = [
        'unidade_id',
        'vendedor_id',
        'data',
        'responsavel',
        'telefone',
        'telefone_secundario',
        'email',
        'aluno',
        'idade',
        'nascimento',
        'serie_id',
        'ciclo_id',
        'turno_id',
        'canal_contato',
        'canal_mkt',
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
            'idade' => 'integer',
            'retorno_data' => 'datetime',
            'data_matricula' => 'datetime',
            'desconto' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Lead $lead): void {
            $lead->recordStatusChange(null, $lead->status_id);
        });

        static::updated(function (Lead $lead): void {
            if ($lead->wasChanged('status_id')) {
                $lead->recordStatusChange(
                    $lead->getOriginal('status_id'),
                    $lead->status_id,
                );
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relacionamentos
    |--------------------------------------------------------------------------
    */

    /** @return BelongsTo<Unidade, $this> */
    public function unidade(): BelongsTo
    {
        return $this->belongsTo(Unidade::class);
    }

    /** @return BelongsTo<Vendedor, $this> */
    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(Vendedor::class);
    }

    /** @return BelongsTo<Serie, $this> */
    public function serie(): BelongsTo
    {
        return $this->belongsTo(Serie::class);
    }

    /** @return BelongsTo<Ciclo, $this> */
    public function ciclo(): BelongsTo
    {
        return $this->belongsTo(Ciclo::class);
    }

    /** @return BelongsTo<Turno, $this> */
    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class);
    }

    /** @return BelongsTo<LeadStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(LeadStatus::class, 'status_id');
    }

    /** @return HasMany<LeadStatusHistory, $this> */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(LeadStatusHistory::class)
            ->orderBy('changed_at')
            ->orderBy('id');
    }

    /** @return HasMany<Visita, $this> */
    public function visitas(): HasMany
    {
        return $this->hasMany(Visita::class);
    }

    /** @return HasOne<Agendamento, $this> */
    public function agendamento(): HasOne
    {
        return $this->hasOne(Agendamento::class);
    }

    /** @return HasMany<Matricula, $this> */
    public function matriculas(): HasMany
    {
        return $this->hasMany(Matricula::class);
    }

    private function recordStatusChange(int|string|null $fromStatusId, int|string|null $toStatusId): void
    {
        if ($fromStatusId === $toStatusId) {
            return;
        }

        $this->statusHistories()->create([
            'from_status_id' => $fromStatusId,
            'to_status_id' => $toStatusId,
            'changed_by' => Auth::id(),
            'changed_at' => now(),
        ]);
    }
}
