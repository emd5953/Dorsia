<?php
declare(strict_types=1);
use App\Modules\Payments\Actions\AuthorizePayment;
use App\Modules\Payments\Actions\VoidPayment;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\GatewayResult;
use App\Modules\Payments\Enums\GatewayStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Jobs\RefundPayment;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Support\FakeGateway;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
require_once __DIR__.'/../Support/helpers.php';
beforeEach(function (): void { paymentsSecret(); Http::fake(); });

it('P1 reaper times out pending row while authorize is in flight, then gateway authorizes', function (): void {
    Bus::fake([RefundPayment::class]);
    $real = fakeGateway();
    $g = Mockery::mock(PaymentGateway::class);
    $g->shouldReceive('authorize')->andReturnUsing(function ($req) use ($real) {
        Payment::query()->update(['created_at' => now()->subSeconds(200)]);
        Artisan::call('payments:reap');
        return $real->authorize($req);
    });
    app()->instance(PaymentGateway::class, $g);
    $err = null;
    try { app(AuthorizePayment::class)->handle(authorizeInput(holdId: 7001)); } catch (Throwable $e) { $err = get_class($e); }
    $p = Payment::query()->sole();
    dump(['exception' => $err, 'status' => $p->status->value, 'provider_ref' => $p->provider_ref,
        'provider_charge' => $real->charges()[0]['status'] ?? null, 'refund_jobs' => Bus::dispatched(RefundPayment::class)->count()]);
    Artisan::call('payments:reap');
    dump(['after_reap' => Payment::query()->sole()->status->value, 'refund_jobs' => Bus::dispatched(RefundPayment::class)->count()]);
    expect(true)->toBeTrue();
});

it('P2 VoidPayment when refund throws a non-timeout error', function (): void {
    Bus::fake([RefundPayment::class]);
    $data = app(AuthorizePayment::class)->handle(authorizeInput(holdId: 7002));
    $g = Mockery::mock(PaymentGateway::class);
    $g->shouldReceive('refund')->andThrow(new RuntimeException('socket'));
    $err = null;
    try { (new VoidPayment($g))->handle($data->id); } catch (Throwable $e) { $err = get_class($e); }
    dump(['exception' => $err, 'status' => Payment::query()->sole()->status->value,
        'provider_charge' => fakeGateway()->charges()[0]['status'], 'refund_jobs' => Bus::dispatched(RefundPayment::class)->count()]);
    expect(true)->toBeTrue();
});

it('P3 authorize throws, webhook already authorized, void refund throws non-timeout', function (): void {
    Bus::fake([RefundPayment::class]);
    $g = Mockery::mock(PaymentGateway::class);
    $g->shouldReceive('authorize')->andReturnUsing(function () {
        Payment::query()->update(['status' => 'authorized', 'provider_ref' => 'ref_p3']);
        throw new RuntimeException('boom');
    });
    $g->shouldReceive('refund')->andThrow(new RuntimeException('socket'));
    app()->instance(PaymentGateway::class, $g);
    $err = null;
    try { app(AuthorizePayment::class)->handle(authorizeInput(holdId: 7003)); } catch (Throwable $e) { $err = get_class($e).': '.$e->getMessage(); }
    dump(['exception' => $err, 'status' => Payment::query()->sole()->status->value, 'refund_jobs' => Bus::dispatched(RefundPayment::class)->count()]);
    expect(true)->toBeTrue();
});

it('P4 reap at exactly 120s pending and exactly TTL+120 orphan (frozen clock)', function (): void {
    $this->freezeTime();
    $a = app(AuthorizePayment::class)->handle(authorizeInput(holdId: 7004));
    $m = Payment::query()->findOrFail($a->id)->member_id;
    $p = Payment::factory()->pending()->create(['member_id' => $m, 'created_at' => now()->subSeconds(120)]);
    Payment::query()->whereKey($a->id)->update(['created_at' => now()->subSeconds(420)]);
    Artisan::call('payments:reap');
    dump(['pending_at_120' => $p->fresh()->status->value, 'orphan_at_420' => Payment::query()->findOrFail($a->id)->status->value]);
    $this->travel(1)->seconds();
    Artisan::call('payments:reap');
    dump(['pending_at_121' => $p->fresh()->status->value, 'orphan_at_421' => Payment::query()->findOrFail($a->id)->status->value]);
    expect(true)->toBeTrue();
});

it('P5 webhook payment.failed without amount fields', function (): void {
    $p = Payment::factory()->pending()->create();
    $ev = ['id' => 'evt_f', 'type' => 'payment.failed', 'data' => ['decline_code' => 'card_declined', 'metadata' => ['payment_id' => $p->id]]];
    postWebhook($this, $ev)->assertOk();
    dump(['status' => $p->fresh()->status->value]);
    expect(true)->toBeTrue();
});
