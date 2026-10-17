<?php

declare(strict_types=1);

namespace Tests\Modules\Reservations\Support;

use App\Modules\Inventory\Models\Slot;
use App\Modules\Inventory\Models\Venue;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Support\FakeGateway;
use App\Modules\Reservations\Models\Reservation;
use App\Modules\Wallet\Actions\IssueGoodwillCredit;
use App\Modules\Wallet\Enums\AccountKind;
use App\Modules\Wallet\Models\LedgerTransaction;
use App\Shared\Members\Member;
use App\Shared\Money\Money;
use App\Shared\Outbox\OutboxEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Modules\Inventory\Support\Fixtures;

/**
 * Helpers for the booking tests: a peak-priced slot (quote 75000 for two), HTTP calls, wallet and ledger readers.
 */
final class Booking
{
    /** Frozen "now" of the Feature tests; the slot below starts 9h59m59.75s later. */
    public const NOW = '2026-11-07 15:00:00.250000';

    public const STARTS_AT = '2026-11-08 01:00:00';

    /** Sat 20:00 New York, peak tier (1.5x of 25000 per person) so a party of 2 is quoted 75000. */
    public const QUOTE_CENTS = 75000;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function slot(array $attributes = [], ?Venue $venue = null): Slot
    {
        return Fixtures::slot(array_merge([
            'starts_at' => self::STARTS_AT,
            'released_at' => '2026-10-01 00:00:00',
            'capacity' => 4,
            'min_party_size' => 1,
            'max_party_size' => 4,
        ], $attributes), $venue);
    }

