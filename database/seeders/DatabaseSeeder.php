<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

final class DatabaseSeeder extends Seeder
{
    /**
     * Modules whose seeder is called once its owner has created it (C§15).
     *
     * @var list<string>
     */
    private const SEEDED_MODULES = ['Inventory', 'Wallet'];

    public function run(): void
    {
        $this->call(MemberSeeder::class);

        foreach (self::SEEDED_MODULES as $module) {
            $seeder = Str::replace('{module}', $module, 'App\Modules\{module}\Database\Seeders\{module}Seeder');

            if (class_exists($seeder)) {
                $this->call($seeder);
            }
        }
    }
}
