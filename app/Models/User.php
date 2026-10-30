<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string $role
 * @property bool $ativo
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role', 'ativo'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public const ROLE_MASTER = 'master';

    public const ROLE_GESTOR = 'gestor';

    public const ROLE_ATENDIMENTO = 'atendimento';

    public const ROLES = [
        self::ROLE_MASTER,
        self::ROLE_GESTOR,
        self::ROLE_ATENDIMENTO,
    ];

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'ativo' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Unidade, $this> */
    public function unidades(): BelongsToMany
    {
        return $this->belongsToMany(Unidade::class, 'unidade_user');
    }

    /** @return HasMany<Vendedor, $this> */
    public function vendedores(): HasMany
    {
        return $this->hasMany(Vendedor::class);
    }

    public function isMaster(): bool
    {
        return $this->role === self::ROLE_MASTER;
    }

    public function canAccessUnit(Unidade|int $unidade): bool
    {
        if ($this->isMaster()) {
            return true;
        }

        $unidadeId = $unidade instanceof Unidade ? $unidade->getKey() : $unidade;

        return $this->unidades()
            ->whereKey($unidadeId)
            ->where('unidades.ativo', true)
            ->exists();
    }
}
