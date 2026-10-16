<?php

declare(strict_types=1);

use App\Shared\Members\Member;

beforeEach(function (): void {
    $this->member = Member::factory()->create([
        'name' => 'Ada',
        'email' => 'ada@example.test',
        'password' => 'secret-pass',
    ]);
});

it('issues a token for valid credentials', function (): void {
    $this->postJson('/api/auth/token', [
        'email' => 'ada@example.test',
        'password' => 'secret-pass',
        'device_name' => 'phpunit',
    ])
        ->assertCreated()
        ->assertJsonPath('data.member', ['id' => $this->member->id, 'name' => 'Ada', 'email' => 'ada@example.test'])
        ->assertJsonStructure(['data' => ['token', 'member' => ['id', 'name', 'email']]]);

    expect($this->member->tokens()->count())->toBe(1);
});

it('lets the issued token read /api/me', function (): void {
    $token = $this->postJson('/api/auth/token', [
        'email' => 'ada@example.test',
        'password' => 'secret-pass',
        'device_name' => 'phpunit',
    ])->json('data.token');

    app('auth')->forgetGuards();

    $this->withToken($token)->getJson('/api/me')
        ->assertOk()
        ->assertExactJson(['data' => ['id' => $this->member->id, 'name' => 'Ada', 'email' => 'ada@example.test']]);
});

it('rejects a wrong password with 401 invalid_credentials', function (): void {
    $this->postJson('/api/auth/token', [
        'email' => 'ada@example.test',
        'password' => 'wrong',
        'device_name' => 'phpunit',
    ])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_credentials');

    expect($this->member->tokens()->count())->toBe(0);
});

it('rejects an unknown email with 401 invalid_credentials', function (): void {
    $this->postJson('/api/auth/token', [
        'email' => 'nobody@example.test',
        'password' => 'secret-pass',
        'device_name' => 'phpunit',
    ])
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'invalid_credentials');
});

it('rejects /api/me without a token with 401 unauthenticated', function (): void {
    $this->getJson('/api/me')
        ->assertStatus(401)
        ->assertExactJson(['error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated.', 'details' => []]]);
});

it('rejects /api/me with a bogus token with 401 unauthenticated', function (): void {
    $this->withToken('1|not-a-real-token')->getJson('/api/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('rejects /api/me without a token even when the client does not ask for JSON', function (): void {
    $this->get('/api/me')
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'unauthenticated');
});

it('validates the token request', function (): void {
    $this->postJson('/api/auth/token', ['email' => 'not-an-email'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonValidationErrorFor('password', 'error.details.errors')
        ->assertJsonValidationErrorFor('device_name', 'error.details.errors');
});
