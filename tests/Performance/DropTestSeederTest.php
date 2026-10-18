<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\Drop;
use App\Modules\Inventory\Models\Slot;
use App\Modules\Inventory\Models\Venue;
use App\Shared\Members\Member;
use Database\Seeders\DropTestSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

it('seeds a drop, members, bearer tokens and wallet credits', function (): void {
    $original = getenv('DROP_TEST_MEMBERS');
    putenv('DROP_TEST_MEMBERS=50');
    $_ENV['DROP_TEST_MEMBERS'] = '50';
    $_SERVER['DROP_TEST_MEMBERS'] = '50';

    try {
        Artisan::call('db:seed', ['--class' => DropTestSeeder::class]);
    } finally {
        $original === false ? putenv('DROP_TEST_MEMBERS') : putenv('DROP_TEST_MEMBERS='.$original);
        unset($_ENV['DROP_TEST_MEMBERS'], $_SERVER['DROP_TEST_MEMBERS']);
    }

    $venue = Venue::query()->where('slug', 'drop-test-venue')->firstOrFail();
    $drop = Drop::query()->where('venue_id', $venue->id)->firstOrFail();
    $slot = Slot::query()->where('drop_id', $drop->id)->firstOrFail();
    expect($venue->timezone)->toBe('America/New_York')
        ->and($venue->base_per_person_cents)->toBe(25000)
        ->and($drop->opens_at->between(now('UTC')->addSeconds(59), now('UTC')->addSeconds(61)))->toBeTrue()
        ->and($slot->capacity)->toBe(50)
        ->and($slot->min_party_size)->toBe(1)
        ->and($slot->max_party_size)->toBe(2)
        ->and($slot->released_at->equalTo($drop->opens_at))->toBeTrue()
        ->and(Member::query()->where('email', 'like', 'drop-member-%@dorsia.test')->count())->toBe(50);

    $tokensContents = file_get_contents(base_path('loadtest/.data/tokens.json')) ?: throw new RuntimeException('Missing token file.');
    $dropContents = file_get_contents(base_path('loadtest/.data/drop.json')) ?: throw new RuntimeException('Missing drop file.');
    $tokens = json_decode($tokensContents, true, 512, JSON_THROW_ON_ERROR);
    $dropFile = json_decode($dropContents, true, 512, JSON_THROW_ON_ERROR);
    /** @var list<array{member_id: int, token: string}> $tokens */
    /** @var array{venue_id: int, drop_id: int, slot_id: int, opens_at: string} $dropFile */
    expect($tokens)->toHaveCount(50)
        ->and($dropFile)->toMatchArray(['venue_id' => $venue->id, 'drop_id' => $drop->id, 'slot_id' => $slot->id]);
    expect(DB::table('personal_access_tokens')->count())->toBe(50);

    $memberId = $tokens[0]['member_id'];
    $stored = DB::table('personal_access_tokens')->where('tokenable_id', $memberId)->first();
    if ($stored === null) {
        throw new RuntimeException('Missing stored personal access token.');
    }
    expect(hash('sha256', explode('|', $tokens[0]['token'], 2)[1]))->toBe($stored->token);
    expect(DB::table('ledger_transactions')->where('idempotency_key', 'like', 'drop:goodwill:%')->count())->toBe(10)
        ->and(DB::table('ledger_transactions')->where('idempotency_key', 'like', 'drop:goodwill:%')->whereRaw("(metadata->>'amount_cents')::bigint = ?", [10000])->count())->toBe(10)
        ->and(DB::table('ledger_entries')->whereIn('transaction_id', function ($query): void {
            $query->select('id')->from('ledger_transactions')->where('idempotency_key', 'like', 'drop:goodwill:%');
        })->sum('amount_cents'))->toBe(0);
});
