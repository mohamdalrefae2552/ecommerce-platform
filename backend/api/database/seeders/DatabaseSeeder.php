<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Products\Database\Seeders\CategorySeeder;
use App\Modules\Users\Database\Seeders\CustomerSeeder;
use Illuminate\Database\Seeder;

/**
 * Entry point of `php artisan db:seed` (run by `make bootstrap`). It only
 * delegates to the seeders of the modules, listed in SEEDERS; each module
 * adds its own seeder there when it has demo data.
 */
final class DatabaseSeeder extends Seeder
{
    /**
     * @var list<class-string<Seeder>>
     */
    private const array SEEDERS = [
        CategorySeeder::class,
        CustomerSeeder::class,
    ];

    public function run(): void
    {
        $this->call(self::SEEDERS);
    }
}
