<?php

use App\Models\User;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

test('creates an account linked to google on first google login', function () {
    $googleUser = new SocialiteUser;
    $googleUser->id = 'google-id-123';
    $googleUser->email = 'test@example.com';
    $googleUser->name = 'Test User';
    $googleUser->nickname = 'tester';

    $provider = Mockery::mock(SocialiteProvider::class);
    $provider->shouldReceive('user')->andReturn($googleUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $response = $this->get(route('google.callback'));

    $response->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'google_id' => 'google-id-123',
    ]);
});

test('links google to an existing account with the same email', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'google_id' => null,
    ]);

    $googleUser = new SocialiteUser;
    $googleUser->id = 'google-id-456';
    $googleUser->email = 'test@example.com';
    $googleUser->name = 'Test User';
    $googleUser->nickname = 'tester';

    $provider = Mockery::mock(SocialiteProvider::class);
    $provider->shouldReceive('user')->andReturn($googleUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $response = $this->get(route('google.callback'));

    $response->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticatedAs($user->fresh());

    $this->assertDatabaseCount('users', 1);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'email' => 'test@example.com',
        'google_id' => 'google-id-456',
    ]);
});
