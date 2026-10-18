<?php

declare(strict_types=1);

use App\Modules\Pricing\Actions\RecordSearch;
use App\Modules\Pricing\Models\SearchEvent;
use App\Shared\Members\Member;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Modules\Pricing\Support\Searches;

it('rolls back to its savepoint when the failed statement aborted the caller transaction', function (): void {
    Log::spy();
    $member = Member::factory()->create();
    $level = DB::transactionLevel();

    $this->mock(RecordSearch::class, function ($mock): void {
        $mock->shouldReceive('handle')->andReturnUsing(function (): bool {
            // Same effect as a deadlock: the statement fails and Postgres marks the transaction aborted.
            DB::select('SELECT 1 / 0');

            return true;
        });
    });

    event(Searches::event($member->id));

    // The caller's transaction is usable again and Laravel's counter is untouched.
    expect(DB::transactionLevel())->toBe($level)
        ->and(DB::selectOne('SELECT 1 AS ok')->ok)->toBe(1)
        ->and(Member::query()->whereKey($member->id)->exists())->toBeTrue();
    Log::shouldHaveReceived('warning')->once();
});

it('survives a deadlock-style QueryException inside a nested caller transaction', function (): void {
    $member = Member::factory()->create();

    $this->mock(RecordSearch::class, function ($mock): void {
        $mock->shouldReceive('handle')->andReturnUsing(function (): bool {
            try {
                DB::select('SELECT 1 / 0');
            } catch (QueryException $e) {
                throw new QueryException('pgsql', 'insert', [], new PDOException('SQLSTATE[40P01]: deadlock detected', 0, $e));
            }

            return true;
        });
    });

    DB::transaction(function () use ($member): void {
        $level = DB::transactionLevel();

        event(Searches::event($member->id));

        expect(DB::transactionLevel())->toBe($level);

        DB::table('members')->where('id', $member->id)->update(['name' => 'still writable']);
    });

    expect(Member::query()->findOrFail($member->id)->name)->toBe('still writable');
});

it('records normally inside a caller transaction and releases its savepoint', function (): void {
    $member = Member::factory()->create();

    DB::transaction(function () use ($member): void {
        event(Searches::event($member->id));
        event(Searches::event($member->id, hour: 21));
    });

    expect(SearchEvent::query()->count())->toBe(2);
});
