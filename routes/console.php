<?php

use App\Console\Commands\CleanupNoteBackupStaging;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(CleanupNoteBackupStaging::class)
    ->hourly()
    ->withoutOverlapping(60);

Schedule::command('app:dispatch-due-note-reminders')
    ->everyMinute()
    ->withoutOverlapping(2);
