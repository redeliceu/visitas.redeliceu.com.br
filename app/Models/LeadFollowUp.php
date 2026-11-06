<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Follow-up note linking a seller to a lead (D-028).
 *
 * @property int $id
 * @property int $lead_id
 * @property int|null $vendedor_id
 * @property int|null $user_id
 * @property string $texto
 * @property Carbon|null $proximo_retorno
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class LeadFollowUp extends Model
{
    use SoftDeletes;

    protected $table = 'lead_follow_ups';

    protected $fillable = [
        'lead_id',
        'vendedor_id',
        'user_id',
        'texto',
        'proximo_retorno',
    ];

    protected function casts(): array
    {
        return [
            'proximo_retorno' => 'datetime',
        ];
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Author edits/deletes own notes; gestor and master manage any note in
     * their units (unit access is checked separately). D-028.
     */
    public function canBeManagedBy(User $user): bool
    {
        return $this->user_id === $user->id
            || in_array($user->role, [User::ROLE_MASTER, User::ROLE_GESTOR], true);
    }
}
