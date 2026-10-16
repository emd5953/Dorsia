<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Shared\Members\Member;
use Illuminate\Database\Seeder;

final class MemberSeeder extends Seeder
{
    public function run(): void
    {
        Member::query()->updateOrCreate(
            ['email' => 'demo@dorsia.test'],
            ['name' => 'Demo Member', 'password' => 'password'],
        );
    }
}
