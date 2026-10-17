<?php

declare(strict_types=1);

use App\Shared\Members\Member;
use Illuminate\Support\Facades\Broadcast;

it('authorizes only the authenticated member on a private member channel', function (): void {
    $member = Member::factory()->create();
    $other = Member::factory()->create();
    $token = $member->createToken('realtime-test')->plainTextToken;
    config(['broadcasting.default' => 'null']);
    $callback = Broadcast::connection()->getChannels()['member.{memberId}'];
    expect($callback($member, $member->id))->toBeTrue()
        ->and($callback($member, $other->id))->toBeFalse();
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'realtime-test-key',
        'broadcasting.connections.reverb.secret' => 'realtime-test-secret',
        'broadcasting.connections.reverb.app_id' => 'realtime-test-app',
    ]);
    Broadcast::connection('reverb')->channel('member.{memberId}', $callback);

    $auth = function (string $channel, ?string $bearer) {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders([
            'Authorization' => $bearer === null ? '' : 'Bearer '.$bearer,
        ])->postJson('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel]);
    };

    $auth('private-member.'.$member->id, $token)->assertOk();
    $auth('private-member.'.$other->id, $token)->assertForbidden();
    $auth('private-member.'.$member->id, null)->assertUnauthorized();
    $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/broadcasting/auth', [
        'socket_id' => 'malformed',
        'channel_name' => 'private-member.'.$member->id,
    ])->assertForbidden();
    $this->withHeaders(['Authorization' => 'Bearer '.$token])->postJson('/broadcasting/auth', [
        'socket_id' => '123.456',
        'channel_name' => 'private-member.0',
    ])->assertForbidden();
});
