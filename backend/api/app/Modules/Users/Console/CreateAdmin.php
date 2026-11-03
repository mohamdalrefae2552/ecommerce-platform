<?php

declare(strict_types=1);

namespace App\Modules\Users\Console;

use App\Modules\Users\Models\User;
use App\Shared\Auth\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The only way to obtain an admin (docs/02 section 3).
 *
 *   php artisan app:create-admin admin@shop.example --name="Shop admin"
 *       asks for the password (read from stdin when not interactive).
 *   php artisan app:create-admin --dev
 *       non-production only (D-05): creates or updates the admin from
 *       DEV_ADMIN_EMAIL / DEV_ADMIN_PASSWORD (make bootstrap, E2E, k6).
 */
final class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email? : Admin e-mail} {--name=Administrator : Display name} {--dev : Use DEV_ADMIN_EMAIL / DEV_ADMIN_PASSWORD (non-production only)}';

    protected $description = 'Create an administrator, or promote and reset an existing user';

    public function handle(): int
    {
        if ($this->option('dev') === true) {
            if ($this->laravel->environment('production')) {
                $this->components->error('--dev is refused in production.');

                return self::FAILURE;
            }

            $email = Config::string('users.dev_admin.email');
            $password = Config::string('users.dev_admin.password');
        } else {
            $argument = $this->argument('email');
            $email = is_string($argument) ? $argument : '';
            $secret = $this->input->isInteractive() ? $this->secret('Password (12 to 128 characters)') : stream_get_contents(STDIN);
            $password = is_string($secret) ? trim($secret) : '';
        }

        $email = Str::lower(trim($email));
        $option = $this->option('name');
        $name = is_string($option) ? $option : '';

        $validator = Validator::make(
            ['email' => $email, 'password' => $password, 'name' => $name],
            [
                'email' => ['required', 'email:rfc', 'max:254'],
                'password' => ['required', 'string', 'min:12', 'max:128'],
                'name' => ['required', 'string', 'min:2', 'max:100'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->components->error($message);
            }

            return self::FAILURE;
        }

        $user = User::query()->firstOrNew(['email' => $email]);
        $user->fill(['name' => $name, 'password' => $password]);
        $user->role = Role::Admin;
        $user->disabled_at = null;
        $user->save();

        $this->components->info('Admin ready: '.$email);

        return self::SUCCESS;
    }
}
