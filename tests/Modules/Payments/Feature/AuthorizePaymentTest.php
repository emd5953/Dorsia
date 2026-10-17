<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\AuthorizePayment;
use App\Modules\Payments\Actions\VoidPayment;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\AuthorizeRequest;
use App\Modules\Payments\Data\GatewayResult;
use App\Modules\Payments\Enums\GatewayStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\PaymentConflictException;
use App\Modules\Payments\Exceptions\PaymentDeclinedException;
use App\Modules\Payments\Exceptions\PaymentInProgressException;
use App\Modules\Payments\Exceptions\PaymentTimeoutException;
use App\Modules\Payments\Models\Payment;
use App\Shared\Members\Member;
use App\Shared\Money\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

require_once __DIR__.'/../Support/helpers.php';

beforeEach(function (): void {
    paymentsSecret();
    Http::fake();
});

it('authorizes tok_ok into an authorized payment with a fake_ch_ ref', function (): void {
    $member = Member::factory()->create();

    $data = app(AuthorizePayment::class)->handle(authorizeInput($member, 9, 55000));

    expect($data->status)->toBe(PaymentStatus::Authorized)
        ->and($data->providerRef)->toMatch('/^fake_ch_[0-9a-z]{26}$/i')
        ->and($data->amount->equals(Money::of(55000, 'USD')))->toBeTrue()
        ->and($data->memberId)->toBe($member->id)
        ->and($data->holdId)->toBe(9)
        ->and($data->reservationId)->toBeNull()
        ->and($data->declineCode)->toBeNull();

    $row = Payment::query()->sole();

    expect($row->idempotency_key)->toBe('hold:9:auth')
        ->and($row->provider)->toBe('fake')
        ->and($row->provider_ref)->toBe($data->providerRef)
        ->and($row->currency)->toBe('USD')
        ->and(fakeGateway()->authorizeCalls())->toBe(1);
});

it('turns declines into PaymentDeclinedException and a failed payment', function (string $token, string $code): void {
    expect(fn () => app(AuthorizePayment::class)->handle(authorizeInput(token: $token)))
        ->toThrow(fn (PaymentDeclinedException $e) => expect($e->declineCode)->toBe($code)
            ->and($e->details())->toBe(['decline_code' => $code]));

    $row = Payment::query()->sole();

    expect($row->status)->toBe(PaymentStatus::Failed)
        ->and($row->decline_code)->toBe($code)
        ->and($row->provider_ref)->toBeNull();
})->with([
    ['tok_decline', 'card_declined'],
    ['tok_insufficient_funds', 'insufficient_funds'],
    ['tok_unknown', 'invalid_token'],
]);

it('renders a decline as 402 payment_failed and a timeout as 504 payment_timeout', function (): void {
    Route::middleware('api')->post('/api/_test/authorize', fn () => response()->json(['data' => app(AuthorizePayment::class)->handle(
        authorizeInput(Member::query()->firstOrFail(), (int) request('hold'), 1000, (string) request('token')),
    )->status->value]));
    Member::factory()->create();

    $this->postJson('/api/_test/authorize', ['hold' => 1, 'token' => 'tok_insufficient_funds'])
        ->assertStatus(402)
        ->assertJsonPath('error.code', 'payment_failed')
        ->assertJsonPath('error.details.decline_code', 'insufficient_funds');

    $this->postJson('/api/_test/authorize', ['hold' => 2, 'token' => 'tok_timeout'])
        ->assertStatus(504)
        ->assertJsonPath('error.code', 'payment_timeout');

    $this->postJson('/api/_test/authorize', ['hold' => 3, 'token' => 'tok_ok'])->assertOk();
});

it('times out on tok_timeout: payment timed_out and the charge exists provider-side', function (): void {
    expect(fn () => app(AuthorizePayment::class)->handle(authorizeInput(holdId: 4)))->not->toThrow(Throwable::class);

    expect(fn () => app(AuthorizePayment::class)->handle(authorizeInput(holdId: 5, token: 'tok_timeout')))
        ->toThrow(PaymentTimeoutException::class);

    $row = Payment::query()->where('hold_id', 5)->sole();

    expect($row->status)->toBe(PaymentStatus::TimedOut)
        ->and($row->provider_ref)->toBeNull()
        ->and(fakeGateway()->charges())->toHaveCount(2)
        ->and(array_column(fakeGateway()->charges(), 'status'))->toBe(['authorized', 'authorized']);
});

