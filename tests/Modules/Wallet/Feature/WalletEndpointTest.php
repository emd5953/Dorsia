<?php

declare(strict_types=1);

use App\Modules\Wallet\Enums\AccountKind;
use App\Shared\Members\Member;

require_once __DIR__.'/../WalletHelpers.php';

it('returns zeros for a new member', function (): void {
    $member = Member::factory()->create();

    $this->actingAs($member, 'sanctum')
        ->getJson('/api/wallet')
        ->assertOk()
        ->assertExactJson(['data' => ['currency' => 'USD', 'credit_cents' => 0, 'coupon_cents' => 0]]);
});

it('returns the cached credit and coupon balances of the caller only', function (): void {
    $member = Member::factory()->create();
    $other = Member::factory()->create();
    fundMember($member->id, 12345);
    fundMember($member->id, 700, AccountKind::Coupon);
    fundMember($other->id, 999999);

    $this->actingAs($member, 'sanctum')
        ->getJson('/api/wallet')
        ->assertOk()
        ->assertExactJson(['data' => ['currency' => 'USD', 'credit_cents' => 12345, 'coupon_cents' => 700]]);
});

it('requires authentication', function (): void {
    $this->getJson('/api/wallet')
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('works with a real bearer token', function (): void {
    $member = Member::factory()->create();
    $token = $member->createToken('t')->plainTextToken;

    $this->withToken($token)->getJson('/api/wallet')->assertOk()->assertJsonPath('data.currency', 'USD');
});
