<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\NoteAttachmentController;
use App\Http\Controllers\SyncController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

Route::get('/auth/google/redirect', [GoogleController::class, 'redirect'])
    ->name('google.redirect');

Route::get('/auth/google/callback', [GoogleController::class, 'callback'])
    ->name('google.callback');

Route::view('/app', 'pwa.notes')->name('pwa.notes');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('/notes', 'notes.index')->name('notes.index');
    Route::get('/sync', [SyncController::class, 'index'])->name('sync.index');
    Route::post('/sync', [SyncController::class, 'store'])->name('sync.store');
    Route::get('/note-attachments/{attachment}/download', [
        NoteAttachmentController::class,
        'download',
    ])->name('note-attachments.download');
});

require __DIR__.'/settings.php';
