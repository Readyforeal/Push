<?php

use App\Models\Relationship;
use App\Services\DailyPromptScheduler;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('prompts:schedule {--force : Ignore the local delivery hour}', function (DailyPromptScheduler $scheduler) {
    $scheduled = 0;

    Relationship::query()->each(function (Relationship $relationship) use ($scheduler, &$scheduled): void {
        if ($scheduler->scheduleFor($relationship, force: (bool) $this->option('force'))) {
            $scheduled++;
        }
    });

    $this->info("Scheduled {$scheduled} daily prompt round(s).");
})->purpose('Schedule the next daily prompt for eligible couples');

Schedule::command('prompts:schedule')->everyMinute()->withoutOverlapping();
