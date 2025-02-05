<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use function Pest\Laravel\actingAs;
use Qruto\LaravelWave\Events\PresenceChannelJoinEvent;
use Qruto\LaravelWave\Events\SseConnectionClosedEvent;
use Qruto\LaravelWave\Tests\Events\SomePresenceEvent;
use Qruto\LaravelWave\Tests\Events\SomePrivateEvent;
use Qruto\LaravelWave\Tests\Support\User;

it('send join event on join request', function () {
    Event::fake([PresenceChannelJoinEvent::class]);

    $connection = waveConnection();

    joinRequest('presence-channel', $this->user, $connection->id());

    Event::assertDispatched(PresenceChannelJoinEvent::class);
});

it('stores user in redis presence channel pool', function () {
    $connection = waveConnection();

    $key = 'presence_channel:presence-presence-channel:user:'.auth()->user()->getAuthIdentifier();

    joinRequest('presence-channel', $this->user, $connection->id());

    expect((bool) Redis::exists($key))->toBeTrue();
    expect(unserialize(Redis::get($key))->first())->toBe($connection->id());
});

test('join request respond with actual count of channel users', function () {
    $connection = waveConnection();
    joinRequest('presence-channel', $this->user, $connection->id());

    /** @var \Illuminate\Contracts\Auth\Authenticatable */
    $rick = User::factory()->create(['name' => 'Rick']);
    $connectionRick = waveConnection($rick);
    $response = joinRequest('presence-channel', $rick, $connectionRick->id());

    $response->assertJson([
        $this->user->toArray(),
        $rick->toArray(),
    ]);
});

test('leave request respond with actual count of channel users', function () {
    $connection = waveConnection();
    joinRequest('presence-channel', $this->user, $connection->id());

    /** @var \Illuminate\Contracts\Auth\Authenticatable */
    $rick = User::factory()->create(['name' => 'Rick']);
    $connectionRick = waveConnection($rick);
    $response = leaveRequest('presence-channel', $rick, $connectionRick->id());

    $response->assertJson([
        $this->user->toArray(),
    ]);
});

it('receives join channel event', function () {
    /** @var \Illuminate\Contracts\Auth\Authenticatable */
    $rick = User::factory()->create(['name' => 'Rick']);

    /** @var \Illuminate\Contracts\Auth\Authenticatable */
    $morty = User::factory()->create(['name' => 'Morty']);

    $connectionRick = waveConnection($rick);

    $connectionMorty = waveConnection($morty);
    joinRequest('presence-channel', $morty, $connectionMorty->id());

    actingAs($rick);
    $connectionRick->assertEventReceived('presence-presence-channel.join', fn ($event) => $event['data']['user']['id'] === $morty->id);
});

test('leave channel event received', function () {
    /** @var \Illuminate\Contracts\Auth\Authenticatable */
    $rick = User::factory()->create(['name' => 'Rick']);

    /** @var \Illuminate\Contracts\Auth\Authenticatable */
    $morty = User::factory()->create(['name' => 'Morty']);

    $connectionRick = waveConnection($rick);

    $connectionMorty = waveConnection($morty);
    joinRequest('presence-channel', $morty, $connectionMorty->id());
    leaveRequest('presence-channel', $morty, $connectionMorty->id());

    actingAs($rick);
    $connectionRick->assertEventReceived('presence-presence-channel.leave', fn ($event) => $event['data']['user']['id'] === $morty->id);
});

it('doesn\'t receive events without access', function () {
    Broadcast::channel('presence-channel', fn () => false);
    $connection = waveConnection();
    event(new SomePresenceEvent());

    $connection->assertEventNotReceived(SomePrivateEvent::class);
});

test('user leave all channels on connection close', function () {
    Broadcast::channel('presence-channel-2', fn () => true);

    /** @var \Illuminate\Contracts\Auth\Authenticatable */
    $rick = User::factory()->create(['name' => 'Rick']);

    /** @var \Illuminate\Contracts\Auth\Authenticatable */
    $morty = User::factory()->create(['name' => 'Morty']);

    $connectionRick = waveConnection($rick);

    $connectionMorty = waveConnection($morty);
    joinRequest('presence-channel', $morty, $connectionMorty->id());
    joinRequest('presence-channel-2', $morty, $connectionMorty->id());

    event(new SseConnectionClosedEvent($morty, $connectionMorty->id()));

    $connectionRick->assertEventReceived('presence-presence-channel.leave');
    $connectionRick->assertEventReceived('presence-presence-channel-2.leave');
});

it('successfully stores several connections', function () {
    $connectionOne = waveConnection();
    $connectionTwo = waveConnection();

    joinRequest('presence-channel', $this->user, $connectionOne->id());
    joinRequest('presence-channel', $this->user, $connectionTwo->id());

    $key = 'presence_channel:presence-presence-channel:user:'.auth()->user()->getAuthIdentifier();

    $storedUserConnections = unserialize(Redis::get($key));

    expect($storedUserConnections->values())->toEqual(
        collect([
            $connectionOne->id(),
            $connectionTwo->id(),
        ])
    );
});

it('successfully removes one of several connections', function () {
    $connectionOne = waveConnection();
    $connectionTwo = waveConnection();

    joinRequest('presence-channel', $this->user, $connectionOne->id());
    joinRequest('presence-channel', $this->user, $connectionTwo->id());

    leaveRequest('presence-channel', $this->user, $connectionOne->id());

    $key = 'presence_channel:presence-presence-channel:user:'.auth()->user()->getAuthIdentifier();

    $storedUserConnections = unserialize(Redis::get($key));

    expect($storedUserConnections->values())->toEqual(
        collect([
            $connectionTwo->id(),
        ])
    );
});

function joinRequest($channelName, Authenticatable $user, string $connectionId)
{
    return actingAs($user)->post(route('wave.presence-channel-users'), ['channel_name' => 'presence-'.$channelName], ['X-Socket-Id' => $connectionId]);
}

function leaveRequest($channelName, Authenticatable $user, string $connectionId)
{
    return actingAs($user)->delete(route('wave.presence-channel-users'), ['channel_name' => 'presence-'.$channelName], ['X-Socket-Id' => $connectionId]);
}
