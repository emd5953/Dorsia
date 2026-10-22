<?php

declare(strict_types=1);

use App\Modules\Inventory\Models\Slot;
use App\Modules\Inventory\Models\Venue;
use App\Modules\Reservations\Actions\CancelReservation;
use App\Modules\Reservations\Actions\ConfirmReservation;
use App\Modules\Reservations\Actions\PlaceHold;
use App\Modules\Reservations\Actions\PreviewCancellation;
use App\Modules\Reservations\Http\Resources\CancellationPreviewResource;
use App\Modules\Wallet\Actions\IssueGoodwillCredit;
use App\Shared\Idempotency\IdempotencyKey;
use App\Shared\Members\Member;
use App\Shared\Money\Money;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || config('payments.gateway') !== 'fake' || config('database.connections.pgsql.database') !== 'dorsia') {
    throw new RuntimeException('Cancellation fixtures require the local dorsia database and fake gateway.');
}
$hours = config()->integer('reservations.cancel_window_hours');
if ($hours < 1) {
    throw new RuntimeException('A positive cancellation window is required to validate late cancellations.');
}
$venue = Venue::query()->where('slug', 'carbone')->firstOrFail();
$run = Str::lower(Str::random(10));
$accounts = [];
$created = [];
try {
    foreach (['desktop', 'mobile', 'integration'] as $device) {
        $member = Member::query()->create(['name' => 'Cancellation Test '.ucfirst($device), 'email' => "cancel-{$device}-{$run}@dorsia.test", 'password' => 'password']);
        app(IssueGoodwillCredit::class)->handle($member->id, Money::of(200000, 'USD'), "cancel-fixture:{$run}:{$device}");
        $bookings = [];
        foreach (['early', 'late'] as $timing) {
            $start = now()->toImmutable()->addMinutes($timing === 'early' ? ($hours + 24) * 60 : min(120, $hours * 30));
            $slot = Slot::factory()->create(['venue_id' => $venue->id, 'starts_at' => $start, 'released_at' => now()->subDay(), 'capacity' => 1, 'min_party_size' => 1, 'max_party_size' => 2]);
            $hold = app(PlaceHold::class)->handle($member->id, $slot->id, 2, new IdempotencyKey($member->id, (string) Str::uuid()));
            $reservation = app(ConfirmReservation::class)->handle($member->id, $hold->hold->id, null, new IdempotencyKey($member->id, (string) Str::uuid()));
            $created[] = [$member->id, $reservation->id];
            $preview = app(PreviewCancellation::class)->handle($member->id, $reservation->id);
            $bookings[$timing] = ['reservation_id' => $reservation->id, 'slot_id' => $slot->id, 'starts_at' => $start->toIso8601String(), 'preview' => (new CancellationPreviewResource($preview))->toArray(Request::create('/'))];
        }
        $accounts[$device] = ['id' => $member->id, 'email' => $member->email, 'initial_credit_cents' => 200000, 'bookings' => $bookings];
    }
} catch (Throwable $error) {
    foreach ($created as [$memberId, $reservationId]) {
        app(CancelReservation::class)->handle($memberId, $reservationId);
    }
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
}
echo json_encode(['run' => $run, 'accounts' => $accounts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