it('honours declineNext and timeoutNext through the action', function (): void {
    fakeGateway()->declineNext('lost_card');
    expect(fn () => app(AuthorizePayment::class)->handle(authorizeInput(holdId: 1)))
        ->toThrow(fn (PaymentDeclinedException $e) => expect($e->declineCode)->toBe('lost_card'));

    fakeGateway()->timeoutNext();
    expect(fn () => app(AuthorizePayment::class)->handle(authorizeInput(holdId: 2)))->toThrow(PaymentTimeoutException::class);

    expect(app(AuthorizePayment::class)->handle(authorizeInput(holdId: 3))->status)->toBe(PaymentStatus::Authorized);
});

it('returns the same payment on a repeat and calls the gateway once', function (): void {
    $member = Member::factory()->create();

    $first = app(AuthorizePayment::class)->handle(authorizeInput($member, 7));
    $second = app(AuthorizePayment::class)->handle(authorizeInput($member, 7));

    expect($second)->toEqual($first)
        ->and(fakeGateway()->authorizeCalls())->toBe(1)
        ->and(Payment::query()->count())->toBe(1);

    fakeGateway()->assertChargedOnce('hold:7:auth');
});

it('also replays a captured payment', function (): void {
    $member = Member::factory()->create();
    $first = app(AuthorizePayment::class)->handle(authorizeInput($member, 7));
    Payment::query()->whereKey($first->id)->update(['status' => 'captured']);

    expect(app(AuthorizePayment::class)->handle(authorizeInput($member, 7))->status)->toBe(PaymentStatus::Captured)
        ->and(fakeGateway()->authorizeCalls())->toBe(1);
});

it('rethrows the stored decline on a repeat without calling the gateway', function (): void {
    $member = Member::factory()->create();

    expect(fn () => app(AuthorizePayment::class)->handle(authorizeInput($member, 7, token: 'tok_insufficient_funds')))
        ->toThrow(PaymentDeclinedException::class);

    // Even with a token that would succeed, the stored outcome wins.
    expect(fn () => app(AuthorizePayment::class)->handle(authorizeInput($member, 7)))
        ->toThrow(fn (PaymentDeclinedException $e) => expect($e->declineCode)->toBe('insufficient_funds'))
        ->and(fakeGateway()->authorizeCalls())->toBe(1);
});

it('rethrows the timeout on a repeat of a timed out payment', function (): void {
    $member = Member::factory()->create();

    expect(fn () => app(AuthorizePayment::class)->handle(authorizeInput($member, 7, token: 'tok_timeout')))->toThrow(PaymentTimeoutException::class)
        ->and(fn () => app(AuthorizePayment::class)->handle(authorizeInput($member, 7)))->toThrow(PaymentTimeoutException::class)
        ->and(fakeGateway()->authorizeCalls())->toBe(1);
});

it('rejects a repeat with a different amount', function (int $cents): void {
    $member = Member::factory()->create();
    app(AuthorizePayment::class)->handle(authorizeInput($member, 7, 55000));

    expect(fn () => app(AuthorizePayment::class)->handle(authorizeInput($member, 7, $cents)))
        ->toThrow(PaymentConflictException::class)
        ->and(fakeGateway()->authorizeCalls())->toBe(1);
})->with([55001, 54999, 1]);

it('rejects a different amount even after a decline', function (): void {
    $member = Member::factory()->create();
    expect(fn () => app(AuthorizePayment::class)->handle(authorizeInput($member, 7, 100, 'tok_decline')))->toThrow(PaymentDeclinedException::class)
        ->and(fn () => app(AuthorizePayment::class)->handle(authorizeInput($member, 7, 200)))->toThrow(PaymentConflictException::class);
});

it('rejects a repeat by another member for the same hold', function (): void {
    app(AuthorizePayment::class)->handle(authorizeInput(null, 7));

    expect(fn () => app(AuthorizePayment::class)->handle(authorizeInput(null, 7)))->toThrow(PaymentConflictException::class);
});

