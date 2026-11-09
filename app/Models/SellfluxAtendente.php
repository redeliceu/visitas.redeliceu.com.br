<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person who answers chats on a unit's SellFlux project, and the seller a
 * master confirmed for them. Leads only follow a confirmed association.
 *
 * @property string $nome
 * @property string $situacao
 * @property int|null $vendedor_id
 * @property Carbon $visto_em
 */
class SellfluxAtendente extends Model
{
    public const PENDENTE = 'pendente';

    public const ASSOCIADO = 'associado';

    public const IGNORADO = 'ignorado';

    protected $table = 'sellflux_atendentes';

    protected $fillable = [
        'unidade_id',
        'sellflux_user_id',
        'nome',
        'vendedor_id',
        'situacao',
        'visto_em',
        'alterado_por',
    ];

    protected function casts(): array
    {
        return [
            'visto_em' => 'datetime',
        ];
    }

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
}
