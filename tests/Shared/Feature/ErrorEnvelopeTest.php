<?php

declare(strict_types=1);

use App\Shared\Exceptions\DomainError;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Route;

it('renders validation failures as validation_failed with field errors', function (): void {
    $this->postJson('/api/auth/token', [])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['errors' => ['email']]]]);
});

it('renders an unknown route as not_found', function (): void {
    $this->getJson('/api/does-not-exist')
        ->assertStatus(404)
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Not found.', 'details' => []]]);
});

it('renders a wrong method as method_not_allowed', function (): void {
    $this->getJson('/api/auth/token')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'method_not_allowed');
});

it('renders domain errors with their code, status and details', function (): void {
    $error = new class('Nope.') extends DomainError
    {
        public function errorCode(): string
        {
            return 'sold_out';
        }

        public function httpStatus(): int
        {
            return 409;
        }

        public function details(): array
        {
            return ['slot_id' => 5];
        }
    };

    Route::get('/api/_test/domain-error', fn () => throw $error);

    $this->getJson('/api/_test/domain-error')
        ->assertStatus(409)
        ->assertExactJson(['error' => ['code' => 'sold_out', 'message' => 'Nope.', 'details' => ['slot_id' => 5]]]);
});

it('renders model-not-found as not_found', function (): void {
    Route::get('/api/_test/model-missing', fn () => throw (new ModelNotFoundException)->setModel('X'));

    $this->getJson('/api/_test/model-missing')
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'not_found');
});

it('renders unexpected exceptions as server_error without a trace when debug is off', function (): void {
    config(['app.debug' => false]);

    Route::get('/api/_test/boom', fn () => throw new RuntimeException('secret internal detail'));

    $response = $this->getJson('/api/_test/boom')
        ->assertStatus(500)
        ->assertExactJson(['error' => ['code' => 'server_error', 'message' => 'Server error.', 'details' => []]]);

    expect($response->getContent())
        ->not->toContain('secret internal detail')
        ->not->toContain('trace')
        ->not->toContain('RuntimeException');
});
