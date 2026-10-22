<?php

declare(strict_types=1);

use App\Modules\Reservations\Actions\CancelReservation;
use App\Modules\Reservations\Actions\ListReservations;
use App\Modules\Reservations\Enums\ReservationStatus;
use App\Shared\Members\Member;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('local') || config('payments.gateway') !== 'fake' || config('database.connections.pgsql.database') !== 'dorsia') {
    throw new RuntimeException('Only local cancellation fixtures are supported.');
}
$run = $argv[1] ?? '';
if ($run !== '' && ! preg_match('/^[a-z0-9]{10}$/D', $run)) {
    throw new InvalidArgumentException('Supply the exact fixture run suffix.');
}
$members = Member::query()->where('email', 'like', $run === '' ? 'cancel-%@dorsia.test' : "cancel-%-{$run}@dorsia.test")->get();
foreach ($members as $member) {
    foreach (app(ListReservations::class)->handle($member->id) as $reservation) {
        if ($reservation->status === ReservationStatus::Confirmed && $run !== '') {
            app(CancelReservation::class)->handle($member->id, $reservation->id);
        }
        echo json_encode(['email' => $member->email, 'reservation_id' => $reservation->id, 'status_before' => $reservation->status->value, 'cancelled' => $run !== ''], JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