    public static function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::STARTS_AT.' UTC');
    }

    public static function fund(Member|int $member, int $cents, AccountKind $kind = AccountKind::Credit): void
    {
        $id = $member instanceof Member ? $member->id : $member;

        if ($kind === AccountKind::Credit) {
            app(IssueGoodwillCredit::class)->handle($id, Money::of($cents, 'USD'), 'fund:'.Str::uuid());

            return;
        }

        LedgerTransaction::factory()->forMember($id)->kind($kind)->amount($cents)->create();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function post(string $uri, array $body, string $token, ?string $key = null): TestResponse
    {
        // The guard caches the resolved member between requests of one test.
        app('auth')->forgetGuards();

        return test()->withToken($token)
            ->withHeaders(['Idempotency-Key' => $key ?? (string) Str::uuid()])
            ->postJson($uri, $body);
    }

    public static function get(string $uri, string $token): TestResponse
    {
        app('auth')->forgetGuards();

        return test()->withToken($token)->getJson($uri);
    }

    public static function hold(string $token, Slot|int $slot, int $partySize = 2, ?string $key = null): TestResponse
    {
        return self::post('/api/holds', ['slot_id' => $slot instanceof Slot ? $slot->id : $slot, 'party_size' => $partySize], $token, $key);
    }

    public static function confirm(string $token, int $holdId, ?string $cardToken = null, ?string $key = null): TestResponse
    {
        return self::post('/api/reservations', ['hold_id' => $holdId, 'card_token' => $cardToken], $token, $key);
    }

    public static function cancel(string $token, int $reservationId, ?string $key = null): TestResponse
    {
        return self::post("/api/reservations/{$reservationId}/cancel", [], $token, $key);
    }

    /**
     * Holds and confirms in one go; returns the reservation id.
     */
    public static function book(string $token, Slot|int $slot, ?string $cardToken = null, int $partySize = 2): int
    {
        $holdId = (int) self::hold($token, $slot, $partySize)->assertCreated()->json('data.id');

        return (int) self::confirm($token, $holdId, $cardToken)->assertCreated()->json('data.id');
    }

    public static function gateway(): FakeGateway
    {
        $gateway = app(PaymentGateway::class);

        assert($gateway instanceof FakeGateway);

        return $gateway;
    }

    /**
     * Balance of one ledger account as the cache stores it (normal-side orientation); 0 if it does not exist.
     */
    public static function balance(string $ownerType, int $ownerId, AccountKind $kind): int
    {
        return (int) DB::table('ledger_accounts')
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('kind', $kind->value)
            ->value('balance_cents');
    }

    public static function credit(Member|int $member): int
    {
        return self::balance('member', $member instanceof Member ? $member->id : $member, AccountKind::Credit);
    }

    public static function venuePayable(int $venueId): int
    {
        return self::balance('venue', $venueId, AccountKind::VenuePayable);
    }

    public static function platform(): int
    {
        return self::balance('system', 0, AccountKind::Platform);
    }

    public static function cardClearing(): int
    {
        return self::balance('system', 0, AccountKind::Cash);
    }

    /**
     * Entries of one ledger transaction as "owner_type:owner_id:kind" => signed cents (debit positive).
     *
     * @return array<string, int>
     */
    public static function entries(int $transactionId): array
    {
        $entries = [];

        $rows = DB::table('ledger_entries')
            ->join('ledger_accounts', 'ledger_accounts.id', '=', 'ledger_entries.account_id')
            ->where('ledger_entries.transaction_id', $transactionId)
            ->orderBy('ledger_entries.id')
            ->get(['ledger_accounts.owner_type', 'ledger_accounts.owner_id', 'ledger_accounts.kind', 'ledger_entries.amount_cents']);

        foreach ($rows as $row) {
            $entries["{$row->owner_type}:{$row->owner_id}:{$row->kind}"] = (int) $row->amount_cents;
        }

        return $entries;
    }

    /**
     * Ids of the ledger transactions of one type, oldest first.
     *
     * @return list<int>
     */
    public static function transactions(string $type): array
    {
        return DB::table('ledger_transactions')->where('type', $type)->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /** `wallet:reconcile` must exit 0: entries balance, global sum is 0, no cache drift. */
    public static function assertLedgerClean(): void
    {
        expect(Artisan::call('wallet:reconcile'))->toBe(0, 'wallet:reconcile found a problem: '.Artisan::output());
    }

    /**
     * @return array<string, int> topic => rows
     */
    public static function outboxCounts(): array
    {
        return OutboxEvent::query()->orderBy('topic')->get()->countBy('topic')->all();
    }

    public static function reservationCount(): int
    {
        return Reservation::query()->count();
    }

    /**
     * Snapshot of every table a confirm or cancel may write, to prove a replay or a failure wrote nothing.
     *
     * @return array<string, int|array<string, int>>
     */
    public static function footprint(): array
    {
        return [
            'reservations' => self::reservationCount(),
            'payments' => DB::table('payments')->count(),
            'ledger_transactions' => DB::table('ledger_transactions')->count(),
            'ledger_entries' => DB::table('ledger_entries')->count(),
            'outbox' => self::outboxCounts(),
            'authorize_calls' => self::gateway()->authorizeCalls(),
        ];
    }

    /**
     * One HTTP request through the in-process kernel, for use inside a forked child (Concurrency::run).
     *
     * @param  array<string, mixed>  $body
     * @return array{status: int, code: string|null, json: array<mixed>, headers: array<string, string>}
     */
    public static function call(string $method, string $uri, string $token, array $body = [], ?string $key = null): array
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ];

        if ($key !== null) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $key;
        }

        app('auth')->forgetGuards();

        $response = app(Kernel::class)->handle(Request::create(
            $uri,
            $method,
            [],
            [],
            [],
            $server,
            $method === 'GET' ? null : json_encode($body, JSON_THROW_ON_ERROR),
        ));

        $decoded = json_decode((string) $response->getContent(), true);
        $json = is_array($decoded) ? $decoded : [];
        $code = $json['error']['code'] ?? null;

        $headers = [];

        foreach ($response->headers->all() as $name => $values) {
            $headers[(string) $name] = (string) (is_array($values) ? ($values[0] ?? '') : $values);
        }

        return ['status' => $response->getStatusCode(), 'code' => is_string($code) ? $code : null, 'json' => $json, 'headers' => $headers];
    }

    /**
     * @param  list<array{status: int, code: string|null}>  $responses
     * @return array<string, int> "status code" => count
     */
    public static function tally(array $responses): array
    {
        $tally = [];

        foreach ($responses as $response) {
            $label = $response['status'].' '.($response['code'] ?? 'ok');
            $tally[$label] = ($tally[$label] ?? 0) + 1;
        }

        ksort($tally);

        return $tally;
    }
}
