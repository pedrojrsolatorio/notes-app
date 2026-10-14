<?php

use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});

test('new users receive default folders on registration', function () {
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
