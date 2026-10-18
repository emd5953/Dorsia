<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\Hold;
use App\Modules\Pricing\Actions\QuoteHold;
use App\Modules\Reservations\Actions\GetHoldView;
use App\Modules\Reservations\Data\HoldView;
use App\Modules\Reservations\Http\Resources\HoldResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Inventory\Support\Fixtures;
use Tests\Modules\Reservations\Support\Booking;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse(Booking::NOW));
    $this->member = Fixtures::member();
    $this->token = Fixtures::token($this->member);
    $this->slot = Booking::slot();
});

it('returns a quote whose amount_cents equals the price_quotes row', function (): void {
    $response = Booking::hold($this->token, $this->slot)->assertCreated();

    $row = DB::table('price_quotes')->sole();

    expect($response->json('data.quote'))->toBe([
        'per_person_cents' => (int) $row->per_person_cents,
        'amount_cents' => (int) $row->amount_cents,
        'currency' => 'USD',
        'expires_at' => '2026-11-07T15:05:00Z',
    ])
        ->and((int) $row->amount_cents)->toBe(Booking::QUOTE_CENTS)
        ->and((int) $row->hold_id)->toBe($response->json('data.id'));
});

it('quotes the same price on replay and on GET /api/holds/{id}', function (): void {
    $first = Booking::hold($this->token, $this->slot, 2, 'hold-key')->assertCreated();
    $replay = Booking::hold($this->token, $this->slot, 2, 'hold-key')->assertCreated();
    $shown = Booking::get('/api/holds/'.$first->json('data.id'), $this->token)->assertOk();

    expect($replay->getContent())->toBe($first->getContent())
        ->and($shown->json('data.quote'))->toBe($first->json('data.quote'))
        ->and(DB::table('price_quotes')->count())->toBe(1);
});

it('creates the hold and the quote in one transaction: a failure inside QuoteHold leaves nothing behind', function (): void {
    $this->partialMock(QuoteHold::class, function ($mock): void {
        $mock->shouldReceive('handle')->once()->andThrow(new RuntimeException('pricing down'));
    });

    Booking::hold($this->token, $this->slot)->assertStatus(500)->assertJsonPath('error.code', 'server_error');

    expect(Hold::query()->count())->toBe(0)
        ->and(DB::table('price_quotes')->count())->toBe(0)
        ->and($this->slot->fresh()->held_count)->toBe(0)
        ->and(Fixtures::outbox('slot.held'))->toHaveCount(0)
        ->and(Fixtures::gate($this->slot))->toBe(4);
});

it('can hold the same slot right after a pricing failure', function (): void {
    $this->partialMock(QuoteHold::class, function ($mock): void {
        $mock->shouldReceive('handle')->once()->andThrow(new RuntimeException('pricing down'));
    });

    Booking::hold($this->token, $this->slot)->assertStatus(500);

    $this->app->forgetInstance(QuoteHold::class);
    $this->app->offsetUnset(QuoteHold::class);

    Booking::hold($this->token, $this->slot)->assertCreated();

    expect($this->slot->fresh()->held_count)->toBe(1)
        ->and(Fixtures::gate($this->slot))->toBe(3);
});

it('always fills HoldView::$quote from GetHoldView', function (): void {
    $id = (int) Booking::hold($this->token, $this->slot)->assertCreated()->json('data.id');

    $view = app(GetHoldView::class)->handle($this->member->id, $id);

    expect($view->quote)->not->toBeNull()
        ->and($view->quote?->holdId)->toBe($id)
        ->and($view->quote?->amount->cents)->toBe(Booking::QUOTE_CENTS);
});

it('refuses to render a hold without its quote', function (): void {
    $hold = Fixtures::hold($this->slot, $this->member);

    expect(fn () => (new HoldResource(new HoldView($hold, null)))->toArray(Request::create('/')))
        ->toThrow(LogicException::class, "Hold {$hold->id} has no quote.");
});
