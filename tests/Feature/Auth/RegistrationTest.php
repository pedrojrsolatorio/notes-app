<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('registration screen links to google signup', function () {
    $this->get(route('register'))
        ->assertSee('Sign up with Google')
        ->assertSee('href="'.route('google.redirect', ['return' => 'register']).'"', false);
});

test('new users can register', function () {
    Notification::fake();

    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();

    $user = User::where('email', 'test@example.com')->firstOrFail();
    Notification::assertSentTo($user, VerifyEmail::class);
});

test('[AC-6] creates the four starter folders for new users', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Folder User',
        'email' => 'folders@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors();

    $user = User::where('email', 'folders@example.com')->firstOrFail();

    expect($user->folders()->pluck('name')->sort()->values()->all())
        ->toEqual(['Personal', 'Projects', 'School', 'Work']);
});
