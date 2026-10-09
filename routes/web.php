<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\GoogleController;

Route::view('/', 'welcome')->name('home');

Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])
    ->name('google.redirect');

Route::get('/auth/google/callback', [GoogleController::class, 'callback'])
    ->name('google.callback');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('/notes', 'notes.index')->name('notes.index');
    Route::get('/note-attachments/{attachment}/download', [
        \App\Http\Controllers\NoteAttachmentController::class,
        'download',
    ])->name('note-attachments.download');
});

require __DIR__ . '/settings.php';
