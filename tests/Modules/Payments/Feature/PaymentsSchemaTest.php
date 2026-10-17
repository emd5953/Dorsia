<?php

declare(strict_types=1);

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\PaymentsServiceProvider;
use App\Modules\Payments\Support\FakeGateway;
use App\Shared\Members\Member;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Support/helpers.php';

/**
 * @param  array<string, mixed>  $overrides
 */
function insertPaymentRow(array $overrides = []): void
{
    static $n = 0;
    $n++;

    DB::table('payments')->insert($overrides + [
        'member_id' => Member::query()->value('id') ?? Member::factory()->create()->id,
        'hold_id' => $n,
        'idempotency_key' => "hold:{$n}:auth",
        'amount_cents' => 100,
        'currency' => 'USD',
        'status' => 'authorized',
        'created_at' => '2026-10-17 10:00:00.123456+00',
        'updated_at' => '2026-10-17 10:00:00.123456+00',
    ]);
}

it('stores payment amount_cents as bigint', function (): void {
    $column = DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_name = 'payments' AND column_name = 'amount_cents'");
    expect($column?->data_type)->toBe('bigint');
});

it('rejects a duplicate provider_ref in raw SQL but allows many nulls', function (): void {
    insertPaymentRow(['provider_ref' => 'fake_ch_DUP']);

    expect(fn () => DB::transaction(fn () => insertPaymentRow(['provider_ref' => 'fake_ch_DUP'])))->toThrow(QueryException::class);

    insertPaymentRow(['provider_ref' => null]);
    insertPaymentRow(['provider_ref' => null]);

    expect(DB::table('payments')->whereNull('provider_ref')->count())->toBe(2);
});

it('rejects a duplicate idempotency_key', function (): void {
    insertPaymentRow(['idempotency_key' => 'hold:1:auth']);

    expect(fn () => DB::transaction(fn () => insertPaymentRow(['idempotency_key' => 'hold:1:auth'])))->toThrow(QueryException::class);
});

it('rejects non-positive amounts and unknown statuses', function (array $override): void {
    expect(fn () => DB::transaction(fn () => insertPaymentRow($override)))->toThrow(QueryException::class);
})->with([
    'zero' => [['amount_cents' => 0]],
    'negative' => [['amount_cents' => -5]],
    'bad status' => [['status' => 'settled']],
]);

it('defaults provider to fake and requires an existing member', function (): void {
    insertPaymentRow();

    expect(DB::table('payments')->value('provider'))->toBe('fake')
        ->and(fn () => DB::transaction(fn () => insertPaymentRow(['member_id' => 999_999])))->toThrow(QueryException::class);
});

it('enforces one webhook event per provider and event id', function (): void {
    $row = ['provider' => 'fake', 'event_id' => 'evt_1', 'type' => 'payment.authorized', 'payload' => '{}', 'created_at' => '2026-10-17 10:00:00+00'];
    DB::table('payment_webhook_events')->insert($row);

    expect(fn () => DB::transaction(fn () => DB::table('payment_webhook_events')->insert($row)))->toThrow(QueryException::class);

    DB::table('payment_webhook_events')->insert([...$row, 'provider' => 'other']);

    expect(DB::table('payment_webhook_events')->count())->toBe(2);
});

it('stores timestamps as timestamptz with microsecond precision', function (): void {
    $columns = DB::select(
        "SELECT table_name, column_name, datetime_precision, data_type FROM information_schema.columns
         WHERE table_name IN ('payments', 'payment_webhook_events') AND column_name IN ('created_at', 'updated_at', 'processed_at')",
    );

    expect($columns)->toHaveCount(4);

    foreach ($columns as $column) {
        expect($column->data_type)->toBe('timestamp with time zone')
            ->and($column->datetime_precision)->toBe(6);
    }
});

it('binds the fake as the gateway singleton and rejects any other gateway at boot', function (): void {
    expect(app(PaymentGateway::class))->toBeInstanceOf(FakeGateway::class);

    config(['payments.gateway' => 'stripe']);

    expect(fn () => (new PaymentsServiceProvider(app()))->boot())->toThrow(LogicException::class, 'stripe');

    config(['payments.gateway' => 'fake']);
    (new PaymentsServiceProvider(app()))->boot();
});

it('merges the payments config with the C12 defaults', function (): void {
    expect(config('payments.gateway'))->toBe('fake')
        ->and(config('payments.fake.latency_ms'))->toBe(0)
        ->and(config('payments.fake.emit_webhooks'))->toBeFalse()
        ->and(config('payments.fake.duplicate_webhooks'))->toBeFalse()
        ->and(config('payments.fake'))->toHaveKey('webhook_secret');
});
