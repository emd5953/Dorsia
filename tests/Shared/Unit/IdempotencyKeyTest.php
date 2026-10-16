<?php

declare(strict_types=1);

use App\Shared\Idempotency\IdempotencyKey;
use App\Shared\Idempotency\IdempotencyKeyInvalidException;
use App\Shared\Idempotency\IdempotencyKeyRequiredException;
use Illuminate\Http\Request;

it('accepts every allowed character class', function (string $value): void {
    expect((new IdempotencyKey(7, $value))->value)->toBe($value);
})->with([
    'uuid' => '3f2b8c1e-9a4d-4c55-8e0f-1b2a3c4d5e6f',
    'letters' => 'abcXYZ',
    'digits' => '0123456789',
    'punctuation' => 'a.b_c:d-e',
    'single character' => 'a',
]);

it('rejects invalid formats', function (string $value): void {
    new IdempotencyKey(7, $value);
})->with([
    'empty' => '',
    'space' => 'a b',
    'slash' => 'a/b',
    'unicode' => 'clé',
    'newline' => "abc\n",
    'at sign' => 'a@b',
])->throws(IdempotencyKeyInvalidException::class);

it('accepts length 200 and rejects length 201', function (): void {
    expect((new IdempotencyKey(1, str_repeat('a', 200)))->value)->toHaveLength(200);

    new IdempotencyKey(1, str_repeat('a', 201));
})->throws(IdempotencyKeyInvalidException::class);

it('scopes the value by member id', function (): void {
    expect((new IdempotencyKey(42, 'abc-1'))->scoped())->toBe('42:abc-1');
});

it('requires the header when reading from a request', function (): void {
    IdempotencyKey::fromRequest(Request::create('/x', 'POST'));
})->throws(IdempotencyKeyRequiredException::class);

it('reads the header and the authenticated member from a request', function (): void {
    $request = Request::create('/x', 'POST', server: ['HTTP_IDEMPOTENCY_KEY' => 'key-1']);
    $request->setUserResolver(fn () => new class
    {
        public function getAuthIdentifier(): int
        {
            return 9;
        }
    });

    $key = IdempotencyKey::fromRequest($request);

    expect($key->memberId)->toBe(9)
        ->and($key->value)->toBe('key-1')
        ->and($key->scoped())->toBe('9:key-1');
});
