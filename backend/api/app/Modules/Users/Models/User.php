<?php

declare(strict_types=1);

namespace App\Modules\Users\Models;

use App\Modules\Users\Database\Factories\UserFactory;
use App\Shared\Auth\AuthUser;
use App\Shared\Auth\Role;
use App\Shared\Database\HasUppercaseUlids;
use Illuminate\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property Role $role
 * @property Carbon|null $disabled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[UseFactory(UserFactory::class)]
final class User extends Model implements AuthUser
{
    use Authenticatable;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUppercaseUlids;

    /**
     * `role` is never mass assignable (API3): only RegisterUser (customer)
     * and app:create-admin set it explicitly.
     *
     * @var list<string>
     */
    protected $fillable = ['name', 'email', 'password'];

    /**
     * @var list<string>
     */
    protected $hidden = ['password'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['role' => 'customer'];

    public function userId(): string
    {
        return $this->id;
    }

    public function userRole(): Role
    {
        return $this->role;
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'disabled_at' => 'datetime',
        ];
    }
}