it('reports payment_in_progress while the first attempt is pending', function (): void {
    $member = Member::factory()->create();
    Payment::factory()->pending()->create(['member_id' => $member->id, 'hold_id' => 7, 'idempotency_key' => 'hold:7:auth', 'amount_cents' => 55000]);

    expect(fn () => app(AuthorizePayment::class)->handle(authorizeInput($member, 7)))->toThrow(PaymentInProgressException::class)
        ->and(fakeGateway()->authorizeCalls())->toBe(0);
});

it('refuses a repeat for a settled payment', function (PaymentStatus $status): void {
    $member = Member::factory()->create();
    Payment::factory()->withStatus($status)->create(['member_id' => $member->id, 'hold_id' => 7, 'idempotency_key' => 'hold:7:auth', 'amount_cents' => 55000]);

    expect(fn () => app(AuthorizePayment::class)->handle(authorizeInput($member, 7)))->toThrow(PaymentConflictException::class);
})->with([PaymentStatus::Refunded, PaymentStatus::CaptureFailed]);

it('throws LogicException inside a transaction and touches nothing', function (): void {
    $member = Member::factory()->create();

    expect(fn () => DB::transaction(fn () => app(AuthorizePayment::class)->handle(authorizeInput($member, 7))))
        ->toThrow(LogicException::class)
        ->and(Payment::query()->count())->toBe(0)
        ->and(fakeGateway()->authorizeCalls())->toBe(0);
});

it('rejects a zero amount before any row or gateway call', function (): void {
    $input = authorizeInput(cents: 0);

    expect(fn () => app(AuthorizePayment::class)->handle($input))->toThrow(InvalidArgumentException::class)
        ->and(Payment::query()->count())->toBe(0)
        ->and(fakeGateway()->authorizeCalls())->toBe(0);
});

it('sends the idempotency key and payment_id metadata to the gateway', function (): void {
    $spy = new class implements PaymentGateway
    {
        public ?AuthorizeRequest $seen = null;

        public function authorize(AuthorizeRequest $request): GatewayResult
        {
            $this->seen = $request;

            return new GatewayResult(GatewayStatus::Authorized, 'spy_ref_1');
        }

        public function capture(string $providerRef, Money $amount, string $idempotencyKey): GatewayResult
        {
            return new GatewayResult(GatewayStatus::Captured, $providerRef);
        }

        public function refund(string $providerRef, Money $amount, string $idempotencyKey): GatewayResult
        {
            return new GatewayResult(GatewayStatus::Refunded, $providerRef);
        }

        public function payout(string $destination, Money $amount, string $idempotencyKey): GatewayResult
        {
            return new GatewayResult(GatewayStatus::PaidOut, 'po');
        }
    };
    app()->instance(PaymentGateway::class, $spy);

    $data = app(AuthorizePayment::class)->handle(authorizeInput(holdId: 33, cents: 1234, token: 'tok_spy'));

    expect($spy->seen?->idempotencyKey)->toBe('hold:33:auth')
        ->and($spy->seen?->metadata)->toBe(['payment_id' => $data->id])
        ->and($spy->seen?->paymentMethodToken)->toBe('tok_spy')
        ->and($spy->seen?->amount->cents)->toBe(1234)
        ->and($data->providerRef)->toBe('spy_ref_1');
});

it('releases the money when a webhook confirmed the charge before the timeout surfaced', function (): void {
    // Webhook delivery is synchronous here: the authorized event lands while authorize() is still running.
    routeWebhooksThroughKernel($this);
    fakeGateway()->emitWebhooks();

    expect(fn () => app(AuthorizePayment::class)->handle(authorizeInput(holdId: 8, token: 'tok_timeout')))
        ->toThrow(PaymentTimeoutException::class);

    $row = Payment::query()->sole();

    expect($row->status)->toBe(PaymentStatus::Refunded)
        ->and(fakeGateway()->charges()[0]['status'])->toBe('refunded');
});

it('keeps VoidPayment usable straight after authorize', function (): void {
    $data = app(AuthorizePayment::class)->handle(authorizeInput(holdId: 1));

    app(VoidPayment::class)->handle($data->id);

    expect(Payment::query()->sole()->status)->toBe(PaymentStatus::Refunded);
});
