<?php

declare(strict_types=1);

namespace App\Modules\Users\Database\Factories;

use App\Modules\Users\Models\User;
use App\Shared\Auth\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    public const string PASSWORD = 'correct-horse-battery';

    protected $model = User::class;

    private static ?string $passwordHash = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Hash once per process: argon2id is deliberately slow.
        self::$passwordHash ??= Hash::make(self::PASSWORD);

        return [
            'name' => fake()->name(),
            'email' => Str::lower((string) Str::ulid()).'@example.test',
            'password' => self::$passwordHash,
        ];
    }

    public function customer(): self
    {
        return $this->state(['role' => Role::Customer]);
    }

    public function admin(): self
    {
        return $this->state(['role' => Role::Admin]);
    }

    public function disabled(): self
    {
        return $this->state(['disabled_at' => Carbon::now()]);
    }
}
