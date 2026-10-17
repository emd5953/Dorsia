<?php

declare(strict_types=1);

use App\Modules\Payments\Actions\AuthorizePayment;
use App\Modules\Payments\Actions\MarkPaymentForCapture;
use App\Modules\Payments\Actions\VoidPayment;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Data\AuthorizePaymentInput;
use App\Modules\Payments\Data\AuthorizeRequest;
use App\Modules\Payments\Data\GatewayResult;
use App\Modules\Payments\Data\PaymentData;
use App\Modules\Payments\Enums\GatewayStatus;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Exceptions\GatewayTimeoutException;
use App\Modules\Payments\Exceptions\InvalidWebhookSignatureException;
use App\Modules\Payments\Exceptions\PaymentConflictException;
use App\Modules\Payments\Exceptions\PaymentDeclinedException;
use App\Modules\Payments\Exceptions\PaymentInProgressException;
use App\Modules\Payments\Exceptions\PaymentTimeoutException;
use App\Modules\Payments\Jobs\CapturePayment;
use App\Modules\Payments\Jobs\DeliverFakeWebhook;
use App\Modules\Payments\Jobs\RefundPayment;
use App\Modules\Payments\Support\FakeGateway;
use App\Modules\Payments\Support\HandleWebhook;
use App\Shared\Exceptions\DomainError;

/**
 * @return list<string> "name:type" per parameter, then "=>return"
 */
function paymentsSignature(string $class, string $method): array
{
    $m = new ReflectionMethod($class, $method);
    $params = array_map(
        fn (ReflectionParameter $p): string => $p->getName().':'.$p->getType(),
        $m->getParameters(),
    );

    return [...$params, '=>'.$m->getReturnType()];
}

it('PaymentGateway has exactly authorize, capture, refund and payout with C5.5 signatures', function (): void {
    $methods = array_map(fn (ReflectionMethod $m): string => $m->getName(), (new ReflectionClass(PaymentGateway::class))->getMethods());
    sort($methods);

    expect((new ReflectionClass(PaymentGateway::class))->isInterface())->toBeTrue()
        ->and($methods)->toBe(['authorize', 'capture', 'payout', 'refund'])
        ->and(paymentsSignature(PaymentGateway::class, 'authorize'))->toBe(['request:'.AuthorizeRequest::class, '=>'.GatewayResult::class])
        ->and(paymentsSignature(PaymentGateway::class, 'capture'))->toBe(['providerRef:string', 'amount:App\Shared\Money\Money', 'idempotencyKey:string', '=>'.GatewayResult::class])
        ->and(paymentsSignature(PaymentGateway::class, 'refund'))->toBe(['providerRef:string', 'amount:App\Shared\Money\Money', 'idempotencyKey:string', '=>'.GatewayResult::class])
        ->and(paymentsSignature(PaymentGateway::class, 'payout'))->toBe(['destination:string', 'amount:App\Shared\Money\Money', 'idempotencyKey:string', '=>'.GatewayResult::class]);
});

it('FakeGateway implements the interface and exposes the C11 knobs', function (): void {
    expect(is_subclass_of(FakeGateway::class, PaymentGateway::class))->toBeTrue();

    foreach (['declineNext', 'timeoutNext', 'failNextCapture', 'emitWebhooks', 'duplicateWebhooks', 'latency', 'charges', 'authorizeCalls', 'assertChargedOnce'] as $method) {
        expect(method_exists(FakeGateway::class, $method))->toBeTrue("missing {$method}");
    }

    expect(paymentsSignature(FakeGateway::class, 'declineNext'))->toBe(['code:string', '=>void'])
        ->and(paymentsSignature(FakeGateway::class, 'emitWebhooks'))->toBe(['on:bool', '=>void'])
        ->and(paymentsSignature(FakeGateway::class, 'duplicateWebhooks'))->toBe(['on:bool', '=>void'])
        ->and(paymentsSignature(FakeGateway::class, 'latency'))->toBe(['ms:int', '=>void'])
        ->and(paymentsSignature(FakeGateway::class, 'authorizeCalls'))->toBe(['=>int'])
        ->and(paymentsSignature(FakeGateway::class, 'assertChargedOnce'))->toBe(['idempotencyKey:string', '=>void'])
        ->and(paymentsSignature(FakeGateway::class, 'sign'))->toBe(['rawBody:string', '=>string'])
        ->and((new ReflectionMethod(FakeGateway::class, 'sign'))->isStatic())->toBeTrue()
        ->and((new ReflectionMethod(FakeGateway::class, 'webhookPayload'))->isStatic())->toBeTrue();
});

