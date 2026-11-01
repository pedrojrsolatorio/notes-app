<?php

use App\Http\Controllers\NoteBackupController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::livewire('settings/profile', 'pages::settings.profile')->name('profile.edit');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('settings/appearance', 'pages::settings.appearance')->name('appearance.edit');

    Route::livewire('settings/import-export', 'pages::settings.import-export')
        ->name('import-export.edit');

    Route::post('settings/import-export/export', [NoteBackupController::class, 'export'])
        ->name('import-export.export');
    Route::post('settings/import-export/preview', [NoteBackupController::class, 'preview'])
        ->name('import-export.preview');
    Route::post('settings/import-export/import', [NoteBackupController::class, 'import'])
        ->name('import-export.import');
    Route::post('settings/import-export/cancel', [NoteBackupController::class, 'cancel'])
        ->name('import-export.cancel');

    Route::livewire('settings/security', 'pages::settings.security')
        ->middleware([
            'password.confirm',
        ])
        ->name('security.edit');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
