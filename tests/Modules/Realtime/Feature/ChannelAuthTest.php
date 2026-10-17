<?php

declare(strict_types=1);

use App\Shared\Members\Member;
use Illuminate\Support\Facades\Broadcast;

it('authorizes only the authenticated member on a private member channel', function (): void {
    $member = Member::factory()->create();
    $other = Member::factory()->create();
    $token = $member->createToken('realtime-test')->plainTextToken;
    config(['broadcasting.default' => 'reverb']);
    Broadcast::channel('member.{memberId}', fn (Member $authenticated, int $memberId): bool => (int) $authenticated->id === $memberId);

    $auth = function (string $channel, ?string $bearer) {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => $bearer === null ? '' : 'Bearer '.$bearer,
        ])->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel]);
    };

    $auth('private-member.'.$member->id, $token)->assertOk();
    $auth('private-member.'.$other->id, $token)->assertForbidden();
    $auth('private-member.'.$member->id, null)->assertUnauthorized();
});
