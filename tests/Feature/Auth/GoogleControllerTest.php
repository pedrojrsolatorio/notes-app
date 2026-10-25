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

    $response = $this->get(route('google.callback', ['code' => 'google-auth-code']));

    $response->assertRedirect(route('notes.index', absolute: false));

    $this->assertAuthenticated();

    $this->assertDatabaseHas('users', [
        'email' => 'test@example.com',
        'google_id' => 'google-id-123',
    ]);

    expect(User::where('email', 'test@example.com')->firstOrFail()->hasVerifiedEmail())->toBeTrue();
});

test('links google to an existing account with the same email', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'google_id' => null,
        'email_verified_at' => null,
    ]);

    $googleUser = new SocialiteUser;
    $googleUser->id = 'google-id-456';
    $googleUser->email = 'test@example.com';
    $googleUser->name = 'Test User';
    $googleUser->nickname = 'tester';

    $provider = Mockery::mock(SocialiteProvider::class);
    $provider->shouldReceive('user')->andReturn($googleUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $response = $this->get(route('google.callback', ['code' => 'google-auth-code']));

    $response->assertRedirect(route('notes.index', absolute: false));

    $this->assertAuthenticatedAs($user->fresh());

    $this->assertDatabaseCount('users', 1);

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'email' => 'test@example.com',
        'google_id' => 'google-id-456',
    ]);

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('marks an existing google account as verified when it signs in', function () {
    $user = User::factory()->create([
        'email' => 'test@example.com',
        'google_id' => 'google-id-789',
        'email_verified_at' => null,
    ]);

    $googleUser = new SocialiteUser;
    $googleUser->id = 'google-id-789';
    $googleUser->email = 'test@example.com';

    $provider = Mockery::mock(SocialiteProvider::class);
    $provider->shouldReceive('user')->andReturn($googleUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $this->get(route('google.callback', ['code' => 'google-auth-code']))
        ->assertRedirect(route('notes.index', absolute: false));

    expect($user->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('returns to signup when google authorization is canceled', function () {
    $provider = Mockery::mock(SocialiteProvider::class);
    $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com'));
    $provider->shouldNotReceive('user');

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $this->get(route('google.redirect', ['return' => 'register']))
        ->assertRedirect('https://accounts.google.com')
        ->assertSessionHas('google_auth_return_to', 'register');

    $this->get(route('google.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('register', absolute: false))
        ->assertSessionHas('status', 'Google sign in was cancelled.');
});

test('returns to login when google callback has no authorization code', function () {
    $provider = Mockery::mock(SocialiteProvider::class);
    $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com'));
    $provider->shouldNotReceive('user');

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $this->get(route('google.redirect', ['return' => 'login']))
        ->assertRedirect('https://accounts.google.com')
        ->assertSessionHas('google_auth_return_to', 'login');

    $this->get(route('google.callback'))
        ->assertRedirect(route('login', absolute: false))
        ->assertSessionHas('status', 'Google sign in was cancelled.');
});