it('declares the DTO constructors of C5.5', function (): void {
    $ctor = fn (string $class): array => array_map(
        fn (ReflectionParameter $p): string => $p->getName().':'.$p->getType(),
        (new ReflectionMethod($class, '__construct'))->getParameters(),
    );

    expect($ctor(AuthorizeRequest::class))->toBe(['amount:App\Shared\Money\Money', 'paymentMethodToken:string', 'idempotencyKey:string', 'metadata:array'])
        ->and($ctor(GatewayResult::class))->toBe(['status:'.GatewayStatus::class, 'providerRef:?string', 'declineCode:?string'])
        ->and($ctor(AuthorizePaymentInput::class))->toBe(['memberId:int', 'holdId:int', 'amount:App\Shared\Money\Money', 'cardToken:string'])
        ->and($ctor(PaymentData::class))->toBe([
            'id:int', 'memberId:int', 'holdId:int', 'reservationId:?int', 'status:'.PaymentStatus::class,
            'amount:App\Shared\Money\Money', 'providerRef:?string', 'declineCode:?string',
        ]);
});

it('declares the enum cases of C5.5', function (): void {
    expect(array_column(GatewayStatus::cases(), 'value'))->toBe(['authorized', 'captured', 'refunded', 'paid_out', 'declined', 'failed'])
        ->and(array_column(PaymentStatus::cases(), 'value'))->toBe(['pending', 'authorized', 'captured', 'failed', 'timed_out', 'refunded', 'capture_failed']);
});

it('declares the actions with one handle() each', function (): void {
    expect(paymentsSignature(AuthorizePayment::class, 'handle'))->toBe(['input:'.AuthorizePaymentInput::class, '=>'.PaymentData::class])
        ->and(paymentsSignature(MarkPaymentForCapture::class, 'handle'))->toBe(['paymentId:int', 'reservationId:int', '=>void'])
        ->and(paymentsSignature(VoidPayment::class, 'handle'))->toBe(['paymentId:int', '=>void'])
        ->and(array_map(fn (ReflectionParameter $p): string => (string) $p->getType(), (new ReflectionMethod(AuthorizePayment::class, '__construct'))->getParameters()))->toBe([PaymentGateway::class])
        ->and(array_map(fn (ReflectionParameter $p): string => (string) $p->getType(), (new ReflectionMethod(VoidPayment::class, '__construct'))->getParameters()))->toBe([PaymentGateway::class]);
});

it('maps the exceptions to C9 codes and statuses', function (): void {
    $cases = [
        [new PaymentDeclinedException('card_declined'), 'payment_failed', 402],
        [new PaymentTimeoutException, 'payment_timeout', 504],
        [new PaymentInProgressException, 'payment_in_progress', 409],
        [new PaymentConflictException, 'payment_conflict', 409],
        [new InvalidWebhookSignatureException, 'invalid_signature', 401],
    ];

    foreach ($cases as [$exception, $code, $status]) {
        expect($exception)->toBeInstanceOf(DomainError::class)
            ->and($exception->errorCode())->toBe($code)
            ->and($exception->httpStatus())->toBe($status);
    }

    expect((new PaymentDeclinedException('insufficient_funds'))->details())->toBe(['decline_code' => 'insufficient_funds'])
        ->and(is_subclass_of(GatewayTimeoutException::class, RuntimeException::class))->toBeTrue();
});

it('has the internal jobs with the C5.5 retry policy', function (): void {
    foreach ([CapturePayment::class, RefundPayment::class] as $job) {
        $instance = new $job(1);

        expect($instance->tries)->toBe(5)
            ->and($instance->backoff())->toBe([1, 5, 15, 60, 300]);
    }

    expect(class_exists(DeliverFakeWebhook::class))->toBeTrue()
        ->and(method_exists(HandleWebhook::class, 'handle'))->toBeTrue();
});

it('keeps domain code on the interface: only the provider and tests name the fake', function (): void {
    $offenders = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $allowed = str_ends_with($path, 'Payments/PaymentsServiceProvider.php') || str_ends_with($path, 'Payments/Support/FakeGateway.php');

        if (! $allowed && str_contains((string) file_get_contents($path), 'FakeGateway')) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});
