<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Inventory\Models\City;
use App\Modules\Inventory\Models\Drop;
use App\Modules\Inventory\Models\Slot;
use App\Modules\Inventory\Models\Venue;
use App\Modules\Wallet\Actions\IssueGoodwillCredit;
use App\Shared\Members\Member;
use App\Shared\Money\Money;
use App\Shared\Support\Timestamps;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class DropTestSeeder extends Seeder
{
    public function run(): void
    {
        $count = max(1, (int) Env::get('DROP_TEST_MEMBERS', 5000));
        $now = CarbonImmutable::now('UTC');
        $localStart = CarbonImmutable::now('America/New_York')->next(CarbonImmutable::SATURDAY)->setTime(20, 0);
        $opensAt = $now->addSeconds(60);

        $city = City::query()->create(['name' => 'New York', 'slug' => 'drop-test-new-york', 'country_code' => 'US']);
        $venue = Venue::query()->create([
            'city_id' => $city->id, 'name' => 'Drop Test Venue', 'slug' => 'drop-test-venue',
            'timezone' => 'America/New_York', 'status' => 'active', 'base_per_person_cents' => 25000, 'currency' => 'USD',
        ]);
        $drop = Drop::query()->create(['venue_id' => $venue->id, 'opens_at' => $opensAt]);
        $slot = Slot::query()->create([
            'venue_id' => $venue->id, 'drop_id' => $drop->id,
            'starts_at' => $localStart->utc(), 'released_at' => $opensAt,
            'capacity' => 50, 'held_count' => 0, 'booked_count' => 0,
            'min_party_size' => 1, 'max_party_size' => 2,
        ]);

        $password = Hash::make(Str::random(40));
        $memberRows = [];
        for ($i = 1; $i <= $count; $i++) {
            $memberRows[] = [
                'name' => sprintf('Drop Member %04d', $i),
                'email' => sprintf('drop-member-%04d@dorsia.test', $i),
                'password' => $password,
                'created_at' => $now->format(Timestamps::FORMAT),
                'updated_at' => $now->format(Timestamps::FORMAT),
            ];
        }
        foreach (array_chunk($memberRows, 500) as $chunk) {
            DB::table('members')->insert($chunk);
        }

        $members = Member::query()->where('email', 'like', 'drop-member-%@dorsia.test')->orderBy('id')->get(['id', 'email']);
        $tokens = [];
        $tokenRows = [];
        foreach ($members as $member) {
            $plain = Str::random(64);
            $tokens[] = ['member_id' => $member->id, 'token' => $member->id.'|'.$plain];
            $tokenRows[] = [
                'tokenable_type' => Member::class, 'tokenable_id' => $member->id,
                'name' => 'drop-test', 'token' => hash('sha256', $plain), 'abilities' => '["*"]',
                'created_at' => $now->format(Timestamps::FORMAT), 'updated_at' => $now->format(Timestamps::FORMAT),
            ];
        }
        foreach (array_chunk($tokenRows, 500) as $chunk) {
            DB::table('personal_access_tokens')->insert($chunk);
        }

        $goodwill = app(IssueGoodwillCredit::class);
        foreach ($members->values() as $index => $member) {
            if (($index + 1) % 5 === 0) {
                $goodwill->handle((int) $member->id, Money::of(10000, 'USD'), 'drop:goodwill:'.$member->id);
            }
        }

        if (! is_dir(base_path('loadtest/.data'))) {
            mkdir(base_path('loadtest/.data'), 0775, true);
        }
        file_put_contents(base_path('loadtest/.data/tokens.json'), json_encode($tokens, JSON_THROW_ON_ERROR));
        file_put_contents(base_path('loadtest/.data/drop.json'), json_encode([
            'venue_id' => $venue->id, 'drop_id' => $drop->id, 'slot_id' => $slot->id,
            'opens_at' => $opensAt->format(Timestamps::FORMAT),
        ], JSON_THROW_ON_ERROR));
    }
}
